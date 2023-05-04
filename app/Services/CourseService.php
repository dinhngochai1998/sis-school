<?php


namespace App\Services;


use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\CourseSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class CourseService extends BaseService
{
    function createModel(): void
    {
        $this->model = new CourseSQL();
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 28, 2021
     *
     * @param int|string $id
     *
     * @return Model|CourseSQL
     */
    public function get(int|string $id): Model|CourseSQL
    {
        return parent::get($id);
    }

    /**
     * @param string|int $lmsId
     * @param string|int $schoolId
     * @param string|int $externalId
     *
     * @return CourseSQL|null
     */
    public function getByLmsIdAndSchoolIdAndExId(string|int $lmsId, string|int $schoolId,
                                                 string|int $externalId): CourseSQL|null
    {
        return CourseSQL::whereLmsId($lmsId)
                        ->whereExternalId($externalId)
                        ->whereSchoolId($schoolId)
                        ->first();
    }

    public static function queryGetViaLms(int $lmsId): Builder
    {
        $request = request()->all();

        return CourseSQL::whereLmsId($lmsId)
                        ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
                        ->when($request['name'] ?? null, function ($q) use ($request) {
                            $q->where('name', 'ILIKE', '%' . trim($request['name']) . '%');
                        });
    }

    public function preDelete(int|string $id)
    {
        $course       = $this->get($id);
        $course->name = $course->name . ' ' . Carbon::now()->timestamp;
        $course->save();
        parent::preDelete($id);
    }
}
