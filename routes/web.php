<?php

use App\Http\Controllers\BackupStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BackupStatusController::class, 'index']);
Route::get('/api/backup-status', [BackupStatusController::class, 'status']);
Route::post('/api/backup-retry', [BackupStatusController::class, 'retry']);
