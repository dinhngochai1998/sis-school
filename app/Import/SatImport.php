<?php
/**
 * @Author Admin
 * @Date   Mar 18, 2022
 */

namespace App\Import;

use App\Services\impl\MailWithRabbitMQ;
use App\Services\UserService;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use JetBrains\PhpStorm\NoReturn;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;
use MongoDB\BSON\UTCDateTime as MongoDate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use YaangVu\SisModel\App\Models\impl\SatNoSql;


class SatImport implements ToArray, WithHeadingRow, WithCalculatedFormulas, WithStartRow, SkipsEmptyRows
{
    public static string  $school_uuid;
    public static ?string $email;

    public function __construct(string $school_uuid, string $email)
    {
        self::$school_uuid = $school_uuid;
        self::$email       = $email;
    }


    public function startRow(): int
    {
        return 2;
    }

    /**
     * @throws Exception
     */
    #[NoReturn]
    public function array(array $array)
    {

        $collect  = collect([]);
        $data     = [];
        $testDate = $validatorRuleTestDate = $messagesDuplicate = [];

        $studentCode = (new UserService())->getStudentCodesByCurrentSchool(self::$school_uuid);
        foreach ($array as $key => $dataSat) {

            foreach ($dataSat as $ruleKey => $row) {
                if ($ruleKey == 'test_date') {
                    if (is_numeric($dataSat['test_date'])) {
                        $testDateItem    = Date::excelToDateTimeObject($dataSat['test_date'])->format('Y-m-d');
                        $testDate [$key] = $testDateItem;

                    } else {
                        $validatorRuleTestDate ["$key.$ruleKey"] = 'date_format:Y-m-d';
                    }
                }

            }
            $checkCollect = $collect->where('student_code', $dataSat['student_code'])
                                    ->where('test_date', $dataSat['test_date']);

            if (count($checkCollect) != 0) {
                $messagesDuplicate []
                    = ["The student_code and test_date duplicate in line:" . ($key + 2) . " please check data sheet"];
                continue;
            }

            $collect->push([
                               'student_code' => $dataSat['student_code'],
                               'test_date'    => $dataSat['test_date']
                           ]);
        }
        $rules = [
            '*.student_code' => "required|in:" . implode(',', $studentCode),
            '*.test_date'    => 'required',
            '*.test_name'    => 'required',
        ];

        $rules          = array_merge($rules, $validatorRuleTestDate);
        $customMessages = [
            'required'    => "Field is required in line :attribute",
            'date_format' => "Invalid test date format:exam date must be d/m/y  :attribute on Sat sheet",
            'exists'      => "student code is not Invalid",
        ];

        $validator = Validator::make($array, $rules, $customMessages);
        $mail      = new MailWithRabbitMQ();
        if (!empty($validator->fails()) || !empty($messagesDuplicate)) {
            $titleError          = '[SIS] error when import Sat';
            $messages            = array_merge($validator->errors()->messages(), $messagesDuplicate);
            $formatMessagesError = json_encode($messages);
            $mail->sendMails($titleError, $formatMessagesError, [self::$email]);

            return [];
        }

        foreach ($array as $key => $dataSat) {

            $data[] = [
                "student_code"         => trim($dataSat['student_code']),
                "test_name"            => $dataSat['test_name'],
                "test_date"            => $testDate[$key],
                "total_score"          => $dataSat['total_score_1600'] ?? null,
                "reading"              => $dataSat['reading_400'] ?? null,
                "writing_and_language" => $dataSat['writing_and_language_400'] ?? null,
                "math"                 => $dataSat['math_200_800'] ?? null,
                "school_uuid"          => self::$school_uuid ?? null,
                'created_at'           => new MongoDate(Carbon::now()),
            ];
        }
        $studentCodeExits = array_column($data, 'student_code');
        $testDateExits    = array_column($data, 'test_date');
        SatNoSql::query()->whereIn('student_code', $studentCodeExits)
                ->whereIn('test_date', $testDateExits)->delete();
        SatNoSql::query()->insert($data);
        $titleSuccess    = '[SIS] import Sat success';
        $messagesSuccess = 'import Sat success in :' . Carbon::now()->toDateTimeString();
        $mail->sendMails($titleSuccess, $messagesSuccess, [self::$email]);
    }

}
