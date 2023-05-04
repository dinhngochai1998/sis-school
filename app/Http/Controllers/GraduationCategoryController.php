<?php


namespace App\Http\Controllers;


use App\Exports\CreditSummaryExport;
use App\Services\GraduationCategoryService;
use App\Services\UserService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\LaravelBase\Controllers\BaseController;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\UserParentSQL;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class GraduationCategoryController extends BaseController
{
    use RoleAndPermissionTrait;

    public function __construct()
    {
        $this->service = new GraduationCategoryService();
        parent::__construct();
    }

    public function creditSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        $roleCounselorAndTeacherAndFamily = $this->hasAnyRole(RoleConstant::COUNSELOR, RoleConstant::TEACHER,
                                                              RoleConstant::FAMILY);
        if (!$this->isGod() && !$roleCounselorAndTeacherAndFamily && !$this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::VIEW),
                                                                                                    RoleServiceProvider::$currentRole->id)) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        return response()->json($this->service->getCreditSummaryViaUserIdAndProgramId($request));
    }

    public function exportCreditSummary(Request $request): Response|BinaryFileResponse
    {
        $roleCounselorAndTeacherAndFamily = $this->hasAnyRole(RoleConstant::COUNSELOR, RoleConstant::TEACHER,
                                                              RoleConstant::FAMILY);
        if (!$this->isGod() && !$roleCounselorAndTeacherAndFamily && !$this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::VIEW),
                                                                                                    RoleServiceProvider::$currentRole->id)) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        BaseService::doValidate($request, [
            'program_id'      => 'required|exists:programs,id',
            'user_id' => 'required|exists:mongodb.users,_id,deleted_at,NULL',
        ]);

        return (new CreditSummaryExport($request->input('user_id'),$request->input('program_id')))->download();
    }

}
