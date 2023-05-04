<?php

namespace App\Exports;

use App\Services\ClassActivityLmsService;
use App\Services\ClassActivityService;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\ArrayShape;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Excel;
use YaangVu\SisModel\App\Models\impl\ActivityClassLmsSQL;

class ClassActivityExport implements FromCollection, WithTitle, WithHeadings, WithEvents, ShouldAutoSize
{
    use Exportable;

    protected array   $data                             = [];
    protected array   $headings                         = [];
    protected array   $categoriesSyncLms                = [];
    protected array   $activityNameAndCategoryIdSyncLms = [];
    protected array   $activityNameCoursework           = [];
    protected array   $subHeadings                      = ['student_name', 'final_score', 'final_score_coursework', 'grade_letter', 'current_score'];
    public static int $classId;

    public function __construct($classId)
    {
        self::$classId = $classId;
    }

    public function title(): string
    {
        return 'data';
    }

    /**
     * It's required to define the fileName within
     * the export class when making use of Responsible.
     */
    private string $fileName = 'Export_Score.xlsx';

    /**
     * Optional Writer Type
     */
    private string $writerType = Excel::XLSX;

    /**
     * Optional headers
     */
    private array $headers
        = [
            'Content-Type' => 'text/csv',
        ];

    public function headings(): array
    {
        $classActivityService = new ClassActivityService();
        $classActivities      = $classActivityService->getClassActivityByClassId(self::$classId);

        $userIds                 = array_column($classActivities->toArray(), 'user_id');
        $activityScoreCourseWork = (new ClassActivityLmsService())->getScoreActivityLmsByClassId(self::$classId,
                                                                                                 $userIds);

        foreach ($classActivities as $classActivity) {
            foreach ($classActivity->activities as $activity) {
                $categoryName = $activity->category_name ?? null;
                $categoryId   = $activity->external_id ?? null;
                $activityName = $activity->name ?? null;

                $this->activityNameAndCategoryIdSyncLms[]                   = $activityName . '-' . $categoryId;
                $this->categoriesSyncLms[$activityName . '-' . $categoryId] = $categoryName;
            }
        }

        // handle activity sync lms
        $this->activityNameAndCategoryIdSyncLms = array_merge($this->subHeadings,
                                                              $this->activityNameAndCategoryIdSyncLms);
        $this->activityNameAndCategoryIdSyncLms = array_unique($this->activityNameAndCategoryIdSyncLms);

        // handle activity course work
        $this->activityNameCoursework = $this->_handleActivityCourseWork($activityScoreCourseWork);

        $this->headings = array_merge($this->activityNameAndCategoryIdSyncLms,
                                      array_keys($this->activityNameCoursework));

        // remove categoryId and return heading
        $this->headings = $this->_handleHeading($this->headings);

        return $this->headings;
    }

