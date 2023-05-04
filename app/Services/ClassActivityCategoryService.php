<?php

namespace App\Services;

use DB;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\CalendarSQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityCategorySQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ClassActivityCategoryService extends BaseService
{
    public const DEFAULT_NAME = 'Scores synced from LMS system';

    function createModel(): void
    {
        $this->model = new ClassActivityCategorySQL();
    }

    /**
     * @Author Edogawa Conan
     * @Date   Jun 01, 2022
     *
     * @param int|string $id
     *
     * @return Model|ClassActivityCategorySQL
     */
    public function get(int|string $id): Model|ClassActivityCategorySQL
    {
        return parent::get($id);
    }

    public function getViaClassId(int $classId): array|Collection
    {
        try {
            $class                 = (new ClassService())->get($classId);
            $classActivityCategory = $this->model->where('class_id', $classId)->get();

            if (count($classActivityCategory) > 0 || !(($class->lms?->name ?? LmsSystemConstant::SIS) == LmsSystemConstant::SIS)) {
                return $classActivityCategory;
            }
            $school = SchoolServiceProvider::$currentSchool;

            return ActivityCategorySQL::query()->where('school_id', $school->id)->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author Egawa Conan
     * @Date   Aug 19, 2021
     *
     * @param int    $classId
     * @param object $request
     *
     * @return array
     * @throws Throwable
     */
    public function insertBatch(int $classId, object $request): array
    {
        $rules = [
            '*.name'   => 'required',
            '*.weight' => 'required|numeric|min:0'
        ];
        $this->doValidate($request, $rules);
        try {
            $class = (new ClassService())->get($classId);
            foreach ($request->all() as $item) {
                $data[] = [
                    'class_id' => $class->id,
                    "name"     => $item['name'],
                    "weight"   => $item['weight']
                ];
            }
            DB::beginTransaction();
            $this->model->where('class_id', $classId)->forceDelete();
            $this->model->insert($data ?? []);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        return $data ?? [];
    }

    public function getDefaultCategoryViaClassId(int $classId): ClassActivityCategorySQL|Builder|null
    {
        return $this->model->where('is_default', true)->where('class_id', $classId)->first();
    }

    public function deleteByClassId(int $classId)
    {
        return $this->model->where('class_id', $classId)->delete();
    }
}
