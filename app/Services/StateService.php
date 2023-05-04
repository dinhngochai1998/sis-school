<?php
/**
 * @Author Admin
 * @Date   Aug 01, 2022
 */

namespace App\Services;

use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\StateNoSQL;

class StateService extends BaseService
{
    function createModel(): void
    {
        $this->model = new StateNoSQL();
    }
}