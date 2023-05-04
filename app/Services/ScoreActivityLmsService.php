<?php
/**
 * @Author Edogawa Conan
 * @Date   Jun 01, 2022
 */

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ScoreActivityLmsSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ScoreActivityLmsService extends BaseService
{
    public function createModel(): void
    {
        $this->model = new ScoreActivityLmsSQL();
    }

    public function upsert(array $data, int $classId): Model|ScoreActivityLmsSQL|Builder
    {
        $school      = SchoolServiceProvider::$currentSchool;
        $currentUser = self::currentUser();

        return ScoreActivityLmsSQL::query()
                                  ->updateOrCreate(
                                      [
                                          'user_id'               => $data['user_id'],
                                          'class_id'              => $classId,
                                          'activity_class_lms_id' => $data['activity_id'],
                                          'school_id'             => $school->id
                                      ],
                                      [
                                          'score'                 => $data['score'],
                                          'user_id'               => $data['user_id'],
                                          'class_id'              => $classId,
                                          'activity_class_lms_id' => $data['activity_id'],
                                          'school_id'             => $school->id,
                                          'created_by'            => $currentUser->id
                                      ]
                                  );
    }

    public function deleteByClassId(int $classId)
    {
        return $this->model->where('class_id', $classId)->delete();
    }
}
