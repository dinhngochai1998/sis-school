<?php
/**
 * @Author Admin
 * @Date   Mar 15, 2022
 */

namespace App\Import;

use App\Services\UserService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use MongoDB\BSON\UTCDateTime as MongoDate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

class PhysicalPerformanceMeasuresImport implements ToArray, WithHeadingRow, SkipsEmptyRows
{

    public string $field;

    public function __construct(string $field)
    {
        $this->field = $field;
    }

    /**
     * @throws Throwable
     */
    public function array(array $array)
    {
        $collect     = collect([]);
        $data        = $errorDuplicateStudentCodeTestDate = $validatorRuleTestDate = [];
        $studentCode = (new UserService())->getStudentCodesByCurrentSchool(PhysicalPerformanceMeasures::$school_uuid);
        foreach ($array as $key => $physicalPerformances) {
            foreach ($physicalPerformances as $ruleKey => $physicalPerformance) {
                if ($key != 'student_code') {
                    if (is_numeric($ruleKey)) {
                        $test_date = Date::excelToDateTimeObject($ruleKey)->format('Y-m-d');

                        $data [] = [
                            'student_code' => $physicalPerformances['student_code'],
                            'test_date'    => $test_date,
                            $this->field   => $physicalPerformances[$ruleKey] ?? null,
                        ];
                    } else {
                        if ($ruleKey != 'student_code') {
                            $validatorRuleTestDate ["$key.$ruleKey"] = 'required|date_format:Y-m-d';
                        }

                    }

                }

            }
            $checkCollect = $collect->where('student_code', $physicalPerformances['student_code']);

            if (count($checkCollect) != 0) {
                $errorDuplicateStudentCodeTestDate []
                    = ["The student_code duplicate in sheet:" . $this->field . " please check data sheet"];
                continue;
            }

            $collect->push([
                               'student_code' => $physicalPerformances['student_code'],
                           ]);
        }
        $rules = [
            '*.student_code' => "required|in:" . implode(',', $studentCode),

        ];
        $rules = array_merge($rules, $validatorRuleTestDate);

        $customMessages = [
            'required'    => "Field is required in line :attribute on $this->field sheet",
            'date_format' => "Invalid test date format:exam date must be d/m/y  :attribute on $this->field sheet",
            'in'          => "student code is not Invalid on $this->field sheet",
        ];

        $validator = Validator::make($data, $rules, $customMessages);
        if (!empty($validator->errors()->messages()) || !empty($errorDuplicateStudentCodeTestDate)) {

            $messages                                         = array_merge($validator->errors()->messages(),
                                                                            $errorDuplicateStudentCodeTestDate);
            PhysicalPerformanceMeasures::$messageMailError [] = $messages ?? null;

            Log::error('[IMPORT ACTIVITY SCORE] validation false when import physical performance measures on sheet data' . json_encode($messages));

            return [];
        }

        foreach ($data as $valueImport) {
            PhysicalPerformanceMeasures::$physicals [] = [
                'student_code' => trim($valueImport['student_code']),
                'test_date'    => $valueImport['test_date'],
                $this->field   => $valueImport[$this->field] ?? null,
                'school_uuid'  => PhysicalPerformanceMeasures::$school_uuid ?? null,
                'created_at'   => new MongoDate(Carbon::now()),
                'updated_at'   => new MongoDate(Carbon::now()),
            ];
        }
    }
}