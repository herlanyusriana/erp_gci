<?php

use App\Http\Controllers\BomController;
use App\Http\Controllers\ConfigMasterController;
use App\Http\Controllers\IncomingArrivalController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocalPoController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\MaterialIssueController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PartStockController;
use App\Http\Controllers\PartSubstituteController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\ProductionPlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceiveController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TruckingCompanyController;
use App\Http\Controllers\UomController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::post('/locale', LocaleController::class)->name('locale.update');

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('launcher')
        : redirect()->route('login');
})->name('home');

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
    Route::get('/production-data', fn () => Inertia::render('Production/ModuleLauncher'))->name('production-data');
    Route::get('/outgoing', fn () => Inertia::render('Outgoing/ModuleLauncher'))->name('outgoing-data');

    // Outgoing — pengeluaran material ke produksi
    Route::get('material-issues', [MaterialIssueController::class, 'index'])->name('material-issues.index');
    Route::get('material-issues/{materialIssue}', [MaterialIssueController::class, 'show'])->name('material-issues.show');
    Route::get('material-issues/{materialIssue}/print', [MaterialIssueController::class, 'print'])->name('material-issues.print');

    // Master Data — CRUD
    Route::resource('parts', PartController::class)->except(['show']);
    Route::resource('suppliers', SupplierController::class)->except(['show']);
    Route::resource('trucking-companies', TruckingCompanyController::class)->except(['show'])->parameters(['trucking-companies' => 'trucking']);
    Route::resource('machines', MachineController::class)->except(['show']);
    Route::get('machines/{machine}/label', [MachineController::class, 'printLabel'])->name('machines.label');
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

    // Production Plan (papan harian; sumber WO)
    Route::get('production-plans', [ProductionPlanController::class, 'index'])->name('production-plans.index');
    Route::post('production-plans', [ProductionPlanController::class, 'store'])->name('production-plans.store');
    Route::post('production-plans/items/reorder', [ProductionPlanController::class, 'reorder'])->name('production-plans.items.reorder');
    Route::patch('production-plans/items/{item}', [ProductionPlanController::class, 'update'])->name('production-plans.items.update');
    Route::delete('production-plans/items/{item}', [ProductionPlanController::class, 'detach'])->name('production-plans.items.detach');

    // Work Order (Production)
    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('work-orders/create', [WorkOrderController::class, 'create'])->name('work-orders.create');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::get('work-orders/{workOrder}/items/{item}/edit', [WorkOrderController::class, 'editItem'])->name('work-orders.items.edit');
    Route::patch('work-orders/{workOrder}/items/{item}', [WorkOrderController::class, 'updateItem'])->name('work-orders.items.update');
    Route::post('work-orders/{workOrder}/release', [WorkOrderController::class, 'release'])->name('work-orders.release');
    Route::post('work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');
    Route::post('work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->name('work-orders.cancel');
    Route::delete('work-orders/{workOrder}', [WorkOrderController::class, 'destroy'])->name('work-orders.destroy');

    // Administration
    Route::resource('config', ConfigMasterController::class)->except(['show', 'create', 'edit']);
    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::resource('users', UserController::class)->except(['show', 'create', 'edit']);
});

require __DIR__.'/auth.php';
