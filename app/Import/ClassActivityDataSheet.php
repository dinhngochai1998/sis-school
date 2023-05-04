<?php

namespace App\Import;

use App\Services\ClassActivityService;
use App\Services\ClassService;
use App\Services\DossierService;
use App\Services\GradeLetterService;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use DB;
use Exception;
use Faker\Provider\Uuid;
use Log;
use Maatwebsite\Excel\Concerns\ToArray;
use stdClass;
use Validator;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\ClassActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class  ClassActivityDataSheet implements ToArray
{
    /**
     * @param array $array
     *
     * @return array
     * @throws Exception
     */
    public function array(array $array): array
    {
        $dossier              = new stdClass();
        $dossier->file_url    = ClassActivityImport::$url;
        $dossier->action      = 'IMPORT_SCORE';
        $dossier->status      = StatusConstant::PENDING;
        $dossier->school_uuid = ClassActivityImport::$school_uuid;
        $dossier->class_id    = ClassActivityImport::$classId;

        $dossier = (new DossierService())->add($dossier);

        if (ClassActivityCategoriesSheet::$stop) {
            // delete dossier
            $dossier->forceDelete();

            return [];

        }

        $groupSheetCategories    = ClassActivityCategoriesSheet::$groupCategories;
        $activitySheetCategories = ClassActivityCategoriesSheet::$activities;
        $maxPointSheetCategories = ClassActivityCategoriesSheet::$maxPoint;
        $class_id                = ClassActivityImport::$classId;
        $class                   = (new ClassService())->get($class_id);
        $userUuids               = ClassAssignmentSQL::whereClassId($class->id)
                                                     ->whereAssignment(ClassAssignmentConstant::STUDENT)
                                                     ->join('users', 'users.id', '=', 'user_id')
                                                     ->pluck('users.uuid');
        $studentCodes            = UserNoSQL::whereIn('uuid', $userUuids)
                                            ->pluck('student_code')
                                            ->toArray();

        unset($array[0][0]);
        $rules = [
            '0'   => 'max:' . count($activitySheetCategories) + 1 . '|' . 'min:' . count($activitySheetCategories) - 1,
            '0.*' => 'in:' . implode(',', $activitySheetCategories)
        ];

        foreach ($array as $key => $data) {
            if ($key == 0)
                continue;

            foreach ($data as $ruleKey => $ruleItem) {
                if ($ruleKey == 0) {
                    $scoreRule["$key.0"] = 'required|in:' . implode(',', $studentCodes);
                    continue;
                }
                $scoreRule["$key.$ruleKey"] = 'required|numeric';

            }
        }


        $customMessages = [
            'max'      => "The activity doesn't math activity of category sheet",
            'min'      => "The activity doesn't math activity of category sheet",
            'in'       => "The data invalid in line :attribute please review on data sheet",
            'required' => "Field is required in line :attribute on data sheet",
            'numeric'  => "Field must be a number in line :attribute on data sheet",
            'exists'   => 'The student_code invalid in line :attribute please review on data sheet',
        ];
        $rules          = array_merge($rules, $scoreRule ?? []);
        $validator      = Validator::make($array, $rules, $customMessages);
        if ($validator->fails()) {
            $messages = $validator->errors()->messages();
            $title    = '[SIS] error when import activity score';
            $error    = ClassActivityService::sendMailWhenFalseValidateImport($title, $messages,
                                                                              ClassActivityImport::$email
            );

            Log::error('[IMPORT ACTIVITY SCORE] validation false when import activity score on sheet data' . $error);

            // delete dossiers
            $dossier->forceDelete();

            return [];
        }
        $uuid = Uuid::uuid();

        $totalWeigh = ClassActivityCategorySQL::whereClassId($class->id)->sum('weight');
        foreach ($array as $key => $item) {
            if ($key == 0) {
                continue;
            }
            $totalAvgScoreGroupCategory = 0;
            Log::info('[IMPORT ACTIVITY SCORE] create class activity with student_code : ' . $item[0]);
            $categories = [];
            foreach ($groupSheetCategories as $groupCategoryKey => $groupCategory) {
                $activityCategory   = [];
                $scoreGroupCategory = 0;
                foreach ($groupCategory as $groupCategoryItem) {
                    $activityKey = array_search($groupCategoryItem, $activitySheetCategories);
                    $score       = $item[$activityKey];
                    $maxPoint    = $maxPointSheetCategories[$activityKey];
                    if ($score > $maxPoint) {
                        // send mail false via rabbitMQ
                        $now       = Carbon::now()->toDateTimeString();
                        $mail      = new MailWithRabbitMQ();
                        $titleError
                                   = "[SIS] import activity score false for score : $score is greater than max point: $maxPoint in activity $groupCategoryItem";
                        $bodyError = "import activity score false in : $now , please contact admin";
                        $mail->sendMails($titleError, $bodyError, [ClassActivityImport::$email]);

                        // delete dossier
                        $dossier->forceDelete();

                        return [];
                    }
                    $activity            = new stdClass();
                    $activity->name      = $groupCategoryItem;
                    $activity->score     = $score;
                    $activity->max_point = $maxPoint;
                    $activity->score_divide_max_point
                                         = ((int)$item[$activityKey] / (int)$maxPointSheetCategories[$activityKey]) * 100;
                    $scoreGroupCategory  += $activity->score_divide_max_point;
                    $activityCategory[]  = $activity;
                }
                $classActivityCategoriesSQL = ClassActivityCategorySQL::whereClassId($class->id)
                                                                      ->whereName($groupCategoryKey)
                                                                      ->first();

                Log::info('[IMPORT ACTIVITY SCORE] with classActivityCategoriesSQL id : ' . $classActivityCategoriesSQL->id);

                $avgScoreActivity = ($scoreGroupCategory / count($activityCategory));

                $totalAvgScoreGroupCategory += $avgScoreActivity * ($classActivityCategoriesSQL->weight ?? 0);
                $categories[]               = (object)[
                    'id'                 => $classActivityCategoriesSQL->id,
                    'name'               => $classActivityCategoriesSQL->name . '(' . ($classActivityCategoriesSQL->weight ?? 0) . '%)',
                    'weight'             => $classActivityCategoriesSQL->weight ?? null,
                    'activities'         => $activityCategory ?? [],
                    'avg_activity_score' => $avgScoreActivity
                ];
            }

            $currentScore = $totalAvgScoreGroupCategory / ($totalWeigh ?? 0);

            $gradeLetter = (new GradeLetterService())->getViaScoreAndClassId($currentScore, $class->id);

            $arrScore[] = [
                'score'           => $currentScore,
                'class_id'        => $class->id,
                'user_id'         => UserNoSQL::whereStudentCode($item[0])->with('userSql')
                                              ->first()?->userSql?->id ?? null,
                'school_id'       => $class->school_id,
                'is_pass'         => $currentScore >= $class->subject->gradeScale->score_to_pass,
                'grade_letter'    => $gradeLetter->letter ?? null,
                'grade_letter_id' => $gradeLetter->id ?? null,
                'current_score'   => $currentScore,
                'real_weight'     => $class?->subject->weight ?? null,
            ];

            $classActivities[] = [
                'uuid'          => $uuid,
                'class_id'      => $class->id,
                'student_code'  => $item[0],
                'student_uuid'  => UserNoSQL::whereStudentCode($item[0])->first()?->uuid ?? null,
                'categories'    => $categories ?? [],
                'current_score' => $currentScore,
                'final_score'   => $currentScore,
                'url'           => ClassActivityImport::$url,
                'school_uuid'   => ClassActivityImport::$school_uuid,
                'grade_letter'  => $gradeLetter->letter ?? null
            ];
        }
        try {
            // save all score
            ScoreSQL::whereClassId($class->id)->delete();
            DB::table('scores')->insert($arrScore ?? []);

            // delete all class activity in class
            ClassActivityNoSql::whereClassId($class->id)->delete();

            Log::info("[IMPORT ACTIVITY SCORE] success delete classActivitySql with class_id : $class->id");

            ClassActivityNoSql::insert($classActivities ?? []);

            // send mail success via rabbitMQ
            $mail         = new MailWithRabbitMQ();
            $titleSuccess = '[SIS] import activity score success for class name : ' . $class->name;
            $bodySuccess  = 'import activity score success in : ' . Carbon::now()->toDateTimeString();
            $mail->sendMails($titleSuccess, $bodySuccess, [ClassActivityImport::$email]);
            Log::info('[IMPORT ACTIVITY SCORE] success insert batch to ClassActivityNoSql');

            // delete dossier
            $dossier->forceDelete();
        } catch (Exception $e) {
            // send mail false via rabbitMQ
            $now        = Carbon::now()->toDateTimeString();
            $mail       = new MailWithRabbitMQ();
            $titleError = '[SIS] import activity score false for class name : ' . $class->name;
            $bodyError  = "import activity score false in : $now , please contact admin";
            $mail->sendMails($titleError, $bodyError, [ClassActivityImport::$email]);
            Log::error('[IMPORT ACTIVITY SCORE] false to insert batch ClassActivityNoSql with error : ' . $e->getMessage());
        }

        return $array;
    }
}
