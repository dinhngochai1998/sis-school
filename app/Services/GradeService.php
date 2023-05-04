<?php


namespace App\Services;


use Exception;
use Illuminate\Database\Eloquent\Collection;
use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\GradeSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class GradeService extends BaseService
{
    public function createModel(): void
    {
        $this->model = new GradeSQL();
    }

    public function preGetAll()
    {
       $this->queryHelper->addParams(['school_id' => SchoolServiceProvider::$currentSchool->id]);
        parent::preGetAll();
    }


    /**
     * @return Collection
     */
    public function getGradeLevels(): Collection
    {
        try {
            return $this->model->select('grades.id', 'grades.name', 'grades.index')
                               ->orderBy('index', 'asc')
                               ->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     *
     * @param string $name
     *
     * @return Collection
     */
    public function getByName(string $name): Collection
    {
        try {
            return GradeSQL::whereName($name)->select('grades.index', 'grades.name')->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     *
     * @param int $index
     *
     * @return Collection
     */
    public function getByIndex(int $index): Collection
    {
        try {
            return GradeSQL::whereIndex($index)->select('grades.index', 'grades.name')->get();
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }
}
