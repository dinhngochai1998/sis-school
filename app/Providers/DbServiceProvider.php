<?php

namespace App\Providers;

use DB;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;
use Log;

class DbServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        DB::listen(function (QueryExecuted $query) {
            Log::info("Query debugger: ",
                      [
                          'connection'      => $query->connection,
                          'connection_name' => $query->connectionName,
                          'query'           => $query->sql,
                          'bindings'        => $query->bindings,
                          'time'            => $query->time
                      ]
            );
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
