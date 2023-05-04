<?php

namespace App\Import;

use App\Constants\PhysicalConstant;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PhysicalPerformanceMeasures implements WithMultipleSheets
{
    public static array  $messageMailError;
    public static array  $physicals;
    public static string $school_uuid;

    public function __construct(string $school_uuid)
    {
        self::$school_uuid = $school_uuid;
    }

    #[Pure] #[ArrayShape(['BENCHPRESS IN POUNDS' => "\App\Import\PhysicalPerformanceMeasuresBenchpressInPoundsSheet", '40 YARD DASH IN SECONDS' => "\App\Import\PhysicalPerformanceMeasures40YardDashInSecondSheet", 'VERTICAL JUMP IN INCHES' => "\App\Import\PhysicalPerformanceMeasuresVerticalJumpInInchesSheet", 'SQUAT IN POUNDS' => "\App\Import\PhysicalPerformanceMeasuresSquatInPoundsSheet", 'HEIGHT IN INCHES' => "\App\Import\PhysicalPerformanceMeasuresHeightInInchesSheet", 'WEIGHT IN POUNDS' => "\App\Import\PhysicalPerformanceMeasuresWeightInPoundsSheet", 'BODY MASS INDEX IN PERCENTAGE' => "\App\Import\PhysicalPerformanceMeasuresBodyMassIndexInPercentageSheet"])]
    public function sheets(): array
    {
        return [
            'BENCHPRESS_IN_POUNDS'          => new PhysicalPerformanceMeasuresImport(PhysicalConstant::BENCHPRESS_IN_POUNDS),
            '40_YARD_DASH_IN_SECONDS'       => new PhysicalPerformanceMeasuresImport(PhysicalConstant::FORTY_YARD_DASH_IN_SECONDS),
            'VERTICAL_JUMP_IN_INCHES'       => new PhysicalPerformanceMeasuresImport(PhysicalConstant::VERTICAL_JUMP_IN_INCHES),
            'SQUAT_IN_POUNDS'               => new PhysicalPerformanceMeasuresImport(PhysicalConstant::SQUAT_IN_POUNDS),
            'HEIGHT_IN_INCHES'              => new PhysicalPerformanceMeasuresImport(PhysicalConstant::HEIGHT_IN_INCHES),
            'WEIGHT_IN_POUNDS'              => new PhysicalPerformanceMeasuresImport(PhysicalConstant::WEIGHT_IN_POUNDS),
            'BODY_MASS_INDEX_IN_PERCENTAGE' => new PhysicalPerformanceMeasuresImport(PhysicalConstant::BODY_MASS_INDEX_IN_PERCENTAGE)
        ];
    }

}
