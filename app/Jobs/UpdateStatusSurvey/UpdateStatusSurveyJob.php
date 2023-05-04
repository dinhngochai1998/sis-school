<?php
/**
 * @Author Admin
 * @Date   Jul 11, 2022
 */

namespace App\Jobs\UpdateStatusSurvey;

use App\Jobs\Job;
use Exception;
use Illuminate\Support\Carbon;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\SurveyNoSql;

class UpdateStatusSurveyJob extends Job
{
    /**
     * @throws Exception
     */
    public function handle()
    {
        $surveys = SurveyNoSql::query()->where('status', StatusConstant::PENDING)
                              ->where('gerneral_information.effective_end_date', '<', Carbon::now()->format('Y-m-d'))
                              ->get();
        foreach ($surveys as $survey) {
            $survey->status = StatusConstant::ARCHIVED;
            $survey->save();
        }
    }

}