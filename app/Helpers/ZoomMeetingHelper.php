<?php
/**
 * @Author im.phien
 * @Date   Jun 15, 2022
 */

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Log;

class ZoomMeetingHelper
{
    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 21, 2022
     *
     * @param string $userId
     * @param string $token
     *
     * @return false|mixed
     */
    public function getListZoomMeeting(string $userId, string $token): mixed
    {
        $url      = env('ZOOM_MEETING_URL') . '/users/' . $userId . '/meetings';
        $response = Http::withHeaders(
            [
                'Authorization' => 'Bearer ' . $token,
            ]
        )->get($url);
        $status   = $response->status();
        if ($status != 200) {
            Log::info("[VIDEO CONFERENCE] create meeting with URL : $url, status :{$response->status()} , message :  {$response->body()}");

            return false;
        }
        Log::info("[VIDEO CONFERENCE] create meeting with URL : $url, success");

        return json_decode($response);
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 21, 2022
     *
     * @param string $userId
     * @param object $data
     * @param string $token
     *
     * @return mixed
     */
    public function createZoomMeeting(string $userId, object $data, string $token): mixed
    {
        $url      = env('ZOOM_MEETING_URL') . '/users/' . $userId . '/meetings';
        $response = Http::withHeaders(
            [
                'Authorization' => 'Bearer ' . $token,
            ]
        )->post($url, [
            'topic'        => $data->topic,
            'duration'     => $data->duration,
            'password'     => $data->password ?? null,
            'pre_schedule' => $data->pre_schedule,
            'recurrence'   => $data->recurrence ?? null,
            'settings'      => [
                'allow_multiple_devices'               => true,
                'alternative_hosts_email_notification' => true,
                'alternative_hosts'                    => $data->settings['email'] ?? null,
                'audio'                                => 'both',
                'auto_recording'                       => 'none',
                'calendar_type'                        => 2,
                'email_notification'                   => true,
                'focus_mode'                           => true,
                'jbh_time'                             => $data->settings['jbh_time'],
                'join_before_host'                     => $data->settings['join_before_host'],
                'meeting_authentication'               => false,
                'use_pmi'                              => true,
                'waiting_room'                         => $data->settings['waiting_room']
            ],
            'start_time'   => $data->start_time,
            'timezone'     => $data->timezone,
            'type'         => $data->type
        ]);

        $status = $response->status();
        if ($status != 201) {
            Log::info("[VIDEO CONFERENCE] create meeting with URL : $url, status :{$response->status()} , message :  {$response->body()}");

            return false;
        }
        Log::info("[VIDEO CONFERENCE] create meeting with URL : $url, success");

        return json_decode($response);
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 21, 2022
     *
     * @param string $meetingId
     * @param object $data
     * @param string $token
     *
     * @return bool|false
     */
    public function updateZoomMeeting(string $meetingId, object $data, string $token): bool
    {
        $url      = env('ZOOM_MEETING_URL') . '/meetings/' . $meetingId;
        $response = Http::withHeaders(
            [
                'Authorization' => 'Bearer ' . $token,
            ]
        )->patch($url, [
            'topic'        => $data->topic,
            'duration'     => $data->duration,
            'password'     => $data->password ?? null,
            'pre_schedule' => $data->pre_schedule,
            'recurrence'   => $data->recurrence ?? null,
            'settings'    => [
                'alternative_hosts_email_notification' => true,
                'alternative_hosts'                    => $data->settings['email'] ?? null,
                'jbh_time'                             => $data->settings['jbh_time'],
                'join_before_host'                     => $data->settings['join_before_host'],
                'meeting_authentication'               => false,
                'use_pmi'                              => true,
                'waiting_room'                         => $data->settings['waiting_room']
            ],
            'start_time' => $data->start_time,
            'timezone'   => $data->timezone,
            'type'       => $data->type
        ]);


        $status = $response->status();
        if ($status != 204) {
            Log::info("[VIDEO CONFERENCE] update meeting with URL : $url, status :{$response->status()} , message :  {$response->body()}");

            return false;
        }
        Log::info("[VIDEO CONFERENCE] update meeting with URL : $url, success");

        return true;
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 22, 2022
     *
     * @param string $meetingId
     * @param string $token
     *
     * @return bool|false
     */
    public function deleteZoomMeeting(string $meetingId, string $token): bool
    {
        $url      = env('ZOOM_MEETING_URL') . '/meetings/' . $meetingId;
        $response = Http::withHeaders(
            [
                'Authorization' => 'Bearer ' . $token,
            ]
        )->delete($url);
        $status   = $response->status();
        if ($status != 204) {
            Log::info("[VIDEO CONFERENCE] delete meeting with URL : $url, status :{$response->status()} , message :  {$response->body()}");

            return false;
        }
        Log::info("[VIDEO CONFERENCE] delete meeting with URL : $url, success");

        return true;
    }

    public function getRecord(string $meetingId, string $token)
    {
        $url      = env('ZOOM_MEETING_URL') . '/meetings/' . $meetingId . '/recordings';
        $response = Http::withHeaders(
            [
                'Authorization' => 'Bearer ' . $token,
            ]
        )->get($url);
        $status   = $response->status();
        if ($status != 200) {
            Log::info("[VIDEO CONFERENCE] get recordings meeting with URL : $url, status :{$response->status()} , message :  {$response->body()}");

            return false;
        }
        Log::info("[VIDEO CONFERENCE] get recordings meeting with URL : $url, success");

        return json_decode($response);
    }

    public function downloadRecord($url, $token)
    {
        $response = Http::withHeaders(
            [
                'Authorization' => 'Bearer ' . $token,
            ]
        )->get($url);
        $status   = $response->status();
        if ($status != 200) {
            Log::info("[VIDEO CONFERENCE] download record meeting with URL : $url, status :{$response->status()} , message :  {$response->body()}");

            return false;
        }
        Log::info("[VIDEO CONFERENCE] download record meeting with URL : $url, success");

        return $response->body();
    }
}

