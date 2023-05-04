<?php


namespace App\Helpers;


use Exception;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use YaangVu\RabbitMQ\RabbitMQConnection;

trait RabbitMQHelper
{
    public AMQPStreamConnection $connection;
    private ?string $vHost    = null;

    public function init()
    {
        $host     = env('RABBITMQ_HOST');
        $port     = env("RABBITMQ_PORT");
        $user     = env("RABBITMQ_USER");
        $password = env('RABBITMQ_PASSWORD');
        $vHost    = empty($this->vHost) ? env("RABBITMQ_VHOST") : $this->vHost;
        $this->connection = (new RabbitMQConnection())
            ->setHost($host)
            ->setPort($port)
            ->setUser($user)
            ->setPassword($password)
            ->setVHost($vHost)
            ->connect();
    }

    /**
     * @param array  $body
     * @param string $exchange
     * @param string $type
     * @param string $routingKey
     *
     * @throws Exception
     */
    public function pushToExchange(array $body, string $exchange, string $type = AMQPExchangeType::DIRECT,
                                   string $routingKey = ''): void
    {
        $this->init();

        $channel = $this->connection->channel();

        $channel->exchange_declare($exchange, $type, false, true, false);

        $messageBody = json_encode($body);

        $message = new AMQPMessage($messageBody, ['content_type' => 'text/plain']);
        if (empty($routingKey))
            $channel->basic_publish($message, $exchange);
        else
            $channel->basic_publish($message, $exchange, $routingKey);

        Log::info("Push to RabbitMQ exchange: $exchange, type: $type, routing key: $routingKey, body: ", $body);

        $channel->close();
        $this->connection->close();
    }

    public function setQueue()
    {
        // Do something
    }

    /**
     * @param array  $body
     * @param string $queue
     *
     * @throws Exception
     */
    public function pushToQueue(array $body, string $queue)
    {
        $this->init();

        $channel = $this->connection->channel();

        # Create the queue if it does not already exist.
        $channel->queue_declare($queue, false, true, false, false, false, null);

        $messageBody = json_encode($body);

        # make message persistent
        $message = new AMQPMessage($messageBody, ['content_type' => 'text/plain']);

        $channel->basic_publish($message, '', $queue);

        Log::info("Push to RabbitMQ queue: $queue, body: ", $body);

        $channel->close();
        $this->connection->close();
    }

    /**
     * @param string|null $vHost
     *
     * @return RabbitMQHelper
     */
    public function setVHost(?string $vHost): static
    {
        $this->vHost = $vHost;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getVHost(): ?string
    {
        return $this->vHost;
    }
}
