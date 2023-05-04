<?php


namespace App\Import;


use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SriSmiImport implements WithMultipleSheets, WithHeadingRow
{
    public static int    $schoolId;
    public static string $schoolUuid;
    public static int    $importBy;
    public static string $importedByNosql;
    public static string $email;

    public function __construct(string $schoolUuid, int $schoolId, int $importBy, string $importedByNosql,
                                string $email)
    {
        self::$schoolId        = $schoolId;
        self::$schoolUuid      = $schoolUuid;
        self::$importBy        = $importBy;
        self::$importedByNosql = $importedByNosql;
        self::$email           = $email;
    }

    #[Pure]
    #[ArrayShape(['SRI_SMI' => "\App\Import\ClassActivityCategoriesSheet"])]
    public function sheets(): array
    {
        return [
            'SRI_SMI' => new SriSmiSheet(),
        ];
    }

}
