<?php


namespace App\Jobs;


use App\Import\SriSmiImport;
use Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportSriSmi extends QueueJob
{

    public function handle($consumer = null, object $data = null)
    {
        Log::info('BODY import : ', (array)$data);
        Excel::import(new SriSmiImport($data->school_uuid, $data->school_id, $data->imported_by,
                                       $data->imported_by_nosql, $data->email), $data->file_url, 's3');
    }
}
