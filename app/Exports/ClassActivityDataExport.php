<?php

namespace App\Exports;

use App\Services\ClassActivityService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ClassActivityDataExport implements WithTitle, ShouldAutoSize, WithHeadings, FromCollection
{
    public static int $classId;
    public static     $classActivity;
    protected         $data                      = [];
    protected array   $units                     = [];
    protected array   $activityNameAndCategoryId = [];
    protected         $subUnits                  = ['student_code', 'full_name'];

    public function __construct($classId, $classActivity)
    {
        self::$classId       = $classId;
        self::$classActivity = $classActivity;
    }

    public function title(): string
    {
        return 'data';
    }

    public function headings(): array
    {
        $classActivity = self::$classActivity;

        foreach ($classActivity['categories'] as $category) {

            foreach ($category['activities'] as $activity) {
                $activityName                      = $activity['name'] ?? null;
                $this->units[]                     = $activityName;
                $this->activityNameAndCategoryId[] = $activityName . '-' . $category['id'] ?? null;
            }

        }
        $this->units                     = array_merge($this->subUnits, $this->units);
        $this->activityNameAndCategoryId = array_merge($this->subUnits, $this->activityNameAndCategoryId);

        return $this->units;
    }

    /**
     * @return Collection
     */
    public function collection(): Collection
    {
        $classActivities = (new ClassActivityService())->getClassActivityByClassId(self::$classId);

        foreach ($classActivities as $classActivity) {

            $dataUnits                = [];
            $classActivity->full_name = $classActivity['student']['full_name'] ?? "";
            foreach ($this->activityNameAndCategoryId as $unit) {

                // handle name activity and categoryId
                $keyStr       = stripos($unit, '-');
                $nameActivity = substr($unit, 0, $keyStr);
                $categoryId   = substr($unit, $keyStr + 1);

                // student_code
                $studentName = $classActivity['student']['full_name'] ?? "";

                if (in_array($unit, $this->subUnits))
                    $dataUnits[] = $classActivity[$unit];
                else
                    foreach ($classActivity['categories'] as $category) {
                        $nameKeyActivity = array_column($category['activities'], 'name');
                        $activitySearch  = array_search($nameActivity, $nameKeyActivity);
                        if ($activitySearch !== false && $category['id'] == $categoryId) {
                            $dataUnits[] = $category['activities'][$activitySearch]['score'] ?? null;
                        }
                    }

            }
            $this->data[] = $dataUnits;
        }

        return collect($this->data);
    }
}
