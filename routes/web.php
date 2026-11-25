<?php

use App\Http\Controllers\ProposalController;
use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\ProposalImportController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'estimate');
Route::view('/history', 'estimate');
Route::view('/workspace', 'estimate');
Route::view('/today', 'estimate');
Route::view('/telegram-inbox', 'estimate');
Route::view('/mini', 'mini');
Route::view('/reset-password/{token}', 'estimate')->name('password.reset');
Route::view('/p/{token}', 'estimate')->where('token', '[a-f0-9]{64}');
Route::post('/api/import-positions', ProposalImportController::class)->middleware('throttle:20,1');
Route::prefix('api/mini')->controller(MiniAppController::class)->group(function () {
    Route::get('/', 'home')->middleware('throttle:120,1');
    Route::post('/requests', 'create')->middleware('throttle:12,1');
    Route::post('/requests/{thread}/messages', 'followUp')->whereNumber('thread')->middleware('throttle:30,1');
});
Route::prefix('api/telegram')->middleware('auth')->controller(MiniAppController::class)->group(function () {
    Route::get('/inbox', 'inbox');
    Route::post('/inbox/{thread}/reply', 'reply')->whereNumber('thread');
});

Route::prefix('api')->controller(ProposalController::class)->group(function () {
    Route::get('/catalog', 'catalog');
    Route::get('/proposals', 'index');
    Route::post('/proposals', 'store');
    Route::post('/review', 'review');
    Route::get('/proposals/{token}', 'show')->where('token', '[a-f0-9]{64}');
    Route::get('/proposals/{token}/pdf', 'download')->where('token', '[a-f0-9]{64}');
    Route::get('/proposals/{token}/xlsx', 'spreadsheet')->where('token', '[a-f0-9]{64}');
    Route::post('/proposals/{token}/archive', 'archive')->where('token', '[a-f0-9]{64}');
    Route::get('/proposals/{token}/receipts', 'receipts')->where('token', '[a-f0-9]{64}');
    Route::post('/proposals/{token}/receipts', 'storeReceipt')->where('token', '[a-f0-9]{64}');
    Route::post('/proposals/{token}/receipts/{receipt}/void', 'voidReceipt')->where('token', '[a-f0-9]{64}')->whereNumber('receipt');
    Route::post('/proposals/{token}/accept', 'accept')->where('token', '[a-f0-9]{64}');
    Route::post('/proposals/{token}/withdraw', 'withdraw')->where('token', '[a-f0-9]{64}');
    Route::post('/proposals/{token}/changes', 'requestChanges')->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1');
});

Route::prefix('api')->controller(WorkspaceController::class)->group(function () {
    Route::get('/session', 'session');
    Route::post('/session/register', 'register')->middleware('throttle:5,1');
    Route::post('/session/login', 'login')->middleware('throttle:10,1');
    Route::post('/session/logout', 'logout');
    Route::post('/session/forgot', 'forgot')->middleware('throttle:5,1');
    Route::post('/session/reset', 'reset')->middleware('throttle:8,1');
    Route::middleware('auth')->where(['kind' => 'drafts|clients|services|templates|contractors', 'id' => '[0-9]+'])->group(function () {
        Route::get('/workspace/{kind}', 'records');
        Route::post('/workspace/{kind}/{id?}', 'save');
        Route::delete('/workspace/{kind}/{id}', 'remove');
    });
});
