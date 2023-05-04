<?php

namespace App\Services\impl;

use App\Helpers\RabbitMQHelper;
use App\Services\MailService;
use Exception;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class MailWithRabbitMQ implements MailService
{
    use RabbitMQHelper;

    /**
     * @param string $title
     * @param string $body
     * @param array  $recipients
     *
     * @throws Exception
     */
    public function sendMails(string $title, string $body, array $recipients)
    {
        $data = [
            "subject"    => $title,
            "body"       => $body,
            "recipients" => $recipients,
        ];
        $this->pushToExchange($data, 'EMAIL', AMQPExchangeType::DIRECT, env('MAIL_PROVIDER', 'ses'));
    }
}
