<?php

use App\Http\Controllers\TildaController;
use App\Http\Controllers\CRM\CRMController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::group(['prefix' => 'bx'], function () {
    Route::post('/install', [CRMController::class, 'install']);
    Route::post('/index', [CRMController::class, 'index']);
});

Route::post('/tilda/data', [TildaController::class, 'tilda']);
Route::post('/test', [TildaController::class, 'test']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});