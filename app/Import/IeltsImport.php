<?php

namespace App\Import;

use App\Services\IeltsService;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use YaangVu\SisModel\App\Models\impl\IeltsNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class IeltsImport implements ToArray, SkipsEmptyRows, WithHeadingRow, WithCalculatedFormulas
{
    public static ?string $email;
    public static string  $schoolUuid;

    public function __construct(?string $email, string $schoolUuid)
    {
        self::$email      = $email;
        self::$schoolUuid = $schoolUuid;
    }

    /**
     * @throws Exception
     */
    public function array(array $array): array
    {

        $data        = [];
        $collect     = collect([]);
        $messagesErr = [];

        $studentCode = UserNoSQL::query()
                                ->where(function ($q) {
                                    $q->where('sc_id', self::$schoolUuid)
                                      ->whereNotNull('student_code')
                                      ->where('student_code', '!=', '');
                                })
                                ->pluck('student_code')
                                ->toArray();

        foreach ($array as $key => $row) {
            // key + 2: remove line 1 vs heading
            $checkCollect = $collect->where('student_code', $row['student_code'])
                                    ->where('test_name', $row['test_name'])
                                    ->where('test_date_w', $row['test_date_w']);

            if (count($checkCollect) != 0) {
                $messagesErr[] = ["The student_code, test_name, test_date_w duplicate in line:"
                                  . ($key + 2) . " please check data sheet"];
                continue;
            }
            // add item to collect
            $collect->push([
                               'student_code' => $row['student_code'],
                               'test_name'    => $row['test_name'],
                               'test_date_w'  => $row['test_date_w'],
                           ]);
        }

        // validate
        $rules = [
            // '*.student_code' => "required|exists:mongodb.users,student_code,sc_id," . self::$schoolUuid,
            '*.student_code' => "required|in:" . implode(',', $studentCode),
            '*.test_name'    => 'required',
            '*.test_date_s'  => 'required|numeric',
            '*.test_date_w'  => 'required|numeric',
        ];

        $customMessages = [
            'required'    => "Field is required in line :attribute on data sheet",
            'in'          => "The data does not exist in the line :attribute, please check the data sheet",
            'date_format' => "The date invalid in line :attribute please check data sheet",
            'numeric'     => 'The date invalid in line :attribute please check data sheet'
        ];

        $validator = Validator::make($array, $rules, $customMessages);
        if ($validator->fails() || $messagesErr != []) {
            $messages = array_merge($validator->errors()->messages(), $messagesErr);
            $title    = '[SIS] error when import ielts';
            $error    = IeltsService::sendMailWhenFalseValidateImport($title, $messages, self::$email);
            Log::error('[IMPORT IELTS] validation false when import ielts on sheet data' . $error);

            return [];
        }

        // custom data
        foreach ($array as $key => $row) {
            $studentCode = trim($row['student_code']);
            $studentUuid = UserNoSQL::query()->where('student_code',$studentCode)->select('uuid')->first();
            // convert date
            $row['test_date_s'] = $this->convertDate($row['test_date_s']);
            $row['test_date_w'] = $this->convertDate($row['test_date_w']);

            $uuid   = Uuid::uuid();
            $data[] = [
                "uuid"            => $uuid,
                "student_code"    => $studentCode,
                "student_uuid"    => $studentUuid['uuid'],
                "test_name"       => trim($row['test_name']),
                "overall"         => $row['overall'],
                "listening"       => (object)[
                    "score"       => $row['listening'],
                    "test_date_s" => $row['test_date_s']
                ],
                "reading"         => (object)[
                    "score"       => $row['reading'],
                    "test_date_s" => $row['test_date_s']
                ],
                "speaking"        => (object)[
                    "FC"           => $row['fc'],
                    "LR"           => $row['lr'],
                    "GR"           => $row['gr'],
                    "P"            => $row['p'],
                    "score"        => $row['bandscore_s'],
                    "band_score_s" => $row['bandscore_s'],
                    "test_date_s"  => $row['test_date_s']
                ],
                "writing"         => (object)[
                    "task 1"       => $row['task_1'],
                    "task 2"       => $row['task_2'],
                    "score"        => $row['bandscore_w'],
                    "band_score_w" => $row['bandscore_w'],
                    "test_date_w"  => $row['test_date_w']
                ],
                "comment"         => (object)[
                    "speaking" => trim($row['speaking_comment']),
                    "writing"  => trim($row['writing_comment']),
                ],
                "test_date_final" => $row['test_date_w'],
                "school_uuid"     => self::$schoolUuid
            ];
        }

        try {
            // import data to table ielts
            $demo = IeltsNoSQL::query()->whereIn('student_code', array_column($data, 'student_code'))
                              ->whereIn('test_name', array_column($data, 'test_name'))
                              ->forceDelete();

            IeltsNoSQL::query()->insert($data ?? []);

            // send mail success via rabbitMQ
            $mail         = new MailWithRabbitMQ();
            $titleSuccess = '[SIS] import ielts success';
            $bodySuccess  = 'import ielts success in : ' . Carbon::now()->toDateTimeString();
            $mail->sendMails($titleSuccess, $bodySuccess, [self::$email]);
            Log::info('[IMPORT IELTS] success insert batch to IeltsNoSQL');

        } catch (Exception $e) {
            // send mail false via rabbitMQ
            $now        = Carbon::now()->toDateTimeString();
            $mail       = new MailWithRabbitMQ();
            $titleError = '[SIS] import ielts false';
            $bodyError  = "import ielts false in : $now , please contact admin";
            $mail->sendMails($titleError, $bodyError, [self::$email]);
            Log::error('[IMPORT IELTS] false to insert batch ielts with error : ' . $e->getMessage());
        }

        return $array;
    }

    public function convertDate($date): string
    {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)
                                                    ->format('Y-m-d');
    }

}
