<?php

use Illuminate\Support\Facades\Route;

Route::get('/{prefix}/{path}', [\App\Http\Controllers\StoredDocumentController::class, 'show'])
    ->where('prefix', implode('|', \App\Services\DocumentStorage::PREFIXES))
    ->where('path', '.+');

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect('http://192.168.0.11/');
});
