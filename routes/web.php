<?php

use App\Http\Controllers\DeliverableDownloadController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Middleware\AbsoluteSessionLifetime;
use App\Livewire\Account\Settings as AccountSettings;
use App\Livewire\Portal\ApplicationForm;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\BusinessClientIndex;
use App\Livewire\Portal\BusinessClientShow;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\LicenceCostEstimator;
use App\Livewire\Portal\PaymentQueue;
use App\Livewire\Portal\QuoteBuilder;
use App\Livewire\Portal\ReviewQueue;
use App\Livewire\Portal\ReviewWorkspace;
use App\Livewire\Portal\TeamIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect('/overview/index.html');
})->name('home');

Route::middleware(['auth', AbsoluteSessionLifetime::class])->group(function (): void {
    Route::get('/dashboard', function () {
        $user = auth()->user();

        if ($user->isClient()) {
            return redirect()->route('applications.index');
        }

        $financeOnly = $user->hasRole('finance')
            && ! $user->hasAnyRole(['reviewer', 'customer_admin', 'super_admin', 'auditor']);

        return redirect()->route($financeOnly ? 'finance.payments' : 'review.queue');
    })->name('dashboard');

    Route::get('/applications', Dashboard::class)->name('applications.index');
    Route::get('/applications/create', ApplicationForm::class)->name('applications.create');
    Route::get('/applications/{application}/edit', ApplicationForm::class)->name('applications.edit');
    Route::get('/applications/{application}/quote', QuoteBuilder::class)->name('applications.quote');
    Route::get('/applications/{application}', ApplicationShow::class)->name('applications.show');

    Route::get('/review', ReviewQueue::class)->name('review.queue');
    Route::get('/review/{application}', ReviewWorkspace::class)->name('review.show');

    Route::get('/business-clients', BusinessClientIndex::class)->name('business-clients.index');
    Route::get('/business-clients/{businessClient}', BusinessClientShow::class)->name('business-clients.show');

    Route::get('/finance/payments', PaymentQueue::class)->name('finance.payments');

    Route::get('/account', AccountSettings::class)->name('account.settings');
    Route::get('/team', TeamIndex::class)->name('team.index');
    Route::get('/estimate', LicenceCostEstimator::class)->name('estimate.index');

    Route::get('/documents/versions/{version}', DocumentDownloadController::class)->name('documents.download');
    Route::get('/deliverables/{deliverable}', DeliverableDownloadController::class)->name('deliverables.download');
});
