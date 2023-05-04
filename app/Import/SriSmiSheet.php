<?php


namespace App\Import;


use App\Services\impl\MailWithRabbitMQ;
use App\Services\SriSmiService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Validator;
use YaangVu\SisModel\App\Models\impl\ScholasticAssessmentNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class SriSmiSheet implements ToArray, WithHeadingRow, SkipsEmptyRows
{
    /**
     * @param array $students
     *
     * @return array
     * @throws Exception
     */
    public function array(array $students): array
    {
        $rule       = [];
        $testTimes  = 19;
        $smiSriRule = [];

        for ($i = 1; $i <= $testTimes; $i++) {
            // SMI title
            $smiQuantileScores = 'smi_quantile_scores';
            $sriLexileScores   = 'sri_lexile_scores';

            $smiSriRule[] = $smiQuantileScores . '_' . $i;
            $smiSriRule[] = $sriLexileScores . '_' . $i;

        }

        $collect                 = collect([]);
        $messagesStudentCodeExit = [];
        foreach ($students as $key => $data) {
            // key + 2: remove line 1 vs heading
            $checkCollect = $collect->where('student_code', $data['student_code'])->where('grade', $data['grade']);
            if (count($checkCollect) != 0) {
                $messagesStudentCodeExit[]
                    = ["The student_code and grade duplicate in line:" . ($key + 2) . " please check data sheet"];
                continue;
            }
            // add item to collect
            $collect->push([
                               'student_code' => $data['student_code'],
                               'grade'        => $data['grade']
                           ]);

            foreach ($data as $ruleKey => $ruleItem) {
                if ($ruleKey == 'student_code')
                    $rule["$key.$ruleKey"] = 'required';

                if ($ruleKey == 'smi_last_quantile' || $ruleKey == 'sri_last_lexile_score')
                    $rule["$key.$ruleKey"] = 'numeric';

                if (in_array($ruleKey, $smiSriRule) && $ruleItem)
                    $rule["$key.$ruleKey"] = 'numeric';

                if ($ruleKey == 'grade') {
                    $rule["$key.$ruleKey"] = 'required|in:1,2,3,4,5,6,7,8,9,10,11,12';
                }

            }
        }

        $customMessages = [
            'required' => "Field is required in line :attribute on data sheet",
            'numeric'  => "Field must be a number in line :attribute on data sheet",
        ];
        $validator      = Validator::make($students, $rule, $customMessages);

        if ($validator->fails() || $messagesStudentCodeExit != []) {
            $messages = array_merge($messagesStudentCodeExit, $validator->errors()->messages());
            $title    = '[SIS] error when import sri smi';
            $error    = SriSmiService::sendMailWhenFalseValidateImport($title, $messages,
                                                                       SriSmiImport::$email);
            Log::error('[IMPORT SRI SMI] validation false when import sri_smi on sheet data :' . $error);

            return [];
        }

        $sriSmi = [];
        foreach ($students as $student) {
            $sri       = [];
            $smi       = [];
            $testTimes = 19;

            for ($i = 1; $i <= $testTimes; $i++) {
                // SMI title
                $smiTestDate         = 'smi_test_date';
                $smiQuantileScores   = 'smi_quantile_scores';
                $smiPerformanceLevel = 'smi_performance_level';

                if ($student[$smiQuantileScores . '_' . $i] || $student[$smiPerformanceLevel . '_' . $i] || $student[$smiTestDate . '_' . $i]) {

                    $smi [] = [
                        'quantile_scores'   => $student[$smiQuantileScores . '_' . $i] ?? null,
                        'performance_level' => $student[$smiPerformanceLevel . '_' . $i] ?? null,
                        'test_time'         => $i,
                        'date'              => $student[$smiTestDate . '_' . $i] ?? null,
                        'grade'             => $student['grade']
                    ];
                }


                // SRI title
                $sriTestDate         = 'sri_test_date';
                $sriLexileScores     = 'sri_lexile_scores';
                $sriTotalTimePerTest = 'sri_total_time_per_test';

                if ($student[$sriLexileScores . '_' . $i] || $student[$sriTotalTimePerTest . '_' . $i] || $student[$sriTestDate . '_' . $i]) {
                    $sri [] = [
                        'lexile_scores'       => $student[$sriLexileScores . '_' . $i] ?? null,
                        'total_time_per_test' => $student[$sriTotalTimePerTest . '_' . $i] ?? null,
                        'test_time'           => $i,
                        'date'                => $student[$sriTestDate . '_' . $i] ?? null,
                        'grade'               => $student['grade']
                    ];
                }
            }

            $user = UserNoSQL::whereStudentCode($student['student_code'])->with('userSql')->first();

            if (!$user)
                continue;

            $sriSmiIds = ScholasticAssessmentNoSQL::whereStudentNosqlId($user?->_id)
                                                  ->whereGrade($student['grade'])
                                                  ->whereSchoolId(SriSmiImport::$schoolId)
                                                  ->pluck('_id');

            foreach ($sriSmiIds as $sriSmiId) {
                ScholasticAssessmentNoSQL::find($sriSmiId)->delete();
            }
            $date = Carbon::now();
            Log::info('[IMPORT SRI SMI]  data', $student);

            $sriSmi [] = [
                'student_code'               => $student['student_code'],
                'student_id'                 => $user->userSql ? $user->userSql['id'] : null,
                'student_nosql_id'           => $user?->_id,
                'full_name'                  => $user?->full_name,
                'grade'                      => $student['grade'],
                'school_id'                  => SriSmiImport::$schoolId,
                'school_uuid'                => SriSmiImport::$schoolUuid,
                'imported_by'                => SriSmiImport::$importBy,
                'imported_by_nosql'          => SriSmiImport::$importedByNosql,
                'smi_last_quantile_date'     => $student['smi_last_quantile_date'] ?? null,
                'smi_last_quantile'          => $student['smi_last_quantile'],
                'smi_last_performance_level' => $student['smi_last_performance_level'],
                'smi_percentile'             => $student['smi_percentile'],
                'smi_nce'                    => $student['smi_nce'],
                'smi_stanine'                => $student['smi_stanine'],
                'smi_growth_in_date_range'   => $student['smi_growth_in_date_range'],
                'smi_test_taken'             => $student['smi_test_taken'],
                'sri_last_lexile_date'       => $student['sri_last_lexile_date'] ?? null,
                'sri_last_lexile_score'      => $student['sri_last_lexile_score'],
                'sri_percentile'             => $student['sri_percentile'],
                'sri_nce'                    => $student['sri_nce'],
                'sri'                        => $sri,
                'smi'                        => $smi,
                'created_at'                 => $date->toDateTimeString(),
                'updated_at'                 => $date->toDateTimeString(),
            ];
        }

        try {
            if ($sriSmi != [])
                ScholasticAssessmentNoSQL::insert($sriSmi);

            // send mail success via rabbitMQ
            $mail         = new MailWithRabbitMQ();
            $titleSuccess = '[SIS] IMPORT SRI SMI';
            $bodySuccess  = 'IMPORT SRI SMI success in : ' . Carbon::now()->toDateTimeString();
            $mail->sendMails($titleSuccess, $bodySuccess, [SriSmiImport::$email]);

            Log::info('[IMPORT SRI SMI success insert batch to ScholasticAssessmentNoSQL');
        } catch (Exception $e) {
            Log::error('[IMPORT SRI SMI] false to insert batch ScholasticAssessmentNoSQL with error : ' . $e->getMessage());
        }

        return $students;

    }

}
