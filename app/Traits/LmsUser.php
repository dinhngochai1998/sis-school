<?php
/**
 * @Author yaangvu
 * @Date   Aug 30, 2021
 */

namespace App\Traits;

use App\Services\RoleService;
use Illuminate\Support\Facades\Crypt;
use JetBrains\PhpStorm\Pure;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;

trait LmsUser
{
    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Aug 30, 2021
     *
     * @param object $user
     *
     * @return object|null
     */
    public function lmsUser(object $user): ?object
    {
        $role = $this->mappingLmsRole($user->role_names ?? null);
        if (!$role)
            return null;

        if ($role == RoleConstant::TEACHER)
            $userCode = $user->staff_code ?? "";
        else
            $userCode = $user->student_code ?? "";

        try {
            $password = $user->password ? Crypt::decrypt($user->password) : null;
        } catch (\RuntimeException $e) {
            $password = $user->password;
        }

        return (object)[
            "uuid"       => $user->uuid ?? null, //uuid from users in Mongodb
            "username"   => $user->username ?? null,
            "email"      => $user->email ?? null,
            "grade"      => $user->grade ?? null,
            "firstName"  => $user->first_name ?? null,
            "lastName"   => $user->last_name ?? null,
            "middleName" => $user->middle_name ?? null,
            "role"       => $role,
            "userCode"   => $userCode,
            "password"   => $password,
            "isActive"   => ($user->status ?? StatusConstant::ACTIVE) == StatusConstant::ACTIVE
        ];
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Aug 30, 2021
     *
     * @param string|array|null $roles
     *
     * @return string|null
     */
    #[Pure]
    public function mappingLmsRole(string|array|null $roles): ?string
    {
        if ($roles === null)
            return null;

        if (is_string($roles))
            $roles = [$roles];

        $schoolRoles = [];
        foreach ($roles as $role)
            $schoolRoles[] = RoleService::removeScId($role);

        $rolesMapping = [];

        foreach ($schoolRoles as $role)
            switch ($role) {
                // case RoleConstant::ADMIN:
                //     $rolesMapping[0] = RoleConstant::ADMIN;
                //     break;
                case RoleConstant::TEACHER:
                    $rolesMapping[1] = RoleConstant::TEACHER;
                    break;
                case RoleConstant::STUDENT:
                    $rolesMapping[2] = RoleConstant::STUDENT;
                    break;
                default:
                    break;
            }

        if (!$rolesMapping)
            return null;

        return $rolesMapping[min(array_keys($rolesMapping))];
    }
}
