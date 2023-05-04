<?php
/**
 * @Author Admin
 * @Date   Mar 04, 2022
 */

namespace App\Jobs;

use App\Import\PhysicalPerformanceMeasures;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use YaangVu\SisModel\App\Models\impl\PhysicalPerformanceMeasuresNoSQL;

class ImportPhysicalPerformanceMeasuresJob extends QueueJob
{
    /**
     * Execute the job.
     *
     * @param null        $consumer
     * @param object|null $data
     *
     * @return array|void
     * @throws Exception
     */
    public function handle($consumer = null, ?object $data = null)
    {
        Excel::import(new PhysicalPerformanceMeasures($data->school_uuid), $data->file_path);
        $mail  = new MailWithRabbitMQ();
        $error = "";
        if (!empty(PhysicalPerformanceMeasures::$messageMailError)) {
            $errorImportPhysical = PhysicalPerformanceMeasures::$messageMailError;
            foreach ($errorImportPhysical as $errorsImport) {
                foreach ($errorsImport as $errorImport) {
                    $error = $error . implode('|', $errorImport) . "<br>";
                }
            }
            $titleError = 'import physical performance measures error';
            $mail->sendMails($titleError, $error, [$data->email]);

            return [];
        }
        $dataImportPhysicals = PhysicalPerformanceMeasures::$physicals;
        foreach ($dataImportPhysicals as $dataImportPhysical) {
            PhysicalPerformanceMeasuresNoSQL::query()->updateOrInsert(
                [
                    'student_code' => $dataImportPhysical['student_code'],
                    'test_date'    => $dataImportPhysical['test_date']
                ],
                $dataImportPhysical,
            );
        }
        $currentDate = Carbon::now()->toDateTimeString();
        $bodySuccess = "import physical performance measures success in : $currentDate";
        $title       = 'import physical performance measures success';
        $mail->sendMails($title, $bodySuccess, [$data->email]);
        Log::info("[IMPORT success] import physical performance success in : $currentDate");

    }
}
