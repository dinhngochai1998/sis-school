<?php

namespace App\Jobs;

use App\Jobs\CalculateGpa\CalculateCPAScore;
use App\Jobs\CalculateGpa\CalculateGPAScore;
use App\Jobs\Sms\SendSmsJob;
use App\Jobs\Survey\NotificationSurveyJob;
use App\Queues\SyncClassQueueFactory;
use Exception;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

class RabbitMQConsumer extends BaseJob
{
    #[ArrayShape(['job' => "string", 'data' => "mixed"])]
    public function payload(): array
    {
        $payload = json_decode($this->getRawBody(), false);
        $job     = $this->_getJobClass();

        return [
            'job'  => "$job@handle",
            'data' => $payload
        ];
    }

    /**
     * @return string
     */
    private function _getJobClass(): string
    {
        $queue = $this->getQueue();
        Log::info("Run with queue name : $queue");

        return match ($queue) {
            'sample_queue' => RabbitMQSampleQueue::class,
            'import_activity_score' => ImportActivityScoreJob::class,
            'calculate_gpa_score' => CalculateGPAScore::class,
            'calculate_cpa_score' => CalculateCPAScore::class,
            'import_sri_smi' => ImportSriSmi::class,
            'sync_class_lms' => SyncClassQueueFactory::class,
            'import_physical_performance_measures' => ImportPhysicalPerformanceMeasuresJob::class,
            'import_act' => ImportActJob::class,
            'import_sbac' => ImportSbacJob::class,
            'import_ielts' => ImportIeltsJob::class,
            'import_sat' => ImportSatJob::class,
            'import_toefl' => ImportToeflJob::class,
            'import_enroll_student' => ImportEnrollStudentJob::class,
            'send_notification_survey' => NotificationSurveyJob::class,
            'send_sms' => SendSmsJob::class,
            default => UnknownQueue::class
        };
    }

    function fire()
    {
        try {
            parent::fire();
        } catch (Exception $exception) {
            Log::debug($exception);
            $this->markAsFailed();
        } finally {
            $this->rabbitmq->ack($this);
        }
    }
}
