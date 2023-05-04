<?php

namespace App\Services;

use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Date;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\TaskManagementConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\MainTaskSQL;
use YaangVu\SisModel\App\Models\impl\SubTaskSQL;
use YaangVu\SisModel\App\Models\impl\TaskStatusSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class TaskManagementService
{
    use RoleAndPermissionTrait;

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 28, 2022
     *
     * @param $request
     *
     * @return LengthAwarePaginator
     * @throws Exception
     */
    public function getAllListTaskManagement($request): LengthAwarePaginator
    {
        $isAllList = $this->hasPermission(PermissionConstant::taskManagement(PermissionActionConstant::ALL_LIST));

        if (!$isAllList && !$request->filter_assigneed) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $rules = [
            'sent_date.*' => 'date|date_format:Y-m-d',
            'type'        => 'in:' . TaskManagementConstant::INDIVIDUAL . ',' . TaskManagementConstant::MAIN_TASK,
            'assignee_id' => 'exists:mongodb.users,_id',
        ];

        BaseService::doValidate($request, $rules);
        $currentUserId = BaseService::currentUser()->userNoSql->_id;
        $assigneeId    = $request->assignee_id ?? null;
        $statusId      = $request->task_status_id ?? null;

        $mainTaskIdAndAssigneeOrReviewerIds = [];
        if ($assigneeId) {
            $mainTaskIdAndAssigneeOrReviewerIds = $this->getMainTaskIdsAndAssigneeOrReviewerIds($assigneeId,
                                                                                                'assignee_id_no_sql');

        }
        if ($request->filter_assigneed == TaskManagementConstant::ASSIGNED) {
            $mainTaskIdAndAssigneeOrReviewerIds = $this->getMainTaskIdsAndAssigneeOrReviewerIds($currentUserId,
                                                                                                'assignee_id_no_sql');
        }
        if ($request->filter_assigneed == TaskManagementConstant::REVIEWED) {
            $mainTaskIdAndAssigneeOrReviewerIds = $this->getMainTaskIdsAndAssigneeOrReviewerIds($currentUserId,
                                                                                                'reviewer_id_no_sql');
        }
        $queryMainTasks = MainTaskSQL::query()
                                     ->select('id', 'project_name', 'owner_id_no_sql', 'type', 'owner_id',
                                              'created_at', 'school_id', 'task_status_id')
                                     ->when($assigneeId, function ($q) use ($mainTaskIdAndAssigneeOrReviewerIds) {
                                         $q->whereIn('id',
                                                     $mainTaskIdAndAssigneeOrReviewerIds['main_task_id']);
                                     })
                                     ->when($request->filter_assigneed == TaskManagementConstant::ASSIGNED,
                                         function ($q) use ($mainTaskIdAndAssigneeOrReviewerIds) {
                                             $q->whereIn('id',
                                                         $mainTaskIdAndAssigneeOrReviewerIds['main_task_id']);
                                         })
                                     ->when($request->filter_assigneed == TaskManagementConstant::REVIEWED,
                                         function ($q) use ($mainTaskIdAndAssigneeOrReviewerIds) {
                                             $q->whereIn('id',
                                                         $mainTaskIdAndAssigneeOrReviewerIds['main_task_id']);
                                         })
                                     ->when($statusId, function ($q) use ($statusId) {
                                         $q->where('task_status_id', $statusId);
                                     })
                                     ->where('school_id', SchoolServiceProvider::$currentSchool->id);

        $mainTasks = $this->queryListTasks($queryMainTasks, $request, 'project_name');

        $queryIndividualTasks = SubTaskSQL::query()
                                          ->select('id', 'task_name as project_name', 'owner_id_no_sql', 'type',
                                                   'owner_id', 'created_at', 'school_id', 'task_status_id')
                                          ->when($request->filter_assigneed == TaskManagementConstant::ASSIGNED,
                                              function ($q) use ($mainTaskIdAndAssigneeOrReviewerIds) {
                                                  $q->where('assignee_id_no_sql',
                                                            $mainTaskIdAndAssigneeOrReviewerIds['assignee_or_reviewer_ids']);
                                              })
                                          ->when($request->filter_assigneed == TaskManagementConstant::REVIEWED,
                                              function ($q) use ($mainTaskIdAndAssigneeOrReviewerIds) {
                                                  $q->where('reviewer_id_no_sql',
                                                            $mainTaskIdAndAssigneeOrReviewerIds['assignee_or_reviewer_ids']);
                                              })
                                          ->when($assigneeId,
                                              function ($q) use ($mainTaskIdAndAssigneeOrReviewerIds) {
                                                  $q->whereIn('assignee_id_no_sql',
                                                              $mainTaskIdAndAssigneeOrReviewerIds['assignee_or_reviewer_ids']);
                                              })
                                          ->when($statusId, function ($q) use ($statusId) {
                                              $q->where('task_status_id', $statusId);
                                          })
                                          ->where('school_id', SchoolServiceProvider::$currentSchool->id);

        $individualTasks = $this->queryListTasks($queryIndividualTasks, $request, 'task_name',
                                                 TaskManagementConstant::INDIVIDUAL);

        $orderBy    = $request->order_by ?? null;
        $mergeTasks = $individualTasks->unionAll($mainTasks)
                                      ->when($orderBy == 'project_name asc', function ($q) {

                                          $q->orderBy('project_name');
                                      })
                                      ->when($orderBy == 'project_name desc', function ($q) {

                                          $q->orderByDesc('project_name');
                                      })
                                      ->when($orderBy != 'project_name asc' && $orderBy != 'project_name desc',
                                          function ($q) {
                                              $q->orderByDesc('created_at');
                                          });

        $userIds   = $mergeTasks->pluck('owner_id_no_sql')
                                ->toArray();
        $mainTasks = MainTaskSQL::query()->whereIn('id', $mergeTasks->pluck('id')->toArray())
                                ->get();
        $subTasks  = SubTaskSQL::query()
                               ->whereIn('main_task_id', $mainTasks->pluck('id')->toArray())
                               ->get()
                               ->groupBy('main_task_id');

        $status              = TaskStatusSQL::query()
                                            ->whereIn('id', $mergeTasks
                                                ->pluck('task_status_id')->toArray())
                                            ->get();
        $users               = UserNoSQL::query()->whereIn('_id', $userIds)->get();
        $listTasks           = $mergeTasks->paginate(QueryHelper::limit());
        $listTaskManagements = $listTasks->getCollection()->map(function ($object) use (
            $users, $status, $subTasks, $mainTasks
        ) {
            $mainTask          = $mainTasks->where('id', $object->id)->first();
            $object->full_name = $users->where('_id', $object->owner_id_no_sql)->first()['full_name'] ?? null;
            $object->status    = $status->where('id', $object->task_status_id)->first()->name ?? null;
            $object->subtasks
                               = !empty($mainTask->id) && !empty($subTasks[$mainTask->id]) ? $subTasks[$mainTask->id] : [];

            return $object;
        });

        return (new SurveyReportService())->paginateCollection($listTasks,
                                                               $listTaskManagements);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jul 20, 2022
     *
     * @param $currentUserId
     * @param $field
     *
     * @return array
     */
    public function getMainTaskIdsAndAssigneeOrReviewerIds($currentUserId, $field): array
    {
        $querySubtask             = SubTaskSQL::query()->where($field, $currentUserId);
        $assigneeIdsOrReviewerIds = $querySubtask->pluck($field)->toArray();
        $mainTaskIds              = $querySubtask->whereNotNull('main_task_id')->pluck('main_task_id')->toArray();

        return [
            'main_task_id'             => $mainTaskIds,
            'assignee_or_reviewer_ids' => $assigneeIdsOrReviewerIds,
        ];
    }

    public function queryListTasks($query, $request, $taskName, $typeIndividual = null)
    {
        $title    = $request->title ?? null;
        $type     = $request->type ?? null;
        $fromDate = $request['sent_date'][0] ?? null;
        $toDate   = $request['sent_date'][1] ?? null;
        $ownerId  = $request->owner_id ?? null;
        $userId   = BaseService::currentUser()->userNoSql->_id;
        $query->when($typeIndividual,
            function ($q) use ($typeIndividual) {
                $q->where('type', $typeIndividual);
            })
              ->when($title,
                  function ($q) use ($title, $taskName) {
                      $q->where($taskName, 'ILIKE', '%' . $title . '%');
                  })
              ->when($type,
                  function ($q) use ($type) {
                      $q->where('type', $type);
                  })
              ->when($fromDate,
                  function ($q) use ($fromDate) {
                      $q->whereDate('created_at', '>=', $fromDate);
                  })
              ->when($toDate,
                  function ($q) use ($toDate) {
                      $q->whereDate('created_at', '<=', $toDate);
                  })
              ->when($ownerId,
                  function ($q) use ($ownerId) {
                      $q->where('owner_id_no_sql', $ownerId);
                  })
              ->when($request->filter_assigneed == TaskManagementConstant::OWNED,
                  function ($q) use ($userId) {
                      $q->where('owner_id_no_sql', $userId);
                  });

        return $query;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jul 17, 2022
     *
     * @param $request
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getOwnerTaskManagement($request): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $roleNames = [
            $this->decorateWithSchoolUuid(RoleConstant::PRINCIPAL),
            $this->decorateWithSchoolUuid(RoleConstant::ADMIN)
        ];

        $uuidsAdminAndPrincipal = UserSQL::query()->Join('model_has_roles', 'model_has_roles.model_id', 'users.id')
                                         ->Join('roles', 'roles.id', 'model_has_roles.role_id')
                                         ->whereIn('roles.name', $roleNames)
                                         ->select('model_has_roles.model_id', 'users.*')
                                         ->pluck('uuid')->toArray();
        $searchKey              = $request->search_key ?? null;

        return UserNoSQL::query()
                        ->whereIn('uuid', $uuidsAdminAndPrincipal)
                        ->when($searchKey, function ($q) use ($searchKey) {
                            $q->where(function ($q) use ($searchKey) {
                                $q->where('full_name', 'like', '%' . $searchKey . '%')
                                  ->orWhere('staff_code', 'like', '%' . $searchKey . '%');
                            });
                        })
                        ->paginate(QueryHelper::limit());
    }
}
