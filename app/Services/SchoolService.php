<?php


namespace App\Services;


use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\NotFoundException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\SchoolNoSQL;
use YaangVu\SisModel\App\Models\impl\SchoolSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class SchoolService extends BaseService
{
    use RoleAndPermissionTrait;

    function createModel(): void
    {
        $this->model = new SchoolNoSQL();
    }

    // public function preAdd(object $request)
    // {
    //     if (!BaseService::currentUser()->hasRole(RoleConstant::GOD)) {
    //         throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
    //     }
    //     parent::preAdd($request);
    // }
    //
    // public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    // {
    //     $rules = [
    //         'name'      => 'required|iunique:schools,name',
    //         'address'   => 'required',
    //         'fax'       => 'required',
    //         'principal' => 'required',
    //         'phone'     => 'required|numeric',
    //         'timezone'  => 'required'
    //     ];
    //
    //     return parent::storeRequestValidate($request, $rules, $messages);
    // }

    public function postAdd(object $request, Model|SchoolNoSQL $model)
    {
        $schoolSQL               = new SchoolSQL();
        $schoolSQL->name         = $model->name ?? null;
        $schoolSQL->uuid         = $model->uuid;
        $schoolSQL->year_founded = $model->year_founded ?? null;
        $schoolSQL->save();

        return $schoolSQL;
    }

    public function preUpdate(int|string $id, object $request)
    {
        if (!$this->isGod()) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        parent::preUpdate($id, $request);
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $schoolNoSQL = $this->get($id);
        $schoolSQL   = $this->getSchoolSQLByUuid($schoolNoSQL->uuid);

        $rules = [
            'name'      => "required|iunique:schools,name," . $schoolSQL->id,
            'address'   => 'required',
            'fax'       => 'required',
            'principal' => 'required',
            'phone'     => 'required|numeric',
            'timezone'  => 'required'
        ];

        return parent::updateRequestValidate($id, $request, $rules, $messages);
    }

    public function postUpdate(int|string $id, object $request, Model $model)
    {
        $schoolSQL = $this->getSchoolSQLByUuid($model->uuid);
        $schoolSQL->update($request->all());
        parent::postUpdate($id, $request, $model);
    }

    public function preDelete(int|string $id)
    {
        if (!BaseService::currentUser()->hasRole(RoleConstant::GOD)) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $schoolNoSQL = $this->get($id);
        SchoolSQL::where('uuid', $schoolNoSQL->uuid)->delete();
        parent::preDelete($id);
    }

    public function getSchoolSQLByUuid($uuid)
    {
        return SchoolSQL::where('uuid', $uuid)->first();
    }

    public function getCurrentSchool()
    {
        $schoolId = SchoolServiceProvider::$currentSchool->id ?? null;
        try {
            return SchoolSQL::with('schoolNoSql')
                            ->where('id', '=', $schoolId)
                            ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new NotFoundException(
                ['message' => __("not-exist", ['attribute' => __('entity')]) . ": $schoolId"],
                $e
            );
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }


}
