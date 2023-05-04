<?php
/**
 * @Author Edogawa Conan
 * @Date   May 30, 2022
 */

namespace App\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ActivityClassLmsSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ActivityClassLmsService extends BaseService
{
    public ClassActivityLmsService $classActivityLmsService;

    public function __construct()
    {
        $this->classActivityLmsService = new ClassActivityLmsService();
        parent::__construct();
    }

    public function createModel(): void
    {
        $this->model = new ActivityClassLmsSQL();
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->queryHelper
            ->addParams([
                            'school_id' => SchoolServiceProvider::$currentSchool->id
                        ]);

        return parent::getAll();
    }

    public function preAdd(object $request)
    {
        $rules = [
            'name'                       => 'required',
            'max_point'                  => 'required|numeric|min:0',
            'class_activity_category_id' => 'required|exists:class_activity_categories,id',
            'class_id'                   => 'required|exists:classes,id,deleted_at,NULL'
        ];

        $this->doValidate($request, $rules);
        $class                 = (new ClassService())->get($request->class_id);

        if (!in_array($class->lms->name,[LmsSystemConstant::AGILIX,LmsSystemConstant::EDMENTUM]))
            throw new BadRequestException(
                ['message' => __("class.not_lms_class")], new Exception()
            );
        $currentSchool         = SchoolServiceProvider::$currentSchool;
        $classActivityCategory = (new ClassActivityCategoryService())->get($request->class_activity_category_id);
        if ($classActivityCategory->is_default)
            throw new BadRequestException(
                ['message' => __("activityCategory.category_default")], new Exception()
            );

        if ($request instanceof Request)
            $request->merge([
                                'class_id'  => $class->id,
                                'school_id' => $currentSchool->id
                            ]);
        else {
            $request->class_id  = $class->id;
            $request->school_id = $currentSchool->id;
        }

        parent::preAdd($request);
    }

    public function postAdd(object $request, Model $model)
    {
        $this->classActivityLmsService->calculateActivityScoreViaClassId($request->class_id);
        parent::postAdd($request, $model);
    }

    public function preUpdate(int|string $id, object $request)
    {
        $rules = [
            'name'                       => 'sometimes|required',
            'max_point'                  => 'sometimes|required|numeric|min:0',
            'class_activity_category_id' => 'sometimes|required|exists:class_activity_categories,id',
            // 'class_id'                   => 'sometimes|required|exists:classes,id,deleted_at,NULL'
        ];

        $this->doValidate($request, $rules);
        $classActivityCategory = (new ClassActivityCategoryService())->get($request->class_activity_category_id);
        if ($classActivityCategory->is_default)
            throw new BadRequestException(
                ['message' => __("activityCategory.category_default")], new Exception()
            );

        unset($request->class_id);
        parent::preUpdate($id, $request);
    }

    public function postUpdate(int|string $id, object $request, Model $model)
    {
        $this->classActivityLmsService->calculateActivityScoreViaClassId($request->class_id);
        parent::postUpdate($id, $request, $model);
    }

    public function deleteByClassId(int $classId)
    {
        return $this->model->where('class_id', $classId)->delete();
    }
}
