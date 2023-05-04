<?php
/**
 * @Author im.phien
 * @Date   Sep 21, 2022
 */

namespace App\Jobs;

use App\Helpers\ZoomMeetingHelper;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\UserJoinZoomMeetingConstant;
use YaangVu\LaravelAws\S3Service;
use YaangVu\SisModel\App\Constants\CalendarTypeConstant;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ZoomMeetingSQL;
use YaangVu\SisModel\App\Models\impl\ZoomParticipantSQL;
use YaangVu\SisModel\App\Models\impl\ZoomSettingSQL;

class GetUrlRecordVcrJob extends Job
{
    public function handle()
    {
        $today             = Carbon::today();
        $tomorrow          = Carbon::tomorrow();
        $calendars         = CalendarNoSQL::query()
                                          ->whereBetween('start',[$today, $tomorrow])
                                          ->where('type', CalendarTypeConstant::VIDEO_CONFERENCE)
                                          ->get();
        $urlDownloadRecord = '';

        foreach ($calendars as $calendar) {
            $zoomMeeting    = ZoomMeetingSQL::query()->where('id', $calendar->zoom_meeting_id)->first();
            $pmi            = $zoomMeeting->pmi;
            $zoomMeetingId  = $zoomMeeting->id;
            $zoomHostUuid   = ZoomParticipantSQL::query()->where('zoom_meeting_id', $zoomMeetingId)
                                                ->where('user_join_meeting', UserJoinZoomMeetingConstant::HOST)
                                                ->first()->user_uuid;
            $token          = ZoomSettingSQL::query()
                                            ->join('zoom_hosts', 'zoom_hosts.zoom_setting_id', '=', 'zoom_settings.id')
                                            ->where('zoom_hosts.uuid', $zoomHostUuid)
                                            ->first()->token;
            $responseRecord = (new ZoomMeetingHelper())->getRecord($pmi, $token);

            if ($responseRecord->recording_count == 0) {
                Log::info("This class schedule is not recorded");
                continue;
            }
            $urlDownloadRecord .= "wget -cO - " . $responseRecord->recording_files[0]->download_url . " > " . env('LOCAL_DATA_RECORD') . $calendar->_id . ".mp4" . "\n";

            if (!$urlDownloadRecord) {
                Log::info("Upload file record zoom meeting: $zoomMeetingId, false");
                continue;
            }

            $recordUrl       = env('AMAZON_PATH') . '/record_vcr/' . $calendar->_id . '.mp4';
            $this->S3Service = new S3Service();

            $recordUrlS3          = $this->S3Service->createPresigned($recordUrl, env('RECORD_VCR_EXPIRED'));
            $calendar->record_url = $recordUrlS3;
            $calendar->save();
        }

        $file         = new Filesystem();
        $resultUpload = $file->put(getcwd() . '/record.sh', $urlDownloadRecord);
        if ($resultUpload)
            Log::info("Upload file record zoom meeting with script in file record.sh : $urlDownloadRecord, true");
        else
            Log::info("Upload file record zoom meeting with script in file record.sh false");
    }
}