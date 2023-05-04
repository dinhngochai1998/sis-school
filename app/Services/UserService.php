<?php


namespace App\Services;


use App\Helpers\KeycloakHelper;
use App\Traits\SubjectRuleTraits;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Constant\SubjectRuleConstant;
use YaangVu\Constant\SurveyConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\SubjectRuleSQL;
use YaangVu\SisModel\App\Models\impl\TermSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserParentSQL;
use YaangVu\SisModel\App\Models\impl\UserProgramSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;


class UserService extends BaseService
{
    use SubjectRuleTraits, RoleAndPermissionTrait;

    private static array           $subUser = [];
    public Model|Builder|UserNoSQL $model;

    /**
     * @param string $role
     *
     * @return array|Collection
     */
    public static function getUserByRole(string $role): array|Collection
    {
        return UserSQL::query()
                      ->select('users.*')
                      ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                      ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                      ->where('roles.name', '=', RoleService::decorateWithScId($role))
                      ->get();
    }

    public static function getUserSqlViaUsernames(array $usernames): Collection|array
    {
        return UserSQL::query()->whereIn('username', $usernames)->get();
    }

    public static function getViaRoleName(string $roleName): Collection|array
    {
        return UserSQL::with([])
                      ->select('users.*')
                      ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                      ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                      ->where('roles.name', '=', RoleService::decorateWithScId($roleName))
                      ->get();
    }

    public static function getViaId(int|string $id): Model|UserNoSQL|EBuilder|\Jenssegers\Mongodb\Eloquent\Builder|null
    {
        return UserNoSQL::query()->where('_id', $id)->with(['userSql.user'])->first();
    }

    public function createModel(): void
    {
        $this->model = new UserNoSQL();
    }

    #[ArrayShape(['total' => "int", 'last_page' => "float", 'per_page' => "int", 'from' => "float|int", 'to' => "mixed", 'data' => "array"])]
    public function getStudentViaClassId(int $classId, Request $request): array
    {
        $search          = $request->search ?? null;
        $userAssignments = $this->getUsersByClassIdAndAssignment($classId, [ClassAssignmentConstant::STUDENT])
                                ->pluck('status', 'username')
                                ->toArray();
        $usernames       = [];
        foreach ($userAssignments as $key => $userAssignment)
            $usernames[] = (string)$key;

        $user = $this->model
            ->whereIn('username', $usernames)
            ->when($search, function ($q) use ($search) {
                $q->where(function ($where) use ($search) {
                    $where->where('student_code', 'LIKE', '%' . $search . '%');
                    $where->orWhere('full_name', 'LIKE', '%' . $search . '%');
                    $where->orWhere('email', 'LIKE', '%' . $search . '%');
                });
            })
            ->orderBy('last_name')
            ->get();

        $classActivities = ClassActivityNoSql::query()->where('class_id', $classId)
                                             ->pluck('current_score', 'student_uuid')->toArray();
        $class           = ClassSQL::query()->where('id', $classId)->where('status', StatusConstant::CONCLUDED)
                                   ->first();


        $user->transform(function ($value, $key) use (
            $user, $userAssignments, $classActivities, $class
        ) {
            $result                    = $user[$key];
            $result->assignment_status = $userAssignments[$result->username];
            if (isset($classActivities[$result->uuid]) && $class) {
                $result->score = round($classActivities[$result->uuid], 2);

            }

            return $result;
        });
        $totalStudents       = count($user);
        $page                = $request->page ?? 1;
        $limit               = $request->limit ?? 10;
        $firstNumericalOrder = $page == 1 ? 1 : ($page - 1) * $limit + 1;
        $lastNumericalOrder  = min($page * $limit, $totalStudents);

        return [
            'total'     => $totalStudents,
            'last_page' => ceil($totalStudents / $limit),
            'per_page'  => (int)$limit,
            'from'      => $firstNumericalOrder,
            'to'        => $lastNumericalOrder,
            'data'      => array_values($user->sortBy('assignment_status')->forPage($page, $limit)->toArray()),
        ];

    }

