<?php

namespace App\Console\Commands;

use App\Services\RoleService;
use App\Services\SchoolService;
use App\Services\UserService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Validator;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\SystemException;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class SchoolCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'school:insert';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add new school';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        DB::beginTransaction();
        $current_timestamp = Carbon::now()->toDateTimeString();

        if ($this->confirm('Do you want to add a new school?')) {
            try {
                    $schoolName = $this->validate_console(function () {
                        return $this->ask('School name: ');
                    }, ['schoolName' => 'required|unique:schools,name']);

                    $schoolUuid = $this->validate_console(function () {
                        return $this->ask('School uuid: ');
                    }, ['schoolUuid' => 'required|unique:schools,uuid']);

                    $userName = $this->validate_console(function () {
                        return $this->ask('Username (userAdmin): ');
                    }, ['username' => 'required|unique:users,username']);

                    $password = $this->validate_console(function () {
                        return $this->secret('Password (userAdmin): ');
                    }, ['password' => 'required|min:8']);

                    $requestSchool = [
                        'uuid'         => $schoolUuid,
                        'name'         => $schoolName,
                        'year_founded' => $current_timestamp
                    ];

                    $requestUser = [
                        'username'   => $userName,
                        'password'   => $password,
                        'role_names' => [
                            $schoolUuid . ':' . RoleConstant::ADMIN
                        ],
                        'sc_id'      => $schoolUuid
                    ];

                    // add school
                    $dataSchool = $this->addNewSchool($requestSchool);

                    // add role default in school
                    $this->addNewRole($schoolUuid);

                    // add user has role admin
                    $this->addUserHasRoleAdmin($requestUser);

                    $this->info('----- School data -----');
                    $this->info('- name:' . $schoolName);
                    $this->info('- uuid:' . $schoolUuid);
                    $this->info('----- User Admin -----');
                    $this->info('- username:' . $userName);
                    $this->info('- password:' . $password);

                DB::commit();
                return true;
            } catch (Exception $e) {
                DB::rollBack();
                throw new SystemException($e->getMessage() ?? __('system-500'), $e);
            }
        }
    }

    public function addNewSchool($request)
    {
        $schoolService = new SchoolService();
        $data          = $schoolService->add((object)$request);

        return $data;
    }


    public function addNewRole($uuid)
    {
        $roleDefaults = [
            ['name' => RoleConstant::ADMIN, 'group' => RoleConstant::STAFF, 'priority' => 2],
            ['name' => RoleConstant::TEACHER, 'group' => RoleConstant::STAFF, 'priority' => 5],
            ['name' => RoleConstant::PRINCIPAL, 'group' => RoleConstant::STAFF, 'priority' => 2],
            ['name' => RoleConstant::STUDENT, 'group' => RoleConstant::STUDENT_AND_FAMILY, 'priority' => 13],
            ['name' => RoleConstant::COUNSELOR, 'group' => RoleConstant::STAFF, 'priority' => 8],
            ['name' => RoleConstant::FAMILY, 'group' => RoleConstant::STUDENT_AND_FAMILY, 'priority' => 3],
        ];

        foreach ($roleDefaults as $role) {
            $request     = [
                'name'       => $uuid . ':' . $role['name'],
                'group'      => $role['group'],
                'guard_name' => 'api',
                'is_mutable' => false,
                'status'     => StatusConstant::ACTIVE,
                'priority'   => $role['priority']
            ];
            $roleService = new RoleService();
            $roleService->add((object)$request);

        }

        return true;
    }

    public function addUserHasRoleAdmin($requestUser)
    {
        $userService = new UserService();
        $data        = $userService->add((object)$requestUser);

        return $data;
    }

    public function validate_console($method, $rules)
    {
        $value    = $method();
        $validate = $this->validateInput($rules, $value);

        if ($validate !== true) {
            $messages = collect($validate->messages())->flatten()->all();
            foreach ($messages as $failure) {
                $this->warn($failure);
            }
            $value = $this->validate_console($method, $rules);
        }

        return $value;
    }

    public function validateInput($rules, $value)
    {
        $validator = Validator::make([key($rules) => $value], $rules);
        if ($validator->fails()) {
            return $validator->errors();
        } else {
            return true;
        }
    }
}
