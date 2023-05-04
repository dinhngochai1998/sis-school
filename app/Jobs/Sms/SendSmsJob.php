<?php

namespace App\Jobs\Sms;

use App\Jobs\QueueJob;
use App\Services\impl\SMSWithRabbitMQ;
use App\Services\UserService;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\NoReturn;
use YaangVu\Constant\StatusSmsTemplateConstant;
use YaangVu\SisModel\App\Models\impl\SmsParticipantSQL;
use YaangVu\SisModel\App\Models\impl\SmsSettingSQL;

class SendSmsJob extends QueueJob
{
    public UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    /**
     * Execute the job.
     *
     * @param null $consumer
     * @param      $data
     *
     * @return void
     * @throws Exception
     */
    #[NoReturn]
    public function handle($consumer = null, $data = null)
    {
        $dataSmsParticipants = [];
        $smsSetting          = SmsSettingSQL::query()->where('id', $data->providerId)->first();
        $hash                = Str::random(80);
        foreach ($data->chooseRecipient as $user) {
            $dataSmsParticipants [] = [
                'user_uuid'      => $user->uuid ?? null,
                'phone_number'   => $user->phone_number ?? null,
                'status'         => StatusSmsTemplateConstant::QUEUE,
                'template_id'    => $data->templateId,
                'provider_id'    => $smsSetting->id,
                'created_at'     => Carbon::now(),
                'sent_date_time' => Carbon::now(),
                'created_by'     => $data->currentUserId,
                'sms_id'         => $data->smsId,
                'school_id'      => $data->schoolId ?? null,
            ];
        }
        foreach (array_chunk($dataSmsParticipants, 500) as $smsParticipants) {
            SmsParticipantSQL::query()->insert($smsParticipants);
        }

        $smsParticipants = SmsParticipantSQL::query()
                                            ->whereNotNull('phone_number')
                                            ->where('sms_id', $data->smsId)
                                            ->get();
        $sendSms         = new SMSWithRabbitMQ();
        foreach ($smsParticipants as $smsParticipant) {
            $sendSms->sendSms($smsSetting, $smsParticipant, $data->content, $hash);
            log::info('send sms success');
        }
    }
}
