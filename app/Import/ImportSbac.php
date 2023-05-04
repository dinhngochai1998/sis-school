<?php
/**
 * @Author Dung
 * @Date   Mar 11, 2022
 */

namespace App\Import;

use App\Services\ClassActivityService;
use App\Services\impl\MailWithRabbitMQ;
use App\Services\UserService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use MongoDB\BSON\UTCDateTime as MongoDate;
use YaangVu\SisModel\App\Models\impl\SbacNoSQL;


class ImportSbac implements toArray, SkipsEmptyRows
{

    public static ?string $email;
    public static ?string $school_uuid;

    public function __construct(?string $email, ?string $school_uuid)
    {
        self::$email       = $email;
        self::$school_uuid = $school_uuid;
    }

    /**
     * @throws \Exception
     */
    public function array(array $array)
    {
        if ((count($array[0]) - 9) % 4 != 0) {
            $mail    = new MailWithRabbitMQ();
            $tittle  = 'Error import SBAC Score';
            $message = 'File  import SBAC Score does not format';
            $mail->sendMails($tittle, $message, [ImportSbac::$email]);

            Log::error('[IMPORT SBAC SCORE] validation false format when import SBAC score on sheet data');

            return [];
        }
        $studentCode = (new UserService())->getStudentCodesByCurrentSchool(self::$school_uuid);
        $rules = [];
        foreach ($array as $key => $data) {
            if ($key == 0)
                continue;

            foreach ($data as $ruleKey => $ruleItem) {

                switch ($ruleKey) {
                    case 0:
                        $rules["$key.$ruleKey"] = 'required|in:'. implode(',',$studentCode);
                        break;
                    case 1:
                        $rules["$key.$ruleKey"] = 'required|exists:pgsql.grades,name';
                        break;
                    case $ruleKey % 4 == 3 && $ruleKey != 7 :
                        $rules["$key.$ruleKey"] = 'required|numeric';
                        break;
                    default:
                        $rules["$key.$ruleKey"] = 'required';

                }

            }

        }

        $customMessages = [
            'required' => "Field is required in line :attribute on data sheet",
            'numeric'  => "Field must be a number in line :attribute on data sheet",

        ];

        $validator = Validator::make($array, $rules, $customMessages);

        if ($validator->fails()) {

            $messages = $validator->errors()->messages();
            $title    = '[SIS] error when import SBAC score';
            $error    = ClassActivityService::sendMailWhenFalseValidateImport($title, $messages,
                                                                              ImportSbac::$email
            );

            Log::error('[IMPORT SBAC SCORE] validation false when import sbac score on sheet data' . $error);

            return [];
        }

        $sbac         = [];
        $data         = [];
        $studentCodes = [];
        $grades       = [];
        foreach ($array as $key => $sbacs) {
            if ($key == 0)
                continue;
            foreach ($sbacs as $keys => $value) {
                $formatKeyArray        = str_replace(" ", "_", strtolower($array[0][$keys]));
                $data[$formatKeyArray] = $value;

            }
            $data['created_at']  = new MongoDate(Carbon::now());
            $data['school_uuid'] = ImportSbac::$school_uuid;
            $sbac[]              = $data;
            $studentCodes[]      = $data['student_code'];
            $grades[]            = $data['grade'];
        }
        $collect       = collect([]);
        $messagesError = [];

        foreach ($sbac as $key => $value) {
            $checkCollect = $collect->where('student_code', $value['student_code'])
                                    ->where('grade', $value['grade']);
            if (count($checkCollect) != 0) {
                $messagesError[]
                    = ["The student_code grade duplicate in line:" . ($key + 2) . " please check data sheet"];
                continue;
            }
            $collect->push([
                               'student_code' => $value['student_code'],
                               'grade'        => $value['grade'],
                           ]);
        }
        if ($messagesError != []) {
            $title = '[SIS] error when import SBAC score';
            $error = ClassActivityService::sendMailWhenFalseValidateImport($title, $messagesError,
                                                                           ImportSbac::$email
            );
            Log::error('[IMPORT SBAC SCORE] validation false when import sbac score on sheet data' . $error);

            return [];
        }

        try {
            SbacNoSQL::whereIn('student_code', $studentCodes)->whereIn('grade', $grades)->forceDelete();
            SbacNoSQL::insert($sbac);
            $mail    = new MailWithRabbitMQ();
            $tittle  = 'Success import SBAC Score';
            $message = 'Success import SBAC Score';
            $mail->sendMails($tittle, $message, [ImportSbac::$email]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }


    }
}
