<?php

namespace App\Services\impl;

use App\Helpers\RabbitMQHelper;
use Exception;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class SMSWithRabbitMQ implements SmsService
{
    use RabbitMQHelper;

    /**
     * @param        $smsSetting
     * @param        $smsParticipant
     * @param string $content
     * @param string $hash
     *
     * @throws Exception
     */
    public function sendSms($smsSetting, $smsParticipant,string $content, string $hash)
    {
        $body = [
            "auth"    => [
                "type"       => "manual",
                "owner"      => "sis",
                "provider"   => strtolower($smsSetting->provider),
                "brand_name" => $smsSetting->phone_number,
                "username"   => $smsSetting->external_id,
                "password"   => $smsSetting->token,
            ],
            "content" => $content,
            "to"      => $smsParticipant->phone_number,
            "webhook" => [
                "url"                => env('URL_PROJECT'),
                "participant_sms_id" => $smsParticipant->id,
                "hash"               => $hash,
                "token"              => null
            ],
        ];
        $this->setVHost(env('RABBITMQ_VHOST_NOTIFICATION_DEV'))
                 ->pushToExchange($body, 'SMS', AMQPExchangeType::DIRECT, strtolower($smsSetting->provider));
    }
}
