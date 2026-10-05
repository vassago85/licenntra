<?php

use App\Http\Controllers\DeliverableDownloadController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\HandoverPrintController;
use App\Http\Controllers\HandoverSignedDownloadController;
use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Middleware\AbsoluteSessionLifetime;
use App\Livewire\Account\Settings as AccountSettings;
use App\Livewire\Portal\ApplicationForm;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\BusinessClientForm;
use App\Livewire\Portal\BusinessClientIndex;
use App\Livewire\Portal\BusinessClientShow;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\DocumentHandoverForm;
use App\Livewire\Portal\DocumentHandoverIndex;
use App\Livewire\Portal\FinanceInvoiceQueue;
use App\Livewire\Portal\FleetReviewConfirm;
use App\Livewire\Portal\FleetReviewQueue;
use App\Livewire\Portal\FleetVehicleIndex;
use App\Livewire\Portal\InvoiceIndex;
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
    Route::get('/business-clients/create', BusinessClientForm::class)->name('business-clients.create');
    Route::get('/business-clients/{businessClient}/edit', BusinessClientForm::class)->name('business-clients.edit');
    Route::get('/business-clients/{businessClient}', BusinessClientShow::class)->name('business-clients.show');

    Route::get('/finance/payments', PaymentQueue::class)->name('finance.payments');
    Route::get('/finance/invoices', FinanceInvoiceQueue::class)->name('finance.invoices');

    Route::get('/invoices', InvoiceIndex::class)->name('invoices.index');

    Route::get('/account', AccountSettings::class)->name('account.settings');
    Route::get('/team', TeamIndex::class)->name('team.index');
    Route::get('/estimate', LicenceCostEstimator::class)->name('estimate.index');

    Route::get('/fleet-vehicles', FleetVehicleIndex::class)->name('fleet.vehicles.index');
    Route::get('/fleet-vehicles/review', FleetReviewQueue::class)->name('fleet.review.queue');
    Route::get('/fleet-vehicles/review/{document}', FleetReviewConfirm::class)->name('fleet.review.show');

    Route::get('/handovers', DocumentHandoverIndex::class)->name('handovers.index');
    Route::get('/handovers/create', DocumentHandoverForm::class)->name('handovers.create');
    Route::get('/handovers/{handover}/edit', DocumentHandoverForm::class)->name('handovers.edit');
    Route::get('/handovers/{handover}/print', HandoverPrintController::class)->name('handovers.print');
    Route::get('/handovers/{handover}/signed', HandoverSignedDownloadController::class)->name('handovers.signed.download');

    Route::get('/documents/versions/{version}', DocumentDownloadController::class)->name('documents.download');
    Route::get('/deliverables/{deliverable}', DeliverableDownloadController::class)->name('deliverables.download');
    Route::get('/invoices/{invoice}', InvoiceDownloadController::class)->name('invoices.download');
});
