<?php

namespace App\Jobs;

use App\Import\ClassActivityImport;
use Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportActivityScoreJob extends QueueJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[IMPORT ACTIVITY SCORE] Data import activity : ', (array)$data);
        Excel::import(new ClassActivityImport($data->class_id, $data->email ?? null, $data->url,
                                              $data->school_uuid ?? null),
                      $data->file_path);
        Log::info("[IMPORT ACTIVITY SCORE] Success to import activity score with url : $data->url");
    }
}
