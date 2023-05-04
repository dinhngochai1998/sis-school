<?php
/**
 * @Author Admin
 * @Date   Jul 11, 2022
 */

namespace App\Jobs\NotificationTaskManagement;

use App\Jobs\Job;
use App\Services\SubTaskService;
use Exception;
use Illuminate\Support\Carbon;
use YaangVu\Constant\TaskManagementConstant;
use YaangVu\SisModel\App\Models\impl\SubTaskSQL;

class NotificationTaskManagementJob extends Job
{
    protected const WARNING_TIME
        = [
            1,
            3
        ];

    /**
     * @throws Exception
     */
    public function handle()
    {
        $threeDaysAgo  = Carbon::now()->subDays(self::WARNING_TIME[0])->format('Y-m-d');
        $oneDaysAgo    = Carbon::now()->subDays(self::WARNING_TIME[1])->format('Y-m-d');
        $deadlineTasks = SubTaskSQL::query()->with(['subTaskStatus', 'mainTasks'])
                                   ->whereDate('deadline', '=', $threeDaysAgo)
                                   ->orWhereDate('deadline', '=', $oneDaysAgo)
                                   ->get();
        foreach ($deadlineTasks as $deadlineTask) {
            $nameStatus = $deadlineTask->subTaskStatus->name;
            $isStatus
                        = ($nameStatus == TaskManagementConstant::ASSIGNED || $nameStatus == TaskManagementConstant::IN_PROGRESS || $nameStatus == TaskManagementConstant::RE_OPEN);

            if (!$isStatus) {
                continue;
            }

            $deadline = Carbon::parse($deadlineTask->deadline)->format('Y-m-d');
            if ($deadline == $threeDaysAgo) {
                $this->sendNotificationTaskManagement($deadlineTask, self::WARNING_TIME[1]);
            }
            if ($deadline == $oneDaysAgo) {
                $this->sendNotificationTaskManagement($deadlineTask, self::WARNING_TIME[0]);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function sendNotificationTaskManagement($deadlineTask, $day): bool
    {
        $detailUrlTask
            = ($deadlineTask->type == 'individual') ? 'edit-individual-task/' . $deadlineTask->id : 'edit-main-task/' . $deadlineTask->mainTasks->id;

        $contentAndTitleNotification = [
            'title'   => 'The deadline of task  ' . $deadlineTask->task_name . ' is expired in ' . $day . ' day',
            'content' => 'Warning before ' . $day . ' day  deadline'
        ];
        $linkDetailSubTask           = env('URL_PROJECT') . '/' . $detailUrlTask;
        return (new SubTaskService())->sendNotificationTaskManagement((array)$deadlineTask->assignee_id,
                                                                      (array)$deadlineTask->assignee_id_no_sql,
                                                                      $contentAndTitleNotification['title'],
                                                                      $contentAndTitleNotification['content'],
                                                                      $linkDetailSubTask);
    }
}