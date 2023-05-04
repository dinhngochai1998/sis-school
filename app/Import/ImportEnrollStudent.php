<?php

namespace App\Import;


use App\Services\ClassActivityLmsService;
use App\Services\ClassActivitySisService;
use App\Services\ClassAssignmentService;
use App\Services\ClassService;
use App\Services\impl\MailWithRabbitMQ;
use App\Services\UserService;
use App\Services\ZoomMeetingService;
use App\Traits\AgilixTraits;
use App\Traits\EdmentumTraits;
use Carbon\Carbon;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use stdClass;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\LmsSQL;
use YaangVu\SisModel\App\Models\impl\SchoolSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ImportEnrollStudent implements ToArray, SkipsEmptyRows, WithCalculatedFormulas, WithHeadingRow
{
    use EdmentumTraits, AgilixTraits;

    public static ?string $email;
    public static string  $schoolUuid;
    public static int     $classId;
    public static int     $lmsId;

    public function __construct(?string $email, string $schoolUuid, int $classId, int $lmsId)
    {
        self::$email      = $email;
        self::$schoolUuid = $schoolUuid;
        self::$classId    = $classId;
        self::$lmsId      = $lmsId;
    }

    /**
     * @throws Exception
     */
    public function array(array $array): array
    {
        $collect     = collect([]);
        $messagesErr = [];

        // set current school
        SchoolServiceProvider::$currentSchool = SchoolSQL::whereUuid(self::$schoolUuid)->first();

        // get student_code already assign to class
        $studentCodes = (new UserService())->getArrAssignableStudentCodes(self::$classId);

        foreach ($array as $key => $row) {
            // validate check duplicate student_id
            $checkCollect = $collect->where('student_id', $row['student_id']);

            if (count($checkCollect) != 0) {
                $messagesErr[] = ["The student_id duplicate in line:"
                                  . ($key + 1) . " please check data sheet"];
                continue;
            }

            // add item to collect
            $collect->push([
                               'student_id' => $row['student_id'],
                           ]);
        }

        // validate
        $rules = [
            '*.student_id' => 'required|in:' . implode(',', $studentCodes)
        ];

        $customMessages = [
            '*.student_id.in' => "The student_id invalid in line :attribute please check data sheet"
        ];

        $validator = Validator::make($array, $rules, $customMessages);

        if ($validator->fails() || $messagesErr != []) {
            $messages = array_merge($validator->errors()->messages(), $messagesErr);
            $title    = '[SIS] error when import enroll student';
            $error    = ClassService::sendMailWhenFalseValidateImport($title, $messages, self::$email);
            Log::error('[IMPORT ENROLL STUDENT] validation false when import enroll student on sheet data' . $error);

            return [];
        }

        // get user_id by student_code
        $studentIdsInFileCsv = array_column($array, 'student_id');
        $studentIds          = explode(',', implode(',', $studentIdsInFileCsv));

        // dd($studentIds);
        $userIds = UserNoSQL::query()
                            ->with('userSql')
                            ->whereIn('student_code', $studentIds)
                            ->get()
                            ->pluck('userSql.id')
                            ->toArray();

        // get name lms
        $lmsName = LmsSQL::whereId(self::$lmsId)->first()?->name;

        //count activity
        $countActivity = ClassActivityNoSql::query()->whereClassId(self::$classId)
                                           ->count();

        // insert data to class_assignments
        foreach ($userIds as $userId) {
            $uuid = Uuid::uuid();

            $classAssignment = ClassAssignmentSQL::query()->updateOrCreate(
                [
                    'user_id'    => $userId,
                    'class_id'   => self::$classId,
                    'assignment' => ClassAssignmentConstant::STUDENT
                ],
                [
                    'user_id'    => $userId,
                    'class_id'   => self::$classId,
                    'assignment' => ClassAssignmentConstant::STUDENT,
                    'uuid'       => $uuid,
                    'status'     => StatusConstant::ACTIVE
                ]
            );

            // push assign student to lms
            if ($lmsName && $lmsName !== LmsSystemConstant::SIS)
                switch ($lmsName) {
                    case LmsSystemConstant::EDMENTUM :
                        $this->assignUsersToClassToEdmentum($classAssignment->id, RoleConstant::STUDENT);
                        break;
                    default :
                        $this->assignUsersToClassToAgilix($classAssignment->id, RoleConstant::STUDENT);
                        break;
                }

            if ($lmsName && $lmsName == LmsSystemConstant::SIS) {
                if (!(new ClassActivitySisService())->getViaUserIdAndClassSisId($userId,
                                                                                self::$classId) && $countActivity != 0)
                    (new ClassActivitySisService())->addFakeDataViaUserIdAndCLassSisId($userId, self::$classId);
            } else {
                $classActivityLmsService = new ClassActivityLmsService();
                if (!$classActivityLmsService->getViaUserIdAndClassId($userId, self::$classId))
                    $classActivityLmsService->addFakeDataViaUserIdAndCLassId($userId, self::$classId);
            }
        }

        (new ZoomMeetingService())->assignStudentsToVcrCViaClassIdAndUserIds(self::$classId, $userIds);

            // send mail success via rabbitMQ
            $mail         = new MailWithRabbitMQ();
            $titleSuccess = '[SIS] import enroll student';
            $bodySuccess  = 'import enroll student success in : ' . Carbon::now()->toDateTimeString();
            $mail->sendMails($titleSuccess, $bodySuccess, [self::$email]);
            Log::info('[IMPORT ENROLL STUDENT] success insert to class_assignments');

        return $array;
    }
}
