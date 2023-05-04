<?php

namespace App\Jobs\SyncActivity;

use App\Models\AgilixEnrollment;
use App\Models\AgilixRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Jenssegers\Mongodb\Relations\BelongsTo as MBelongsTo;
use MongoDB\BSON\Decimal128;
use stdClass;
use YaangVu\Constant\LmsSystemConstant;

class SyncActivityAgilix extends SyncActivity
{

    protected string $lmsName = LmsSystemConstant::AGILIX;
    protected string $table   = 'lms_agilix_enrollments';

    public function getData(): Collection
    {
        $studentRole = AgilixRole::where('domainid', '=', '1') // <--> domainname = Root
                                 ->where('name', '=', 'Student')
                                 ->first();
        if (!$studentRole->count())
            return collect();

        return AgilixEnrollment::with(['userNoSql.userSql', 'classSql' => function (BelongsTo|MBelongsTo $query) {
            return $query->where('lms_id', '=', $this->lms->id);
        }])
                               ->where('roleid', '=', (string)$studentRole->id)
            // ->where('courseid', '=', '184383021')
                               ->orderBy($this->jobName . '_at')
                               ->limit($this->limit)
                               ->get()
                               ->toBase();
    }

    public function _handleActivityScore(object $activityScore): ?object
    {
        $this->classSQL  = $activityScore->classSql;
        $this->userNoSQL = $activityScore->userNoSql;

        $data                     = new stdClass();
        $data->source             = $this->lmsName;
        $data->agilix_id          = $activityScore->_id;
        $data->agilix_external_id = $activityScore->id;

        $categories        = $activityScore->grades['categories']['category'] ?? [];
        $handledCategories = [];
        foreach ($categories as $category) {
            $handledCategories[$category['_id']] = $category['name'];
        }

        $activities = collect();

        foreach ($activityScore->grades['items']['item'] ?? [] as $item) {
            $title = trim($item['title']);
            if (!preg_match('/^\*/', $title))
                continue;
            $maxPoint = $item['possible'] ?? null;
            $score    = $item['achieved'] ?? null;

            if ($maxPoint instanceof Decimal128)
                $maxPoint = (double)$maxPoint->__toString();
            if ($score instanceof Decimal128)
                $score = (double)$score->__toString();

            $activities->push(
                [
                    'score'            => $score,
                    'name'             => $title ?? null,
                    'max_point'        => $maxPoint,
                    'percentage_score' => $maxPoint == 0
                        ? 0
                        : (100 * $score / $maxPoint),
                    'category_name'    => $handledCategories[$item['categoryid']] ?? null,
                    'external_id'      => $item['itemid'] ?? null
                ]
            );
        }

        $activities       = $activities->sortBy('name', SORT_NATURAL)->toArray();
        $data->activities = [];
        foreach ($activities as $activity) {
            $data->activities[] = $activity;
        }

        return $data;
    }

}
