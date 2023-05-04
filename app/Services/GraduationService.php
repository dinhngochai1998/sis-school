<?php
/**
 * @Author Admin
 * @Date   Apr 25, 2022
 */

namespace App\Services;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\GraduationNoSql;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class GraduationService extends BaseService
{
    use RoleAndPermissionTrait;

    function createModel(): void
    {
        $this->model = new GraduationNoSql();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Apr 25, 2022
     *
     * @param $request
     * @param $userId
     *
     * @return array
     */
    public function upsertGraduation($request, $userId)
    {
        $dynamicEdit = $this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::EDIT),
                                                     RoleServiceProvider::$currentRole->id);
        if (!$this->isGod() && !$dynamicEdit) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $graduationAwardInformation = [];
        foreach ($request->graduation_award_information ?? [] as $graduationAward) {
            $graduationAwardInformation [] = array_map("trim", (array)$graduationAward);
        }
        $user           = (new UserService())->get($userId);
        $dataGraduation = [
            'program_ids'                      => $user->program_ids ?? null,
            'user_uuid'                        => $user->uuid,
            'user_id'                          => $user->_id,
            'date_first_entered_the_9th_grade' => $request->date_first_entered_the_9th_grade ?? null,
            'diploma_date'                     => $request->diploma_date ?? null,
            'diploma_type'                     => trim($request->diploma_type) ?? null,
            'comment'                          => trim($request->comment) ?? null,
            'graduation_award_information'     => $graduationAwardInformation,
        ];

        $this->model->updateOrInsert(['user_id' => $user->_id], $dataGraduation);

        return $dataGraduation;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Apr 25, 2022
     *
     * @param $userId
     *
     * @return Builder|Model|null
     */
    public function getGraduationByUserId($userId): Model|Builder|null
    {
        $dynamic                 = $this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::VIEW),
                                                                 RoleServiceProvider::$currentRole->id);
        $roleTeacherAndFamily    = $this->hasAnyRole(RoleConstant::TEACHER, RoleConstant::FAMILY);
        $roleStudentAndCounselor = $this->hasAnyRole(RoleConstant::STUDENT, RoleConstant::COUNSELOR);
        if (!$this->isGod() && !$dynamic && !$roleTeacherAndFamily && !$roleStudentAndCounselor) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $user = (new UserService())->get($userId);

        return $this->model->where('user_id', $user->_id)->first();
    }

}