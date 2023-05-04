<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\CpaSQL;

class CpaService extends BaseService
{

    public Model|Builder|CpaSQL $model;

    function createModel(): void
    {
        $this->model = new CpaSQL();
    }

    /**
     *
     * @param string|int|null $user_id
     * @param int|null        $program_id
     * @param int|null        $school_id
     *
     * @return Builder
     */
    public function getCurrentCpa(null|string|int $user_id, ?int $program_id,
                                  ?int $school_id): Builder
    {
        return $this->model
            ->when($user_id, function (Builder $query) use ($user_id) {
                return $query->where('cpa.user_id', '=', $user_id);
            })
            ->when($program_id, function (Builder $query) use ($program_id) {
                return $query->where('cpa.program_id', $program_id);
            })
            ->when($school_id, function (Builder $query) use ($school_id) {
                return $query->where('cpa.school_id', $school_id);
            });
    }

}