    public function getUsersByClassIdAndAssignment(int $classId, array $assignment): Collection|array
    {
        return UserSQL::with([])
                      ->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                      ->where('class_assignments.class_id', '=', $classId)
                      ->whereIn('assignment', $assignment)
                      ->orderByDesc('class_assignments.updated_at')
                      ->get();
    }

    /**
     * @param int $classId
     *
     * @return LengthAwarePaginator
     */
    public function getAssignableStudents(int $classId): LengthAwarePaginator
    {
        $class   = (new ClassService())->get($classId);
        $lmsName = $class->lms_id ? (new LMSService())->get($class->lms_id)->name : LmsSystemConstant::SIS;
        // get student by class lms
        // $source  = $lmsName !== LmsSystemConstant::SIS ? [$lmsName, LmsSystemConstant::SIS] : [LmsSystemConstant::SIS];
        $request = request()->all();
        $this->queryHelper->removeParam('student_ids')
                          ->removeParam('term_never_assigned');
        $termNeverAssigned = $request['term_never_assigned'] ?? null;
        $userIdAlreadyAssign
                           = ClassAssignmentSQL::whereClassId($class->id)
                                               ->when($termNeverAssigned,
                                                   function ($q) use ($termNeverAssigned, $class) {
                                                       $q->join('classes', 'classes.id', 'class_assignments.class_id');
                                                       if ($termNeverAssigned === 'All term') {
                                                           $termIds
                                                               = TermSQL::query()
                                                                        ->where('terms.school_id',
                                                                                SchoolServiceProvider::$currentSchool->id)
                                                                        ->join('classes', 'classes.term_id', '=',
                                                                               'terms.id')
                                                                        ->where('classes.id', $class->id)
                                                                        ->pluck('terms.id');
                                                           $q->whereIn('classes.term_id', $termIds);
                                                       } else
                                                           $q->where('classes.term_id', $class->term_id);
                                                   })
                                               ->whereAssignment(ClassAssignmentConstant::STUDENT)
                                               ->pluck('class_assignments.user_id');

        $usernames = UserSQL::query()
                            ->select('users.*')
                            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('roles.name', '=', RoleService::decorateWithScId(RoleConstant::STUDENT))
                            ->whereNotIn('users.id', $userIdAlreadyAssign)
                            ->pluck('username');

        $students = $this->queryHelper->buildQuery($this->model)
                                      ->with('userSql')
                                      ->when($request['student_ids'] ?? null, function ($q) use ($request) {
                                          $q->whereIn('student_code', $request['student_ids']);
                                      })
                                      ->whereIn('username', $usernames)
            // ->whereIn('source', $source)
                                      ->orderBy('username')
                                      ->paginate(QueryHelper::limit());
        if (!$class->subject_id)
            return $students;

        $subjectService = new SubjectService();
        $subjectRules   = SubjectRuleSQL::whereSubjectId($class->subject_id)->get();
        $subjectName    = $subjectService->get($class->subject_id)->name;
        $students->getCollection()->transform(function ($student) use (
            $subjectRules, $classId, $subjectService, $subjectName
        ) {
            $warning = [];
            $userId  = $student?->userSql ? $student->userSql?->id : null;
            foreach ($subjectRules as $subjectRule) {
                if (!$userId)
                    continue;

                $relevanceSubjectName = $subjectService->get($subjectRule->relevance_subject_id)->name;
                match ((string)$subjectRule->type) {
                    SubjectRuleConstant::BEFORE, SubjectRuleConstant::PRECEDE =>
                    $this->isBefore($classId, $subjectRule->subject_id, $subjectRule->relevance_subject_id, $userId)
                        ? array_push($warning, __('subject_rule.before',
                                                  ['A' => $subjectName, 'B' => $relevanceSubjectName])
                    ) : null,

                    SubjectRuleConstant::AFTER =>
                    $this->isStudentPassSubject($classId, $subjectRule->relevance_subject_id, $userId)
                        ? null : array_push($warning, __('subject_rule.after',
                                                         ['A' => $subjectName, 'B' => $relevanceSubjectName])),

                    SubjectRuleConstant::SAME_TEACHER =>
                    $this->isSameTeacher($classId, $subjectRule->relevance_subject_id, $userId)
                        ? null : array_push($warning, __('subject_rule.same_teacher',
                                                         ['A' => $subjectName, 'B' => $relevanceSubjectName])),

                    SubjectRuleConstant::CONSECUTIVE =>
                    $this->isConsecutive($classId, $subjectRule->relevance_subject_id, $userId)
                        ? null : array_push($warning, __('subject_rule.consecutive',
                                                         ['A' => $subjectName, 'B' => $relevanceSubjectName])),

                    SubjectRuleConstant::DIFFERENT_TERM =>
                    $this->isSameTerm($classId, $subjectRule->relevance_subject_id, $userId)
                        ? array_push($warning, __('subject_rule.different_term',
                                                  ['A' => $subjectName, 'B' => $relevanceSubjectName])) : null,

                    SubjectRuleConstant::SAME_TERM =>
                    $this->isSameTerm($classId, $subjectRule->relevance_subject_id, $userId)
                        ? null : array_push($warning, __('subject_rule.same_term',
                                                         ['A' => $subjectName, 'B' => $relevanceSubjectName])),

                    default => [
                        $this->isStudentPassSubject($classId, $subjectRule->relevance_subject_id, $userId)
                            ? null : array_push($warning, __('subject_rule.follow_with_after',
                                                             ['A' => $subjectName, 'B' => $relevanceSubjectName])),

                        $this->isConsecutive($classId, $subjectRule->relevance_subject_id, $userId)
                            ? null : array_push($warning, __('subject_rule.follow_with_consecutive',
                                                             ['A' => $subjectName, 'B' => $relevanceSubjectName]))
                    ],
                };
            }
            // check is student passes class
            if (ScoreSQL::whereClassId($classId)->whereUserId($userId ?? null)->first()?->is_pass ?? false)
                $warning[] = __('subject_rule.pass_subject');

            $student->warning = $warning ?? [];

            return $student;
        });

        return $students;
    }

