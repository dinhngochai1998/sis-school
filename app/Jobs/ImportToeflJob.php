<?php
/**
 * @Author Pham Van Tien
 * @Date   Mar 25, 2022
 */

namespace App\Jobs;

use App\Import\ToeflImport;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportToeflJob extends QueueJob
{
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[IMPORT] Data import:  ', (array)$data);
        Excel::import(new ToeflImport($data->email ?? null, $data->school_uuid ?? null), $data->file_path);
        Log::info("[IMPORT TOEFL] Success to import toefl with url :  $data->file_path");
    }
}