    /**
     * @return Collection
     */
    public function collection(): Collection
    {
        $classActivityService = new ClassActivityService();
        $classActivities      = $classActivityService->getClassActivityByClassId(self::$classId)->toArray();
        $userUuidsAssignable  = $classActivityService->getUserUuidsAssignable(self::$classId);

        $userIds                 = array_column($classActivities, 'user_id');
        $activityScoreCourseWork = (new ClassActivityLmsService())->getScoreActivityLmsByClassId(self::$classId,
                                                                                                 $userIds);
        $collect                 = collect($activityScoreCourseWork);

        foreach ($classActivities as $classActivity) {
            $classActivity['student_name'] = $classActivity['student']['full_name'];
            if (!in_array($classActivity['student_uuid'], $userUuidsAssignable)) {
                continue;
            }

            // handle data sync lms
            $activities       = (array)$classActivity['activities'];
            foreach ($activities as $activity) {
                $activity->score
                                            = ($activity->max_point == 0) ? null : round($activity->score / $activity->max_point * 100 ,2);
            }
            $activityNameKey = array_column($activities, 'name');
            $dataUnits       = [];
            foreach ($this->activityNameAndCategoryIdSyncLms as $column) {
                $keyStr              = stripos($column, '-');
                $activityNameSyncLms = substr($column, 0, $keyStr);

                $activitySearch = array_search($activityNameSyncLms, $activityNameKey);

                if (in_array($column, $this->subHeadings)) {
                    if($column == 'current_score')
                    {
                        if (isset($classActivity['current_score_coursework']))
                            $dataUnits[] = round($classActivity['current_score_coursework'], 2) ?? null;
                        else
                            $dataUnits[] = round($classActivity[$column], 2) ?? null;
                    }
                    elseif($column == 'final_score_coursework'){
                        if (isset($classActivity['final_score_coursework']))
                            $dataUnits[] = round($classActivity['final_score_coursework'], 2) ?? null;
                        else
                            $dataUnits[] = null;
                    }
                    else{
                        $dataUnits[] = $classActivity[$column] ?? null;
                    }
                } elseif ($activitySearch !== false) {
                    $dataUnits[] = $classActivity['activities'][$activitySearch]->score ?? null;
                    unset($activityNameKey[$activitySearch]);
                } else {
                    $dataUnits[] = null;
                }
            }

            // dandle data course work
            $activitiesScoreCourseWorkViaStudent = $collect->where('user_id', $classActivity['user_id']);
            foreach ($this->activityNameCoursework as $key => $column) {
                $keyStr               = stripos($key, '-');
                $activityIdCourseWork = substr($key, $keyStr + 1);

                $activityCourseWork = $activitiesScoreCourseWorkViaStudent->where('id_activity', $activityIdCourseWork)
                                                                          ->first();
                $dataUnits[]        = $activityCourseWork['score'] ?? null;
            }

            $this->data[] = $dataUnits;
        }
        // handle category name sync lms
        $categories = $this->_handleCategoryNameWithHeading($this->activityNameAndCategoryIdSyncLms,
                                                            $this->categoriesSyncLms, $this->activityNameCoursework);

        // add category to array data
        array_unshift($this->data, $categories);
        return collect($this->data);
    }

    public function _handleHeading(array $heading): array
    {
        $dataHeading = [];
        foreach ($heading as $value) {

            if (in_array($value, $this->subHeadings)) {
                $dataHeading[] = $value;
                continue;
            }

            $keyStr        = stripos($value, '-');
            $dataHeading[] = substr($value, 0, $keyStr);
        }

        return $dataHeading;
    }

    public function _handleCategoryNameWithHeading(array $activityNameAndCategoryId = [], array $categoriesSyncLms = [],
                                                   array $categoryCourseWork = []): array
    {
        $arrayCategories    = [];
        $arrayKeyCategories = array_keys($categoriesSyncLms);

        //category sync lms
        foreach ($activityNameAndCategoryId as $value) {
            $arrayCategories[] = !in_array($value, $arrayKeyCategories)
                ? " "
                : "[Sync LMS] " . $categoriesSyncLms[$value];
        }

        // category course work
        $activityClassLms = ActivityClassLmsSQL::query()->where('activity_class_lms.class_id', '=', self::$classId)
                                               ->join('class_activity_categories', 'class_activity_categories.id',
                                                      'activity_class_lms.class_activity_category_id')
                                               ->pluck('class_activity_categories.name as name_category',
                                                       'activity_class_lms.id')
                                               ->toArray();

        foreach ($categoryCourseWork as $key => $value) {
            $keyStr     = stripos($key, '-');
            $activityId = substr($key, $keyStr + 1);

            $arrayCategories[] = "[SIS] " . $activityClassLms[$activityId];
        }

        return $arrayCategories;
    }

    public function _handleActivityCourseWork(array $activityCourseWork): array
    {
        $arrActivityCourseWork = [];
        foreach ($activityCourseWork as $value) {
            $activityName = $value['name'] ?? null;
            $activityId   = $value['id_activity'] ?? null;

            $arrActivityCourseWork[$activityName . '-' . $activityId] = $activityName;
        }

        return $arrActivityCourseWork;
    }

    #[ArrayShape([AfterSheet::class => "\Closure"])]
    public function registerEvents(): array
    {
        $styleArray = [

            'font' => [
                'bold' => true,
            ],
        ];

        return [
            AfterSheet::class => function (AfterSheet $event) use ($styleArray) {
                $cellRange = 'A1:W1';
                $event->sheet->getDelegate()->getStyle($cellRange)->applyFromArray($styleArray);
            },
        ];
    }

}
