<?php
/**
 * @Author Dung
 * @Date   Mar 19, 2022
 */

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use MongoDB\BSON\UTCDateTime as MongoDate;
use YaangVu\Constant\CommunicationLogConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\CommunicationLogNoSql;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class CommunicationServices extends BaseService
{
    use RoleAndPermissionTrait, RoleAndPermissionTrait;

    function createModel(): void
    {
        $this->model = new CommunicationLogNoSql();
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->preGetAll();
        $request = \request()->all();

        $isViewCommunicationLog
            = $this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::VIEW_COMMUNICATION_LOG),
                                            RoleServiceProvider::$currentRole->id);

        $this->checkRoleAndPermission($isViewCommunicationLog);

        $rules = [
            'student_id'   => 'required|exists:mongodb.users,_id',
            'date_range.*' => 'date_format:Y-m-d',
        ];
        BaseService::doValidate((object)$request, $rules);

        $arrDateRange = !empty($request['date_range']) ? $request['date_range'] : null ;

        $this->queryHelper->removeParam('date_range');
        $data = $this->queryHelper->buildQuery($this->model)
                                  ->orderByDesc('created_at')
                                  ->with('createdBy.userNoSql')
                                  ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
                                  ->when($arrDateRange && count($arrDateRange) == 2, function ($q) use ($arrDateRange) {
                                      $q->where('date_of_contact', '>=', $arrDateRange[0])->where('date_of_contact', '<=', $arrDateRange[1]);
                                  })
                                  ->whereNull('deleted_at');
        try {
            $response = $data->paginate(QueryHelper::limit());
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function checkRoleAndPermission($isDynamic)
    {
        if (!$this->isGod() && !$isDynamic) {
            throw new ForbiddenException(__('forbidden . forbidden'), new \Exception());
        }
    }


    public function preAdd(object $request): object
    {
        $isAddCommunicationLog
            = $this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::EDIT_COMMUNICATION_LOG),
                                            RoleServiceProvider::$currentRole->id);
        $this->checkRoleAndPermission($isAddCommunicationLog);
        if ($request instanceof Request)
            $request = (object)$request->toArray();
        $request->date_of_contact = date('Y-m-d', strtotime($request->date_of_contact));
        $request->school_uuid     = SchoolServiceProvider::$currentSchool->uuid;

        return $request;
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        if (is_array($request->concerns)) {
            $rules = [
                'communication_detail' => 'required',
                'response_from_parent' => 'required',
                'method'               => 'required|in:' . implode(',', CommunicationLogConstant::METHODS),
                'concerns'             => 'required',
                'concerns.*'           => 'in:' . implode(',', CommunicationLogConstant::CONCERNS),
                'student_id'           => 'required|exists:mongodb.users,_id',
            ];
        } else {
            $rules = [
                'communication_detail' => 'required',
                'response_from_parent' => 'required',
                'method'               => 'required|in:' . implode(',', CommunicationLogConstant::METHODS),
                'concerns'             => 'required|in:' . implode(',', CommunicationLogConstant::CONCERNS),
                'student_id'           => 'required|exists:mongodb.users,_id',

            ];
        }

        return parent::storeRequestValidate($request, $rules, $messages);
    }


    public function preUpdate(int|string $id, object $request): object
    {
        $isEditCommunicationLog
            = $this->hasPermission(PermissionConstant::student(PermissionActionConstant::EDIT_COMMUNICATION_LOG));
        $this->checkRoleAndPermission($isEditCommunicationLog);
        if ($request instanceof Request)
            $request = (object)$request->toArray();
        $request->date_of_contact = date('Y-m-d', strtotime($request->date_of_contact));

        return $request;
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        if (is_array($request->concerns)) {
            $rules = [
                'communication_detail' => 'required',
                'response_from_parent' => 'required',
                'method'               => 'required|in:' . implode(',', CommunicationLogConstant::METHODS),
                'concerns'             => 'required',
                'concerns.*'           => 'in:' . implode(',', CommunicationLogConstant::CONCERNS),
                'student_id'           => 'sometimes|exists:mongodb.users,_id',
            ];
        } else {
            $rules = [
                'communication_detail' => 'required',
                'response_from_parent' => 'required',
                'method'               => 'required|in:' . implode(',', CommunicationLogConstant::METHODS),
                'concerns'             => 'required|in:' . implode(',', CommunicationLogConstant::CONCERNS),
                'student_id'           => 'sometimes|exists:mongodb.users,_id',
            ];
        }

        return parent::updateRequestValidate($id, $request, $rules, $messages);
    }

    public function preDelete(int|string $id)
    {
        $isDeleteCommunicationLog
            = $this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::EDIT_COMMUNICATION_LOG),
                                            RoleServiceProvider::$currentRole->id);
        $this->checkRoleAndPermission($isDeleteCommunicationLog);
        parent::preDelete($id); // TODO: Change the autogenerated stub
    }
}
