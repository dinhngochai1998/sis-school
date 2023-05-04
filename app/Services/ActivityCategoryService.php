<?php
/**
 * @Author Edogawa Conan
 * @Date   Aug 20, 2021
 */

namespace App\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ActivityCategorySQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ActivityCategoryService extends BaseService
{
    use RoleAndPermissionTrait;

    function createModel(): void
    {
        $this->model = new ActivityCategorySQL();
    }

    public function preGetAll()
    {
        $this->queryHelper->addParams(['school_id' => SchoolServiceProvider::$currentSchool->id]);
        parent::preGetAll();
    }

    public function preAdd(object $request)
    {
        if (!BaseService::currentUser()->hasRole(RoleConstant::GOD)) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        return $this->handleRequestParam($request);
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        $schoolId = SchoolServiceProvider::$currentSchool->id;
        $rules    = [
            'name'   => [
                'required',
                Rule::unique('activity_categories',)->where(function ($query) use ($schoolId) {
                    return $query->where('school_id', '=', $schoolId);
                }),
            ],
            'weight' => 'required|numeric'
        ];

        return parent::storeRequestValidate($request, $rules, $messages);
    }

    public function preUpdate(int|string $id, object $request)
    {
        if (!$this->isGod()) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        return $this->handleRequestParam($request);

    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $schoolId = SchoolServiceProvider::$currentSchool->id;
        $rules    = [
            'name'   => [
                'required',
                Rule::unique('activity_categories',)->where(function ($query) use ($id, $schoolId) {
                    return $query->where('school_id', '=', $schoolId)
                                 ->where('id', '=', $id);
                }),
            ],
            'weight' => 'required|numeric'
        ];

        return parent::updateRequestValidate($id, $request, $rules, $messages);
    }

    public function preDelete(int|string $id)
    {
        if (!BaseService::currentUser()->hasRole(RoleConstant::GOD)) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        parent::preDelete($id);
    }

    private function handleRequestParam(object $request): object
    {
        if ($request instanceof Request) {
            $request->merge(
                ['school_id' => SchoolServiceProvider::$currentSchool->id]
            );
        } else {
            $request->school_id = SchoolServiceProvider::$currentSchool->id;
        }

        return $request;
    }

    public function getParameterCurrentSchools(): \Illuminate\Database\Eloquent\Collection|array
    {
        try {
            $school = SchoolServiceProvider::$currentSchool;

            return ActivityCategorySQL::query()->where('school_id', $school->id)->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @throws Throwable
     */
    public function upsertActivityCategories(object $request): bool
    {
        if (!$this->isGod()) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $sum = 0;
        foreach ($request->all() as $item) {
            $sum += $item['weight'];
        }
        if ($sum > 100) {
            throw new BadRequestException(
                ['message' => __("activityCategory.weight-max")], new Exception()
            );
        }
        $this->doValidate($request,
                          [
                              '*.name'   => 'required',
                              '*.weight' => [
                                  'required',
                                  'numeric',
                              ]
                          ]);

        $data = array_map(function ($item) {
            $item['school_id']  = SchoolServiceProvider::$currentSchool->id;
            $item['created_by'] = $this->currentUser()->id;

            return $item;
        }, $request->all());

        DB::beginTransaction();
        try {
            $schoolId = SchoolServiceProvider::$currentSchool->id;
            ActivityCategorySQL::query()->where('school_id', '=', $schoolId)->forceDelete();
            $activityCategories = DB::table('activity_categories')->insert($data);
            DB::commit();

            return $activityCategories;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

}
