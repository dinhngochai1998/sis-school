<?php

namespace App\Providers;

use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use DB;
use Flipbox\LumenGenerator\LumenGeneratorServiceProvider;
use Hedii\ArtisanLogCleaner\ArtisanLogCleanerServiceProvider;
use Illuminate\Cache\CacheManager;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionServiceProvider;
use Validator;
use VladimirYuldashev\LaravelQueueRabbitMQ\LaravelQueueRabbitMQServiceProvider;
use YaangVu\EurekaClient\EurekaProvider;
use YaangVu\SisModel\App\Providers\AuthServiceProvider;
use YaangVu\SisModel\App\Providers\LocaleServiceProvider;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use Kreait\Laravel\Firebase\ServiceProvider as Firebase;

class PostServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // https://github.com/laravel/framework/issues/9430
        Validator::extend('iunique', function ($attribute, $value, $parameters) {
            $table      = $parameters[0];
            $column     = $parameters[1];
            $query      = DB::table($table);
            $columnWrap = $query->getGrammar()->wrap($column);
            $id         = $parameters[2] ?? null;
            if ($id && DB::table($table)->where('id', $id)->first()?->{$column} === $value)
                return true;

            return !$query->whereRaw("lower({$columnWrap}) = lower(?)", [$value])->count();
        },                __('validation.unique'));
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(AuthServiceProvider::class);
        $this->app->register(IdeHelperServiceProvider::class);
        $this->app->register(LocaleServiceProvider::class);
        if (!App::environment('local'))
            $this->app->register(EurekaProvider::class);
        $this->app->register(\Sentry\Laravel\ServiceProvider::class);
        $this->app->register(LumenGeneratorServiceProvider::class);
        $this->app->register(SchoolServiceProvider::class);
        $this->app->register(RedisServiceProvider::class);
        //        if (env('CONTAINER_ROLE') === 'queue')
        $this->app->register(LaravelQueueRabbitMQServiceProvider::class);
        // https://spatie.be/docs/laravel-permission/v4/introduction
        $this->app->configure('permission');
        $this->app->alias('cache', CacheManager::class);
        $this->app->register(PermissionServiceProvider::class);
        // https://docs.laravel-excel.com/3.1/getting-started/installation.html
        $this->app->register(ArtisanLogCleanerServiceProvider::class);
        $this->app->register(ExcelServiceProvider::class);
        $this->app->alias('Excels', Excel::class);
        $this->app->register(RoleServiceProvider::class);
        $this->app->register(Firebase::class);
        if (App::environment('local')) {
            $this->app->register(DbServiceProvider::class);
        }
    }
}
