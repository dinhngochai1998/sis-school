<?php
/**
 * @Author apple
 * @Date   May 26, 2022
 */

namespace App\Services;

use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JetBrains\PhpStorm\ArrayShape;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\SisModel\App\Models\ClassAssignment;
use YaangVu\SisModel\App\Models\impl\ClassActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\LmsSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ClassActivitySisService extends ClassActivityService
{
    public function setUpParameter(object $request, int|string $classId): bool
    {
        $classActivityCategory = ClassActivityCategorySQL::whereClassId($classId)->get()->toArray();

        $rules = [
            '*.id'     => 'sometimes|in:' . implode(',', array_column($classActivityCategory, 'id')),
            '*.name'   => 'required',
            '*.weight' => 'required|numeric|min:0'
        ];

        $this->doValidate($request, $rules);

        // validate duplicate name
        $arrName       = array_column($request->all(), 'name');
        $arrNameUnique = array_unique($arrName);
        $nameDuplicate = array_diff_assoc($arrName, $arrNameUnique);

        if (!empty($nameDuplicate)) {
            throw new BadRequestException(
                [
                    'message' => __("validation.duplicate",
                                    ['attribute' => __(implode(',', $nameDuplicate))])
                ], new Exception()
            );
        }

        // validate weight max 100
        $arrWeight = array_column($request->all(), 'weight');
        if (array_sum($arrWeight) > 100) {
            throw new BadRequestException(
                ['message' => __("classActivityCategory.weight_max")], new Exception()
            );
        }

        return parent::setUpParameter($request, $classId);
    }

    public function postSetupParameter(object $request, int $classId, array $idsWasDrop)
    {
        $classActivityCategories       = (new ClassActivityCategoryService())->getViaClassId($classId);
        $classActivityCategoriesIds    = array_column($classActivityCategories->toArray(), 'id');
        $classActivityCategoriesName   = array_column($classActivityCategories->toArray(), 'name');
        $classActivityCategoriesWeight = array_column($classActivityCategories->toArray(), 'weight');

        // get class_activity by class_id
        $classActivities    = (new ClassActivityService())->getClassActivityByClassId($classId);
        $class              = (new ClassService())->get($classId);
        $arrClassActivities = [];
        $arrScore           = [];

        foreach ($classActivities as $classActivity) {
            $categories   = $classActivity->categories;
            $currentScore = 0;
            // add category -> classActivity when add new category
            $classActivityCategoriesIdsAddNew = array_diff($classActivityCategoriesIds,
                                                           array_column($categories, 'id'));
            $this->decorateCategoryAddNewInClassActivity($categories, $classActivityCategories->toArray(),
                                                         $classActivityCategoriesIdsAddNew);

            foreach ($classActivity->categories as $category) {
                if (!in_array($category['id'], $classActivityCategoriesIds)) {
                    // search key category in $categories ---> delete
                    $keyCategoryDelete = array_search($category['id'], array_column($categories, 'id'));
                    array_splice($categories, $keyCategoryDelete, 1);
                } else {
                    // get key category request -> get data name and weight
                    $keyCategoryRequest   = array_search($category['id'], $classActivityCategoriesIds);
                    $weightCategoryUpdate = $classActivityCategoriesWeight[$keyCategoryRequest];
                    $nameCategoryUpdate   = $classActivityCategoriesName[$keyCategoryRequest];

                    // update category
                    $keyCategoryUpdate                        = array_search($category['name'],
                                                                             array_column($categories, 'name'));
                    $categories[$keyCategoryUpdate]['name']
                                                              = $nameCategoryUpdate . '(' . $weightCategoryUpdate . '%)';
                    $categories[$keyCategoryUpdate]['weight'] = $weightCategoryUpdate;

                    // calculator current_score
                    $avgActivityScore = $categories[$keyCategoryUpdate]['avg_activity_score'];
                    $currentScore     += $avgActivityScore == 0
                        ? 0
                        : $avgActivityScore * $categories[$keyCategoryUpdate]['weight'] / 100;
                }
            }

            $gradeLetter = (new GradeLetterService())->getViaScoreAndClassId($currentScore, $class->id);

            $arrClassActivities[] = $this->decorateDataClassActivity($classActivity, $categories, $currentScore,
                                                                     $gradeLetter);

            $arrScore[] = $this->decorateDataScore($class, $currentScore, $classActivity, $gradeLetter);
        }

        // save all score
        ScoreSQL::whereClassId($class->id)->delete();
        DB::table('scores')->insert($arrScore ?? []);

        // delete all class activity in class
        ClassActivityNoSql::whereClassId($class->id)->delete();

        // insert class_activity
        ClassActivityNoSql::query()->insert($arrClassActivities ?? []);

        parent::postSetupParameter($request, $classId, $idsWasDrop);
    }


    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param int    $classId
     * @param object $request
     *
     * @return bool
     */

    public function addActivity(int $classId, object $request): bool
    {
        $isDynamic = $this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT));
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);

        if (!($isDynamic || $isTeacher))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $class           = (new ClassService())->get($classId);
        $classActivities = $this->getClassActivityByClassId($classId);

        if (count($classActivities) <= 0) {
            $this->addActivityWhenClassHasNotScore($request, $classId);

            return true;
        }

        $this->preAddActivityToClassActivity($request, $class->id, $classActivities);
        try {
            $data = $arrScore = [];
            foreach ($classActivities as $classActivity) {
                $avgActivityScoreAdd     = 0;
                $avgActivityScoreDeviant = 0;
                $categories              = $classActivity->categories;
                $categoriesIds           = array_column($categories, 'id');
                foreach ($categories as $keyCategory => $category) {
                    if ($request->category_id == $category['id']) {
                        $categories[$keyCategory]['activities'][]       = [
                            "name"                   => trim($request->name),
                            "score"                  => 0,
                            "max_point"              => $request->max_point,
                            "score_divide_max_point" => 0
                        ];
                        $countActivities                                = count($category['activities']);
                        $avg_activity_score
                                                                        = $category['avg_activity_score'] * $countActivities / ($countActivities + 1);
                        $categories[$keyCategory]['avg_activity_score'] = $avg_activity_score;
                        $avgActivityScoreAdd
                                                                        = $avg_activity_score * $categories[$keyCategory]['weight'] / 100;
                        $avgActivityScoreDeviant
                                                                        = ($category['avg_activity_score'] * $category['weight'] / 100) - $avgActivityScoreAdd;
                    }
                }

                if (in_array($request->category_id, $categoriesIds) == false) {
                    $classActivityCategory = ClassActivityCategorySQL::query()->find($request->category_id);
                    $categories[]          = $this->decorateActivityCategory($classActivityCategory, $request->name,
                                                                             $request->max_point);
                }

                $currentScore = $classActivity['current_score'] - $avgActivityScoreDeviant;

                $gradeLetter = (new GradeLetterService())->getViaScoreAndClassId($currentScore, $class->id);

                $data[] = $this->decorateDataClassActivity($classActivity, $categories, $currentScore, $gradeLetter);

                $arrScore[] = $this->decorateDataScore($class, $currentScore, $classActivity, $gradeLetter);
            }

            // insert activity and score
            $this->insertActivity($data, $classId);
            $this->insertScore($arrScore, $classId);

            $studentIds = ClassAssignmentSQL::query()->where('class_id', $classId)
                                            ->where('assignment', ClassAssignmentConstant::STUDENT)
                                            ->pluck('user_id')->toArray();

            $studentUuidsCategory = ClassActivityNoSql::query()->where('class_id', $classId)->pluck('student_uuid')
                                                      ->toArray();
            $studentIdsCategory   = UserSQL::query()->whereIn('uuid', $studentUuidsCategory)->pluck('id')->toArray();
            $studentIdsCompare    = array_diff($studentIds, $studentIdsCategory);
            if (!empty($studentIdsCompare)) {
                foreach ($studentIdsCompare as $studentId)
                    (new ClassActivitySisService())->addFakeDataViaUserIdAndCLassSisId($studentId, $classId);
            }

            return true;

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param object     $request
     * @param int|string $classId
     * @param            $classActivities
     */

    public function preAddActivityToClassActivity(object $request, int|string $classId, $classActivities)
    {
        $classActivityCategoryId = $this->getClassActivityCategoryIds($classId);
        $activities              = $this->groupActivityNamesViaCategoryId($classActivities, $request->category_id);

        $this->doValidate($request, [
            'name'        => 'required|not_in:' . implode(',', $activities),
            'max_point'   => 'required|numeric|min:1|max:10000',
            'category_id' => 'required|in:' . implode(',', $classActivityCategoryId),
        ]);
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param object $request
     * @param int    $classId
     *
     * @return bool
     */

    public function addActivityWhenClassHasNotScore(object $request, int $classId): bool
    {
        $studentUuid = (new UserService())->getUserUuidByClassId($classId);

        if (empty($studentUuid))
            throw new BadRequestException(
                ['message' => __("classActivity.class_has_not_student")], new Exception()
            );

        $studentCode = UserNoSQL::query()
                                ->whereIn('uuid', $studentUuid)
                                ->pluck('uuid', 'student_code')
                                ->toArray();

        $class                   = (new ClassService())->get($classId);
        $classActivityCategoryId = $this->getClassActivityCategoryIds($classId);

        $this->doValidate($request, [
            'name'        => 'required',
            'max_point'   => 'required|numeric|min:1|max:10000',
            'category_id' => 'required|in:' . implode(',', $classActivityCategoryId),
        ]);

        // get class activity category
        $classActivityCategory = (new ClassActivityCategoryService())->get($request->category_id);

        $classActivities = [];
        $arrScore        = [];
        foreach ($studentUuid as $value) {
            $classActivities[] = [
                "uuid"          => Uuid::uuid(),
                "class_id"      => $classId,
                "student_code"  => array_search($value, $studentCode) ?? '',
                "student_uuid"  => $value,
                "categories"    => [
                    (object)[
                        "id"                 => $classActivityCategory['id'],
                        "name"               => $classActivityCategory['name'] . '(' . $classActivityCategory['weight'] . '%)',
                        "weight"             => $classActivityCategory['weight'],
                        "activities"         => [
                            (object)[
                                "name"                   => $request->name ?? '',
                                "score"                  => 0,
                                "max_point"              => $request->max_point,
                                "score_divide_max_point" => 0
                            ]
                        ],
                        "avg_activity_score" => 0
                    ]
                ],
                "current_score" => 0,
                "final_score"   => 0,
                "url"           => '',
                "school_uuid"   => SchoolServiceProvider::$currentSchool->uuid,
                "grade_letter"  => null
            ];

            $arrScore[] = [
                "class_id"        => $class->id,
                "score"           => 0,
                "user_id"         => UserNoSQL::whereUuid($value)->with('userSql')
                                              ->first()?->userSql?->id ?? null,
                "school_id"       => $class->school_id,
                "is_pass"         => false,
                "grade_letter"    => null,
                "grade_letter_id" => null,
                "current_score"   => 0,
                "real_weight"     => $class?->subject->weight ?? null,
            ];
        }

        // insert activity and score
        $this->insertActivity($classActivities, $classId);
        $this->insertScore($arrScore, $classId);

        return true;
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param int|string $classId
     * @param object     $request
     *
     * @return bool
     */

    public function deleteActivity(int|string $classId, object $request): bool
    {
        $isDynamic = $this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT));
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);

        if (!($isDynamic || $isTeacher))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $class           = (new ClassService())->get($classId);
        $classActivities = $this->getClassActivityByClassId($classId);
        $this->preDeleteActivityToClassActivity($request, $class->id, $classActivities);
        try {
            $data = $arrScore = [];
            foreach ($classActivities as $classActivity) {
                $currentScoreDeviant = 0;
                $categories          = $classActivity->categories;

                foreach ($categories as $keyCategory => $category) {
                    if ($request->category_id == $category['id']) {
                        $activityName      = array_column($category['activities'], 'name');
                        $keyActivityDelete = array_search($request->name, $activityName);

                        // activity delete
                        $activityDelete = $category['activities'][$keyActivityDelete];

                        $countActivities = count($category['activities']);

                        $avgActivityScore                               = $countActivities != 1
                            ? (($category['avg_activity_score'] * $countActivities) - $activityDelete['score_divide_max_point']) / ($countActivities - 1)
                            : 0;
                        $categories[$keyCategory]['avg_activity_score'] = $avgActivityScore;
                        $currentScoreDeviant
                                                                        = ($avgActivityScore - $category['avg_activity_score']) * ($category['weight'] / 100);
                        array_splice($categories[$keyCategory]['activities'], $keyActivityDelete, 1);
                    }
                }

                $currentScore       = $classActivity['current_score'] + $currentScoreDeviant;
                $currentScoreUpdate = ($currentScore < 0) ? 0 : $currentScore;

                $gradeLetter = (new GradeLetterService())->getViaScoreAndClassId($currentScoreUpdate, $class->id);

                $data[] = $this->decorateDataClassActivity($classActivity, $categories, $currentScoreUpdate,
                                                           $gradeLetter);

                $arrScore[] = $this->decorateDataScore($class, $currentScoreUpdate, $classActivity, $gradeLetter);
            }

            // insert activity and score
            $this->insertActivity($data, $classId);
            $this->insertScore($arrScore, $classId);

            return true;

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param object     $request
     * @param int|string $classId
     * @param            $classActivities
     */
    public function preDeleteActivityToClassActivity(object $request, int|string $classId, $classActivities)
    {
        $classActivityCategoryId = $this->getClassActivityCategoryIds($classId);
        $activities              = $this->groupActivityNamesViaCategoryId($classActivities, $request->category_id);

        $this->doValidate($request, [
            'name'        => 'required|in:' . implode(',', $activities),
            'category_id' => 'required|in:' . implode(',', $classActivityCategoryId),
        ],                [
                              'name.in'        => __("classActivity.validate_name",
                                                     ['attribute' => __($request->name)]),
                              'category_id.in' => __("classActivity.validate_category",
                                                     ['attribute' => __($request->category_id)])
                          ]);
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param object     $request
     * @param int|string $classId
     *
     * @return bool
     */

    public function updateActivity(object $request, int|string $classId): bool
    {
        $isDynamic = $this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT));
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);

        if (!($isDynamic || $isTeacher))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $class           = (new ClassService())->get($classId);
        $classActivities = $this->getClassActivityByClassId($classId);
        $this->preUpdateActivityToClassActivity($request, $class->id, $classActivities);
        try {
            $data = $arrScore = [];
            foreach ($classActivities as $classActivity) {
                $categories    = $classActivity->categories;
                $categoriesIds = array_column($categories, 'id');

                // get activity old
                $keyCategoryActivityOld = array_search($request->category_id, $categoriesIds);
                $categoryOld            = $categories[$keyCategoryActivityOld];
                $keyActivityOld         = array_search($request->name,
                                                       array_column($categoryOld['activities'], 'name'));
                $activityOld            = $categoryOld['activities'][$keyActivityOld];

                if ($request->category_id == $request->category_id_update) {
                    $categories[$keyCategoryActivityOld]['activities'][$keyActivityOld] = [
                        "name"                   => trim($request->name_update),
                        "score"                  => $activityOld['score'],
                        "max_point"              => $request->max_point,
                        "score_divide_max_point" => $activityOld['score'] / $request->max_point * 100
                    ];
                } else {
                    // delete activity
                    array_splice($categoryOld['activities'], $keyActivityOld, 1);
                    $categories[$keyCategoryActivityOld] = $categoryOld;

                    // update activity
                    $keyCategoryActivityUpdate                              = array_search($request->category_id_update,
                                                                                           array_column($categories,
                                                                                                        'id'));
                    $categories[$keyCategoryActivityUpdate]['activities'][] = [
                        "name"                   => trim($request->name_update),
                        "score"                  => $activityOld['score'],
                        "max_point"              => $request->max_point,
                        "score_divide_max_point" => $activityOld['score'] / $request->max_point * 100
                    ];

                }

                $currentScore = 0;
                // calculate activity
                foreach ($categories as $key => $category) {
                    $countActivities     = count($category['activities']);
                    $scoreDivideMaxPoint = array_column($category['activities'],
                                                        'score_divide_max_point');
                    $categories[$key]['avg_activity_score']
                                         = $countActivities == 0 ? 0 : array_sum($scoreDivideMaxPoint) / $countActivities;
                    $currentScore        += $categories[$key]['avg_activity_score'] * $categories[$key]['weight'] / 100;
                }

                $gradeLetter = (new GradeLetterService())->getViaScoreAndClassId($currentScore, $class->id);

                $data[] = $this->decorateDataClassActivity($classActivity, $categories, $currentScore, $gradeLetter);

                $arrScore[] = $this->decorateDataScore($class, $currentScore, $classActivity, $gradeLetter);
            }
            // insert activity and score
            $this->insertActivity($data, $classId);
            $this->insertScore($arrScore, $classId);

            return true;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param object     $request
     * @param int|string $classId
     * @param            $classActivities
     */

    public function preUpdateActivityToClassActivity(object $request, int|string $classId, $classActivities)
    {
        $classActivityCategoryId           = $this->getClassActivityCategoryIds($classId);
        $activitiesNameViaCategoryId       = $this->groupActivityNamesViaCategoryId($classActivities,
                                                                                    $request->category_id);
        $activitiesNameViaCategoryIdUpdate = $this->groupActivityNamesViaCategoryId($classActivities,
                                                                                    $request->category_id_update);

        $rules = [
            'name'               => 'required|in:' . implode(',', $activitiesNameViaCategoryId),
            'max_point'          => 'required|numeric|min:1|max:10000',
            'category_id'        => 'required|in:' . implode(',', $classActivityCategoryId),
            'category_id_update' => 'required|in:' . implode(',', $classActivityCategoryId),
        ];

        if (($request->name == $request->name_update && $request->category_id == $request->category_id_update)) {
            $rules['name_update'] = 'required';
        } else {
            $rules['name_update'] = 'required|not_in:' . implode(',', $activitiesNameViaCategoryIdUpdate);
        }

        $messages = [
            'name.in'            => __("classActivity.validate_name",
                                       ['attribute' => __($request->name)]),
            'name_update.not_in' => __("classActivity.validate_name_update",
                                       ['attribute' => __($request->name_update)]),
        ];

        $this->doValidate($request, $rules, $messages);
    }

    /**
     * @Author apple
     * @Date   Jun 01, 2022
     *
     * @param object $request
     * @param int    $classId
     *
     * @return bool
     */

    public function updateScoreToClassActivity(object $request, int $classId): bool
    {
        $isDynamic = $this->hasPermission(PermissionConstant::class(PermissionActionConstant::EDIT));
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);

        if (!($isDynamic || $isTeacher))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $class    = (new ClassService())->get($classId);
        $maxPoint = $this->getMaxPointToClassActivity($request, $classId);
        $data     = $arrScore = [];

        $classActivities = ClassActivityNoSql::query()->where('class_id', $classId)->get();
        $score           = [];
        foreach ($request->all() as $reqItem) {
            $classActivity = $classActivities->where('_id', $reqItem['id'])->first();
            $categories    = $classActivity['categories'];
            $categoriesIds = array_column($categories, 'id');

            $keyCategories = array_column($categories, 'activities', 'id');

            // validate request activity
            $reActivities = $reqItem['activities'];
            $nameActivity = [];
            foreach ($reActivities as $activity) {
                $maxPointActivity = $maxPoint[$activity['name'] . '-' . $activity['category_id']] ?? null;
                $this->doValidate((object)$activity, [
                    'name'  => 'in:' . implode(',',
                                               array_column($keyCategories[$activity['category_id']], 'name')),
                    'score' => 'required|numeric|min:1|lte:' . $maxPointActivity,
                ],
                                  [
                                      'name.in'   => __("classActivity.validate_name",
                                                        ['attribute' => __($activity['name'])]),
                                      'score.lte' => __("classActivity.validate_score",
                                                        ['attribute' => __($activity['score'])]),
                                  ]);

                // update activity
                $keyCategoryUpdate = array_search($activity['category_id'],
                                                  $categoriesIds);
                $groupNameActivity
                                   = array_column($categories[$keyCategoryUpdate]['activities'],
                                                  'name');
                $keyActivity       = array_search($activity['name'],
                                                  $groupNameActivity);
                $activityUpdate
                                   = $categories[$keyCategoryUpdate]['activities'][$keyActivity];

                $categories[$keyCategoryUpdate]['activities'][$keyActivity] = [
                    "name"                   => $activity['name'],
                    "score"                  => $activity['score'],
                    "max_point"              => $activityUpdate['max_point'],
                    "score_divide_max_point" => $activity['score'] / $activityUpdate['max_point'] * 100,
                ];
            }

            // calculate activity
            $currentScore = 0;
            foreach ($categories as $key => $category) {
                $countActivities = count($category['activities']);
                if ($countActivities != 0) {
                    $scoreDivideMaxPoint                    = array_column($category['activities'],
                                                                           'score_divide_max_point');
                    $categories[$key]['avg_activity_score'] = array_sum($scoreDivideMaxPoint) / $countActivities;
                    $currentScore                           += $categories[$key]['avg_activity_score'] * $categories[$key]['weight'] / 100;
                }

            }

            $gradeLetter = (new GradeLetterService())->getViaScoreAndClassId($currentScore, $class->id);

            $data = $this->decorateDataClassActivity($classActivity, $categories, $currentScore, $gradeLetter);

            $score[] = $this->decorateDataScore($class, $currentScore, $classActivity, $gradeLetter);

            $this->refreshScore($data, $score, $reqItem['id'], $classId);
        }
        $this->insertScore($score, $classId);

        return true;
    }

    public function refreshScore($data, $score, $IdClassActivity, $classId)
    {
        // refresh class activity
        ClassActivityNoSql::query()->where('_id', $IdClassActivity)->delete();
        ClassActivityNoSql::query()->insert($data ?? []);
    }

    public function getMaxPointToClassActivity(object $request, int $classId): array
    {
        $keyActivity  = [];
        $nameActivity = [];
        $idsCategory  = [];

        $classActivity = ClassActivityNoSql::query()->where('class_id', $classId)->first();
        $categories    = $classActivity['categories'];
        if ($categories) {
            foreach ($categories as $category) {
                foreach ($category['activities'] as $activity) {
                    $keyActivity[$activity['name'] . '-' . $category['id']] = $activity['max_point'];
                    $nameActivity[]                                         = $activity['name'];
                    $idsCategory[]                                          = $category['id'];
                }
            }
        }

        $this->doValidate($request, [
            "*.activities.*.name"        => "required|in:" . implode(',', $nameActivity),
            "*.activities.*.category_id" => "required|in:" . implode(',', array_unique($idsCategory))
        ]);

        return $keyActivity;
    }

    public function decorateDataClassActivity($classActivity, $categories, $currentScore, $gradeLetter): array
    {
        return [
            "uuid"          => $classActivity['uuid'],
            "class_id"      => $classActivity['class_id'],
            "student_code"  => $classActivity['student_code'],
            "student_uuid"  => $classActivity['student_uuid'],
            "categories"    => (array)$categories,
            "current_score" => $currentScore,
            "final_score"   => $currentScore,
            "url"           => $classActivity['url'],
            "school_uuid"   => $classActivity['school_uuid'],
            "grade_letter"  => $gradeLetter->letter ?? null,
        ];
    }

    #[ArrayShape(["class_id" => "mixed", "score" => "", "user_id" => "mixed", "school_id" => "mixed", "is_pass" => "bool", "grade_letter" => "mixed", "grade_letter_id" => "mixed", "current_score" => "", "real_weight" => "mixed"])]
    public function decorateDataScore($class, $currentScore, $classActivity, $gradeLetter): array
    {
        return [
            "class_id"        => $class->id,
            "score"           => $currentScore,
            "user_id"         => UserNoSQL::whereUuid($classActivity['student_uuid'])->with('userSql')
                                          ->first()?->userSql?->id ?? null,
            "school_id"       => $class->school_id,
            "is_pass"         => $currentScore >= ($class->subject->gradeScale->score_to_pass ?? 0),
            "grade_letter"    => $gradeLetter->letter ?? null,
            "grade_letter_id" => $gradeLetter->id ?? null,
            "current_score"   => $currentScore,
            "real_weight"     => $class?->subject->weight ?? null,
        ];
    }

    public function decorateCategoryAddNewInClassActivity(&$categories, array $classActivityCategories,
                                                          array $classActivityCategoriesIdsAddNew)
    {
        foreach ($classActivityCategoriesIdsAddNew as $classActivityCategoriesId) {
            $keyClassActivityCategory    = array_search($classActivityCategoriesId,
                                                        array_column($classActivityCategories, 'id'));
            $classActivityCategoryName   = $classActivityCategories[$keyClassActivityCategory]['name'];
            $classActivityCategoryWeight = $classActivityCategories[$keyClassActivityCategory]['weight'];

            $categories[] = [
                "id"                 => $classActivityCategoriesId,
                "name"               => $classActivityCategoryName . '(' . $classActivityCategoryWeight . '%)',
                "weight"             => $classActivityCategoryWeight,
                "activities"         => [],
                "avg_activity_score" => 0
            ];
        }

        return $categories;
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

    public function insertScore(array $arrScore, $classId)
    {
        ScoreSQL::whereClassId($classId)->delete();
        DB::table('scores')->insert($arrScore ?? []);
    }

    public function insertActivity(array $data, int $classId)
    {
        ClassActivityNoSql::query()->where('class_id', $classId)->delete();
        ClassActivityNoSql::query()->insert($data);
    }

    public function getDataFakeActivitiesClassSis(int $classId): array
    {
        $classActivitiesCategories = ClassActivityNoSql::query()->where('class_id', $classId)
                                                       ->select('categories')
                                                       ->first();

        $activities = [];

        foreach ($classActivitiesCategories['categories'] as $keyClassActivitiesCategories => $classActivitiesCategory) {
            $activities[$keyClassActivitiesCategories]['id']                 = $classActivitiesCategory['id'];
            $activities[$keyClassActivitiesCategories]['name']               = $classActivitiesCategory['name'];
            $activities[$keyClassActivitiesCategories]['weight']             = $classActivitiesCategory['weight'];
            $activities[$keyClassActivitiesCategories]['avg_activity_score'] = 0;
            foreach ($classActivitiesCategory['activities'] as $activityCategory) {
                $activity['name']                                          = $activityCategory['name'];
                $activity['score']                                         = 0;
                $activity['max_point']                                     = $activityCategory['max_point'];
                $activity['score_divide_max_point']                        = 0;
                $activities[$keyClassActivitiesCategories]['activities'][] = $activity;
            }
        }

        return $activities;
    }

    public function addFakeDataViaUserIdAndCLassSisId(int $userId, int $classId): ClassActivityNoSql
    {
        $userSql = UserSQL::whereId($userId)->with('userNoSql')->first();
        $school  = SchoolServiceProvider::$currentSchool;

        $classActivityNoSql               = new ClassActivityNoSql();
        $classActivityNoSql->class_id     = $classId;
        $classActivityNoSql->uuid         = Uuid::uuid();
        $classActivityNoSql->student_code = $userSql->userNoSql->student_code;
        $classActivityNoSql->student_uuid = $userSql->userNoSql->uuid;
        $classActivityNoSql->school_uuid  = $school->uuid;

        $classActivityNoSql->categories    = $this->getDataFakeActivitiesClassSis($classId);
        $classActivityNoSql->source        = $classActivityNoSql->lms_name;
        $classActivityNoSql->grade_letter  = null;
        $classActivityNoSql->final_score   = 0;
        $classActivityNoSql->current_score = 0;
        $classActivityNoSql->save();

        return $classActivityNoSql;
    }

    public function syncStudentAssignmentToClassActivitySis(): bool
    {
        $lmsIds           = LmsSQL::query()->where('name', LmsSystemConstant::SIS)
                                  ->pluck('id')->toArray();
        $classIds         = ClassSQL::query()->whereIn('lms_id', $lmsIds)->pluck('id')->toArray();
        $classAssignments = ClassAssignmentSQL::whereAssignment(ClassAssignmentConstant::STUDENT)
                                              ->whereIn('class_id', $classIds)->get();
        foreach ($classAssignments as $classAssignment) {
            $countActivity = ClassActivityNoSql::query()->where('class_id', $classAssignment->class_id)->count();
            if (!$this->getViaUserIdAndClassSisId($classAssignment->user_id,
                                                  $classAssignment->class_id) && $countActivity != 0)
                $this->addFakeDataViaUserIdAndCLassSisId($classAssignment->user_id, $classAssignment->class_id);

        }

        return true;
    }

    public function getViaUserIdAndClassSisId(int $userId, int $classId): ClassActivityNoSql|Builder|null
    {
        $studentUuid = UserSQL::query()->where('id', $userId)->first();

        return $this->model->where('student_uuid', $studentUuid->uuid)
                           ->where('class_id', $classId)
                           ->first();
    }
}
