<?php
/**
 * @Author Admin
 * @Date   Mar 04, 2022
 */

namespace App\Jobs;


use App\Import\SatImport;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportSatJob extends QueueJob
{

    public function handle($consumer = null, ?object $data = null)
    {
        Excel::import(new SatImport($data->school_uuid, $data->email), $data->file_path);

        Log::info('[IMPORT SAT SUCCESS]');
    }
}