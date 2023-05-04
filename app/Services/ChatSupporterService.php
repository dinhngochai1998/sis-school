<?php
/**
 * @Author im.phien
 * @Date   Jul 11, 2022
 */

namespace App\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use phpDocumentor\Reflection\Types\This;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ChatSupporterService extends BaseService
{
    use RoleAndPermissionTrait;

    function createModel(): void
    {
        $this->model = new UserNoSQL();
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->checkRoleConfigSupporter();

        return $this->queryHelper->buildQuery($this->model)->whereNotNull('is_supporter')
                                 ->paginate(QueryHelper::limit());
    }

    public function updateSupporter(Request $request): int
    {
        $rules = [
            'user_ids'   => 'required|array',
            'user_ids.*' => 'required|exists:mongodb.users,_id'
        ];
        $this->doValidate($request, $rules);
        $this->checkRoleConfigSupporter();
        try {
            $this->model::query()->whereNotNull('is_supporter')->update(['is_supporter' => null]);

            return $this->model::query()->whereIn('_id', $request->user_ids)->update(['is_supporter' => true]);
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function checkRoleConfigSupporter()
    {
        if (!$this->hasPermission(PermissionConstant::setting(PermissionActionConstant::CONFIG))) {
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        }
    }
}