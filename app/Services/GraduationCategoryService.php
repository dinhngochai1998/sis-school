<?php


namespace App\Services;


use Carbon\Carbon;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\ArrayShape;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\GraduationCategorySQL;
use YaangVu\SisModel\App\Models\impl\GraduationCategorySubjectSQL;
use YaangVu\SisModel\App\Models\impl\ProgramGraduationCategorySQL;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class GraduationCategoryService extends BaseService
{
    use RoleAndPermissionTrait;

    function createModel(): void
    {
        $this->model = new GraduationCategorySQL();
    }

    public function getAll(): LengthAwarePaginator
    {
        $this->preGetAll();
        $programIds = request()->all()['program_ids'] ?? null;
        $this->queryHelper->removeParam('program_ids');
        $this->queryHelper->relations = ['programs', 'subjects'];
        $data                         = $this->queryHelper
            ->buildQuery($this->model)
            ->select('graduation_categories.*')
            ->when(($programIds && gettype($programIds) === 'array'),
                function ($q) use ($programIds) {
                    $q->join('program_graduation_category as pgc', 'pgc.graduation_category_id', '=',
                             'graduation_categories.id');
                    $q->whereIn('pgc.program_id', $programIds);
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
        $this->queryHelper->relations = ['programs', 'subjects'];
        parent::preGet($id);
    }

    public function preAdd(object $request): object
    {
        if (!$this->hasPermission(PermissionConstant::graduationCategory(PermissionActionConstant::ADD)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $userId = self::currentUser()->id ?? null;
        if ($request instanceof Request)
            $request->merge(['created_by' => $userId]);
        else
            $request->created_by = $userId;

        return $request;
    }

    public function storeRequestValidate(object $request, array $rules = [], array $messages = []): bool|array
    {
        $rules = [
            'name'          => 'required|iunique:graduation_categories,name',
            'status'        => 'in:Active,Inactive',
            'subject_ids'   => 'required|array',
            'subject_ids.*' => 'required|exists:subjects,id'
        ];

        return parent::storeRequestValidate($request, $rules);
    }

    public function postAdd(object $request, Model|GraduationCategorySQL $model)
    {
        $this->insertBathGraduationCategorySubject($request->subject_ids, $model->id);
    }

    private function insertBathGraduationCategorySubject($subjectIds, $graduationCategoryId)
    {
        $currentUser = self::currentUser();
        foreach ($subjectIds as $subjectId) {
            $data [] = [
                'uuid'                   => Uuid::uuid(),
                'graduation_category_id' => $graduationCategoryId ?? null,
                'subject_id'             => $subjectId,
                'created_by'             => $currentUser?->id
            ];
        }
        GraduationCategorySubjectSQL::insert($data ?? []);
    }

    public function preUpdate(int|string $id, object $request)
    {
        if (!$this->hasPermission(PermissionConstant::graduationCategory(PermissionActionConstant::EDIT)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        parent::preUpdate($id, $request);
    }

    public function updateRequestValidate(int|string $id, object $request, array $rules = [],
                                          array      $messages = []): bool|array
    {
        $rules = [
            'name'          => "sometimes|required|iunique:graduation_categories,name,$id",
            'status'        => 'sometimes|nullable|in:Active,Inactive',
            'subject_ids'   => 'sometimes|required|array',
            'subject_ids.*' => 'sometimes|required|exists:subjects,id'
        ];

        return parent::updateRequestValidate($id, $request, $rules);
    }

    public function postUpdate(int|string $id, object $request, Model|GraduationCategorySQL $model)
    {
        if (isset($request->subject_ids) && count($request->subject_ids) > 0) {
            GraduationCategorySubjectSQL::whereGraduationCategoryId($model->id)->forceDelete();
            $this->insertBathGraduationCategorySubject($request->subject_ids, $model->id);
        }
    }

    public function preDelete(int|string $id)
    {
        if (!$this->hasPermission(PermissionConstant::graduationCategory(PermissionActionConstant::DELETE)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $graduationCategory       = $this->get($id);
        $graduationCategory->name = $graduationCategory->name . ' ' . Carbon::now()->timestamp;
        $graduationCategory->save();

        parent::preDelete($id);
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 28, 2021
     *
     * @param int|string $id
     *
     * @return Model|GraduationCategorySQL
     */
    public function get(int|string $id): Model|GraduationCategorySQL
    {
        return parent::get($id);
    }

    public function postDelete(int|string $id)
    {
        ProgramGraduationCategorySQL::whereGraduationCategoryId($id)->forceDelete();
        parent::postDelete($id);
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Apr 14, 2022
     *
     * @param $request
     *
     * @return array
     */
    #[ArrayShape(['list' => "array", 'total' => "int[]"])]
    public function getCreditSummaryViaUserIdAndProgramId($request): array
    {
        $user = (new UserService())->get($request->user_id);

        return (new GraduationCategorySubjectService())->getUserAcademicPlan($user->uuid, (int)$request->program_id);

    }
}
