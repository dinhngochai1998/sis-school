<?php

namespace App\Exports;

use App\Services\ClassActivityService;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Excel;
use YaangVu\Exceptions\BadRequestException;

class ClassActivityTemplateExport implements WithMultipleSheets, ShouldAutoSize
{
    use Exportable;

    public static int $classId;

    public function __construct($classId)
    {
        self::$classId = $classId;
    }


    /**
     * It's required to define the fileName within
     * the export class when making use of Responsible.
     */
    private string $fileName = 'Activity_Score.xlsx';

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

    #[ArrayShape(['data' => "\App\Exports\ClassActivityDataExport", 'category' => "\App\Exports\ClassActivityCategoryExport"])]
    public function sheets(): array
    {
        $classActivity = (new ClassActivityService)->getFirstClassActivityByClassId(self::$classId);

        if(empty($classActivity)){
            throw new BadRequestException(
                ['message' => __("classActivity.export_score")], new Exception()
            );
        }

        return [
            'data'     => new ClassActivityDataExport(self::$classId, $classActivity),
            'category' => new ClassActivityCategoryExport(self::$classId, $classActivity)
        ];
    }
}
