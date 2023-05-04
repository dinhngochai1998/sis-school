<?php


namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use App\Services\impl\MailWithRabbitMQ;
use App\Traits\AgilixTraits;
use App\Traits\EdmentumTraits;
use App\Traits\SubjectRuleTraits;
use Carbon\Carbon;
use Doctrine\DBAL\Query\QueryException;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as MBuilder;
use Illuminate\Database\Query\Builder as QBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JetBrains\PhpStorm\ArrayShape;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use stdClass;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\NotFoundException;
use YaangVu\Exceptions\SystemException;
use YaangVu\Exceptions\UnauthorizedException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\ClassAssignment;
use YaangVu\SisModel\App\Models\impl\ActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\CourseSQL;
use YaangVu\SisModel\App\Models\impl\LmsSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\TermSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Models\views\ClassAssignmentView;
use YaangVu\SisModel\App\Models\views\StudentProgramClassView;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ClassService extends BaseService
{
    use RoleAndPermissionTrait, RabbitMQHelper;

    public UserService $userService;

    public Model|Builder|ClassSQL $model;

    use SubjectRuleTraits, ElasticsearchHelper;

    public ClassAssignmentService $classAssignmentService;
    private array                 $status = ['', StatusConstant::ON_GOING, StatusConstant::CONCLUDED, StatusConstant::PENDING];

    use RabbitMQHelper, EdmentumTraits, AgilixTraits;

    public function __construct()
    {
        $this->userService            = new UserService();
        $this->classAssignmentService = new ClassAssignmentService();
        parent::__construct();
    }

    public static function getViaIdAndSubjectId(int $classId, int $subjectId): Model|ClassSql|null
    {
        return ClassSql::whereSubjectId($subjectId)
                       ->whereId($classId)
                       ->first();
    }

    public static function getClassesBySubjectId(int $subjectId): Collection|array
    {
        return ClassSql::whereSubjectId($subjectId)->get();
    }

    /**
     * @throws Exception
     */
    public static function sendMailWhenFalseValidateImport(string $title, array $messages, string $email): string
    {
        $mail  = new MailWithRabbitMQ();
        $error = '';
        foreach ($messages as $message)
            $error = $error . implode('|', $message) . '<br>';
        $mail->sendMails($title, $error, [$email]);

        return $error;
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->preGetAll();
        $this->queryHelper->with(
            [
                'lms',
                'terms',
                'students',
                'course',
                'subject',
                'teachers' => function ($q) {
                    $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                },
                'teachers.users.userNoSql',
                'user'
            ]);
        $this->queryHelper->removeParam('teacher_id')
                          ->removeParam('student_id')
                          ->removeParam('status_in')
                          ->removeParam('status')
                          ->removeParam('created_date');
        $request   = request()->all();
        $startDate = $request['created_date'][0] ?? null;
        $endDate   = $request['created_date'][1] ?? null;
        $data      = $this->queryHelper
            ->buildQuery($this->model)
            ->select('classes.*')
            ->where('school_id', SchoolServiceProvider::$currentSchool->id)
            ->when($request['teacher_id'] ?? null, function ($q) use ($request) {
                $q->join('class_assignments', 'class_assignments.class_id', '=', 'classes.id');
                $q->where('class_assignments.assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                $q->where('class_assignments.user_id', '=',
                          (new UserService())->getUserSqlViaId($request['teacher_id'])?->id);
            })
            ->when($request['student_id'] ?? null, function ($q) use ($request) {
                $q->join('class_assignments', 'class_assignments.class_id', '=', 'classes.id');
                $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                $q->where('class_assignments.user_id', '=',
                          (new UserService())->getUserSqlViaId($request['student_id'])?->id);
            })
            ->when($request['status_in'] ?? null, function ($q) use ($request) {
                $q->whereIn('classes.status', $request['status_in']);
            })
            ->when($request['status'] ?? null, function ($q) use ($request) {
                $q->where('classes.status', $request['status']);
            })
            ->when($startDate, function ($q) use ($startDate) {
                $q->whereDate('classes.created_at', '>=', $startDate);
            })
            ->when($endDate, function ($q) use ($endDate) {
                $q->whereDate('classes.created_at', '<=', $endDate);
            });

        try {
            $response = $data->paginate(QueryHelper::limit());

            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function preGet(int|string $id)
    {
        $this->queryHelper->with(
            [
                'terms',
                'graduationCategories',
                'teachers.users.userNoSql',
                'course.lms',
                'subject',
                'lms',
                'user',
                'classActivityCategories'
            ]
        );

    }

    public function preAdd(object $request): object
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::ADD)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        if ($request instanceof Request)
            $request = (object)$request->toArray();

        if (isset($request->status) && !$request->status)
            $request->status = StatusConstant::ON_GOING;

        $request->uuid      = Uuid::uuid();
        $request->school_id = SchoolServiceProvider::$currentSchool->id;

        return $request;
    }

    /**
     * @param object $request
     * @param array  $rules
     * @param array  $messages
     *
     * @return bool|array
     */
    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        $schoolId = SchoolServiceProvider::$currentSchool->id;
        $rules    = [
            'name'           => 'required|iunique:classes,name|max:255',
            'grade_scale_id' => 'nullable|exists:grade_scales,id,deleted_at,NULL',
            'start_date'     => 'required|date_format:Y-m-d',
            'end_date'       => 'required|date_format:Y-m-d|after:start_date',
            'term_id'        => "required|exists:terms,id,school_id,$schoolId,deleted_at,NULL",
            'status'         => 'in:' . implode(',', $this->status),
            'lms_id'         => 'required|exists:lms,id',
            'credit'         => 'nullable|numeric',
            'subject_id'     => 'required|exists:subjects,id,deleted_at,NULL'
        ];

        $lms = LmsSQL::whereId($request->lms_id ?? null)->first();
        if (!$lms)
            return parent::storeRequestValidate($request, $rules);

        $newRule = $this->_validateSystem($lms, $schoolId, $rules);

        return parent::storeRequestValidate($request, $newRule);
    }

    /**
     * @param LmsSQL $lms
     * @param int    $schoolId
     * @param array  $rules
     *
     * @return array
     */
    private function _validateSystem(LmsSQL $lms, int $schoolId, array $rules): array
    {
        if ($lms?->name === LmsSystemConstant::SIS)
            return $rules;

        $zones   = (new LMSService())->getZonesViaId($lms?->id);
        $courses = CourseSQL::whereLmsId($lms?->id)
                            ->whereSchoolId($schoolId)
                            ->pluck('id')
                            ->toArray();
        $lmsRule = [
            'course_id' => 'required|in:' . implode(',', $courses),
            'zone'      => 'required|in:' . implode(',', array_column($zones, 'id')),
        ];

        return array_merge($rules, $lmsRule);
    }

    /**
     * @param object         $request
     * @param Model|ClassSql $model
     *
     * @throws Exception
     */
    public function postAdd(object $request, Model|ClassSql $model)
    {
        $lmsName = (new LMSService())->get($model->lms_id)->name;
        if ($lmsName === LmsSystemConstant::EDMENTUM) {
            // send rabbitMq create class in edmentum
            $this->upsertClassToEdmentum($model->id);
        } elseif ($lmsName === LmsSystemConstant::AGILIX) {
            // send rabbitMq create class in agilix
            $this->upsertClassToAgilix($model->id);
        } else {
            // insert class activity category
            $activityCategories = ActivityCategorySQL::query()
                                                     ->where('school_id', SchoolServiceProvider::$currentSchool->id)
                                                     ->get();
            foreach ($activityCategories as $activityCategory) {
                $data[] = [
                    'class_id' => $model->id,
                    "name"     => $activityCategory['name'],
                    "weight"   => $activityCategory['weight']
                ];
            }
            ClassActivityCategorySQL::query()->insert($data ?? []);
        }

        $subject = (new SubjectService())->getSubjectByClassId($model->id);
        $term    = TermSQL::query()
                          ->join('classes', 'classes.term_id', '=', 'terms.id')
                          ->where('classes.school_id', SchoolServiceProvider::$currentSchool->id)
                          ->where('classes.id', $model->id)
                          ->select('terms.*')
                          ->first();

        $log = BaseService::currentUser()->username . ' new class  : ' . Carbon::now()->toDateString();
        $this->createELS('create_new_class',
                         $log,
                         [
                             'class_name'   => $model->name,
                             'term_name'    => $term->name,
                             'subject_name' => $subject->name,
                         ]);
        parent::postAdd($request, $model);
    }

    /**
     * @param int|string $id
     * @param object     $request
     *
     * @return Model
     * @throws Throwable
     */
    public function update(int|string $id, object $request): Model
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        DB::beginTransaction();
        $request = $this->preUpdate($id, $request) ?? $request;

        // Set data for updated entity
        $fillAbles = $this->model->getFillable();
        $guarded   = $this->model->getGuarded();

        // Validate
        if ($this->updateRequestValidate($id, $request) !== true)
            return $this->model;

        $model = $this->get($id);
        unset($model->students, $model->teachers);

        if (!empty($model->lms_id) || !$request->lms_id)
            if ($request instanceof Request) {
                $request->request->remove('lms_id');
                $request->request->remove('zone');
                $request->request->remove('course_id');
            } else
                unset($request->lms_id, $request->zone, $request->course_id);


        foreach ($fillAbles as $fillAble)
            if (isset($request->$fillAble) && !in_array($fillAble, $guarded))
                $model->$fillAble
                    = gettype($request->$fillAble) === 'string' ? trim($request->$fillAble) : $request->$fillAble;
        try {
            $model->start_date = $request->start_date ?? null;
            $model->end_date   = $request->end_date ?? null;
            $model->save();

            $this->postUpdate($id, $request, $model);

            DB::commit();

            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param int|string $id
     * @param object     $request
     * @param array      $rules
     * @param array      $messages
     *
     * @return bool|array
     */
    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $schoolId = SchoolServiceProvider::$currentSchool->id;
        $rules    = [
            'name'           => "sometimes|required|iunique:classes,name,$id|max:255",
            'term_id'        => "sometimes|required|exists:terms,id,school_id,$schoolId,deleted_at,NULL",
            'start_date'     => 'nullable|date_format:Y-m-d',
            'end_date'       => 'nullable|date_format:Y-m-d|after:start_date',
            'grade_scale_id' => 'nullable|exists:grade_scales,id,deleted_at,NULL',
            'status'         => 'in:' . implode(',', $this->status),
            'credit'         => 'nullable|numeric'
        ];

        $class = $this->get($id);
        if (!empty($class->lms_id) || !$request->lms_id)
            return parent::updateRequestValidate($id, $request, $rules);

        $lms = LmsSQL::whereId($request->lms_id)->first();


        $newRule = $this->_validateSystem($lms, $schoolId, $rules);

        return parent::updateRequestValidate($id, $request, $newRule);
    }

    /**
     * @Author yaangvu
     * @Date   Aug 06, 2021
     *
     * @param int|string $id
     *
     * @return Model|ClassSQL
     */
    public function get(int|string $id): Model|ClassSql
    {
        $school = SchoolServiceProvider::$currentSchool;
        $this->preGet($id);
        try {
            if ($this->queryHelper->relations)
                $this->model = $this->model->with($this->queryHelper->relations);

            $entity = $this->model
                ->when($school, function ($q) use ($school, $id) {
                    $q->where('school_id', $school->id);
                })
                ->findOrFail($id);
            $this->postGet($id, $entity);

            return $entity;
        } catch (ModelNotFoundException $e) {
            throw new NotFoundException(
                ['message' => __("not-exist", ['attribute' => __('entity')]) . ": $id"],
                $e
            );
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author Edogawa Conan
     * @Date   Oct 15, 2021
     *
     * @param int|string     $id
     * @param object         $request
     * @param Model|ClassSQL $model
     *
     * @throws Exception
     */
    public function postUpdate(int|string $id, object $request, Model|ClassSQL $model)
    {
        $lmsName = (new LMSService())->get($model->lms_id)?->name;
        // send rabbitMq create class in edmentum
        if ($lmsName == LmsSystemConstant::EDMENTUM)
            $this->upsertClassToEdmentum($model->id);

        // send rabbitMq create class in agilix
        if ($lmsName == LmsSystemConstant::AGILIX)
            $this->upsertClassToAgilix($model->id);

        if ($request->term_id)
            CalendarNoSQL::whereClassId($model->id)->update(['term_id' => $model->term_id]);
    }

    /**
     * @param int    $id
     * @param object $request
     *
     * @return array
     * @throws Throwable
     */
    public function assignStudents(int $id, object $request): array
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        $classActivityLmsService = new ClassActivityLmsService();
        $classActivitySisService = new ClassActivitySisService();

        $users            = [];
        $class            = $this->get($id);
        $studentUsernames = UserService::getUserByRole(RoleConstant::STUDENT)
                                       ->pluck('username')->toArray();
        $this->doValidate($request, [
            'usernames' => 'required|array|in:' . implode(',', $studentUsernames),
        ]);
        $userIds = UserService::getUserSqlViaUsernames($request->usernames)->pluck('id')->toArray();
        foreach ($userIds as $userId) {
            $users[] = ['user_id' => $userId];
        }
        $this->_checkDuplicateUserIdInArray($users);

        //check student already assign in class or not ?
        if (ClassAssignmentSQL::whereClassId($class->id)
                              ->whereAssignment(ClassAssignmentConstant::STUDENT)
                              ->whereIn('user_id', $userIds)
                              ->first())
            throw new BadRequestException(__('validation.invalid'), new Exception());
        $lmsName = LmsSQL::whereId($class->lms_id)->first()?->name;
        $countActivity = ClassActivityNoSql::query()->whereClassId($class->id)
                                           ->count();

        DB::beginTransaction();
        try {
            (new ZoomMeetingService())->assignStudentsToVcrCViaClassIdAndUserIds($id, $userIds);
            foreach ($users as $user) {
                $userId                      = $user['user_id'] ?? null;
                $classAssignmentService      = new ClassAssignmentService();
                $classAssignment             = new stdClass();
                $classAssignment->user_id    = $userId;
                $classAssignment->assignment = ClassAssignmentConstant::STUDENT;
                $classAssignment->class_id   = $class->id;
                $newClassAssignment          = $classAssignmentService->add($classAssignment);
                $response[]                  = $newClassAssignment;
                if ($lmsName && $lmsName !== LmsSystemConstant::SIS) {
                    switch ($lmsName) {
                        case LmsSystemConstant::EDMENTUM :
                            $this->assignUsersToClassToEdmentum($newClassAssignment->id ?? null, RoleConstant::STUDENT);

                            break;
                        default :
                            $this->assignUsersToClassToAgilix($newClassAssignment->id ?? null, RoleConstant::STUDENT);
                            break;
                    }
                    if (!$classActivityLmsService->getViaUserIdAndClassId($userId, $class->id))
                        $classActivityLmsService->addFakeDataViaUserIdAndCLassId($userId, $class->id);
                }
                if($lmsName && $lmsName == LmsSystemConstant::SIS){
                    if(!(new ClassActivitySisService())->getViaUserIdAndClassSisId($userId,$class->id) && $countActivity != 0)
                        $classActivitySisService->addFakeDataViaUserIdAndCLassSisId($userId,$class->id);
                }
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        return $response ?? [];
    }

    /**
     * @param array $array
     */
    private function _checkDuplicateUserIdInArray(array $array)
    {
        $known = [];
        array_filter($array, function ($val) use (&$known) {
            if (in_array($val['user_id'], $known))
                throw new BadRequestException(__('validation.you_have_the_same_user'), new Exception());
            $known[] = $val['user_id'];
        });
    }

    /**
     * @throws Throwable
     */
    public function assignTeachers(int $id, object $request): array
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $class      = $this->get($id);
        $usernames  = UserService::getUserByRole(RoleConstant::TEACHER)->pluck('username')->toArray();
        $assignment = [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER];
        $this->doValidate($request, [
            'teachers'              => 'required|array',
            'teachers.*.username'   => 'required|in:' . implode(',', $usernames),
            'teachers.*.assignment' => 'in:' . implode(',', $assignment)
        ]);
        $primaryTeachers = [];
        $users           = UserService::getUserSqlViaUsernames(array_column($request->teachers, 'username'))
                                      ->pluck('id', 'username')->toArray();

        $userIds         = ClassAssignmentSQL::whereClassId($class->id)
                                             ->whereIn('assignment', $assignment)
                                             ->pluck('user_id', 'position')
                                             ->toArray();
        $teacherIds = UserService::getUserSqlViaUsernames(array_column($request->teachers, 'username'))->pluck('id')->toArray();
        (new ZoomMeetingService())->assignTeachersToVcrCViaClassIdAndUserIds($id, $teacherIds);
        $newUsers        = [];
        $usersWasDropped = [];
        foreach ($request->teachers as $teacher) {
            // check has primary teachers
            if ($teacher['assignment'] === ClassAssignmentConstant::PRIMARY_TEACHER)
                $primaryTeachers[] = $teacher;

            $position           = $teacher['position'] ?? null;
            $classAssignments[] = [
                'user_id'    => $users[$teacher['username']],
                'assignment' => $teacher['assignment'],
                'position'   => $position
            ];

            $newUsers[$position] = $users[$teacher['username']];
        }

        // check user has been removed
        foreach ($userIds as $position => $userId) {
            if (!(in_array($userId, $newUsers) && ($newUsers[$position] ?? null) == $userId))
                $usersWasDropped[$position] = $userId;
        }
        if (sizeof($primaryTeachers) < 1)
            throw new BadRequestException(__('validation.must_have_at_least_1_primary_teacher'), new Exception());

        $lmsName = LmsSQL::whereId($class->lms_id)->first()?->name;
        // insert or update class assignment
        foreach ($classAssignments ?? [] as $classAssignment) {
            $ownClassAssignment = ClassAssignmentSQL::whereClassId($class->id)
                                                    ->whereUserId($classAssignment['user_id'])
                                                    ->first();
            if (!$ownClassAssignment) {
                $ownClassAssignment             = new ClassAssignmentSQL();
                $ownClassAssignment->class_id   = $class->id;
                $ownClassAssignment->user_id    = $classAssignment['user_id'] ?? null;
                $ownClassAssignment->assignment = $classAssignment['assignment'];
                $ownClassAssignment->position   = $classAssignment['position'];
                $ownClassAssignment->save();
                if ($lmsName && $lmsName !== LmsSystemConstant::SIS)
                    switch ($lmsName) {
                        case LmsSystemConstant::EDMENTUM :
                            $this->assignUsersToClassToEdmentum($ownClassAssignment->id, RoleConstant::TEACHER);
                            break;
                        default :
                            $this->assignUsersToClassToAgilix($ownClassAssignment->id, RoleConstant::TEACHER);
                            break;
                    }
            } else {
                $ownClassAssignment->assignment = $classAssignment['assignment'];
                $ownClassAssignment->save();
            }

            $response[] = $ownClassAssignment;
        }
        if (!empty($usersWasDropped)) {
            foreach ($usersWasDropped as $position => $userWasDropped) {
                $classAssignment = ClassAssignmentSQL::whereClassId($id)
                                                     ->whereUserId($userWasDropped)
                                                     ->when(is_int($position), function ($q) use ($position) {
                                                         $q->where('position', $position);
                                                     })
                                                     ->first();

                if ($lmsName && $lmsName !== LmsSystemConstant::SIS)
                    switch ($lmsName) {
                        case LmsSystemConstant::EDMENTUM :
                            $this->unAssignUsersToEdmentum($classAssignment?->id);
                            break;
                        default :
                            $this->unAssignUsersToAgilix($classAssignment?->id);
                            break;
                    }
                $classAssignment->delete();
            }
        }

        return $response ?? [];
    }

    /**
     * @param int|string $id
     *
     * @throws Exception
     */
    public function preDelete(int|string $id)
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::DELETE)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $class   = $this->get($id);
        $student = $this->userService->getUsersByClassIdAndAssignment($class->id, [ClassAssignmentConstant::STUDENT]);
        if (count($student) > 0)
            throw new BadRequestException(__('validation.confirmation_delete_class', ['number' => count($student)]),
                                          new Exception());

        $class->name   = $class->name . ' ' . Carbon::now()->timestamp;
        $class->status = StatusConstant::PENDING;
        $class->save();
        $lmsName = LmsSQL::whereId($class->lms_id)->first()?->name;
        // update class to deActive
        if ($lmsName == LmsSystemConstant::EDMENTUM)
            $this->upsertClassToEdmentum($class->id);
        if ($lmsName == LmsSystemConstant::AGILIX)
            $this->upsertClassToAgilix($class->id);

        parent::preDelete($id);
    }

    /**
     * @param int    $id
     * @param object $request
     *
     * @return bool
     * @throws Exception
     */
    public function unAssignStudents(int $id, object $request): bool
    {
        $this->doValidate($request, ['usernames' => 'required|array']);
        $class              = $this->get($id);
        $classAssignment    = ClassAssignmentSQL::whereClassId($class->id)
                                                ->join('users', 'users.id', 'class_assignments.user_id')
                                                ->whereIn('users.username', $request->usernames)
                                                ->whereAssignment(ClassAssignmentConstant::STUDENT);
        $classAssignmentIds = $classAssignment->pluck('class_assignments.id')->toArray();
        $lmsName            = LmsSQL::whereId($class->lms_id)->first()?->name;
        $userIds = UserService::getUserSqlViaUsernames($request->usernames)->pluck('id')->toArray();
        (new ZoomMeetingService())->unAssignStudentsToVcrCViaClassIdAndUserIds($id, $userIds);
        foreach ($classAssignmentIds as $assignmentId) {
            if ($lmsName && $lmsName !== LmsSystemConstant::SIS)
                switch ($lmsName) {
                    case LmsSystemConstant::EDMENTUM :
                        $this->unAssignUsersToEdmentum($assignmentId);
                        break;
                    default :
                        $this->unAssignUsersToAgilix($assignmentId);
                        break;
                }
        }
        $classAssignment->delete();

        return true;
    }

    /**
     * @param int $termId
     *
     * @return Collection|array
     */
    public function getViaTermId(int $termId): Collection|array
    {
        return $this->queryHelper->buildQuery($this->model)
                                 ->where('term_id', $termId)
                                 ->get();
    }

    /**
     * @param int    $id
     * @param object $request
     *
     * @return ClassSql|Model
     * @throws Throwable
     */
    public function copyClass(int $id, object $request): ClassSql|Model
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::COPY)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $this->doValidate($request, ['name' => 'required']);
        DB::beginTransaction();
        $class = $this->get($id)->getAttributes();
        unset($class['students'], $class['teachers']);
        $class['name'] = trim($request->name) . ' ' . Carbon::now()->timestamp;
        //        $class['new_lms_class'] = false;
        $this->createModel();
        try {
            $this->model->fill($class);
            $this->model->save();
            $newClassId = $this->model->id ?? null;
            if ($request->copy_students) {
                $students = $this->classAssignmentService
                    ->getViaClassIdAndAssignment($id, [ClassAssignmentConstant::STUDENT]);
                foreach ($students as $student) {
                    $classAssignmentService = new ClassAssignmentService();
                    $student['class_id']    = $newClassId;
                    $student['uuid']        = Uuid::uuid();
                    $classAssignmentService->add((object)$student->getAttributes());
                }
            }
            if ($request->copy_teachers) {
                $teachers = $this->classAssignmentService
                    ->getViaClassIdAndAssignment(
                        $id, [ClassAssignmentConstant::SECONDARY_TEACHER, ClassAssignmentConstant::PRIMARY_TEACHER]);
                foreach ($teachers as $teacher) {
                    $classAssignmentService = new ClassAssignmentService();
                    $teacher['class_id']    = $newClassId;
                    $student['uuid']        = Uuid::uuid();
                    $classAssignmentService->add((object)$teacher->getAttributes());
                }
            }
            DB::commit();
            $clonedClass = $this->get($newClassId);
            $subject     = (new SubjectService())->getSubjectByClassId($clonedClass->id);
            $log         = BaseService::currentUser()->username . ' cloned class  : ' . Carbon::now()->toDateString();
            $this->createELS('cloned_class',
                             $log,
                             [
                                 'class_name'     => $clonedClass->name ?? null,
                                 'subject_name'   => $subject->name ?? null,
                                 'old_class_name' => $this->get($id)->name ?? null,
                             ]);

            return $clonedClass;
        } catch (QueryException $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    function createModel(): void
    {
        $this->model = new ClassSQL();
    }

    /**
     * @param int $id
     *
     * @return Model|ClassSql
     */
    public function concludeClass(int $id): Model|ClassSql
    {
        if (!$this->hasPermission(PermissionConstant::class(PermissionActionConstant::CONCLUDE)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $class = $this->get($id);
        unset($class->students, $class->teachers);
        if ($class->status !== StatusConstant::ON_GOING)
            throw new BadRequestException(__('validation.invalid'), new Exception());

        $class->status = StatusConstant::CONCLUDED;
        // $class->end_date = Carbon::now()->toDateString();

        $class->save();

        $this->updateGradeWhenClassIsConcludedByClassIds([$class->id]);

        return $class;
    }

    /**
     * @param int|string $schoolId
     * @param int|string $lmsId
     * @param int|string $externalId
     *
     * @return Model|Builder|ClassSql|null
     */
    public function getByLmsIdAndExId(int|string $schoolId,
                                      int|string $lmsId,
                                      int|string $externalId): Model|Builder|ClassSql|null
    {
        return ClassSql::whereSchoolId($schoolId)
                       ->whereLmsId($lmsId)
                       ->whereExternalId($externalId)
                       ->with(['subject.gradeScale.gradeLetters' => function ($query) {
                           $query->orderBy('score');
                       }])
                       ->first();
    }

    /**
     *
     * @param array $class
     *
     * @return Model
     * @throws Throwable
     */
    public function createClass(array $class): Model
    {
        DB::beginTransaction();
        try {
            if ($class)
                $this->model->name = $class['name'] ?? null;
            $this->model->uuid       = Uuid::uuid();
            $this->model->subject_id = $class['subject_id'] ?? null;
            $this->model->term_id    = $class['term_id'] ?? null;
            $this->model->school_id  = $class['school_id'] ?? null;
            $this->model->status     = StatusConstant::PENDING;
            $this->model->lms_id     = $class['lms_id'] ?? null;
            $this->model->zone       = $class['zone'] ?? null;
            $this->model->course_id  = $class['course_id'] ?? null;
            $this->model->save();
            DB::commit();

            return $this->model;

        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param int $termId
     *
     * @return Collection
     */
    public function getClassesByTerm(int $termId): Collection
    {
        try {
            return $this->model->with(
                [
                    'students',
                    'students.users',
                    'teachers',
                    'teachers.users'
                ]
            )->where('classes.term_id', $termId)
                               ->where('classes.is_transfer_school', false)
                               ->get();

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    #[ArrayShape(['total' => "int", 'classes' => "array"])]
    public function getClassesInProcess(string $userUuid, object $request): array
    {
        $userNoSql              = (new UserService())->with('userSql')->getByUuid($userUuid);
        $classAssignmentService = new ClassAssignmentService();
        $calendarService        = new CalendarService();

        if ($userNoSql->userSql->hasAnyRole(RoleService::decorateWithScId(RoleConstant::STUDENT)))
            $classes = $this->getClassesInProcessOfStudentAndByProgramId($userNoSql, $request->program_id ?? null);
        elseif ($userNoSql->userSql->hasAnyRole(RoleService::decorateWithScId(RoleConstant::TEACHER)))
            $classes = $this->getClassesInProcessOfTeacher($userNoSql);
        else
            throw new UnauthorizedException('You must be a student or teacher to use this function', new Exception());

        foreach ($classes as $class) {
            $class->teacher   = $classAssignmentService->getMainTeacher($class->class_id);
            $class->calendars = $calendarService->getClassProcess($class->class_id);
        }

        return [
            'total'   => $classes->count(),
            'classes' => $classes
        ];
    }

    /**
     * @Description get classes InProcess of specific student and program
     *
     * @Author      yaangvu
     * @Date        Sep 12, 2021
     *
     * @param UserNoSQL       $user
     * @param string|int|null $programId
     *
     * @return Collection|array
     */
    public function getClassesInProcessOfStudentAndByProgramId(UserNoSQL       $user,
                                                               string|int|null $programId): Collection|array
    {
        return StudentProgramClassView::with('subject')
                                      ->from('student_program_class_view', 'spc')
                                      ->join('class_assignments as ca', 'ca.class_id', '=', 'spc.class_id')
                                      ->where('spc.student_id', '=', $user->userSql->id)
                                      ->where('ca.user_id', '=', $user->userSql->id)
                                      ->where('spc.status', '=', StatusConstant::ON_GOING)
                                      ->when($programId,
                                          function (QBuilder|Builder $query) use ($programId) {
                                              return $query->where('program_id', '=', $programId);
                                          })
                                      ->get();
    }

    /**
     * @Description get classes InProcess of specific student
     *
     * @Author      yaangvu
     * @Date        Sep 12, 2021
     *
     * @param UserNoSQL $user
     *
     * @return Collection|array
     */
    public function getClassesInProcessOfTeacher(UserNoSQL $user): Collection|array
    {
        return ClassAssignmentView::with('subject')
                                  ->select('c.*')
                                  ->from('class_assignment_view', 'c')
                                  ->where('c.assignment_user_id', '=', $user->userSql->id)
                                  ->where('status', '=', StatusConstant::ON_GOING)
                                  ->get();
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Sep 23, 2021
     *
     * @param int|null   $termId
     * @param array|null $classIds
     *
     * @return int
     */
    public function countByTermIdAndClassIds(?int $termId, ?array $classIds): int
    {
        return $this->model
            ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
            ->when($termId, function (MBuilder|Builder $q) use ($termId) {
                return $q->where("term_id", '=', $termId);
            })
            ->when($classIds, function (MBuilder|Builder $q) use ($classIds) {
                return $q->whereIn("id", $classIds);
            })
            ->count();
    }

    public function getListClassForCurrentUser(): LengthAwarePaginator|array
    {
        $isGovernor = $this->hasAnyRole(RoleConstant::PRINCIPAL, RoleConstant::ADMIN);
        $isTeacher  = $this->hasAnyRole(RoleConstant::TEACHER);
        $isDynamic  = $this->hasPermission(PermissionConstant::attendanceReport(PermissionActionConstant::VIEW));
        if (!($isGovernor || $isTeacher || $isDynamic))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        if (!$this->hasAnyRoleWithUser(RoleServiceProvider::$currentRole->id)) {
            throw new BadRequestException(['message' => __('role.validate_role')], new Exception());
        }
        $currentRoleName   = $this->decorateWithSchoolUuid(RoleServiceProvider::$currentRole->name);
        $classAssignmentId = (new ClassAssignmentSQL())->whereUserId(self::currentUser()->id)
                                                       ->whereIn('assignment',
                                                                 [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER])
                                                       ->pluck('class_id')->toArray();

        $roleAccept
            = [RoleService::decorateWithScId(RoleConstant::ADMIN), RoleService::decorateWithScId(RoleConstant::PRINCIPAL), RoleService::decorateWithScId(RoleConstant::TEACHER)];
        $isRoleDynamic
            = $this->hasPermissionViaRoleId(PermissionConstant::attendanceReport(PermissionActionConstant::VIEW),
                                            RoleServiceProvider::$currentRole->id);
        if ($isRoleDynamic)
            $roleAccept[] = $currentRoleName;

        //If the user passing the role is not satisfied, an empty array will be returned
        if (!in_array($currentRoleName, $roleAccept))
            return [];
        try {
            return $this->queryHelper->buildQuery(new ClassSQL())->with([
                                                                            'teachers',
                                                                            'teachers.users.userNoSql',
                                                                            'students'
                                                                        ])
                                     ->join('schools', 'schools.id', '=', 'classes.school_id')
                                     ->when($currentRoleName == RoleService::decorateWithScId(RoleConstant::TEACHER),
                                         function (EBuilder $q) use (
                                             $currentRoleName, $classAssignmentId
                                         ) {
                                             $q->whereIn('classes.id', $classAssignmentId);
                                         })
                                     ->where('schools.uuid', self::currentUser()->userNoSql->sc_id)
                                     ->where('status', StatusConstant::ON_GOING)
                                     ->select('classes.*')
                                     ->paginate(QueryHelper::limit());
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system - 500'), $e);
        }
    }

    /**
     * @param int|string $classAssignmentId
     *
     * @return bool
     * @throws Exception
     */
    public function withdrawalUserLms(int|string $classAssignmentId): bool
    {
        $lmsName = LMSService::getViaClassAssignmentId($classAssignmentId)?->name;

        if ($lmsName == LmsSystemConstant::EDMENTUM)
            $this->unAssignUsersToEdmentum($classAssignmentId);

        if ($lmsName == LmsSystemConstant::AGILIX)
            $this->unAssignUsersToAgilix($classAssignmentId);

        return true;
    }

    /**
     * @throws Exception
     */
    public function importEnrollStudent(object $request): bool
    {
        $class = $this->get($request->id);

        $this->doValidate($request, [
            'file_url' => 'required',
        ]);
        $fileUrl = $request->file_url;

        $filePath = substr($fileUrl, strpos($fileUrl, ".com") + 4);
        $body = [
            'url'         => $fileUrl,
            'file_path'   => $filePath,
            'school_uuid' => SchoolServiceProvider::$currentSchool->uuid,
            'email'       => BaseService::currentUser()->userNoSql->email,
            'class_id'    => $class['id'],
            'lms_id'      => $class['lms_id']
        ];

        $this->pushToExchange($body, 'IMPORT', AMQPExchangeType::DIRECT, 'enroll');

        return true;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   May 16, 2022
     *
     * @param $classId
     *
     * @return bool
     */
    public function isClassConcluded($classId): bool
    {
        $class = $this->model->where('id', $classId)->first();

        return $class->status == StatusConstant::CONCLUDED;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Aug 04, 2022
     *
     * @param $ids
     *
     * @return bool
     */
    public function updateGradeWhenClassIsConcludedByClassIds($ids): bool
    {
        $uuidsAndIds = UserSQL::query()
                              ->join('class_assignments', 'class_assignments.user_id', 'users.id')
                              ->whereIn('class_id', $ids)
                              ->where('class_assignments.assignment', 'Student')
                              ->select('users.*')
                              ->pluck('users.id', 'users.uuid')->toArray();
        $arrUuid     = array_keys($uuidsAndIds);
        $users       = UserNoSQL::query()->whereIn('uuid', $arrUuid)->pluck('grade', 'uuid');
        $scores      = ScoreSQL::query()->whereIn('class_id', $ids)
                               ->whereIn('user_id', $uuidsAndIds)->get();
        foreach ($uuidsAndIds as $uuid => $userId) {
            $score = $scores->where('user_id', $userId)->first();
            $grade = $users[$uuid] ?? null;
            if (!$score) continue;
            $score->grade = !empty($grade) ? $grade : null;
            $score->save();
        }

        return true;
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function unAssignStudentFromClassesViaStudentId(int|string $studentId, object $request): bool
    {
        $this->doValidate($request, [
            'classes'   => 'required|array',
            'classes.*' => 'required|exists:classes,id'
        ]);
        $studentSQL       = (new UserService())->get($studentId)->userSql;
        $classAssignments = ClassAssignmentSQL::query()
                                              ->join('classes', 'classes.id', '=', 'class_assignments.class_id')
                                              ->join('lms', 'lms.id', '=', 'classes.lms_id')
                                              ->whereIn('class_assignments.class_id', $request->classes)
                                              ->where('class_assignments.user_id', $studentSQL->id)
                                              ->whereAssignment(ClassAssignmentConstant::STUDENT)
                                              ->select('class_assignments.*', 'lms.name as lms_name');

        foreach ($classAssignments->get() as $classAssignment) {
            if ($classAssignment->lms_name && $classAssignment->lms_name !== LmsSystemConstant::SIS)
                switch ($classAssignment->lms_name) {
                    case LmsSystemConstant::EDMENTUM :
                        $this->unAssignUsersToEdmentum($classAssignment->id);
                        break;
                    default :
                        $this->unAssignUsersToAgilix($classAssignment->id);
                        break;
                }
            (new ZoomMeetingService())->unAssignStudentsToVcrCViaClassIdAndUserIds($classAssignment->class_id, [$studentSQL->id]);
        }
        $classAssignments->delete();

        return true;
    }

    public function getClassByTermId($termId, $request): LengthAwarePaginator
    {
        $className = $request->class_name ?? null;

        return ClassSQL::query()->where('term_id', $termId)
                                ->when($className, function ($q) use($className) {
                                    $q->where('name', 'like', '%'. $className . '%');
                                })->paginate(QueryHelper::limit());
    }

    public function getNumBerStudentByClassId(int $classId ,Request $request): int
    {
        $rule = [
          'assignment_status' => 'in:' . implode(',', StatusConstant::ALL)
        ];
        $this->doValidate($request, $rule);
        $assignStatus = $request->assignment_status ?? StatusConstant::ACTIVE;
        return ClassAssignmentSQL::query()->where('class_id', $classId)
            ->where('status',$assignStatus )
            ->where('assignment', ClassAssignmentConstant::STUDENT)
            ->count();
    }

    public function getClassByUserIdAndStatus(int $userId, string $status): Collection|array
    {
        return ClassAssignmentSQL::query()->where('user_id', $userId)
                                      ->where('assignment', ClassAssignmentConstant::STUDENT)
                                      ->where('status', $status)
                                      ->get();
    }
}
