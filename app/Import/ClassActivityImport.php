<?php

namespace App\Import;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ClassActivityImport implements WithMultipleSheets
{
    public static int     $classId;
    public static ?string $email;
    public static string  $url;
    public static string  $school_uuid;

    public function __construct(int $classId, ?string $email, string $url, string $school_uuid)
    {
        self::$classId     = $classId;
        self::$email       = $email;
        self::$url         = $url;
        self::$school_uuid = $school_uuid;
    }

    #[ArrayShape(['categories' => "\App\Excels\ClassActivityCategoriesSheet", 'data' => "\App\Excels\ClassActivityDataSheet"])] #[Pure]
    public function sheets(): array
    {
        return [
            'categories' => new ClassActivityCategoriesSheet(),
            'data'       => new ClassActivityDataSheet(),
        ];
    }
}
