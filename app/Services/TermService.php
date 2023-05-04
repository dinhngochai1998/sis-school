<?php


namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\TermSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class TermService extends BaseService
{
    use RoleAndPermissionTrait, ElasticsearchHelper;

    const LOG_NAME = 'cloned term class';

    private array $status = ['', StatusConstant::ON_GOING, StatusConstant::CONCLUDED];

    function createModel(): void
    {
        $this->model = new TermSQL();
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->preGetAll();
        $request    = \request()->all();
        $program_id = $request['program_id'] ?? null;
        $status     = $request['status'] ?? null;
        $name       = $request['name__~'] ?? null;
        $teacherId  = $request['teacher_id'] ?? null;
        $this->queryHelper->removeParam('program_id')
                          ->removeParam('status')
                          ->removeParam('name__~')
                          ->removeParam('teacher_id');
        $data = $this->queryHelper
            ->buildQuery($this->model)
            ->distinct()
            ->select('terms.*')
            ->where('terms.school_id', SchoolServiceProvider::$currentSchool->id)
            ->with(['user'])
            ->when($status, function (Builder $q) use ($status) {
                $q->where('terms.status', $status);
            })
            ->when($name, function (Builder $q) use ($name) {
                $q->where('terms.name', 'ILIKE', '%' . $name . '%');
            })
            ->when($program_id || $teacherId, function (Builder $q) {
                $q->join('classes', 'classes.term_id', '=', 'terms.id');
            })
            ->when($program_id, function (Builder $q) use ($program_id) {
                $q->join('subjects', 'subjects.id', '=', 'classes.subject_id');
                $q->join('graduation_category_subject as gcs', 'gcs.subject_id', '=', 'subjects.id');
                $q->join('graduation_categories as gc', 'gc.id', '=', 'gcs.graduation_category_id');
                $q->join('program_graduation_category as pgc', 'pgc.graduation_category_id', '=', 'gc.id');
                $q->join('programs', 'programs.id', '=', 'pgc.program_id');
                $q->where('programs.id', $program_id);
            })
            ->when($teacherId, function (Builder $q) use ($teacherId) {
                $userNoSql = (new UserService())->get($teacherId);
                $classIds  = ClassAssignmentSQL::whereUserId($userNoSql?->userSql->id ?? null)
                                               ->whereIn('assignment', [ClassAssignmentConstant::PRIMARY_TEACHER,
                                                                        ClassAssignmentConstant::SECONDARY_TEACHER])
                                               ->pluck('class_id')
                                               ->toArray();

                $q->whereIn('classes.id', $classIds);
            });
        try {
            $response = $data->paginate(QueryHelper::limit());
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function preGetAll()
    {
        $request  = request()->all();
        $fromYear = isset($request['from_year']) ? (int)$request['from_year'] : null;
        $toYear   = isset($request['to_year']) ? (int)$request['to_year'] : null;
        if ($fromYear)
            $this->queryHelper->removeParam('from_year')
                              ->addParams(['terms__start_date__gt' => Carbon::create($fromYear)->startOfYear()]);
        if ($toYear)
            $this->queryHelper->removeParam('to_year')
                              ->addParams(['terms__end_date__lt' => Carbon::create($toYear)->endOfYear()]);

        $this->with(['classes', 'user']);
        parent::preGetAll();
    }

    public function preAdd(object $request): object
    {
        if (!$this->hasPermission(PermissionConstant::term(PermissionActionConstant::ADD)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $school = SchoolServiceProvider::$currentSchool;
        if ($request instanceof Request)
            $request->merge(['school_id' => $school->id]);
        else
            $request->school_id = $school->id;

        if (isset($request->status) && !$request->status)
            $request->status = StatusConstant::ON_GOING;

        $request->school_id = SchoolServiceProvider::$currentSchool->id;

        return $request;
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {

        $rules = [
            'name'       => 'required|iunique:terms,name|max:255',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after:start_date',
            'status'     => 'in:' . implode(',', $this->status)
        ];

        return parent::storeRequestValidate($request, $rules);
    }

    public function postAdd(object $request, Model|TermSQL $model)
    {
        $model->start_date = $request->start_date ?? null;
        $model->end_date   = $request->end_date ?? null;
        $model->save();

        parent::postAdd($request, $model);
    }

    /**
     * @throws Throwable
     */
    public function preUpdate(int|string $id, object $request)
    {
        if (!$this->hasPermission(PermissionConstant::term(PermissionActionConstant::EDIT)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $status = $request->status ?? null;

        if ($status == StatusConstant::CONCLUDED) {
            $this->concludeTerm($id, $request);
        }

        parent::preUpdate($id, $request);
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $rules = [
            'name'       => "sometimes|required|iunique:terms,name,$id|max:255",
            'start_date' => 'sometimes|required|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after:start_date',
            'status'     => 'in:' . implode(',', $this->status)
        ];

        return parent::updateRequestValidate($id, $request, $rules);
    }

    public function preDelete(int|string $id)
    {
        if (!$this->hasPermission(PermissionConstant::term(PermissionActionConstant::DELETE)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $classes = ClassSQL::whereTermId($id)->get();
        if (count($classes) > 0)
            throw new BadRequestException(__('validation.confirmation_delete_term', ['number' => $classes->count()]),
                                          new Exception());

        $term       = $this->get($id);
        $term->name = $term->name . ' ' . Carbon::now()->timestamp;
        $term->save();
        parent::preDelete($id);
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 13, 2021
     *
     * @param int|string $id
     *
     * @return Model|TermSQL
     */
    public function get(int|string $id): Model|TermSQL
    {
        return parent::get($id);
    }

    /**
     * @param int $id
     *
     * @return Model|TermSQL
     * @throws Throwable
     */
    public function concludeTerm(int $id, $request): Model|TermSQL
    {
        if (!$this->hasPermission(PermissionConstant::term(PermissionActionConstant::CONCLUDE)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $term = $this->get($id);
        if ($term->status !== StatusConstant::ON_GOING)
            throw new BadRequestException(__('validation.invalid'), new Exception());

        $classIds = ClassSQL::whereTermId($term->id)
                            ->whereStatus(StatusConstant::ON_GOING)
                            ->pluck('id')
                            ->toArray();
        (new ClassService())->updateGradeWhenClassIsConcludedByClassIds($classIds);

        try {
            DB::beginTransaction();
            foreach ($classIds as $classId)
                (new ClassService())->concludeClass($classId);
            $term->status           = StatusConstant::CONCLUDED;
            $term->end_date         = Carbon::now()->toDateString();
            $term->term_course_code = $request->term_course_code ?? null;
            $term->save();

            ClassAssignmentSQL::query()
                              ->join('classes', 'classes.id', '=', 'class_assignments.class_id')
                              ->join('lms', 'lms.id', '=', 'classes.lms_id')
                              ->whereIn('class_assignments.class_id', $classIds)
                              ->where('lms.name', LmsSystemConstant::AGILIX)
                              ->update(['class_assignments.status' => StatusConstant::WITHDRAWAL]);
            DB::commit();

            return $term;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param object $request
     *
     * @return array
     * @throws Throwable
     * */
    public function copyTermClasses(object $request): array
    {
        if (!$this->hasPermission(PermissionConstant::term(PermissionActionConstant::COPY)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $schoolId = SchoolServiceProvider::$currentSchool->id;
        $this->doValidate($request, [
            'source_term_id'      => "required|exists:terms,id,school_id,$schoolId",
            'destination_term_id' => "required|exists:terms,id,school_id,$schoolId",
        ]);

        $termId          = $request->source_term_id ?? null;
        $students        = (new UserService())->getStudentsViaTerm($termId);
        $uuids           = $students->pluck('uuid')->toArray();
        $divisionUsers   = (new UserService())->getDivisionUsers($uuids);
        $gradeLevels     = (new GradeService())->getGradeLevels();
        $GradeLevelNames = $gradeLevels->pluck('name', 'index');

        $grades    = [];
        $divisions = [];
        foreach ($divisionUsers as $key => $user) {
            if (!isset($user->grade) || !$user->grade)
                continue;
            $currentGrade                                                  = (new GradeService())->getByName($user->grade)
                                                                                                 ->first();
            $divisionGroup                                                 = $user->division ?? 'Undefined';
            $divisions[$user->grade][$divisionGroup]['users'][$user->uuid] = UserSQL::whereUuid($user->uuid)
                                                                                    ->first()?->id;
            foreach ($GradeLevelNames as $index => $name) {
                if ($index == $currentGrade->index) {
                    $grades[$user->grade][$index] = [
                        'current_level' => $index,
                        'next_level'    => ($index += 1),
                        'divisions'     => $divisions[$user->grade],
                        'subjects'      => (new SubjectService())->getViaGradeIndex($index)
                    ];
                }
            }
        }

        $termId     = $request->destination_term_id ?? null;
        $newClasses = $response = [];
        foreach ($grades as $grade) {
            foreach ($grade as $gradeInfo) {
                foreach ($gradeInfo['divisions'] as $divisionName => $division) {
                    foreach ($gradeInfo['subjects'] as $subject) {
                        $className
                                      = $termId . ' ' . $subject->subject_name . ' ' . $subject->grade_name . ' ' . $divisionName . ' ' . Carbon::now()->timestamp;
                        $newClasses[] = [
                            'name'       => $className ?? null,
                            'subject_id' => $subject->subject_id,
                            'term_id'    => $termId,
                            'school_id'  => SchoolServiceProvider::$currentSchool->id,
                            'status'     => StatusConstant::PENDING,
                            'user_ids'   => $division['users'],
                            'lms_id'     => null,
                            'next_level' => $subject->grade_name
                        ];
                    }
                }
            }
        }

        Log::info("Information about Copying Classes No1: ", $newClasses);
        DB::beginTransaction();
        try {
            foreach ($newClasses as $class) {
                $newClass = (new ClassService())->createClass($class);
                (new ClassAssignmentService())->sync($newClass->id, ClassAssignmentConstant::STUDENT,
                                                     $class['user_ids']);
                foreach ($class['user_ids'] as $uuid => $user_id) {
                    $request->merge(['grade' => $class['next_level']]);
                    (new UserService())->update($uuid, $request);
                }
                $response[] = $newClass;
            }
            DB::commit();
            $copyTermClass = collect($response);
            $this->createActionLogForClassViaTerm($copyTermClass, $request);

            return $response ?? [];
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 07, 2022
     *
     * @param     $copyTermClass
     * @param     $request
     *
     * @throws Exception
     */
    public function createActionLogForClassViaTerm($copyTermClass, $request)
    {
        $log = BaseService::currentUser()->username . ' ' . self::LOG_NAME . ' : ' . Carbon::now()
                                                                                           ->toDateString();

        $this->createELS('cloned_term_class', $log,
                         [
                             'cloned_name'   => $copyTermClass->pluck('name')->toArray(),
                             'old_term_name' => $this->get($request->source_term_id)->name ?? null,
                             'new_term_name' => $this->get($request->destination_term_id)->name ?? null
                         ]
        );
    }

    /**
     * @param object $request
     *
     * @return array
     * @throws Throwable
     * */
    public function copyOptionsTermClasses(object $request): array
    {
        if (!$this->hasPermission(PermissionConstant::term(PermissionActionConstant::COPY)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $schoolId = SchoolServiceProvider::$currentSchool->id;
        $this->doValidate($request, [
            'source_term_id'      => "required|exists:terms,id,school_id,$schoolId",
            'destination_term_id' => "required|exists:terms,id,school_id,$schoolId",
        ]);

        $termId         = $request->source_term_id ?? null;
        $studentsSource = $request->input('options.students') ?? false;
        $teachersSource = $request->input('options.teachers') ?? false;
        $intactInfo     = $request->input('options.keep_info_intact') ?? false;
        $classes        = (new ClassService())->getClassesByTerm($termId);

        $termId = $request->destination_term_id ?? null;
        // $gradeLevels     = (new GradeService())->getGradeLevels();
        // $GradeLevelNames = $gradeLevels->pluck('name', 'index');

        $newClasses = $response = [];
        foreach ($classes as $class) {
            $students = [];
            $teachers = [];
            if ($studentsSource)
                foreach ($class['students'] as $student) {
                    $students[$student->assignment][] = $student->user_id;
                }
            // foreach ($class['students'] as $student) {
            //     if (isset($student->users->uuid) && $student->users->uuid)
            //         $currentGrade = UserNoSQL::whereUuid($student->users->uuid)->firstOrFail()?->grade;
            //     foreach ($GradeLevelNames as $index => $name) {
            //         if ($name == $currentGrade) {
            //             $students[$student->assignment]['id'][] = $student->user_id;
            //             $students[$student->assignment]['next_level'][$student->users->uuid]
            //                                                     = (new GradeService())->getByIndex($index += 1)
            //                                                                           ->first()?->name;
            //         }
            //     }
            //
            // }
            if ($teachersSource)
                foreach ($class['teachers'] as $teacher) {
                    $teachers[$teacher->assignment][] = $teacher;
                }
            $newClasses[] = [
                'name'       => $class->name . ' ' . Carbon::now()->timestamp . ' ' . '(COPY)',
                'subject_id' => $intactInfo ? $class->subject_id : null,
                'course_id'  => $intactInfo ? $class->course_id : null,
                'term_id'    => $termId,
                'school_id'  => SchoolServiceProvider::$currentSchool->id,
                'status'     => StatusConstant::PENDING,
                'user_ids'   => Arr::collapse([$students, $teachers]),
                'lms_id'     => $intactInfo ? $class->lms_id : null,
                'zone'       => $intactInfo ? $class->zone : null
            ];
        }

        Log::info("Information about Copying Classes No2: ", $newClasses);

        DB::beginTransaction();
        try {
            foreach ($newClasses as $class) {
                $newClass = (new ClassService())->createClass($class);
                if (isset($class['user_ids']) && $class['user_ids']) {
                    foreach ($class['user_ids'] as $key => $users) {
                        if ($key == ClassAssignmentConstant::STUDENT) {
                            (new ClassAssignmentService())->sync($newClass->id, $key, $users);
                        } else {
                            (new ClassAssignmentService())->assigmentTeachers($newClass->id, $key, $users);
                        }
                    }
                }
                // if (isset($class['user_ids']) && $class['user_ids']) {
                //     foreach ($class['user_ids'] as $key => $users) {
                //         if ($key == ClassAssignmentConstant::STUDENT) {
                //             (new ClassAssignmentService())->sync($newClass->id, $key, $users['id']);
                //         } else {
                //             (new ClassAssignmentService())->assigmentTeachers($newClass->id, $key, $users);
                //         }
                //
                //         if (isset($users['next_level']))
                //             foreach ($users['next_level'] as $uuid => $grade) {
                //                 $request->merge(['grade' => $grade]);
                //                 (new UserService())->update($uuid, $request);
                //             }
                //     }
                // }
                $response[] = $newClass;

            }

            DB::commit();
            $copyTermClass = collect($response);
            $this->createActionLogForClassViaTerm($copyTermClass, $request);

            return $response ?? [];
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function queryGetViaStudentSqlId(int|string $studentSqlId): Model|Builder
    {
        return $this->model->select('terms.*')
                           ->distinct()
                           ->leftJoin('classes', 'classes.term_id', '=', 'terms.id')
                           ->join('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                           ->where('class_assignments.user_id', $studentSqlId)
                           ->whereNull('classes.deleted_at')
                           ->where('terms.school_id', SchoolServiceProvider::$currentSchool->id);
    }

    public function getTermsByStudentId(string $id): \Illuminate\Database\Eloquent\Collection|array
    {
        $user    = (new UserService())->get($id);
        $scIdSql = SchoolServiceProvider::$currentSchool->id;

        return $this->model::query()
                           ->join('classes', 'classes.term_id', '=', 'terms.id')
                           ->join('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                           ->where('class_assignments.user_id', $user->userSql->id)
                           ->where('class_assignments.assignment', RoleConstant::STUDENT)
                           ->where('classes.school_id', $scIdSql)
                           ->where('terms.school_id', $scIdSql)
                           ->orderByDesc('class_assignments.created_at')
                           ->select('terms.*')
                           ->get()->unique();
    }

}
