<?php

namespace App\Jobs;

use App\Import\ImportSbac;
use Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportSbacJob extends QueueJob
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[IMPORT ACT SCORE] Data import ACT : ', (array)$data);
        Excel::import(new ImportSbac($data->email,$data->school_uuid),
                      $data->file_path);
        Log::info("[IMPORT ACT SCORE] Success to import ACT score with url : $data->url");
    }
}
