<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\Impl\AmqpBind;
use Psr\Log\LoggerInterface;
use RuntimeException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;

class RabbitMQQueue extends Queue implements QueueContract
{
    protected $sleepOnError;

    protected $queueOptions;
    protected $exchangeOptions;
    protected $receiveConfig;

    private $declaredExchanges = [];
    private $declaredQueues = [];

    private $declarationsCache = [];
    private $consumerCache = [];

    /**
     * @var AmqpContext
     */
    protected $build_context_fn;
    private $context;
    private $correlationId;

    public function __construct(AmqpContext $context, array $config, callable $build_context_fn)
    {
        $this->context = $context;
        $this->build_context_fn = $build_context_fn;

        $this->queueOptions = $config['options']['queue'];
        $this->queueOptions['arguments'] = isset($this->queueOptions['arguments']) ?
            json_decode($this->queueOptions['arguments'], true) : [];

        $this->exchangeOptions = $config['options']['exchange'];
        $this->exchangeOptions['arguments'] = isset($this->exchangeOptions['arguments']) ?
            json_decode($this->exchangeOptions['arguments'], true) : [];

        $this->receiveConfig = $config['receive'] ?? [];

        $this->sleepOnError = $config['sleep_on_error'] ?? 5;
    }

    public function reconnect()
    {
        $this->context = call_user_func($this->build_context_fn);
        $this->correlationId = null;
        $this->declarationsCache = [];
    }

    /** @inheritdoc */
    public function size($queueName = null): int
    {
        /** @var AmqpQueue $queue */
        list($queue) = $this->declareEverythingOnce($queueName);

        return $this->context->declareQueue($queue);
    }

    /** @inheritdoc */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, []);
    }

    /** @inheritdoc */
    public function pushRaw($payload, $queueName = null, array $options = [])
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                /**
                 * @var AmqpTopic $topic
                 * @var AmqpQueue $queue
                 */
                list($queue, $topic) = $this->declareEverythingOnce($queueName);

                $message = $this->context->createMessage($payload);
                $message->setRoutingKey($queue->getQueueName());
                $message->setCorrelationId($this->getCorrelationId());
                $message->setContentType('application/json');
                $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);

                if (isset($options['attempts'])) {
                    $message->setProperty(RabbitMQJob::ATTEMPT_COUNT_HEADERS_KEY, $options['attempts']);
                }

                $producer = $this->context->createProducer();
                if (isset($options['delay']) && $options['delay'] > 0) {
                    $producer->setDeliveryDelay($options['delay'] * 1000);
                }

                $producer->send($topic, $message);

                return $message->getCorrelationId();
            } catch (\Exception $exception) {
                // on first failure, try re-closing and opening the queue connection
                if ($attempt == 1) {
                    $this->reconnect();
                    continue;
                }

                $this->reportConnectionError('pushRaw', $exception);

                return null;
            }
        }
    }

    /** @inheritdoc */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $this->secondsUntil($delay)]);
    }

    /**
     * Release a reserved job back onto the queue.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed   $data
     * @param  string  $queue
     * @param  int  $attempts
     * @return mixed
     */
    public function release($delay, $job, $data, $queue, $attempts = 0)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, [
            'delay' => $this->secondsUntil($delay),
            'attempts' => $attempts
        ]);
    }

    /** @inheritdoc */
    public function pop($queueName = null)
    {
        try {
            /** @var AmqpQueue $queue */
            list($queue) = $this->declareEverythingOnce($queueName);

            // create the consumer once and cache it
            $consumer = $this->createConsumerOnce($queue);

            if (isset($this->receiveConfig['method']) and $this->receiveConfig['method'] == 'basic_consume') {
                $message = $consumer->receive($this->receiveConfig['timeout']);
            } else {
                $message = $consumer->receiveNoWait();
            }

            if ($message) {
                return new RabbitMQJob($this->container, $this, $consumer, $message);
            }
        } catch (\Exception $exception) {
            $this->reportConnectionError('pop', $exception);
        }

        return null;
    }

    /**
     * Retrieves the correlation id, or a unique id.
     *
     * @return string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId ?: uniqid('', true);
    }

    /**
     * Sets the correlation id for a message to be published.
     *
     * @param string $id
     *
     * @return void
     */
    public function setCorrelationId(string $id)
    {
        $this->correlationId = $id;
    }

    /**
     * @return AmqpContext
     */
    public function getContext(): AmqpContext
    {
        return $this->context;
    }

    /**
     * @param string $queueName
     *
     * @return array [Interop\Amqp\AmqpQueue, Interop\Amqp\AmqpTopic]
     */
    private function declareEverythingOnce(string $queueName = null): array
    {
        $queueName = $queueName ?: $this->queueOptions['name'];
        if (!isset($this->declarationsCache[$queueName])) {
            $this->declarationsCache[$queueName] = $this->declareEverything($queueName);
        }
        return $this->declarationsCache[$queueName];
    }

    /**
     * @param string $queueName
     *
     * @return array [Interop\Amqp\AmqpQueue, Interop\Amqp\AmqpTopic]
     */
    private function declareEverything(string $queueName = null): array
    {
        $queueName = $queueName ?: $this->queueOptions['name'];
        $exchangeName = $this->exchangeOptions['name'] ?: $queueName;

        $topic = $this->context->createTopic($exchangeName);
        $topic->setType($this->exchangeOptions['type']);
        $topic->setArguments($this->exchangeOptions['arguments']);
        if ($this->exchangeOptions['passive']) {
            $topic->addFlag(AmqpTopic::FLAG_PASSIVE);
        }
        if ($this->exchangeOptions['durable']) {
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        }
        if ($this->exchangeOptions['auto_delete']) {
            $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);
        }

        if ($this->exchangeOptions['declare'] && !in_array($exchangeName, $this->declaredExchanges, true)) {
            $this->context->declareTopic($topic);

            $this->declaredExchanges[] = $exchangeName;
        }

        $queue = $this->context->createQueue($queueName);
        $queue->setArguments($this->queueOptions['arguments']);
        if ($this->queueOptions['passive']) {
            $queue->addFlag(AmqpQueue::FLAG_PASSIVE);
        }
        if ($this->queueOptions['durable']) {
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        }
        if ($this->queueOptions['exclusive']) {
            $queue->addFlag(AmqpQueue::FLAG_EXCLUSIVE);
        }
        if ($this->queueOptions['auto_delete']) {
            $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        }

        if ($this->queueOptions['declare'] && !in_array($queueName, $this->declaredQueues, true)) {
            $this->context->declareQueue($queue);

            $this->declaredQueues[] = $queueName;
        }

        if ($this->queueOptions['bind']) {
            $this->context->bind(new AmqpBind($queue, $topic, $queue->getQueueName()));
        }

        return [$queue, $topic];
    }

    protected function createConsumerOnce($queue)
    {
        $cache_key = spl_object_hash($queue);
        if (!isset($this->consumerCache[$cache_key])) {
            $this->consumerCache[$cache_key] = $this->context->createConsumer($queue);
        }
        return $this->consumerCache[$cache_key];
    }

    /**
     * @param string $action
     * @param \Exception $e
     * @throws \Exception
     */
    protected function reportConnectionError($action, \Exception $e)
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container['log'];

        $logger->error('AMQP error while attempting ' . $action . ': ' . $e->getMessage());

        // If it's set to false, throw an error rather than waiting
        if ($this->sleepOnError === false) {
            throw new RuntimeException('Error writing data to the connection with RabbitMQ', null, $e);
        }

        // Sleep so that we don't flood the log file
        sleep($this->sleepOnError);
    }
}