    public function getUserSqlViaId(string $id): Model|Builder|UserSQL|null
    {
        $uuid = $this->get($id)->uuid;
        try {
            return UserSQL::whereUuid($uuid)->first();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function get(int|string $id): Model|UserNoSQL
    {
        $this->queryHelper->with(['userSql.user']);

        return parent::get($id);
    }

    public function getUserSqlViaIds(array $ids): Collection|array
    {
        $userNoSqlUuids = $this->model->whereIn('_id', $ids)->pluck('uuid');

        return UserSQL::query()->whereIn('uuid', $userNoSqlUuids)->get();
    }

    public function getUserSqlViaUuid(string $uuid): Model|Builder|UserSQL|null
    {
        $username = $this->getByUuid($uuid)->username;
        try {
            return UserSQL::whereUsername($username)->first();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 29, 2022
     *
     * @param string $uuid
     *
     * @return Model|UserNoSQL
     */
    public function getByUuid(string $uuid): Model|UserNoSQL
    {
        return parent::getByUuid($uuid);
    }

    /**
     * @param int $termId
     *
     * @return Collection
     */
    public function getStudentsViaTerm(int $termId): Collection
    {
        $term = (new TermService())->get($termId);

        try {
            return UserSQL::query()
                          ->select('users.uuid', 'users.id')
                          ->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                          ->join('classes', 'classes.id', '=', 'class_assignments.class_id')
                          ->join('terms', 'terms.id', '=', 'classes.term_id')
                          ->where('classes.is_transfer_school', false)
                          ->where('terms.id', $term->id)
                          ->where('class_assignments.assignment', '=', ClassAssignmentConstant::STUDENT)
                          ->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     *
     * @param array $ids
     *
     * @return Collection
     */
    public function getDivisionUsers(array $ids): Collection
    {
        try {
            return $this->model->whereIn('uuid', $ids)->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
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
        DB::beginTransaction();
        $request = $this->preUpdate($id, $request) ?? $request;

        // Set data for updated entity
        $fillAbles = $this->model->getFillable();
        $guarded   = $this->model->getGuarded();

        // Validate
        if ($this->updateRequestValidate($id, $request) !== true)
            return $this->model;

        $model = $this->getByUuid($id);

        foreach ($fillAbles as $fillAble)
            if (isset($request->$fillAble) && !in_array($fillAble, $guarded))
                $model->$fillAble
                    = gettype($request->$fillAble) === 'string' ? trim($request->$fillAble) : $request->$fillAble;
        try {
            if (isset($request->grade) && $request->grade)
                $model->grade = $request->grade;

            $model->save();
            $this->postUpdate($id, $request, $model);

            DB::commit();

            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    function getForFilerOperatorLike(object $request): Collection|array
    {
        $searchKey = $request->search_key ?? null;
        $fields    = is_array($request->fields) ? $request->fields : [];

        return UserNoSQL::with([])
                        ->when($searchKey && count($fields) > 0,
                            function (EBuilder $q) use ($fields, $searchKey) {
                                $q->where(function (EBuilder $query) use ($searchKey, $fields) {
                                    foreach ($fields as $key => $field) {
                                        if ($key == 0) {
                                            $query->where($field, 'LIKE', '%' . $searchKey . '%');
                                            continue;
                                        }
                                        $query->orWhere($field, 'LIKE', '%' . $searchKey . '%');
                                    }
                                });
                            })->get();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param object $request
     *
     * @return object
     */
    public function preAdd(object $request): object
    {
        if ($request instanceof Request)
            $request = (object)$request->toArray();

        $middleName = ($request->middle_name ?? null) ? trim($request->middle_name) . ' ' : null;

        $request->full_name = (trim($request->first_name ?? null) . ' ' .
            $middleName .
            trim($request->last_name ?? null));

        $request->source = 'sis';
        self::$subUser   = [
            'password'   => $request->password ?? null,
            'role_names' => $request->role_names ?? null
        ];

        $request->username = strtolower($request->username);

        return $request;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 29, 2022
     *
     * @param object          $request
     * @param Model|UserNoSQL $model
     *
     * @throws InvalidArgumentException
     */
    public function postAdd(object $request, Model|UserNoSQL $model)
    {
        // create keycloak user by api keycloak
        $uuid = KeycloakHelper::loginAsAdmin()->createUser($model) ?? null;
        if ($uuid === null)
            $uuid = KeycloakHelper::loginAsAdmin()->getUserByUsername($model->username)?->id;

        if ($uuid === null)
            $uuid = KeycloakHelper::loginAsAdmin()->getUserByEmail($model->email)?->id;

        Log::info("create user with uuid : $uuid");
        KeycloakHelper::loginAsAdmin()->updatePassword($uuid, self::$subUser['password']);
        $model->password             = Crypt::encrypt($request->password);
        $model->{CodeConstant::UUID} = $uuid ?? null;
        $model->save();

        // add user postgree
        $user                       = new UserSQL();
        $user->username             = $model->username ?? null;
        $user->{CodeConstant::UUID} = $uuid ?? null;
        $user->save();

        if (self::$subUser['role_names']) {
            $role = self::$subUser['role_names'];
            $user->syncRoles($role);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param string $userId
     *
     * @return Model|EBuilder|null
     */
    public function getUserDetailSat(string $userId): Model|EBuilder|null
    {
        $isDynamic
                     = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW));
        $isStudent   = $this->hasAnyRole(RoleConstant::STUDENT);
        $isCounselor = $this->hasAnyRole(RoleConstant::COUNSELOR);
        if (!$isStudent && !$isCounselor && !$isDynamic) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $query = $this->queryHelper->buildQuery($this->model)->where('_id', $userId)
                                   ->with(['sats' => function ($query) {
                                       $query->orderBy('test_date', 'DESC');
                                   }])
                                   ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid);
        if (!$this->isGod())
            $query = $this->handleIeltsResponseViaRole($isStudent, $isCounselor, $query, $userId);

        try {
            return $query->first();

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param      $userId
     * @param bool $isRoleStudent
     * @param bool $isCounselorOrTeacher
     * @param      $query
     *
     * @return array|EBuilder
     */
    public function handleIeltsResponseViaRole(bool $isRoleStudent, bool $isCounselorOrTeacher,
                                                    $query, $userId = null): array|EBuilder
    {

        if ($isRoleStudent) {
            if (!(BaseService::currentUser()->userNoSql->_id == $userId)) {
                throw new BadRequestException(__('assignedStudentError.assigned'), new Exception());
            }

            return $query->where('_id', BaseService::currentUser()->userNoSql->_id);
        }
        if ($isCounselorOrTeacher) {

            $studentUuid = BaseService::currentUser()->userNoSql->assigned_student_uuids;
            if (!$studentUuid)
                return [];

            return $query->whereIn('uuid', $studentUuid);
        }

        return $query;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param $userId
     *
     * @return Model|EBuilder|null
     */
    public function getUserDetailPhysicalPerformance($userId): Model|EBuilder|null
    {
        $isDynamic            = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW));
        $isStudent            = $this->hasAnyRole(RoleConstant::STUDENT);
        $isCounselorOrTeacher = $this->hasAnyRole(RoleConstant::COUNSELOR, RoleConstant::TEACHER);
        if (!$isStudent && !$isCounselorOrTeacher && !$isDynamic)
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $query = $this->queryHelper->buildQuery($this->model)->where('_id', $userId)
                                   ->with(['physicalPerformance' => function ($q) {
                                       $q->orderBy('test_date', 'DESC');
                                   }])
                                   ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid);
        if (!$this->isGod())
            $query = $this->handleIeltsResponseViaRole($isStudent, $isCounselorOrTeacher, $query, $userId);

        try {
            return $query->first();

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param string $userId
     *
     * @return Model|EBuilder|null
     */
    public function getUserDetailIelts(string $userId): Model|EBuilder|null
    {
        $isDynamic
                     = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW));
        $isCounselor = $this->hasAnyRole(RoleConstant::COUNSELOR);
        $isStudent   = $this->hasAnyRole(RoleConstant::STUDENT);

        if (!$isCounselor && !$isStudent && !$isDynamic) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $query = $this->queryHelper->buildQuery($this->model)->where('_id', $userId)
                                   ->with(['ielts' => function ($q) {
                                       $q->orderBy('test_date_final', 'DESC');
                                   }])
                                   ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid);

        if (!$this->isGod())
            $query = $this->handleIeltsResponseViaRole($isStudent, $isCounselor, $query, $userId);

        try {

            $response = $query->first();
            foreach ($response->ielts ?? [] as $ielt) {
                $listening     = [
                    "listening" => $ielt->listening["score"],
                ];
                $reading       = [
                    "reading" => $ielt->reading["score"],
                ];
                $ielt->writing = array_merge((array)$ielt->writing, $listening, $reading);
            }

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Pham Van Tien
     * @Date   Apr 01, 2022
     *
     * @param null $schoolUuid
     *
     * @return array
     */
    public function getStudentCodesByCurrentSchool($schoolUuid = null): array
    {
        try {
            return UserNoSQL::query()
                            ->where(function ($q) use ($schoolUuid) {
                                $q->where('sc_id', $schoolUuid ?? SchoolServiceProvider::$currentSchool->uuid)
                                  ->whereNotNull('student_code')
                                  ->where('student_code', '!=', '');
                            })
                            ->pluck('student_code')
                            ->toArray();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getUserUuidByClassId(int $classId): array
    {
        return UserSQL::query()->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                      ->where('class_assignments.class_id', '=', $classId)
                      ->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT)
                      ->pluck('users.uuid')
                      ->toArray();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   May 16, 2022
     *
     * @param $termId
     *
     * @return Collection|array
     */
    public function getAllPrimaryTeacherAssignmentByTermId($termId): Collection|array
    {
        return UserSQL::query()
                      ->with('userNoSql')
                      ->distinct('id')
                      ->join('class_assignments', 'users.id', 'class_assignments.user_id')
                      ->join('classes', 'classes.id', 'class_assignments.class_id')
                      ->where('class_assignments.assignment', ClassAssignmentConstant::PRIMARY_TEACHER)
                      ->where('classes.term_id', $termId)
                      ->select('users.*', 'class_assignments.class_id',
                               'class_assignments.user_id', 'class_assignments.assignment', 'classes.term_id')
                      ->get();
    }

    public function getArrAssignableStudentCodes(int $classId): array
    {
        $class   = (new ClassService())->get($classId);
        $lmsName = $class->lms_id ? (new LMSService())->get($class->lms_id)->name : LmsSystemConstant::SIS;
        // get student by class lms
        $source = $lmsName !== LmsSystemConstant::SIS ? [$lmsName, LmsSystemConstant::SIS] : [LmsSystemConstant::SIS];

        $userUuids = UserSQL::query()
                            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('roles.name', '=', RoleService::decorateWithScId(RoleConstant::STUDENT))
                            ->pluck('uuid')
                            ->toArray();

        return UserNoSQL::query()
                        ->whereIn('uuid', $userUuids)
                        ->whereIn('source', $source)
                        ->orderBy('username')
                        ->pluck('student_code')
                        ->toArray();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array  $programIds
     * @param string $object
     * @param bool   $status
     *
     * @return array
     */
    public function getEmailAndFullNameUserByProgramId(array $programIds, string $object, bool $status): array
    {
        $query = UserProgramSQL::query()
                               ->join('users', 'users.id', '=', 'user_program.user_id')
                               ->join('programs', 'programs.id', '=', 'user_program.program_id')
                               ->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                               ->where('programs.school_id', SchoolServiceProvider::$currentSchool->id)
                               ->when($status == true, function ($q) use ($status) {
                                   $q->where('class_assignments.status', StatusConstant::ACTIVE);
                               })
                               ->when($object == SurveyConstant::FAMILY, function ($q) {
                                   $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                               })
                               ->when($object == SurveyConstant::STUDENT, function ($q) {
                                   $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                               })
                               ->when(!empty($programIds), function ($q) use ($programIds) {
                                   $q->whereIn('user_program.program_id', $programIds);
                               });

        if ($object == SurveyConstant::FAMILY) {
            $userIds   = $query->pluck('users.id')->toArray();
            $userUuids = $this->getParentUuidByUserId($userIds);

            return $this->getUserByUuid($userUuids);
        }
        $userUuids = $query->pluck('users.uuid')->toArray();

        return $this->getUserByUuid($userUuids);

    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 02, 2022
     *
     * @param $userIds
     *
     * @return array
     */
    public function getParentUuidByUserId($userIds): array
    {
        return UserParentSQL::query()
                            ->join('users', 'users.id', '=', 'user_parents.parent_id')
                            ->whereIn('user_parents.children_id', $userIds)
                            ->where('user_parents.school_id', SchoolServiceProvider::$currentSchool->id)
                            ->pluck('users.uuid')
                            ->toArray();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 02, 2022
     *
     * @param $userUuids
     *
     * @return Collection|array
     */
    public function getUserByUuid($userUuids): Collection|array
    {
        return UserNoSQL::query()
                        ->whereIn('uuid', $userUuids)
                        ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid)
                        ->get()->toArray();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array  $grades
     * @param bool   $status
     * @param string $object
     *
     * @return array
     */
    public function getEmailAndFullNameStudentFamilyByGrade(array $grades, string $object, bool $status): array
    {
        $userUuids = UserNoSQL::query()
                              ->with('userSql')
                              ->when($status == true, function ($q) {
                                  $q->where('status', StatusConstant::ACTIVE);
                              })
                              ->when(!empty($grades), function ($q) use ($grades) {
                                  $q->whereIn('grade', $grades);
                              })
                              ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid)
                              ->pluck('uuid')->toArray();

        $roleStudent = $this->decorateWithSchoolUuid(RoleConstant::STUDENT);
        $roleFamily  = $this->decorateWithSchoolUuid(RoleConstant::FAMILY);
        $query       = UserSQL::query()
                              ->leftJoin('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                              ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
                              ->whereIn('users.uuid', $userUuids)
                              ->when($object == SurveyConstant::STUDENT,
                                  function ($q) use ($roleStudent) {
                                      $q->where('roles.name', $roleStudent);
                                  })
                              ->when($object == SurveyConstant::FAMILY,
                                  function ($q) use ($roleFamily) {
                                      $q->where('roles.name', $roleFamily);
                                  });

        if ($object == SurveyConstant::FAMILY) {
            $userIds   = $query->pluck('users.id')->toArray();
            $userUuids = $this->getParentUuidByUserId($userIds);

            return $this->getUserByUuid($userUuids);
        }
        $userUuids = $query->pluck('users.uuid')->toArray();

        return $this->getUserByUuid($userUuids);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array       $userIds
     * @param string|null $object
     *
     * @return array
     */
    public function getAllStudentByUserIds(array $userIds, string $object = null): array
    {

        $arrUuid = UserNoSQL::query()
                            ->when(!empty($userIds), function ($q) use ($userIds) {
                                $q->whereIn('_id', $userIds);
                            })
                            ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid)
                            ->pluck('uuid')->toArray();

        if ($object == SurveyConstant::FAMILY) {
            $arrId         = UserSQL::query()->whereIn('uuid', $arrUuid)->pluck('id')->toArray();
            $arrUuidParent = $this->getParentUuidByUserId($arrId);

            return $this->getUserByUuid($arrUuidParent);
        }
        $userUuids = UserSQL::query()
                            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereIn('users.uuid', $arrUuid)
                            ->when($object == SurveyConstant::STUDENT, function ($q) {
                                $q->where('roles.name', $this->decorateWithSchoolUuid(RoleConstant::STUDENT));
                            })
                            ->when($object == SurveyConstant::TEACHER, function ($q) {
                                $q->where('roles.name', $this->decorateWithSchoolUuid(RoleConstant::TEACHER));
                            })
                            ->pluck('users.uuid')->toArray();

        return $this->getUserByUuid($userUuids);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $roleIds
     * @param bool  $status
     *
     * @return Collection|array
     */
    public function getUserByRoleId(array $roleIds, bool $status): Collection|array
    {
        $userUuid = UserSQL::query()
                           ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                           ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                           ->when($roleIds == true, function ($q) use ($roleIds) {
                               $q->whereIn('model_has_roles.role_id', $roleIds);
                           })
                           ->when($status == true, function ($q) {
                               $q->where('roles.status', StatusConstant::ACTIVE);
                           })
                           ->pluck('users.uuid');

        return $this->getUserByUuid($userUuid);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $familyIds
     *
     * @return array
     */
    public function getUserById(array $familyIds): array
    {
        return UserNoSQL::query()
                        ->whereIn('_id', $familyIds)
                        ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid)
                        ->get()->toArray();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 02, 2022
     *
     * @param array  $classIds
     * @param string $object
     * @param bool   $status
     *
     * @return array|Collection
     */
    public function getEmailAndFullNameUserByClassId(array $classIds, string $object, bool $status): Collection|array
    {
        $query = UserSQL::query()
                        ->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                        ->when($status == true, function ($q) use ($status) {
                            $q->where('class_assignments.status', StatusConstant::ACTIVE);
                        })
                        ->when($object == SurveyConstant::FAMILY, function ($q) {
                            $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                        })
                        ->when($object == SurveyConstant::TEACHER, function ($q) {
                            $q->where('class_assignments.assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                        })
                        ->when($object == SurveyConstant::STUDENT, function ($q) {
                            $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                        })
                        ->when(!empty($classIds), function ($q) use ($classIds) {
                            $q->whereIn('class_assignments.class_id', $classIds);
                        });

        if ($object == SurveyConstant::FAMILY) {
            $userIds   = $query->pluck('users.id')->toArray();
            $userUuids = $this->getParentUuidByUserId($userIds);

            return $this->getUserByUuid($userUuids);
        }
        $userUuids = $query->pluck('users.uuid')->toArray();

        return $this->getUserByUuid($userUuids);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array  $termIds
     * @param string $object
     * @param bool   $status
     *
     * @return array
     */
    public function getEmailAndFullNameUserByTermId(array $termIds, string $object, bool $status): array
    {
        $query = UserSQL::query()->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                        ->join('classes', 'classes.id', '=', 'class_assignments.class_id')
                        ->join('terms', 'terms.id', '=', 'classes.term_id')
                        ->where('terms.school_id', SchoolServiceProvider::$currentSchool->id)
                        ->when($status == true, function ($q) use ($status) {
                            $q->where('class_assignments.status', StatusConstant::ACTIVE);
                        })
                        ->when($object == SurveyConstant::FAMILY, function ($q) {
                            $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                        })
                        ->when($object == SurveyConstant::TEACHER, function ($q) {
                            $q->where('class_assignments.assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                        })
                        ->when($object == SurveyConstant::STUDENT, function ($q) {
                            $q->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT);
                        })
                        ->when(!empty($termIds), function ($q) use ($termIds) {
                            $q->whereIn('classes.term_id', $termIds);
                        });

        if ($object == SurveyConstant::FAMILY) {
            $userIds   = $query->pluck('users.id')->toArray();
            $userUuids = $this->getParentUuidByUserId($userIds);

            return $this->getUserByUuid($userUuids);
        }
        $userUuids = $query->pluck('users.uuid')->toArray();

        return $this->getUserByUuid($userUuids);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 15, 2022
     *
     * @param int $classId
     *
     * @return Collection|array
     */
    public function getStudentByClassId(int $classId): Collection|array
    {
        return UserSQL::query()->with('userNoSql')
                      ->distinct('uuid')
                      ->join('class_assignments', 'class_assignments.user_id', '=', 'users.id')
                      ->leftJoin('scores',
                          function ($join) {
                              $join->on('scores.user_id', '=', 'class_assignments.user_id');
                              $join->on('scores.class_id', '=', 'class_assignments.class_id');

                          })
                      ->where('class_assignments.class_id', '=', $classId)
                      ->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT)
                      ->select('users.uuid', 'scores.current_score as score')
                      ->get();
    }

    public function getEmailFullNameViaId($id): array
    {
        return UserNoSQL::query()->whereIn('_id', $id)->pluck('email', 'full_name')->toArray();
    }

    public function getUserUuidsViaRoleName($roleName, string $object = null): Collection|array
    {
        $userUuids = UserSQL::query()
                            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
                            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                            ->where('roles.name', $roleName)
                            ->pluck('users.uuid', 'users.id')->toArray();
        if ($object == SurveyConstant::FAMILY) {
            $familyUuids = $this->getParentUuidByUserId(array_keys($userUuids));

            return $this->getUserByUuid($familyUuids);
        }

        return $this->getUserByUuid($userUuids);
    }

}
