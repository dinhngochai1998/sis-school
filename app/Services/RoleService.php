<?php


namespace App\Services;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\RoleSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class RoleService extends BaseService
{
    public Model|Builder|RoleSQL $model;

    public static function decorateWithScId(string $value): string
    {
        return SchoolServiceProvider::$currentSchool->uuid . ':' . $value;
    }

    public static function removeScId(string $value): string
    {
        if (!str_contains($value, ':'))
            return $value;

        [$scID, $decorRoleName] = explode(':', $value);

        return $decorRoleName ?? $value;
    }

    function createModel(): void
    {
        $this->model = new RoleSQL();
    }

    public static function getViaName(string $name): Model|RoleSQL|\Jenssegers\Mongodb\Eloquent\Builder|null
    {
        return RoleSQL::whereName(self::decorateWithScId($name))->first();
    }
}
