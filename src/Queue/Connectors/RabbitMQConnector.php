<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors;

use Enqueue\AmqpTools\DelayStrategyAware;
use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use Interop\Amqp\AmqpConnectionFactory as InteropAmqpConnectionFactory;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\AmqpContext;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class RabbitMQConnector implements ConnectorInterface
{
    /**
     * @var Dispatcher
     */
    private $dispatcher;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Establish a queue connection.
     *
     * @param array $config
     *
     * @return Queue
     */
    public function connect(array $config): Queue
    {
        if (false === array_key_exists('factory_class', $config)) {
            throw new \LogicException('The factory_class option is missing though it is required.');
        }

        // for reconnecting...
        $build_context_fn = function() use ($config) {
            $factoryClass = $config['factory_class'];
            if (false === class_exists($factoryClass) || false === (new \ReflectionClass($factoryClass))->implementsInterface(InteropAmqpConnectionFactory::class)) {
                throw new \LogicException(sprintf('The factory_class option has to be valid class that implements "%s"', InteropAmqpConnectionFactory::class));
            }

            /** @var AmqpConnectionFactory $factory */
            $factory = new $factoryClass([
                'dsn' => $config['dsn'],
                'host' => $config['host'],
                'port' => $config['port'],
                'user' => $config['login'],
                'pass' => $config['password'],
                'vhost' => $config['vhost'],
                'ssl_on' => $config['ssl_params']['ssl_on'],
                'ssl_verify' => $config['ssl_params']['verify_peer'],
                'ssl_cacert' => $config['ssl_params']['cafile'],
                'ssl_cert' => $config['ssl_params']['local_cert'],
                'ssl_key' => $config['ssl_params']['local_key'],
                'ssl_passphrase' => $config['ssl_params']['passphrase'],
                'receive_method' => isset($config['receive']) ? $config['receive']['method'] : 'basic_get',
                'heartbeat' => isset($config['timeouts']) ? $config['timeouts']['heartbeat'] : 0,
                'read_timeout' => isset($config['timeouts']) ? $config['timeouts']['read'] : 3,
                'write_timeout' => isset($config['timeouts']) ? $config['timeouts']['write'] : 3,
            ]);

            if ($factory instanceof DelayStrategyAware) {
                $factory->setDelayStrategy(new RabbitMqDlxDelayStrategy());
            }

            return $factory->createContext();
        };
        $context = $build_context_fn();

        $this->dispatcher->listen(WorkerStopping::class, function () use ($context) {
            $context->close();
        });

        return new RabbitMQQueue($context, $config, $build_context_fn);
    }
}
