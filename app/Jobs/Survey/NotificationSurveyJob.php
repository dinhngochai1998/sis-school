<?php
/**
 * @Author Admin
 * @Date   Aug 15, 2022
 */

namespace App\Jobs\Survey;

use App\Helpers\FcmHelper;
use App\Jobs\QueueJob;
use Carbon\Carbon;
use DateTimeZone;
use Exception;
use YaangVu\SisModel\App\Models\impl\DeviceTokenSQL;
use YaangVu\SisModel\App\Models\impl\NotificationNoSQL;
use YaangVu\SisModel\App\Models\impl\SurveyLogNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class NotificationSurveyJob extends QueueJob
{
    /**
     * Execute the job.
     *
     * @param null        $consumer
     * @param object|null $data
     *
     * @throws Exception
     */
    public function handle($consumer = null, ?object $data = null)
    {
        $arrIdNoSql  = $arrUuid = [];
        $currentUser = $data->current_user;

        foreach ($data->users as $user) {
            $arrIdNoSql [] = $user->_id;
            $arrUuid []    = $user->uuid;
        }
        $arrIdSql     = UserSQL::query()->whereIn('uuid', $arrUuid)->pluck('id')->toArray();
        $title        = $data->survey->gerneral_information->title;
        $templateLink = $data->survey_url;
        $content      = 'You are invited to take part in a survey please check the notification';
        $deviceTokens = DeviceTokenSQL::query()->whereIn('user_id', $arrIdSql)->pluck('device_token')->toArray();
        $surveyLogs   = SurveyLogNoSQL::query()->whereIn('user_id', $arrIdNoSql)->get();

        if (!empty($deviceTokens)) {
            foreach ($data->users as $user) {
                $hashCode   = $surveyLogs->where('user_id', $user->_id)
                                         ->where('survey_id', $data->survey->_id)
                                         ->first()->hash_code ?? null;
                $linkSurvey = 'redirect-landing-page'. '/' . $templateLink . $data->survey->_id . '/' . $hashCode;

                $FcmHelper = new FcmHelper();
                $FcmHelper->pushToDevices($deviceTokens, $title, $content, $linkSurvey);
            }
        }
        $receiverIds = UserNoSQL::query()->whereIn('_id', $arrIdNoSql)->pluck('_id', 'full_name')
                                ->toArray();

        $notifications = $this->_handleDataNotification($receiverIds, $content, $title, $currentUser);

        if (!empty($notifications)) {
            NotificationNoSQL::query()->insert($notifications);
        }

    }

    public function _handleDataNotification($receiverIds, $content, $title, $currentUser): array
    {
        $timezone      = !empty($currentUser->reference->timezone) ? new DateTimeZone($currentUser->reference->timezone) :  new DateTimeZone('UTC');
        $notifications = [];
        $date          = Carbon::now($timezone);

        foreach ($receiverIds as $receiverId) {
            $notifications [] = [
                'user_id_from' => $currentUser->_id ?? null,
                'user_id_to'   => $receiverId,
                'title'        => $title,
                'contents'     => $content,
                'time_created' => $date->toDayDateTimeString(),
                'time_read'    => null,
                'created_at'   => $date->toDateTimeString(),
                'updated_at'   => $date->toDateTimeString(),
            ];
        }

        return $notifications;
    }
}