<?php


namespace App\Services;


use Carbon\Carbon;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\GradeLetterSQL;
use YaangVu\SisModel\App\Models\impl\GradeScaleSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class GradeScaleService extends BaseService
{
    use RoleAndPermissionTrait;

    public function createModel(): void
    {
        $this->model = new GradeScaleSQL();
    }

    public function preGetAll()
    {
        $this->queryHelper->relations = ['gradeLetters', 'subjects'];
        parent::preGetAll();
    }

    public function get(int|string $id): Model|GradeScaleSQL
    {
        $this->queryHelper->relations = ['gradeLetters', 'subjects'];

        return parent::get($id);
    }

    public function preAdd(object $request): object
    {
        if (!$this->hasPermission(PermissionConstant::gradeScale(PermissionActionConstant::ADD)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $school = SchoolServiceProvider::$currentSchool;
        if ($request instanceof Request)
            $request->merge(['school_id' => $school->id]);
        else
            $request->school_id = $school->id;

        return $request;
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        $rules = [
            'name'                   => 'required|max:255|iunique:grade_scales,name',
            'grade_letters'          => 'nullable|array',
            'is_calculate_gpa'       => 'boolean',
            'score_to_pass'          => 'numeric|min:0',
            'extra_point_honor'      => 'numeric|min:0',
            'extra_point_advanced'   => 'numeric|min:0',
            'grade_letters.*.score'  => 'sometimes|required|numeric|min:0',
            'grade_letters.*.letter' => 'sometimes|required|max:2',
        ];

        if ($request->is_calculate_gpa ?? null)
            $arrayMerge = array_merge($rules, ['grade_letters.*.gpa' => 'required|numeric|min:0']);

        if (is_array($request->grade_letters ?? null)) {
            $this->_checkDuplicateValueInArray($request->grade_letters, 'letter');
            $this->_checkDuplicateValueInArray($request->grade_letters, 'score');
        }

        return parent::storeRequestValidate($request, $arrayMerge ?? $rules);
    }

    public function postAdd(object $request, Model|GradeScaleSQL $model)
    {
        if (is_array($request->grade_letters ?? null)) {
            foreach ($request->grade_letters as $gradeLetter) {
                if (!$request->is_calculate_gpa)
                    unset($gradeLetter['gpa']);
                $gradeLetter['grade_scale_id'] = $model->id;
                (new GradeLetterService())->add((object)$gradeLetter);
            }
        }

        parent::postAdd($request, $model);
    }

    public function preUpdate(int|string $id, object $request)
    {
        if (!$this->hasPermission(PermissionConstant::gradeScale(PermissionActionConstant::EDIT)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        parent::preUpdate($id, $request);
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $rules = [
            'name'                   => "sometimes|required|max:255|iunique:grade_scales,name,$id",
            'grade_letters'          => 'nullable|array',
            'is_calculate_gpa'       => 'nullable|boolean',
            'score_to_pass'          => 'sometimes|required|numeric|min:0',
            'extra_point_honor'      => 'sometimes|required|numeric|min:0',
            'extra_point_advanced'   => 'sometimes|required|numeric|min:0',
            'grade_letters.*.score'  => 'sometimes|required|numeric|min:0',
            'grade_letters.*.letter' => 'sometimes|required|max:2',
        ];

        if ($request->is_calculate_gpa ?? null)
            $arrayMerge = array_merge($rules, ['grade_letters.*.gpa' => 'sometimes|required|numeric|min:0']);

        if (is_array($request->grade_letters ?? null)) {
            $this->_checkDuplicateValueInArray($request->grade_letters, 'letter');
            $this->_checkDuplicateValueInArray($request->grade_letters, 'score');
        }

        return parent::updateRequestValidate($id, $request, $arrayMerge ?? $rules);
    }

    public function postUpdate(int|string $id, object $request, Model|GradeScaleSQL $model)
    {
        if (is_array($request->grade_letters ?? null)) {
            GradeLetterSQL::whereGradeScaleId($model->id)->delete();
            foreach ($request->grade_letters as $gradeLetter) {
                $gradeLetter['grade_scale_id'] = $model->id;
                $gradeLetter['gpa']            = empty($gradeLetter['gpa']) ? 0 : $gradeLetter['gpa'];
                (new GradeLetterService())->add((object)$gradeLetter);
            }
        }
        parent::postUpdate($id, $request, $model);
    }

    public function update(int|string $id, object $request): Model
    {
        $request = $this->preUpdate($id, $request) ?? $request;

        // Set data for updated entity
        $fillAbles = $this->model->getFillable();
        $guarded   = $this->model->getGuarded();

        // Validate
        if ($this->updateRequestValidate($id, $request) !== true)
            return $this->model;

        $model = $this->get($id);

        foreach ($fillAbles as $fillAble)
            if (isset($request->$fillAble) && !in_array($fillAble, $guarded))
                $model->$fillAble = $this->_handleRequestData($request->$fillAble) ?? $model->$fillAble;

        $model->uuid = Uuid::uuid();
        try {
            $model->save();
            $this->postUpdate($id, $request, $model);

            return $model;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param array  $array
     * @param string $value
     */
    private function _checkDuplicateValueInArray(array $array, string $value)
    {
        $known = [];
        array_filter($array, function ($val) use (&$known, $value) {
            if (in_array($val[$value], $known))
                throw new BadRequestException(__('validation.you_have_the_same_value'), new Exception());
            $known[] = $val[$value];
        });
    }

    public function preDelete(int|string $id)
    {
        if (!$this->hasPermission(PermissionConstant::gradeScale(PermissionActionConstant::DELETE)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $subjects      = (new SubjectService())->getViaGradeScaleId($id);
        $countSubjects = $subjects->count();

        if ($countSubjects > 0)
            throw new BadRequestException(__('validation.confirmation_delete_grade_scale',
                                             ['number' => $countSubjects]),
                                          new Exception());

        $gradeScale       = $this->get($id);
        $gradeScale->name = $gradeScale->name . ' ' . Carbon::now()->timestamp;
        $gradeScale->save();
        parent::preDelete($id);
    }

    public function postDelete(int|string $id)
    {
        GradeLetterSQL::whereGradeScaleId($id)->forceDelete();
        parent::postDelete($id);
    }
}
