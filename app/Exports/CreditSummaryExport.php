<?php

namespace App\Exports;

use App\Services\GraduationCategorySubjectService;
use App\Services\UserService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class CreditSummaryExport implements FromArray, WithHeadings, ShouldAutoSize
{
    use Exportable;

    public function title(): string
    {
        return 'data';
    }

    /**
     * It's required to define the fileName within
     * the export class when making use of Responsible.
     */
    private string $fileName = 'Export_Credit_Summary.xlsx';

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

    protected UserNoSQL $userNoSQL;
    protected int       $programId;

    public function __construct(int|string $userId, int $programId)
    {
        $this->userNoSQL = (new UserService())->get($userId);
        $this->programId = $programId;
    }

    public function headings(): array
    {
        return [
            ['student_name', $this->userNoSQL->{'full_name'}],
            ['grade', $this->userNoSQL->{'grade'}],
            ['Graduation category', 'Needed', 'Earned', 'Missing']
        ];
    }

    public function array(): array
    {
        $academicPlans = (new GraduationCategorySubjectService())->getUserAcademicPlan($this->userNoSQL->uuid, $this->programId);
        $total         = $academicPlans['total'];
        $data          = $academicPlans['list'];
        $data[]        = [
            'total',
            $total['needed'],
            $total['earned'],
            $total['missing']
        ];

        return $data;
    }
}
