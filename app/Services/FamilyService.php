<?php


namespace App\Services;


use Illuminate\Support\Collection;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class FamilyService extends UserService
{
    function getChildren(string|int $userId): Collection
    {
        $user = $this->get($userId);

        if (!$user->children_id)
            return collect([]);

        $childrenIds = explode(',', $user->children_id);

        return UserNoSQL::whereIn('_id', $childrenIds)->get();
    }

}
