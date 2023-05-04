<?php

namespace App\Jobs;

use App\Import\IeltsImport;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @Author apple
 * @Date   Mar 19, 2022
 */
class ImportIeltsJob extends QueueJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[IMPORT] Data import:  ', (array)$data);
        Excel::import(new IeltsImport($data->email ?? null, $data->school_uuid ?? null), $data->file_path);
        Log::info("[IMPORT IELTS] Success to import ielts with url :  $data->file_path");
    }
}
