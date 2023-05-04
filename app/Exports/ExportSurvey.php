<?php
/**
 * @Author Admin
 * @Date   Jun 22, 2022
 */

namespace App\Exports;

use JetBrains\PhpStorm\ArrayShape;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use YaangVu\SisModel\App\Models\impl\SurveyNoSql;

class ExportSurvey implements FromArray, WithHeadings, WithEvents, ShouldAutoSize, WithCustomStartCell
{
    public array  $dataSurvey;
    public string $idSurvey;

    public function __construct(array $dataSurvey, string $idSurvey)
    {
        $this->dataSurvey = $dataSurvey;
        $this->idSurvey   = $idSurvey;
    }

    public function startCell(): string
    {
        return 'A3';
    }

    #[ArrayShape([AfterSheet::class => "\Closure"])]
    public function registerEvents(): array
    {
        $totalParticipant = ($this->dataSurvey['count_respondent'] + $this->dataSurvey['count_not_respondent']) ?? 0;

        return [
            AfterSheet::class => function (AfterSheet $event) use ($totalParticipant) {
                $sheet = $event->sheet;

                $sheet->setCellValue('B1', strtoupper('Participant:')
                                         . ' ' . $totalParticipant);

                $sheet->setCellValue('C1', strtoupper('Respondent:')
                                         . ' ' . $this->dataSurvey['count_respondent']);

                $sheet->setCellValue('D1',
                                     strtoupper('Responded Rate:')
                                     . ' ' . $this->dataSurvey['responded_rate'] . '%');
                $cellRange = 'B1:C1';
                $event->sheet->getDelegate()
                             ->getStyle($cellRange)
                             ->getFont()
                             ->setBold(true);
                $cellRangeD1 = 'D1';
                $event->sheet->getDelegate()
                             ->getStyle($cellRangeD1)
                             ->getFont()
                             ->setBold(true);
            },
        ];
    }

    public function headings(): array
    {
        $survey              = SurveyNoSql::query()->where('_id', $this->idSurvey)->first();
        $questions           = [];
        $headerRespondent    = [
            "No", "Respondent's full name",
            "Respondent's email", "Submission date",
        ];
        $headerNotRespondent = [
            "No", 'Respondent', "Respondent's full name",
            "Respondent's email", "Submission date",
        ];
        foreach ($survey->survey_structure['questions'] as $value) {
            $questions [] = $this->htmlToPlainText($value['question']);
        }
        if ($this->dataSurvey['appearance_report_setting'] == true) {
            return array_merge($headerNotRespondent, $questions);
        } else {
            return array_merge($headerRespondent, $questions);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 22, 2022
     *
     * @return array
     */
    public function array(): array
    {
        return $this->getDataExport();
    }

    public function getDataExport(): array
    {
        $numericalOrder   = 1;
        $dataExportSurvey = [];
        $answerList       = [];
        $users            = $this->dataSurvey['users'];
        foreach ($users as $key => $user) {
            foreach ($user['data_answers'] ?? [] as $answer) {
                $answerList ['answers_' . $key][] = implode(', ', (array)$answer['answer']) ?? [];
            }
            $dataExportSurvey [$key] = array_merge(
                    [
                        'no'                  => $numericalOrder++,
                        'name'                => $user['name'],
                        'respondent'          => null,
                        'email'               => $user['email'],
                        'submission_datetime' => $user['submission_datetime'],
                    ],
                    $answerList['answers_' . $key] ?? []) ?? null;
            if ($this->dataSurvey['appearance_report_setting'] != true) {
                unset($dataExportSurvey [$key]['respondent']);
            }
        }

        return $dataExportSurvey;
    }

    public function htmlToPlainText($str): string
    {

        $str = str_replace('&nbsp;', ' ', $str);

        $str = html_entity_decode($str, ENT_QUOTES | ENT_COMPAT , 'UTF-8');

        $str = html_entity_decode($str, ENT_HTML5, 'UTF-8');

        $str = html_entity_decode($str);

        $str = htmlspecialchars_decode($str);

        $str = strip_tags($str);

        return $str;
    }
}