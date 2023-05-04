<?php

namespace App\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use YaangVu\Constant\TaskManagementConstant;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\MainTaskSQL;
use YaangVu\SisModel\App\Models\impl\SubTaskSQL;
use YaangVu\SisModel\App\Models\impl\TaskCommentSQl;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class TaskCommentService extends BaseService
{
    protected UserService $userService;

    function createModel(): void
    {
        $this->model       = new TaskCommentSQl();
        $this->userService = new UserService();
    }

    public function getAll(): LengthAwarePaginator
    {
        $request = Request();
        $rules   = [
            'main_task_id' => 'integer|exists:main_tasks,id',
            'sub_task_id'  => 'integer|exists:sub_tasks,id',
        ];

        BaseService::doValidate($request, $rules);
        $mainTaskId = $request->main_task_id ?? null;
        $subTaskId  = $request->sub_task_id ?? null;
        $limit      = $request->limit ?? QueryHelper::limit();
        $data       = $this->queryHelper->buildQuery($this->model)
                                        ->when($mainTaskId, function ($q) use ($mainTaskId) {
                                            $q->where('main_task_id', $mainTaskId);
                                        })
                                        ->when($subTaskId, function ($q) use ($subTaskId) {
                                            $q->where('sub_task_id', $subTaskId);
                                        });
        try {
            $arrCreatedBy    = $data->pluck('created_by')->toArray();
            $response        = $data->paginate($limit);
            $users           = UserSQL::query()->with('userNoSql')->whereIn('id', $arrCreatedBy)->get();
            $mapListComments = $response->getCollection()->map(function ($object) use ($users) {
                $object->user = $users->where('id', $object->created_by)->first();

                return $object;
            });

            return (new SurveyReportService())->paginateCollection($response, $mapListComments);
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @throws Exception
     */
    public function postAdd(object $request, Model $model)
    {
        $mainTask            = MainTaskSQL::query()->where('id', $model->main_task_id)->first();
        $subTasks            = SubTaskSQL::query()->where('main_task_id', $model->main_task_id)->get()->toArray();
        $individualTask      = SubTaskSQL::query()->where('id', $model->sub_task_id)
                                         ->where('type', TaskManagementConstant::INDIVIDUAL)
                                         ->first();
        $currentUser         = BaseService::currentUser();
        $fullNameCurrentUser = $currentUser->userNoSql->full_name ?? null;
        if (!empty($mainTask)) {
            $arrIdsNosql = [];
            $arrIdsSql   = [];
            foreach ($subTasks as $subTask) {
                array_push($arrIdsNosql, $subTask['owner_id_no_sql'], $subTask['assignee_id_no_sql'],
                           $subTask['reviewer_id_no_sql']);
                array_push($arrIdsSql, $subTask['owner_id'], $subTask['assignee_id'],
                           $subTask['reviewer_id']);
            }
            $arrIdsSql   = array_diff(array_unique($arrIdsSql), (array)$currentUser->id) ?? [];
            $arrIdsNoSql = array_diff(array_unique($arrIdsNosql), (array)$currentUser->userNoSql->_id) ?? [];
            if (!empty($arrIdsSql)) {
                $linkDetailCommentSubTask = env('URL_PROJECT') . '/edit-main-task/' . $mainTask->id ?? null;
                $content                  = 'Posted add new comment on ' . $mainTask->project_name;
                $title                    = 'Add new comment ';
                (new SubTaskService())->sendNotificationTaskManagement($arrIdsSql,
                                                                       $arrIdsNoSql, $title, $content,
                                                                       $linkDetailCommentSubTask,
                                                                       $fullNameCurrentUser);

            }
        }
        if (!empty($individualTask)) {
            $linkDetailIndividualTask = env('URL_PROJECT') . '/edit-individual-task/' . $individualTask->id;
            $userIdsSql               = [
                $individualTask->assignee_id,
                $individualTask->reviewer_id,
                $individualTask->owner_id,
            ];
            $userIdsNoSql             = [
                $individualTask->assignee_id_no_sql,
                $individualTask->reviewer_id_no_sql,
                $individualTask->owner_id_no_sql,
            ];
            $arrIdsSql                = array_diff(array_unique($userIdsSql), (array)$currentUser->id);
            $arrIdsNoSql              = array_diff(array_unique($userIdsNoSql), (array)$currentUser->userNoSql->_id);
            if (!empty($userIdsNoSql)) {
                $content = 'Posted add new comment on ' . $individualTask->task_name;
                $title   = 'Add new comment ';

                (new SubTaskService())->sendNotificationTaskManagement($arrIdsSql, $arrIdsNoSql, $title, $content,
                                                                       $linkDetailIndividualTask,
                                                                       $fullNameCurrentUser);
            }

        }
        parent::postAdd($request, $model); // TODO: Change the autogenerated stub
    }

}
