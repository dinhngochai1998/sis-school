<?php
/**
 * @Author apple
 * @Date   Mar 11, 2022
 */


namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use YaangVu\Constant\StatusConstant;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\DossierNoSQL;

class DossierService extends BaseService
{

    public Model|Builder|DossierNoSQL $model;

    function createModel(): void
    {
        $this->model = new DossierNoSQL();
    }

    public function getStatusImportScore($classId)
    {
        $dossierNoSQL = DossierNoSQL::where('class_id', $classId)
                                    ->orderBy('created_at', 'DESC')
                                    ->first();
        if ($dossierNoSQL) {
            return $dossierNoSQL->status;
        }

        return StatusConstant::DONE;
    }


}
