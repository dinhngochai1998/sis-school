<?php

namespace App\Console\Commands;

use App\Services\ClassService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Validator;
use YaangVu\Exceptions\SystemException;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;

class ClassCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'class:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete class';

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
        try {
            if ($this->confirm('Do you want to delete class?')) {
                $classId = $this->validate_console(function () {
                    return $this->ask('ClassId: ');
                }, ['classId' => 'required|numeric|exists:classes,id']);

                $class = $this->getClassById($classId);

                // delete classes in mongo
                $this->deleteClassInMongo($class->id);
                // delete class_activities in mongo
                $this->deleteClassActivitiesInMongo($class->id);
                // delete calendars in mongo
                $this->deleteCalendarsInMogo($class->id);
                // delete class in postgres
                $this->deleteClassInPostGree($class->id);

                $this->info('Delete class successfully!');
                DB::commit();
            }

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getClassById($id)
    {
        $classService = new ClassService();

        return $classService->get($id);
    }

    public function deleteClassInPostGree($classId)
    {
        $class = ClassSQL::find($classId);
        if ($class) {
            $class->delete();
        }

        return true;
    }

    public function deleteClassInMongo($classId)
    {
        $classMongo = ClassNoSQL::where('class_id', '=', $classId)->first();
        if ($classMongo) {
            $classMongo->delete();
        }

        return true;
    }

    public function deleteClassActivitiesInMongo($classId)
    {
        $classActivities = ClassActivityNoSql::where('class_id', '=', $classId)->get();
        if ($classActivities) {
            foreach ($classActivities as $classActivity){
                $classActivity->delete();
            }
        }

        return true;
    }

    public function deleteCalendarsInMogo($classId)
    {
        $calendars = CalendarNoSQL::where('class_id', '=', $classId)->get();
        if ($calendars) {
            foreach ($calendars as $calendar){
                $calendar->delete();
            }
        }

        return true;
    }

    public function deleteScore($classId)
    {
        // delete score
        $scores = ScoreSQL::where('class_id', '=', $classId)->get();
        if ($scores) {
            foreach ($scores as $score){
                $score->delete();
            }
        }

        return true;
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
