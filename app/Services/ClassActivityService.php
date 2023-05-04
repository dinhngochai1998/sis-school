<?php

namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Jenssegers\Mongodb\Eloquent\Builder as MBuilder;
use JetBrains\PhpStorm\ArrayShape;
use stdClass;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\LmsSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ClassActivityService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait, ElasticsearchHelper;

    public Model|Builder|ClassActivityNoSql $model;

    /**
     * @param string $title
     * @param array  $messages
     * @param string $email
     *
     * @return string
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

    public function createModel(): void
    {
        $this->model = new ClassActivityNoSql();
    }

    /**
     * @param object $request
     * @param int    $classId
     *
     * @return bool
     * @throws Exception
     */
    public function importActivityScore(object $request, int $classId): bool
    {
        $this->doValidate($request, [
            'file_url' => 'required',
        ]);
        $class = (new ClassService())->get($classId);
        $lms   = LmsSQL::whereId($class->lms_id)->first();
        if ($lms?->name !== LmsSystemConstant::SIS)
            throw new BadRequestException(['message' => __("validation.class-not-in-lms-system",
                                                           ['attribute' => __("class $class->name")])],
                                          new Exception());
        $fileUrl = $request->file_url;
        // 4 = ".com" length
        $filePath = substr($fileUrl, strpos($fileUrl, ".com") + 4);
        $body     = [
            'url'         => $fileUrl,
            'file_path'   => $filePath,
            'class_id'    => $class->id,
            'school_uuid' => SchoolServiceProvider::$currentSchool->uuid,
            'email'       => UserNoSQL::whereUsername(ClassActivityService::currentUser()?->username)->first()?->email
        ];

        $this->pushToQueue($body, 'import_activity_score');
        $this->createELS('import_activity_score',
                         self::currentUser()->username . " import activity score at : " . Carbon::now()->toDateString(),
                         [
                             'file_url' => $body['url']
                         ]);

        return true;
    }

    /**
     * @Author Edogawa Conan
     * @Date   Aug 30, 2021
     *
     * @param int $classId
     *
     * @return array
     */
    public function getViaClassId(int $classId): array
    {
        $class = (new ClassService())->get($classId);
        if ($class->external_id)
            return $this->getLmsClassViaClassSQL($class);
        else
            return $this->getSisClassViaClassSQL($class);
    }

    public function getLmsClassViaClassSQL(ClassSQL $class): array
    {
        return [];
    }

    #[ArrayShape(["id" => "mixed", "name" => "string", "weight" => "mixed", "activities" => "array[]", "avg_activity_score" => "int"])]
    public function decorateActivityCategory($classActivityCategory, $name = null, $max_point = null): array
    {
        return [
            "id"                 => $classActivityCategory->id,
            "name"               => $classActivityCategory->name . '(' . $classActivityCategory->weight . '%)',
            "weight"             => $classActivityCategory->weight,
            "activities"         => [
                [
                    "name"                   => $name,
                    "score"                  => 0,
                    "max_point"              => $max_point,
                    "score_divide_max_point" => 0
                ]
            ],
            "avg_activity_score" => 0
        ];
    }

    public function getClassActivityCategoryIds(int $classId): array
    {
        return ClassActivityCategorySQL::query()->where('class_id', $classId)->pluck('id')->toArray();
    }

    public function groupActivityNames($classActivities): array
    {
        $activities = [];
        foreach ($classActivities[0]['categories'] as $category) {
            $activitiesItem = array_column($category['activities'], 'name');
            $activities     = array_merge($activitiesItem, $activities);
        }

        return $activities;
    }

    public function groupActivityNamesViaCategoryId($classActivities = [], $category_id = null): array
    {
        $activities = [];
        foreach ($classActivities[0]['categories'] as $category) {
            if ($category['id'] === $category_id) {
                $activitiesItem = array_column($category['activities'], 'name');
                $activities     = array_merge($activitiesItem, $activities);
            }
        }

        return $activities;
    }

    public function getUserUuidsAssignable(int $classId): array
    {
        return ClassAssignmentSQL::whereAssignment(ClassAssignmentConstant::STUDENT)
                                 ->whereClassId($classId)
                                 ->join('users', 'users.id', '=', 'class_assignments.user_id')
                                 ->pluck('users.uuid')
                                 ->toArray();
    }

    protected function getAllViaClassId(int $classId, array|object $request): Collection|array
    {
        $search      = $request['search'] ?? null;
        $studentId   = $request['student_id'] ?? null;
        $userService = new UserService();

        return $this->model->with(['student', 'classActivityCategories.activityClassLms'])
                           ->where('class_id', $classId)
                           ->when($studentId, function ($q) use ($studentId, $userService) {
                               $userNoSql = $userService->get($studentId);
                               $q->where('student_uuid', $userNoSql->uuid);
                           })
                           ->when($search, function ($q) use ($search, $userService) {
                               $param             = new stdClass();
                               $param->fields     = ['full_name', 'student_code'];
                               $param->search_key = $search;
                               $uuids             = $userService->getForFilerOperatorLike($param)
                                                                ->pluck('uuid');

                               $q->whereIn('student_uuid', $uuids);
                           })
                           ->orderBy('student_code', 'DESC')
                           ->get();
    }

    #[ArrayShape(['status_import_score' => "mixed", 'avg_score' => "mixed", 'score_lists' => "array|\Illuminate\Database\Eloquent\Collection"])]
    private function getSisClassViaClassSQL(ClassSQL $class): array
    {
        $request         = request()->all();
        $classActivities = $this->getAllViaClassId($class->id, $request);

        // get status import score
        $statusImportScore = (new DossierService)->getStatusImportScore($class->id);

        $totalCurrentScore = 0;
        $totalFinalScore   = 0;
        $countActivities   = count($classActivities);
        $groupActivities   = [];
        foreach ($classActivities as $classActivity) {
            $classActivity->final_score = $class->status == StatusConstant::CONCLUDED ?
                $classActivity->final_score : 0;

            $totalCurrentScore += $classActivity?->current_score ?? 0;
            $totalFinalScore   += $classActivity?->final_score ?? 0;
            $totalPercentageScore = 0;
            foreach ($classActivity['categories'] ?? [] as $category) {
                foreach ($category['activities'] ?? [] as $activity) {
                    $groupActivities['_' . $activity['name'] . '_' . $category['id']] = [
                        'total' => (($groupActivities['_' . $activity['name'] . '_' . $category['id']]['total'] ?? 0) + (float)$activity['score_divide_max_point']),
                        'count' => (($groupActivities['_' . $activity['name'] . '_' . $category['id']]['count'] ?? 0) + 1),
                    ];
                    $totalPercentageScore += $activity['max_point'] != 0 ? $activity['score']/ $activity['max_point'] : 0;
                }
                // $countActivities = count($category['activities']);
                $category['avg_categories'] = $countActivities != 0 ? ($totalPercentageScore/$countActivities) * 100 : 0;
            }
        }
        $avgScore = [];

        foreach ($groupActivities as $key => $groupActivity) {
            $avgScore[$key] = $groupActivity['total'] / $groupActivity['count'];
        }

        $currentScore = $countActivities !== 0 ? $totalCurrentScore / $countActivities : 0;
        $avgScore     = array_merge($avgScore, [
            'current_score' => $currentScore,
            'final_score'   => $countActivities !== 0 ? $totalFinalScore / $countActivities : 0,
            'grade_letter'  => (new GradeLetterService())
                    ->getViaScoreAndClassId($currentScore, $class->id)?->letter ?? null
        ]);
        return [
            'status_import_score' => $statusImportScore,
            'avg_score'           => $avgScore,
            'score_lists'         => $classActivities
        ];
    }

    /**
     * @Author yaangvu
     * @Date   Aug 23, 2021
     *
     * @param string|null $schoolUuid
     * @param int|null    $classId
     * @param string|null $studentUuid
     *
     * @return Model|MBuilder|ClassActivityNoSql|null
     */
    function getBySchoolUuidAndClassIdAndStudentUuid(?string $schoolUuid, ?int $classId,
                                                     ?string $studentUuid): Model|MBuilder|ClassActivityNoSql|null
    {
        return $this->model->whereSchoolUuid($schoolUuid)
                           ->whereClassId($classId)
                           ->whereStudentUuid($studentUuid)
                           ->first();
    }

    public function getClassActivityByClassId(int $classId): Collection|array
    {
        return ClassActivityNoSql::with('student')->where('class_id', '=', $classId)->get();
    }

    public function getFirstClassActivityByClassId(int $classId)
    {
        return ClassActivityNoSql::with('student')->where('class_id', '=', $classId)->first();
    }

    /**
     * @Description setup parameter for class activity
     *
     * @Author      kyhoang
     * @Date        May 26, 2022
     *
     * @param object     $request
     * @param int|string $classId
     *
     * @return bool
     */
    public function setUpParameter(object $request, int|string $classId): bool
    {
        $class  = (new ClassService())->get($classId);
        $arrIds = [];
        if ($request instanceof Request)
            $arrReq = $request->all();
        else
            $arrReq = $request->toArray();

        // CAG = class activity category
        $CAGIds = ClassActivityCategorySQL::whereClassId($class->id)->pluck('id')->toArray();

        foreach ($arrReq as $req) {
            $id = ($req['id'] ?? null) == "" ? null : $req['id'];

            if (!empty($id))
                $arrIds[] = $id;

            ClassActivityCategorySQL::query()->updateOrCreate([
                                                                  'id' => $id,
                                                              ], [
                                                                  'name'       => $req['name'] ?? null,
                                                                  'weight'     => $req['weight'] ?? null,
                                                                  'class_id'   => $class->id,
                                                                  'max_point'  => $req['max_point'] ?? null,
                                                                  'is_default' => ($req['name'] ?? null) == ClassActivityCategoryService::DEFAULT_NAME
                                                              ]
            );
        }

        $idsWasDrop = array_diff($CAGIds, $arrIds);
        ClassActivityCategorySQL::query()->whereIn('id', $idsWasDrop)->forceDelete();

        $this->postSetupParameter($request, $classId, $idsWasDrop);

        return true;
    }

    public function postSetupParameter(object $request, int $classId, array $idsWasDrop)
    {
        // TODO Something
    }

    public function getViaUserIdAndClassId(int $userId, int $classId): ClassActivityNoSql|Builder|null
    {
        return $this->model->where('user_id', $userId)
                           ->where('class_id', $classId)
                           ->first();
    }

}
