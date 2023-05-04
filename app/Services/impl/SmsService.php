<?php

namespace App\Services\impl;

use YaangVu\SisModel\App\Models\impl\SmsParticipantSQL;
use YaangVu\SisModel\App\Models\SmsSetting;

interface SmsService
{
    public function sendSms(SmsSetting $smsSetting, SmsParticipantSQL $smsParticipant, string $content, string $hash);
}
