<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Jenssegers\Mongodb\MongodbServiceProvider;
use YaangVu\Consul\ConsulProvider;

class PreServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        if(env('CONSUL_ENABLE')){
            $this->app->register(ConsulProvider::class);
        }
        $this->app->register(MongodbServiceProvider::class);
    }
}
