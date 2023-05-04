<?php
/**
 * @Author Pham Van Tien
 * @Date   Mar 22, 2022
 */

namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\ToeflConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ToeflNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ToeflService extends BaseService
{
    use RabbitMQHelper;
    use RoleAndPermissionTrait, ElasticsearchHelper;

    function createModel(): void
    {
        $this->model = new ToeflNoSQL();
    }

    /**
     * @throws Exception
     */
    public function importToefl(object $request): bool
    {
        if (!$this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::IMPORT))) {
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        }
        $this->doValidate($request, [
            'file_url' => 'required',
        ]);
        $fileUrl   = $request->file_url;
        $filePath  = substr($fileUrl, strpos($fileUrl, ".com") + 4);
        $filePathActionLog = explode(env('AMAZON_PATH', 'https://equest-sis.s3.ap-southeast-1.amazonaws.com'),
                                     $fileUrl);
        $body      = [
            'url'         => $fileUrl,
            'file_path'   => $filePath,
            'file_url'    => $filePathActionLog[1],
            'school_uuid' => SchoolServiceProvider::$currentSchool->uuid,
            'email'       => BaseService::currentUser()->userNoSql->email
        ];
        $this->pushToExchange($body, 'IMPORT', AMQPExchangeType::DIRECT, 'toefl');
        $log = BaseService::currentUser()->username . ' import toefl : ' . Carbon::now()->toDateString();
        $this->createELS('import_toefl',
                         $log,
                         [
                             'file_url' => $body['file_url']
                         ]);

        return true;
    }

    #[ArrayShape(["label" => "int[]|string[]", "data" => "int[]"])]
    public function getToeflTotalScore(object $request): array
    {
        $this->checkRoleViewToefl();
        $toefls       = $this->getToeflByTestName($request);
        $data         = ['> 95' => 0, '65-95' => 0, '41-64' => 0, '< 40' => 0];
        $dataResponse = array_values($data);
        foreach ($toefls as $toefl) {
            $this->getDataToeflTotalScore($dataResponse, $toefl->total_score);
        }

        return [
            "label" => array_keys($data),
            "data"  => $dataResponse,
        ];
    }

    public function checkRoleViewToefl()
    {
        if (!$this->hasPermission(PermissionConstant::overallAssessment(PermissionActionConstant::VIEW))) {
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        }
    }

    public function getToeflByTestName(object $request): array|\Illuminate\Database\Eloquent\Collection
    {
        $this->checkRoleViewToefl();
        $testName = request('test_name');
        $testDate = request('test_date');
        try {
            return $this->model->when($testName, function ($query) use ($testName) {
                $query->where('test_name', 'LIKE', '%' . $testName . '%');
            })
                               ->when($testDate, function ($query) use ($testDate) {
                                   $query->whereDate('test_date', $testDate);
                               })
                               ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
                               ->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Pham Van Tien
     * @Date   Mar 29, 2022
     *
     * @param $data
     * @param $totalScore
     *
     * @return array
     */

    public function getDataToeflTotalScore(&$data, $totalScore): array
    {
        switch ($totalScore) {
            case  $totalScore > 95:
                $data[0] = ($data[0] ?? 0) + 1;
                break;
            case $totalScore <= 95 && $totalScore >= 65 :
                $data[1] = ($data[1] ?? 0) + 1;
                break;
            case $totalScore <= 64 && $totalScore >= 41:
                $data[2] = ($data[2] ?? 0) + 1;
                break;
            case $totalScore <= 40:
                $data[3] = ($data[3] ?? 0) + 1;
                break;
            default:
                break;
        }

        return $data;
    }

    public function groupTestName(object $request): array|\Illuminate\Database\Eloquent\Collection
    {
        $this->checkRoleViewToefl();
        $testName = request('test_name');
        $testDate = request('test_date');
        try {
            return $this->model->when($testName, function ($query) use ($testName) {
                $query->where('test_name', 'LIKE', '%' . $testName . '%');
            })
                               ->when($testDate, function ($query) use ($testDate) {
                                   $query->whereDate('test_date', $testDate);
                               })
                               ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
                               ->orderBy('test_date', 'desc')
                               ->groupBy('test_name', 'test_date')
                               ->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    #[ArrayShape(["label" => "string[]", "data" => "array"])] public function getToeflComponentScore(object $request): array
    {
        $this->checkRoleViewToefl();
        $this->checkValidateOveral($request);
        $toefls = $this->getToeflByTestName($request);
        $types  = ['listening', 'reading', 'speaking', 'writing'];
        $levels = ["> 15", "11-15", "5-10", "< 5"];
        $data   = ["> 15" => 0, "11-15" => 0, "5-10" => 0, "< 5" => 0];
        foreach ($levels as $key => $level) {
            $result[] = [
                'label' => $levels[$key],
                'data'  => array_values($data)
            ];
        };
        foreach ($toefls as $toefl) {
            foreach ($types as $keyType => $type) {
                if ($toefl[$type] > 15) {
                    $result[0]['data'][$keyType] = ($result[0]['data'][$keyType] ?? 0) + 1;
                }
                if ($toefl[$type] <= 15 && $toefl[$type] >= 11) {
                    $result[1]['data'][$keyType] = ($result[1]['data'][$keyType] ?? 0) + 1;
                }
                if ($toefl[$type] <= 10 && $toefl[$type] >= 5) {
                    $result[2]['data'][$keyType] = ($result[2]['data'][$keyType] ?? 0) + 1;
                }
                if ($toefl[$type] < 5) {
                    $result[3]['data'][$keyType] = ($result[3]['data'][$keyType] ?? 0) + 1;
                }
            }
        }

        return [
            "label" => $types,
            "data"  => $result
        ];
    }

    public function checkValidateOveral(object $request)
    {
        $this->doValidate($request, [
            'test_name' => 'required|exists:mongodb.toefl,test_name',
            'test_date' => 'required|exists:mongodb.toefl,test_date',
        ]);
    }

    public function getToeflTopAndBottom(object $request): \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Builder
    {
        $this->checkRoleViewToefl();
        $this->checkValidateOveral($request);
        try {
            $condition = request('condition');
            $data      = $this->model->with('user')->where('test_name', $request->test_name)
                                     ->where('test_date', $request->test_date)
                                     ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid);
            switch ($condition) {
                case 'listening_top':
                    $score = $this->getScoreMinMax('listening', $request, true);
                    $data  = $data->where('listening', $score);
                    break;
                case 'reading_top':
                    $score = $this->getScoreMinMax('reading', $request, true);
                    $data  = $data->where('reading', $score);
                    break;
                case 'speaking_top':
                    $score = $this->getScoreMinMax('speaking', $request, true);
                    $data  = $data->where('speaking', $score);
                    break;
                case 'writing_top':
                    $score = $this->getScoreMinMax('writing', $request, true);
                    $data  = $data->where('writing', $score);
                    break;
                case 'total_score_top':
                    $score = $this->getScoreMinMax('total_score', $request, true);
                    $data  = $data->where('total_score', $score);
                    break;
                case 'listening_bottom':
                    $score = $this->getScoreMinMax('listening', $request, false);
                    $data  = $data->where('listening', $score);
                    break;
                case 'reading_bottom':
                    $score = $this->getScoreMinMax('reading', $request, false);
                    $data  = $data->where('reading', $score);
                    break;
                case 'speaking_bottom':
                    $score = $this->getScoreMinMax('speaking', $request, false);
                    $data  = $data->where('speaking', $score);
                    break;
                case 'writing_bottom':
                    $score = $this->getScoreMinMax('writing', $request, false);
                    $data  = $data->where('writing', $score);
                    break;
                case 'total_score_bottom':
                    $score = $this->getScoreMinMax('total_score', $request, false);
                    $data  = $data->where('total_score', $score);
                    break;
                default:
                    break;
            }

            return $data->paginate(QueryHelper::limit());
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getScoreMinMax(string $column, object $request, bool $isMax)
    {
        $toefl = ToeflNoSQL::with('user')
                           ->where('test_name', $request->test_name)
                           ->where('test_date', $request->test_date)->get()
                           ->where('school_uuid', '=', SchoolServiceProvider::$currentSchool->uuid);

        return $isMax ? $toefl->max($column) : $toefl->min($column);
    }

    /**
     * @throws Exception
     */
    public function sendMailWhenFalseValidateImport(string $title, array $messages, string $email): string
    {
        $mail  = new MailWithRabbitMQ();
        $error = '';
        foreach ($messages as $message)
            $error = $error . implode('|', $message) . '<br>';
        $mail->sendMails($title, $error, [$email]);

        return $error;
    }

    public function getToeflIndividual(string $userId): object
    {
        $isGod       = $this->isGod();
        $isCounselor = $this->hasAnyRole(RoleConstant::COUNSELOR);
        $isStudent   = $this->hasAnyRole(RoleConstant::STUDENT);

        $isViewIndividual
            = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW));
        if (!$isGod && !$isViewIndividual && !$isCounselor && !$isStudent) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $this->schoolId = SchoolServiceProvider::$currentSchool->uuid;
        $data           = $this->queryHelper->buildQuery(new UserNoSQL())
                                            ->with(['toefl' => function ($query) {
                                                $query->orderBy('test_date', 'DESC');
                                            }])->where('_id', $userId)->where('sc_id', $this->schoolId);
        try {
            $response = $data->firstOrFail();
        } catch (Exception $e) {
            throw new BadRequestException(__('assignedStudentError.student_not_found'), new $e);
        }
        $dataToefl   = $response->toefl->toArray();
        $scoreListen = $scoreReading = $scoreWriting = $scoreSpeaking = $testDate = $dataChart = [];
        $superScore  = null;
        if (!empty($dataToefl)) {
            foreach ($dataToefl as $score) {
                $scoreListen[]   = $score[ToeflConstant::LISTENING];
                $scoreReading[]  = $score[ToeflConstant::READING];
                $scoreSpeaking[] = $score[ToeflConstant::SPEAKING];
                $scoreWriting[]  = $score[ToeflConstant::WRITING];
                $testDate[]      = $score['test_date'];
            }
            $superScore = max($scoreListen) + max($scoreReading) + max($scoreSpeaking) + max($scoreWriting);
            $dataChart  = [(object)['label' => 'Listening', 'data' => array_reverse($scoreListen)],
                           (object)['label' => 'Reading', 'data' => array_reverse($scoreReading)],
                           (object)['label' => 'Speaking', 'data' => array_reverse($scoreSpeaking)],
                           (object)['label' => 'Writing', 'data' => array_reverse($scoreWriting)]];

            (new SbacService())->checkStudentAssigned($response);
        }
        $this->decorateToeflRespone($response, $testDate, $superScore, $dataChart);

        return $response;
    }

    public function decorateToeflRespone($response, $dataDate, $superScore, $dataChart): void
    {
        $response->data_chart  = $dataChart;
        $response->total       = count($response->toefl);
        $response->super_score = $superScore;
        $response->test_date   = array_reverse($dataDate);
    }
}
