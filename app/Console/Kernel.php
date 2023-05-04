<?php

namespace App\Console;

use App\Jobs\CalculateGpa\CalculateCPAScore;
use App\Jobs\CalculateGpa\CalculateGPAScore;
use App\Jobs\GetUrlRecordVcrJob;
use App\Jobs\NotificationTaskManagement\NotificationTaskManagementJob;
use App\Jobs\ScanData\ScanCourseIdJob;
use App\Jobs\SearchingGraduatedStudent;
use App\Jobs\SendHeartbeat;
use App\Jobs\SyncActivity\SyncActivityAgilix;
use App\Jobs\SyncActivity\SyncActivityEdmentum;
use App\Jobs\SyncAssignment\SyncAssignmentAgilix;
use App\Jobs\SyncAssignment\SyncAssignmentEdmentum;
use App\Jobs\SyncClass\SyncClassAgilix;
use App\Jobs\SyncClass\SyncClassEdmentum;
use App\Jobs\SyncCourse\SyncCourseAgilix;
use App\Jobs\SyncCourse\SyncCourseEdmentum;
use App\Jobs\SyncScore\SyncScoreAgilix;
use App\Jobs\SyncScore\SyncScoreEdmentum;
use App\Jobs\SyncZone\SyncZoneAgilix;
use App\Jobs\SyncZone\SyncZoneEdmentum;
use App\Jobs\UpdateStatusSurvey\UpdateStatusSurveyJob;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands
        = [
            //
        ];

    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->job(new SendHeartbeat())->everyMinute();
        $schedule->command('log:clear')->daily();

        $schedule->job(new SyncZoneEdmentum())->dailyAt("00:00");
        $schedule->job(new SyncZoneAgilix())->dailyAt("00:00");

        // $schedule->job(new SyncCourseEdmentum())->everyTwoMinutes();
        // $schedule->job(new SyncCourseAgilix())->everyTwoMinutes();
        //
        // $schedule->job(new SyncClassEdmentum())->everyFiveMinutes();
        // $schedule->job(new SyncClassAgilix())->everyFiveMinutes();

        $schedule->job(new SyncScoreEdmentum())->everyTwoMinutes();
        $schedule->job(new SyncScoreAgilix())->everyTwoMinutes();

        $schedule->job(new SyncActivityEdmentum())->everyTwoMinutes();
        $schedule->job(new SyncActivityAgilix())->everyTwoMinutes();

        // $schedule->job(new SyncAssignmentAgilix())->everyTwoMinutes();
        // $schedule->job(new SyncAssignmentEdmentum())->everyTwoMinutes();

        $schedule->job(new CalculateGPAScore())->everyTenMinutes();
        $schedule->job(new CalculateCPAScore())->dailyAt("02:00");

        $schedule->job(new SearchingGraduatedStudent())->dailyAt("03:00");

        $schedule->job(new ScanCourseIdJob())->everyTwoMinutes();
        $schedule->job(new NotificationTaskManagementJob)->everyFiveMinutes();
        $schedule->job(new UpdateStatusSurveyJob)->everyMinute();
        $schedule->job(new GetUrlRecordVcrJob())->everyMinute();
    }
}
