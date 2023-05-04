<?php

namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\IeltsNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

/**
 * @Author apple
 * @Date   Mar 16, 2022
 */
class IeltsService extends BaseService
{
    use RabbitMQHelper;
    use RoleAndPermissionTrait, ElasticsearchHelper;

    /**
     * @throws Exception
     */
    public static function sendMailWhenFalseValidateImport(string $title, array $messages, string $email): string
    {
        $mail  = new MailWithRabbitMQ();
        $error = '';
        foreach ($messages as $message)
            $error = $error . implode('|', $message) . '<br>';
        $mail->sendMails($title, $error, [$email]);

        return $error;
    }

    function createModel(): void
    {
        $this->model = new IeltsNoSQL();
    }

    public function groupTestName(object $request): array|\Illuminate\Database\Eloquent\Collection
    {
        $this->checkPermissionCurrentUser();
        $testName = $request->test_name ?? null;

        return $this->model->select('test_name', 'test_date_final')
                           ->when($testName, function ($q) use ($testName) {
                               $q->where('test_name', 'like', '%' . $testName . '%');
                           })
                           ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
                           ->orderBy('test_date_final', 'DESC')
                           ->groupBy('test_name', 'test_date_final')
                           ->get();
    }

    public function checkPermissionCurrentUser()
    {
        $isDynamic = $this->hasPermission(PermissionConstant::overallAssessment(PermissionActionConstant::VIEW));

        if (!($isDynamic))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

    }

