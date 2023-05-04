<?php
/**
 * @Author Dung
 * @Date   Mar 06, 2022
 */

namespace App\Import;

use App\Services\ClassActivityService;
use App\Services\impl\MailWithRabbitMQ;
use App\Services\UserService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use MongoDB\BSON\UTCDateTime as MongoDate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use YaangVu\SisModel\App\Models\impl\ActNoSQL;

class ImportAct implements toArray, WithHeadingRow, SkipsEmptyRows
{

    public static ?string $email;
    public static ?string $school_uuid;

    public function __construct(?string $email, ?string $school_uuid)
    {
        self::$email       = $email;
        self::$school_uuid = $school_uuid;
    }

    /**
     *
     * @param array( ["Student Code": string, "Test date": string,
     * "Act composite score" : string ,"Act math score":integer ,"Act science score" : integer,"Act english score" :
     * integer, Act reading score:integer...] ) $rows
     *
     * @throws \Exception
     */
    public function array(array $rows)
    {

        $acts = [];
        foreach ($rows as $row) {
            $act = [];
            foreach ($row as $column => $cell) {
                $column = Str::snake($column);
                if (in_array($column, ['student_code', 'test_date']) && $cell == '') {
                    $act = [];
                    break;
                } else {
                    $act[$column] = $cell;
                }

            }
            if (count($act))
                $acts[] = $act;
        }
        $collect       = collect([]);
        $messagesError = [];
        $testDateExcel = [];

        foreach ($acts as $key => $value) {
            $checkCollect = $collect->where('student_code', $value['student_code'])
                                    ->where('test_date', $value['test_date']);

            if (count($checkCollect) != 0) {
                $messagesError[]
                    = ["The student_code and test_date  duplicate in line:" . ($key + 2) . " please check data sheet"];
                continue;
            }
            $collect->push([
                               'student_code' => $value['student_code'],
                               'test_date'    => $value['test_date'],
                           ]);

            foreach ($value as $items => $item) {
                if ($items == 'test_date') {
                    if (is_numeric($item)) {
                        $testDateExcel[$key] = Date::excelToDateTimeObject($item)->format('Y-m-d');

                    }
                }
            }
        }
        $studentCode = (new UserService())->getStudentCodesByCurrentSchool(self::$school_uuid);
        $rules       = [
            '*.student_code'        => 'required|in:' . implode(',', $studentCode),
            '*.test_date'           => 'required|numeric',
            '*.act_composite_score' => 'required|numeric|min:0',
            '*.act_math_score'      => 'required|numeric|min:0',
            '*.act_english_score'   => 'required|numeric|min:0',
            '*.act_reading_score'   => 'required|numeric|min:0',
            '*.act_science_score'   => 'required|numeric|min:0',

        ];

        $customMessages = [
            'min'      => "score should not be less than 0",
            'required' => "Field is required in line :attribute on data sheet",
            'numeric'  => "Field must be a number in line :attribute on data sheet or test_date is date",
        ];

        $validator = Validator::make($acts, $rules, $customMessages);
        if ($validator->fails() || $messagesError != []) {
            $messages = array_merge($validator->errors()->messages(), $messagesError);
            $title    = '[SIS] error when import act score';
            $error    = ClassActivityService::sendMailWhenFalseValidateImport($title, $messages,
                                                                              ImportAct::$email
            );

            Log::error('[IMPORT ACTIVITY SCORE] validation false when import act score on sheet data' . $error);

            return [];
        }

        // Check if there are duplicate records in excel file

        $studentCode = [];

        foreach ($acts as $key => $act) {
            $studentCode[]             = $act['student_code'];
            $acts[$key]['created_at']  = new MongoDate(Carbon::now());
            $acts[$key]['school_uuid'] = ImportAct::$school_uuid;
            $acts[$key]['test_date']   = $testDateExcel[$key];
        }

        try {
            ActNoSQL::whereIn('student_code', $studentCode)->whereIn('test_date', $testDateExcel)->forceDelete();
            ActNoSQL::insert($acts);
            $mail    = new MailWithRabbitMQ();
            $tittle  = 'Success import ACT Score';
            $message = 'Success import ACT Score';
            $mail->sendMails($tittle, $message, [ImportAct::$email]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }

    }

}
