<?php

use App\Http\Controllers\BomController;
use App\Http\Controllers\ConfigMasterController;
use App\Http\Controllers\IncomingArrivalController;
use App\Http\Controllers\LocalPoController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PartStockController;
use App\Http\Controllers\PartSubstituteController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceiveController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TruckingCompanyController;
use App\Http\Controllers\UomController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', fn () => redirect()->route('launcher'))
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// === Full Application Launcher ===
Route::middleware(['auth'])->group(function () {
    Route::get('/launcher', fn () => Inertia::render('Launcher'))->name('launcher');

    // Module Launchers
    Route::get('/master-data', fn () => Inertia::render('Master/ModuleLauncher'))->name('master-data');
    Route::get('/administration', fn () => Inertia::render('Administration/ModuleLauncher'))->name('administration');
    Route::get('/incoming-data', fn () => Inertia::render('Incoming/ModuleLauncher'))->name('incoming-data');

    // Master Data — CRUD
    Route::resource('parts', PartController::class)->except(['show']);
    Route::resource('suppliers', SupplierController::class)->except(['show']);
    Route::resource('trucking-companies', TruckingCompanyController::class)->except(['show'])->parameters(['trucking-companies' => 'trucking']);
    Route::resource('machines', MachineController::class)->except(['show']);
    Route::resource('processes', ProcessController::class)->except(['show']);
    Route::resource('uoms', UomController::class)->except(['show']);
    Route::resource('substitutes', PartSubstituteController::class)->except(['show']);
    Route::resource('boms', BomController::class)->only(['index', 'show']);

    // Incoming module
    Route::resource('purchase-orders', PurchaseOrderController::class)->parameters(['purchase-orders' => 'purchaseOrder']);
    Route::resource('incoming-arrivals', IncomingArrivalController::class)->parameters(['incoming-arrivals' => 'arrival']);
    Route::post('incoming-containers/{container}/inspect', [IncomingArrivalController::class, 'inspectContainer'])->name('incoming-containers.inspect');
    Route::get('incoming-arrivals/{arrival}/export', [IncomingArrivalController::class, 'export'])->name('incoming-arrivals.export');
    Route::get('incoming-arrivals/{arrival}/invoice', [IncomingArrivalController::class, 'invoice'])->name('incoming-arrivals.invoice');
    Route::get('incoming-arrivals/{arrival}/pdf', [IncomingArrivalController::class, 'pdf'])->name('incoming-arrivals.pdf');

    // Local PO (arrival tanpa dokumen import: no vessel/container)
    Route::get('local-pos/export', [LocalPoController::class, 'export'])->name('local-pos.export');
    Route::get('local-pos/{localPo}/export', [LocalPoController::class, 'exportDetail'])->name('local-pos.export-detail');
    Route::resource('local-pos', LocalPoController::class)->parameters(['local-pos' => 'localPo']);

    // Receive (FIFO tag)
    Route::get('receive', [ReceiveController::class, 'index'])->name('receive.index');
    Route::get('arrival-items/{arrivalItem}/receive', [ReceiveController::class, 'create'])->name('receive.create');
    Route::post('arrival-items/{arrivalItem}/receive', [ReceiveController::class, 'store'])->name('receive.store');
    Route::get('receives/{receive}/label', [ReceiveController::class, 'printLabel'])->name('receive.label');
    Route::get('receives/{receive}/edit', [ReceiveController::class, 'edit'])->name('receive.edit');
    Route::put('receives/{receive}', [ReceiveController::class, 'update'])->name('receive.update');
    Route::delete('receives/{receive}', [ReceiveController::class, 'destroy'])->name('receive.destroy');

    // Stock (simple per-part ledger)
    Route::get('stocks', [PartStockController::class, 'index'])->name('stocks.index');

    // Administration
    Route::resource('config', ConfigMasterController::class)->except(['show', 'create', 'edit']);
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::resource('users', UserController::class)->except(['show', 'create', 'edit']);
});

require __DIR__.'/auth.php';