    public function getIeltsOverall(object $request): array
    {
        $this->checkPermissionCurrentUser();
        $this->validateGetIelts($request);
        try {
            $ielts = $this->getIeltsByTestName($request);

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        if (count($ielts) == 0) {
            return [];
        }

        $data = ['> 5.0' => 0, '= 5.0' => 0, '4.0 - 5.0' => 0, '< 4.0' => 0];
        foreach ($ielts as $item) {
            $overall = $item->overall;
            switch ($overall) {
                case  $overall > 5:
                    $data['> 5.0'] = ($data['> 5.0'] ?? 0) + 1;
                    break;
                case $overall == 5:
                    $data['= 5.0'] = ($data['= 5.0'] ?? 0) + 1;
                    break;
                case $overall >= 4 && $overall < 5:
                    $data['4.0 - 5.0'] = ($data['4.0 - 5.0'] ?? 0) + 1;
                    break;
                case $overall < 4:
                    $data['< 4.0'] = ($data['< 4.0'] ?? 0) + 1;
                    break;
                default:
                    break;
            }
        }

        return [
            'label' => array_keys($data),
            'data'  => array_values($data)
        ];
    }

    public function validateGetIelts(object $request)
    {
        $this->doValidate($request, [
            'test_name'   => 'required|exists:mongodb.ielts,test_name',
            'test_date_w' => 'bail|required|exists:mongodb.ielts,test_date_final|date_format:Y-m-d',
        ]);
    }

    public function getIeltsByTestName(object $request): array|\Illuminate\Database\Eloquent\Collection
    {
        return $this->model
            ->where(function ($query) use ($request) {
                $query->where('test_name', $request->test_name)
                      ->where('test_date_final', $request->test_date_w);
            })
            ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
            ->get();
    }

    public function getIeltsComponentScore(object $request): array
    {
        $this->checkPermissionCurrentUser();
        $this->validateGetIelts($request);
        try {
            $ielts = $this->getIeltsByTestName($request);

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        if (count($ielts) == 0) {
            return [];
        }

        // data response
        $types = ["> 5.0", "= 5.0", "= 4.5", "= 4.0", "< 4.0"];
        $label = ['listening', 'reading', 'speaking', 'writing'];
        $data  = ['listening' => 0, 'reading' => 0, 'speaking' => 0, 'writing' => 0];


        foreach ($types as $type) {
            $result[] = [
                'label' => $type,
                'data'  => array_values($data)
            ];
        }

        foreach ($ielts as $item) {
            foreach ($label as $key => $value) {
                if ($item[$value]['score'] > 5)
                    $result[0]['data'][$key] = $result[0]['data'][$key] + 1;

                if ($item[$value]['score'] == 5)
                    $result[1]['data'][$key] = $result[1]['data'][$key] + 1;

                if ($item[$value]['score'] == 4.5)
                    $result[2]['data'][$key] = $result[2]['data'][$key] + 1;

                if ($item[$value]['score'] == 4)
                    $result[3]['data'][$key] = $result[3]['data'][$key] + 1;

                if ($item[$value]['score'] < 4)
                    $result[4]['data'][$key] = $result[4]['data'][$key] + 1;
            }
        }

        return [
            'label' => $label,
            'data'  => $result
        ];
    }

    public function getIeltsTopAndBottom(object $request): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $this->checkPermissionCurrentUser();
        $this->validateGetIelts($request);
        $orderBy = $this->queryHelper->getOrderBy();
        $data    = IeltsNoSQL
            ::with('user')
            ->where(function ($query) use ($request) {
                $query->where('test_name', $request->test_name)
                      ->where('test_date_final', $request->test_date_w);
            })
            ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
            ->when(!$orderBy, function ($q) use ($orderBy, $request) {
                $q->where('overall', $this->getValueViaOrderBy('overall', $request->test_name, $request->test_date_w));
            })
            ->when($orderBy, function ($q) use ($orderBy, $request) {
                $column = $orderBy['column'];
                $type   = $orderBy['type'];
                switch ($column) {
                    case 'listening':
                        $q->where('listening.score', $this->getValueViaOrderBy('listening.score', $request->test_name,
                                                                               $request->test_date_w, $type));
                        break;
                    case 'reading':
                        $q->where('reading.score',
                                  $this->getValueViaOrderBy('reading.score', $request->test_name, $request->test_date_w,
                                                            $type));
                        break;
                    case 'speaking':
                        $q->where('speaking.band_score_s',
                                  $this->getValueViaOrderBy('speaking.band_score_s', $request->test_name,
                                                            $request->test_date_w, $type));
                        break;
                    case 'writing':
                        $q->where('writing.band_score_w',
                                  $this->getValueViaOrderBy('writing.band_score_w', $request->test_name,
                                                            $request->test_date_w, $type));
                        break;
                    case 'overall':
                        $q->where('overall',
                                  $this->getValueViaOrderBy('overall', $request->test_name, $request->test_date_w,
                                                            $type));
                        break;
                    default:
                        break;
                }
            });

        try {
            return $data->paginate(QueryHelper::limit());

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getValueViaOrderBy($column, $testName, $testDateFinal, $typeOrderBy = 'DESC')
    {
        $data = $this->model
            ->where(function ($query) use ($testName, $testDateFinal) {
                $query->where('test_name', $testName)
                      ->where('test_date_final', $testDateFinal);
            })
            ->orderBy($column, $typeOrderBy)
            ->take(1)->first();

        return $data->{$column};
    }

    /**
     * @throws Exception
     */
    public function importIelts(object $request): bool
    {
        $isDynamic = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::IMPORT));

        if (!($isDynamic))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $this->doValidate($request, [
            'file_url' => 'required',
        ]);
        $fileUrl = $request->file_url;

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

        $this->pushToExchange($body, 'IMPORT', AMQPExchangeType::DIRECT, 'ielts');
        $log = BaseService::currentUser()->username . ' import ielts : ' . Carbon::now()->toDateString();
        $this->createELS('import_ielts',
                         $log,
                         [
                             'file_url' => $body['file_url']
                         ]);

        return true;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param string $studentId
     *
     * @return array|object
     */
    public function chartIndividualIelts(string $studentId): array|object
    {
        $isAdminOrPrincipal = $this->isGod();
        $isDynamic
                            = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW));
        $isStudent          = $this->hasAnyRole(RoleConstant::STUDENT);
        if (!$isDynamic && !$isAdminOrPrincipal && !$isStudent) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $studentCode = (new UserService())->get($studentId)?->student_code;
        $query       = $this->queryHelper->buildQuery($this->model)->where('student_code', $studentCode)
                                         ->orderBy('test_date_final', 'ASC');

        try {
            $response = $query->get();
            $testName = $listening = $reading = $speaking = $writing = $overall = $overallScoreIelts = $testDate = [];
            foreach ($response as $chartData) {
                $testName []          = $chartData->test_name ?? null;
                $listening []         = $chartData->listening['score'] ?? null;
                $reading []           = $chartData->reading['score'] ?? null;
                $speaking []          = $chartData->speaking['band_score_s'] ?? null;
                $writing []           = $chartData->writing['band_score_w'] ?? null;
                $overall []           = $chartData->overall ?? null;
                $testDate []          = (new Carbon($chartData->test_date_final))->format('Y-m-d');
                $getTotalOverall
                                      = $this->getAverageScoreIeltsViaDate((new Carbon($chartData->test_date_final))->format('Y-m-d'), $chartData->test_name);
                $overallScoreIelts [] = (float)number_format($getTotalOverall, 3);
            }

            return Collection::make(["test_name" => $testName, "test_date" => $testDate, "chartData" => [
                [
                    "label" => "Listening",
                    "data"  => $listening,
                ],
                [
                    "label" => "Reading",
                    "data"  => $reading,
                ],
                [
                    "label" => "Speaking",
                    "data"  => $speaking,
                ],
                [
                    "label" => "Writing",
                    "data"  => $writing,
                ],
                [
                    "label" => "Overall",
                    "data"  => $overall,
                ],
                [
                    "label" => "Average score",
                    "data"  => $overallScoreIelts ?? null,
                ],
            ]])->all();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 28, 2022
     *
     * @param        $date
     *
     * get average score ielts via date
     * @param string $testName
     *
     * @return int|float
     */
    public function getAverageScoreIeltsViaDate($date, string $testName): int|float
    {
        $queryStudentIelts = $this->model->where('test_date_final', $date)
                                         ->where('test_name', $testName)
                                         ->orderBy('test_date_final', 'ASC');
        $totalOverallIelts = $queryStudentIelts->sum('overall');
        $countStudentIelts = $queryStudentIelts->count();

        return $totalOverallIelts / $countStudentIelts;
    }
}
