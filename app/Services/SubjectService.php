<?php


namespace App\Services;


use Carbon\Carbon;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use YaangVu\Constant\StatusConstant;
use YaangVu\Constant\SubjectConstant;
use YaangVu\Constant\SubjectRuleConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\SubjectSQL;
use YaangVu\SisModel\App\Models\impl\SubjectTypeSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class SubjectService extends BaseService
{
    private array  $status             = [StatusConstant::ACTIVE, StatusConstant::INACTIVE];
    private array  $allowConflictRules = [SubjectRuleConstant::SAME_TEACHER, SubjectRuleConstant::DIFFERENT_TEACHER];
    private array  $type               = [SubjectConstant::NORMAL, SubjectConstant::HONORS, SubjectConstant::ADVANCEDPLACEMENT];
    private object $subjectRuleService;

    /**
     * @inheritDoc
     */
    function createModel(): void
    {
        $this->subjectRuleService = new SubjectRuleService();
        $this->model              = new SubjectSQL();
    }

    public function preDelete(int|string $id)
    {
        $classes = ClassService::getClassesBySubjectId($id);
        if (!$classes->isEmpty()) {
            throw new BadRequestException(__('validation.confirmation_delete_subject'), new Exception());
        }

        $subject       = $this->get($id);
        $subject->name = $subject->name . ' ' . Carbon::now()->timestamp;
        $subject->save();
        parent::preDelete($id);
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 18, 2021
     *
     * @param int|string $id
     *
     * @return Model|SubjectSQL
     */
    public function get(int|string $id): Model|SubjectSQL
    {
        $this->with('subjectType');

        return parent::get($id);
    }

    public function postDelete(int|string $id)
    {
        $this->subjectRuleService->model->where('subject_id', $id)->orWhere('relevance_subject_id', $id)->delete();

        parent::postDelete($id); // TODO: Change the autogenerated stub
    }

    public function preAdd(object $request)
    {
        if ($request instanceof Request) {
            $data              = [];
            $data['uuid']      = Uuid::uuid();
            $data['school_id'] = SchoolServiceProvider::$currentSchool->id;
            if (empty($request->get('credit'))) $data['credit'] = 0;
            if (empty($request->get('weight'))) $data['weight'] = 0;
            if (empty($request->get('code'))) $request->request->remove('code');
            $request->merge($data);
        } else {
            $request->uuid      = Uuid::uuid();
            $request->school_id = SchoolServiceProvider::$currentSchool->id;
            $request->credit    = $request->credit ?? 0;
            $request->weight    = $request->weight ?? 0;
            if (isset($request->code) && ($request->code == "" || $request->code == null)) unset($request->code);
        }

        parent::preAdd($request); // TODO: Change the autogenerated stub
    }

    public function postAdd(object $request, Model|SubjectSQL $model)
    {
        $rules                       = [];
        $subjectType                 = (new SubjectTypeService())->getViaId($model->subject_type_id);
        $model->type                 = $subjectType->name;
        $model->subject_display_name = '[' . $subjectType->name . '] ' . $model->name;

        $model->save();

        if (isset($request->rules) && is_array($request->rules)) {
            $rules = $request->rules;
            foreach ($rules as $key => $rule) {
                $rules[$key]['subject_id'] = $model->id;
            }

            $this->subjectRuleService->add((object)['rules' => $rules]);
        }

        $model->setAttribute('rules', $rules);
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        $schoolId        = SchoolServiceProvider::$currentSchool->id;
        $additionalRules = [];
        if (isset($request->rules) && is_array($request->rules)) {
            $additionalRules = [
                'rules.*.type'                 => [
                    'required',
                    'in:' . implode(',', SubjectRuleConstant::ALL),
                ],
                'rules.*.relevance_subject_id' => 'required|numeric|exists:subjects,id',
            ];
        }
        $rules = [
            'name'            => 'required|max:255',
            'credit'          => 'nullable|numeric|gte:0',
            'weight'          => 'nullable|numeric|gte:0',
            'code'            => 'nullable|iunique:subjects,code',
            'status'          => 'nullable|in:' . implode(',', $this->status),
            'grade_id'        => 'nullable|exists:grades,id',
            'grade_scale_id'  => 'nullable|exists:grade_scales,id',
            'type'            => 'nullable|in:' . implode(',', $this->type),
            'subject_type_id' => "required|exists:subject_types,id,school_id,$schoolId",
        ];

        return parent::storeRequestValidate($request, array_merge($rules, $additionalRules));
    }

    public function checkConflictRules($attribute, $value, $fail, $request)
    {
        preg_match('/\.([^"]+)\./', $attribute, $selfIndex);

        foreach ($request->rules as $key => $rule) {
            if ($key == $selfIndex[1]) continue;

            if ($rule['type'] == $value ||
                (!in_array($value, $this->allowConflictRules) &&
                    !in_array($rule['type'], $this->allowConflictRules)))
                $fail("The $attribute is conflicted");
        }
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $schoolId        = SchoolServiceProvider::$currentSchool->id;
        $additionalRules = [];
        if (isset($request->rules) && is_array($request->rules)) {
            $additionalRules = [
                'rules.*.type'                 => [
                    'nullable',
                    'in:' . implode(',', SubjectRuleConstant::ALL),
                ],
                'rules.*.relevance_subject_id' => 'nullable|numeric|exists:subjects,id',
            ];
        }

        // Rules
        $rules = [
            'name'            => "sometimes|iunique:subjects,name,$id|max:255",
            'credit'          => 'nullable|numeric|gte:0',
            'weight'          => 'nullable|numeric|gte:0',
            'code'            => "nullable|iunique:subjects,code,$id",
            'status'          => 'nullable|in:' . implode(',', $this->status),
            'grade_id'        => 'nullable|exists:grades,id',
            'grade_scale_id'  => 'nullable|exists:grade_scales,id',
            'type'            => 'nullable|in:' . implode(',', $this->type),
            'subject_type_id' => "nullable|exists:subject_types,id,school_id,$schoolId",
        ];

        return parent::updateRequestValidate($id, $request, array_merge($rules,
                                                                        $additionalRules)); // TODO: Change the autogenerated stub
    }

    public function postUpdate(int|string $id, object $request, Model|SubjectSQL $model)
    {
        $this->subjectRuleService->model->where('subject_id', $id)->orWhere('relevance_subject_id', $id)->delete();
        $rules = $request->rules ?? [];
        foreach ($rules as $key => $rule) {
            $rules[$key]['subject_id'] = $id;
        }

        if ($request->subject_type_id) {
            $subjectType                 = (new SubjectTypeService())->getViaId($request->subject_type_id);
            $model->type                 = $subjectType->name;
            $model->subject_display_name = '[' . $subjectType->name . '] ' . $model->name;
        }

        $model->save();

        $this->subjectRuleService->add((object)['rules' => $rules]);

        $model->setAttribute('rules', $rules);
        $this->with('subjectType');

    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 08, 2022
     *
     * @param int $gradeIndex
     *
     * @return Collection
     */
    public function getViaGradeIndex(int $gradeIndex): Collection
    {
        try {
            return SubjectSQL::selectRaw('grades.name as grade_name, grades.index, subjects.id as subject_id, subjects.name as subject_name')
                             ->join('grades', 'grades.id', '=', 'subjects.grade_id')
                             ->where('grades.index', $gradeIndex)
                             ->where('subjects.school_id', SchoolServiceProvider::$currentSchool->id)
                             ->orderBy('grades.index', 'asc')
                             ->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getViaGradeScaleId(int $gradeScaleId): Collection|array
    {
        return SubjectSQL::whereGradeScaleId($gradeScaleId)->get();
    }

    public function getSubjectByClassId($classId): SubjectSQL|\Illuminate\Database\Eloquent\Builder|null
    {
        return SubjectSQL::query()
                         ->with('subjectType')
                         ->join('classes', 'classes.subject_id', '=', 'subjects.id')
                         ->where('classes.id', $classId)
                         ->where('classes.school_id', SchoolServiceProvider::$currentSchool->id)
                         ->select('subjects.*')
                         ->first();
    }

    public function getAll(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $data = $this->queryHelper
            ->buildQuery($this->model)
            ->with('subjectType')
            ->where('school_id', SchoolServiceProvider::$currentSchool->id);
        try {
            $response = $data->paginate(QueryHelper::limit());
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getViaId(int $id): object|null
    {
        return SubjectSQL::query()->where('id', $id)->first();
    }

    public function syncType()
    {
        $subjects     = SubjectSQL::all();
        $subjectTypes = SubjectTypeSQL::query()->pluck('name', 'id');
        foreach ($subjects as $subject) {
            $subject->type = $subjectTypes[$subject->subject_type_id];
            $subject->save();
        }
    }
}
