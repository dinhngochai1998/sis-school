<?php
/**
 * @Author Pham Van Tien
 * @Date   Mar 22, 2022
 */

namespace App\Import;

use App\Services\impl\MailWithRabbitMQ;
use App\Services\ToeflService;
use App\Services\UserService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use YaangVu\SisModel\App\Models\impl\ToeflNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class ToeflImport implements ToArray, SkipsEmptyRows, WithHeadingRow, WithCalculatedFormulas
{
    public static ?string $email;
    public static string  $schoolUuid;

    public function __construct($email, $schoolUuid)
    {
        self::$email      = $email;
        self::$schoolUuid = $schoolUuid;
    }

    /**
     * @throws Exception
     */
    public function array(array $array)
    {
        $studentCode = (new UserService())->getStudentCodesByCurrentSchool(self::$schoolUuid);
        $rules    = [
            '*.student_code'      => 'required|in:'. implode(',',$studentCode),
            '*.test_date'         => 'required|numeric',
            '*.test_name'         => 'required',
            '*.total_score_0_120' => 'numeric|max:120',
            '*.listening_0_30'    => 'numeric|max:30',
            '*.reading_0_30'      => 'numeric|max:30',
            '*.speaking_0_30'     => 'numeric|max:30',
            '*.writing'           => 'numeric|max:30'
        ];
        $testDate = [];
        $collect  = collect([]);
        $messagesError = [];

        foreach ($array as $key => $value) {
            $checkCollect = $collect->where('student_code',$value['student_code'])
                                    ->where('test_name',$value['test_name'])
                                    ->where('test_date', $value['test_date']);
            if(count($checkCollect) != 0)
            {
                $messagesError[] =
                    ["The student_code and test_date and test_name duplicate in line:" . ($key + 2) . " please check data sheet"];
                continue;
            }
            $collect->push([
                               'student_code' => $value['student_code'],
                               'test_date'    => $value['test_date'],
                               'test_name'    => $value['test_name']
                           ]);
            foreach ($value as $items => $item) {
                if ($items == 'test_date') {
                    if (is_numeric($value[$items])) {
                        $testDate[$key] = Date::excelToDateTimeObject($value[$items])->format('Y-m-d');
                    }
                }
            }
        }

        $customMessages = [
            'required'          => "Field is required in line :attribute on data sheet",
            'exists'            => "The date invalid in line :attribute please check data sheet",
            'date_format:m/d/Y' => "Invalid test date format:exam date must be m/d/Y  :attribute on sheet"
        ];
        $validator      = Validator::make($array, $rules, $customMessages);
        if ($validator->fails() || $messagesError != [] ) {
            $messages = array_merge($validator->errors()->messages(),$messagesError);
            $title    = '[SIS] error when import toefl';
            $error    = (new ToeflService)->sendMailWhenFalseValidateImport($title, $messages, self::$email);
            Log::error('[IMPORT TOEFL] validation false when import ielts on sheet data' . $error);
            return [];
        }

        $toefl = [];
        foreach ($array as $key => $value) {
            $studentCode = trim($value['student_code']);
            $studentUuid = UserNoSQL::query()->where('student_code',$studentCode)->select('uuid')->first();
            $toefl[] = [
                'student_code' => $studentCode,
                'test_name'    => trim($value['test_name']),
                'test_date'    => trim($testDate[$key]),
                'total_score'  => $value['total_score_0_120'],
                'listening'    => $value['listening_0_30'],
                'reading'      => $value['reading_0_30'],
                'speaking'     => $value['speaking_0_30'],
                'writing'      => $value['writing_0_30'],
                'school_uuid'  => self::$schoolUuid,
                'student_uuid' => $studentUuid['uuid']
            ];
        }
        try {
            ToeflNoSQL::query()->whereIn('student_code', array_column($toefl, 'student_code'))
                      ->whereIn('test_date', array_column($toefl, 'test_date'))
                      ->whereIn('test_name', array_column($toefl, 'test_name'))
                      ->forceDelete();
            ToeflNoSQL::query()->insert($toefl ?? []);
            $mail    = new MailWithRabbitMQ();
            $title   = '[SIS] import TOEFL success';
            $message = 'You have successfully imported';
            $mail->sendMails($title, $message, [ToeflImport::$email]);
        } catch (Exception $e) {
            $mail    = new MailWithRabbitMQ();
            $title   = '[SIS] import TOEFL fails';
            $message = 'Import TOEFL fails. Please try again';
            $mail->sendMails($title, $message, [ToeflImport::$email]);
        }
    }
}
