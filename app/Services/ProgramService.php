<?php


namespace App\Services;


use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ProgramGraduationCategorySQL;
use YaangVu\SisModel\App\Models\impl\ProgramSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ProgramService extends BaseService
{
    use RoleAndPermissionTrait;

    protected array $status = [StatusConstant::ACTIVE, StatusConstant::INACTIVE];

    function createModel(): void
    {
        $this->model = new ProgramSQL();
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->preGetAll();
        $request = \request()->all();
        $userId  = $request['user_id'] ?? null;
        $this->queryHelper->removeParam('user_id');
        $querySumPGC = ProgramGraduationCategorySQL::selectRaw
        (
            "SUM(NULLIF(credit, 0)::int) as total_credit,
             COUNT(program_id) count_graduation_categories ,
             program_id"
        )->groupBy('program_id');
        $data        = $this->queryHelper
            ->buildQuery($this->model)
            ->selectRaw("programs.*, pgc.total_credit , pgc.count_graduation_categories")
            ->where('programs.school_id', SchoolServiceProvider::$currentSchool->id)
            ->leftJoinSub($querySumPGC, 'pgc', 'pgc.program_id', '=', 'programs.id')
            ->when($userId, function (EBuilder $q) use ($userId) {
                $userNoSql = (new UserService())->get($userId);
                $q->join('user_program', 'user_program.program_id', '=', 'programs.id');
                $q->where('user_program.user_id', $userNoSql?->userSql->id ?? null);
            });
        try {
            $response = $data->paginate(QueryHelper::limit());
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function preGet(int|string $id)
    {
        $this->queryHelper->with(['graduationCategories']);
        parent::preGet($id);
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 28, 2021
     *
     * @param int|string $id
     *
     * @return Model|ProgramSQL
     */
    public function get(int|string $id): Model|ProgramSQL
    {
        return parent::get($id);
    }

    public function preAdd(object $request): object
    {

        if (!$this->hasPermission(PermissionConstant::program(PermissionActionConstant::ADD)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        if ($request instanceof Request)
            $request = (object)$request->toArray();

        $request->school_id = SchoolServiceProvider::$currentSchool->id;

        return $request;
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        $rules = [
            'name'                           => 'required|iunique:programs,name|max:255',
            'status'                         => 'nullable|in:' . implode(',', $this->status),
            'graduation_categories'          => 'nullable|array',
            'graduation_categories.*.id'     => 'required|exists:graduation_categories,id,deleted_at,NULL,status,' . StatusConstant::ACTIVE,
            'graduation_categories.*.credit' => 'required|numeric'
        ];

        return parent::storeRequestValidate($request, $rules);
    }

    public function postAdd(object $request, Model|ProgramSQL $model)
    {
        if (isset($request->graduation_categories) && !empty($request->graduation_categories)) {
            foreach ($request->graduation_categories as $graduationCategory) {
                $this->_saveProgramGraduationCategory($model->id, $graduationCategory);
            }
        }
        parent::postAdd($request, $model);
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array $messages = []): bool|array
    {
        $rules = [
            'name'                           => "sometimes|required|iunique:programs,name,$id|max:255",
            'status'                         => 'nullable|in:' . implode(',', $this->status),
            'graduation_categories'          => 'nullable|array',
            'graduation_categories.*.id'     => 'required|exists:graduation_categories,id,deleted_at,NULL,status,' . StatusConstant::ACTIVE,
            'graduation_categories.*.credit' => 'required|numeric'
        ];

        return parent::updateRequestValidate($id, $request, $rules);
    }

    public function postUpdate(int|string $id, object $request, Model $model)
    {
        if (is_array($request->graduation_categories ?? null)) {
            ProgramGraduationCategorySQL::whereProgramId($id)->forceDelete();
            foreach ($request->graduation_categories as $graduationCategory) {
                $this->_saveProgramGraduationCategory($id, $graduationCategory);
            }
        }

        parent::postUpdate($id, $request, $model);
    }

    private function _saveProgramGraduationCategory(int $programId, array $graduationCategory): void
    {
        $pgc                         = new ProgramGraduationCategorySQL();
        $pgc->program_id             = $programId;
        $pgc->graduation_category_id = $graduationCategory['id'] ?? null;
        $pgc->credit                 = $graduationCategory['credit'] ?? null;

        $pgc->save();
    }

    public function preDelete(int|string $id)
    {
        $program       = $this->get($id);
        $program->name = $program->name . ' ' . Carbon::now()->timestamp;
        $program->save();
        parent::preDelete($id);
    }

    public function postDelete(int|string $id)
    {
        ProgramGraduationCategorySQL::whereProgramId($id)->forceDelete();
        parent::postDelete($id);
    }
}
