<?php

namespace App\Jobs;

use App\Import\IeltsImport;
use App\Import\ImportEnrollStudent;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @Author apple
 * @Date   Mar 19, 2022
 */
class ImportEnrollStudentJob extends QueueJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[IMPORT] Data import:  ', (array)$data);
        Excel::import(new ImportEnrollStudent($data->email ?? null, $data->school_uuid ?? null, $data->class_id ?? null, $data->lms_id ?? null), $data->file_path);
        Log::info("[IMPORT ENROLL] Success to import enroll student with url :  $data->file_path");
    }
}
