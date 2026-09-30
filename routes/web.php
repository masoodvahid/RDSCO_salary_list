<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\PrintController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Members;
use App\Livewire\Projects;
use App\Livewire\Sheets\CreateSheet;
use App\Livewire\Sheets\Grid;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

Route::get('/invite/{token}', InviteController::class)
    ->middleware('throttle:20,1')
    ->where('token', '[A-Za-z0-9]{20,100}')
    ->name('invite');

Route::middleware(['auth', 'active'])->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');
    Route::livewire('/sheets/create', CreateSheet::class)->name('sheets.create');
    Route::livewire('/sheets/{sheet}', Grid::class)->whereNumber('sheet')->name('sheets.show');
    Route::get('/sheets/{sheet}/export', ExportController::class)->whereNumber('sheet')->name('sheets.export');
    Route::get('/sheets/{sheet}/print', PrintController::class)->whereNumber('sheet')->name('sheets.print');
    Route::livewire('/members', Members::class)->name('members');
    Route::livewire('/projects', Projects::class)->name('projects');
    Route::post('/logout', LogoutController::class)->name('logout');
});
