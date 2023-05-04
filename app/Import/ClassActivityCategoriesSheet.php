<?php

namespace App\Import;

use App\Services\ClassActivityService;
use Exception;
use Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Validator;

class ClassActivityCategoriesSheet implements ToArray, SkipsEmptyRows, WithCalculatedFormulas
{
    public static array $activities      = [];
    public static array $groupCategories = [];
    public static array $maxPoint        = [];
    public static bool  $stop            = false;

    /**
     * @param array $array
     *
     * @return array
     * @throws Exception
     */
    public function array(array $array): array
    {
        $class_id = ClassActivityImport::$classId;
        $newArr   = [];
        foreach ($array as $key => $items) {
            if (!isset($items[2]) || !isset($items[1]))
                continue;

            $array[$key] = array_filter($items);
            foreach ($array[$key] as $itemKey => $item)
                $newArr[$itemKey][] = $item;
        }

        foreach ($newArr as $key => $items) {
            unset($newArr[$key][0]);

            foreach ($newArr[$key] as $keyRule => $arrayValidate) {
                $rules["1.$keyRule"] = "exists:class_activity_categories,name,class_id,$class_id,deleted_at,NULL";
                $rules["2.$keyRule"] = "required|numeric";
            }
        }

        $customMessages = [
            'exists'  => 'The category invalid in line :attribute please review on category sheet',
            'numeric' => 'Your data must be in numeric format in line :attribute please review on category sheet'
        ];

        $validator = Validator::make($newArr, $rules ?? [], $customMessages);

        if ($validator->fails()) {
            $messages = $validator->errors()->messages();
            $title    = '[SIS] error when import activity score';
            $error    = ClassActivityService::sendMailWhenFalseValidateImport($title, $messages,
                                                                              ClassActivityImport::$email
            );

            Log::error('[IMPORT ACTIVITY SCORE] validation false when import activity score in sheet category' . $error);

            self::$stop = true;

            return [];
        }

        self::$activities = $newArr[0];
        foreach ($newArr[1] as $key => $category) {
            $group[$category][] = $newArr[0][$key];
        }
        self::$groupCategories = $group ?? [];
        self::$maxPoint        = $newArr[2];

        Log::info('[IMPORT ACTIVITY SCORE] complete pass sheet category when import activity score');

        self::$stop = false;

        return $newArr;
    }
}
