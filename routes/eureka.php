<?php

/** @var $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/status', function () {
    return response()->json("OK");
});
$router->get('/health-check', function () {
    return response()->json(['status' => 'UP']);
});

