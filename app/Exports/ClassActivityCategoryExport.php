<?php

namespace App\Exports;

use JetBrains\PhpStorm\ArrayShape;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class ClassActivityCategoryExport implements WithTitle, ShouldAutoSize, WithHeadings, WithEvents, FromArray
{
    public static int $classId;
    public static     $classActivity;

    public function __construct($classId, $classActivity)
    {
        self::$classId       = $classId;
        self::$classActivity = $classActivity;
    }

    public function title(): string
    {
        return 'category';
    }

    public function headings(): array
    {
        return ["activity", "category", "max_point"];
    }

    public function array(): array
    {
        $dataSheetCategories = [];
        $classActivity       = self::$classActivity;
        foreach ($classActivity['categories'] as $category) {
            foreach ($category['activities'] as $activity) {
                $strWeight             = '(' . $category['weight'] . '%)';
                $categoryName          = str_replace($strWeight, '', $category['name']);
                $dataSheetCategories[] = [
                    $activity['name'],
                    $categoryName,
                    $activity['max_point']
                ];
            }
        }

        return $dataSheetCategories;
    }

    /**
     * @return array
     */
    #[ArrayShape([AfterSheet::class => "\Closure"])]
    public function registerEvents(): array
    {
        return [
            // handle by a closure.
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getDelegate()->getStyle('A1:C1')
                             ->getFill()
                             ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                             ->getStartColor()
                             ->setARGB('f1c232');
            },
        ];
    }

}
