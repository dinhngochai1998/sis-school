<?php
/**
 * @Author Edogawa Conan
 * @Date   Sep 28, 2021
 */

namespace App\Helpers;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Log;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

trait ElasticsearchHelper
{
    use RabbitMQHelper;

    /**
     * @Author Edogawa Conan
     * @Date   Sep 24, 2021
     *
     * @param string $nestedField
     * @param string $activity
     * @param int    $page
     * @param int    $limit
     *
     * @return mixed
     */
    public function searchELSViaNestedField(string $nestedField, string $activity, int $page = 0,
                                            int    $limit = 10): mixed
    {
        $url  = env('ELASTICSEARCH_DOMAIN') . '/' . env('ELASTICSEARCH_INDEX') . '/_search';
        $body = [
            'query' => [
                'term' => [
                    $nestedField => $activity
                ]
            ],
            'from'  => $page,
            'size'  => $limit
        ];

        $response     = Http::post($url, $body);
        $statusCode   = $response->status();
        $responseBody = json_decode($response->body());
        if ($statusCode >= 200 && $statusCode < 300) {
            Log::info("get elasticsearch success with nested field : $nestedField , activity : $activity");

            return $responseBody;
        } else {
            Log::info("get elasticsearch false with error : ", (array)$responseBody);

            return false;
        }
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 24, 2021
     *
     * @param string $log
     *
     * @return mixed
     */
    public function searchAllELS(string $log = ''): mixed
    {
        $url = env('ELASTICSEARCH_DOMAIN') . "/" . env('ELASTICSEARCH_INDEX') . "/_doc/_search?q=" . $log;

        $response     = Http::get($url);
        $statusCode   = $response->status();
        $responseBody = json_decode($response->body());

        if ($statusCode >= 200 && $statusCode < 300) {
            Log::info("get elasticsearch success with value : $log");

            return $responseBody;
        } else {
            Log::info("get elasticsearch false with error : ", (array)$responseBody);

            return false;
        }
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 24, 2021
     *
     * @param string|int $id
     *
     * @return mixed
     */
    public function searchELSViaId(string|int $id): mixed
    {
        $url = env('ELASTICSEARCH_DOMAIN') . "/" . env('ELASTICSEARCH_INDEX') . "/_doc/$id=";

        $response     = Http::get($url);
        $responseBody = json_decode($response->body());

        if ($response->status() >= 200 && $response->status() < 300) {
            Log::info("get elasticsearch success with id : $id");

            return $responseBody;
        } else {
            Log::info("get elasticsearch false with error : ", (array)$responseBody);

            return false;
        }
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 28, 2021
     *
     * @param string      $type
     * @param string      $log
     * @param array       $context
     * @param string|null $index
     *
     * @throws Exception
     */
    public function createELS(string $type, string $log, array $context = [], string $index = null)
    {
        $currentUser = BaseService::currentUser();
        $body        = [
            'index' => $index ?? env('ELASTICSEARCH_INDEX'),
            'log'   => (object)[
                'type'  => $type,
                'who'   => (object)[
                    'payload'     => (object)[
                        'created_by' => $currentUser?->id,
                        'username'   => $currentUser?->username,
                        'uuid'       => $currentUser?->uuid
                    ],
                    'name'        => $currentUser?->username,
                    'description' => 'log created by'
                ],
                'where' => (object)[
                    'payload'     => (object)[
                        'class'       => debug_backtrace()[1]['class'],
                        'function'    => debug_backtrace()[1]['function'],
                        'service'     => env('APP_NAME'),
                        'school_name' => SchoolServiceProvider::$currentSchool->name,
                        'school_uuid' => SchoolServiceProvider::$currentSchool->uuid,
                    ],
                    'name'        => debug_backtrace()[1]['class'],
                    'description' => 'log occurs at'
                ],
                'when' => (object)[
                    'payload'     => (object)[
                        'date_time_zone' => (string)Carbon::now(),
                        'date'           => Carbon::now()->toDateString(),
                        'time'           => Carbon::now()->toTimeString(),
                        'timestamp'      => Carbon::now()->timestamp
                    ],
                    'name'        => Carbon::now()->toDateString(),
                    'description' => 'log generated at'
                ],
                'what' => (object)[
                    'payload'     => (object)[
                        'message_log' => $log,
                        'context'     => (object)$context
                    ],
                    'name'        => $type,
                    'description' => 'log for'
                ]
            ]
        ];
        $this->pushToExchange($body, 'LOG', AMQPExchangeType::DIRECT, 'activity');
    }
}
