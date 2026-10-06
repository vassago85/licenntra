<?php

use App\Http\Controllers\DeliverableDownloadController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\HandoverPrintController;
use App\Http\Controllers\HandoverSignedDownloadController;
use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Controllers\NatisFormPrintController;
use App\Http\Controllers\SubmissionPackPrintController;
use App\Http\Middleware\AbsoluteSessionLifetime;
use App\Livewire\Account\Settings as AccountSettings;
use App\Livewire\Portal\Admin\AuditLog as AdminAuditLog;
use App\Livewire\Portal\Admin\Branding as AdminBranding;
use App\Livewire\Portal\Admin\ClientAccounts as AdminClientAccounts;
use App\Livewire\Portal\Admin\DocumentRules as AdminDocumentRules;
use App\Livewire\Portal\Admin\DocumentTypes as AdminDocumentTypes;
use App\Livewire\Portal\Admin\FeeLines as AdminFeeLines;
use App\Livewire\Portal\Admin\FeeTables as AdminFeeTables;
use App\Livewire\Portal\Admin\FeeTableVersionEditor as AdminFeeTableVersionEditor;
use App\Livewire\Portal\Admin\FeeTableVersions as AdminFeeTableVersions;
use App\Livewire\Portal\Admin\Overview as AdminOverview;
use App\Livewire\Portal\Admin\PlatformBilling as AdminPlatformBilling;
use App\Livewire\Portal\Admin\SystemSettings as AdminSystemSettings;
use App\Livewire\Portal\Admin\Users as AdminUsers;
use App\Livewire\Portal\ApplicationForm;
use App\Livewire\Portal\ApplicationShow;
use App\Livewire\Portal\BusinessClientForm;
use App\Livewire\Portal\BusinessClientIndex;
use App\Livewire\Portal\BusinessClientShow;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\DealershipCards;
use App\Livewire\Portal\DealershipParticulars;
use App\Livewire\Portal\DocumentHandoverForm;
use App\Livewire\Portal\DocumentHandoverIndex;
use App\Livewire\Portal\FinanceInvoiceQueue;
use App\Livewire\Portal\FleetReviewConfirm;
use App\Livewire\Portal\FleetReviewQueue;
use App\Livewire\Portal\FleetVehicleIndex;
use App\Livewire\Portal\InvoiceIndex;
use App\Livewire\Portal\LicenceCostEstimator;
use App\Livewire\Portal\NatisFormEditor;
use App\Livewire\Portal\OutstandingTasks;
use App\Livewire\Portal\PaymentQueue;
use App\Livewire\Portal\QuoteBuilder;
use App\Livewire\Portal\ReviewQueue;
use App\Livewire\Portal\ReviewWorkspace;
use App\Livewire\Portal\TeamIndex;
use App\Services\FeatureFlags;
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

        if (! $user->isLicensingStaff()) {
            return redirect()->route($user->hasRole('developer') ? 'platform.billing' : 'account.settings');
        }

        $financeOnly = $user->hasRole('finance')
            && ! $user->hasAnyRole(['reviewer', 'owner']);

        if ($financeOnly) {
            return redirect()->route(FeatureFlags::paymentTrackingRequired() ? 'finance.payments' : 'admin.overview');
        }

        return redirect()->route('review.queue');
    })->name('dashboard');

    Route::get('/applications', Dashboard::class)->name('applications.index');
    Route::get('/applications/create', ApplicationForm::class)->name('applications.create');
    Route::get('/applications/{application}/edit', ApplicationForm::class)->name('applications.edit');
    Route::get('/applications/{application}/quote', QuoteBuilder::class)->name('applications.quote');
    Route::get('/applications/{application}', ApplicationShow::class)->name('applications.show');

    Route::get('/review', ReviewQueue::class)->name('review.queue');
    Route::get('/review/packs/print', SubmissionPackPrintController::class)->name('review.packs.print');
    Route::get('/review/{application}', ReviewWorkspace::class)->name('review.show');
    Route::get('/review/{application}/natis-form', NatisFormEditor::class)->name('review.natis-form');
    Route::get('/review/{application}/natis-form/print', NatisFormPrintController::class)->name('review.natis-form.print');

    Route::get('/dealerships/board', DealershipCards::class)->name('dealerships.board');
    Route::get('/tasks/outstanding', OutstandingTasks::class)->name('tasks.outstanding');

    Route::get('/business-clients', BusinessClientIndex::class)->name('business-clients.index');
    Route::get('/business-clients/create', BusinessClientForm::class)->name('business-clients.create');
    Route::get('/business-clients/{businessClient}/edit', BusinessClientForm::class)->name('business-clients.edit');
    Route::get('/business-clients/{businessClient}', BusinessClientShow::class)->name('business-clients.show');

    Route::get('/finance/payments', PaymentQueue::class)->name('finance.payments');
    Route::get('/finance/invoices', FinanceInvoiceQueue::class)->name('finance.invoices');

    Route::get('/invoices', InvoiceIndex::class)->name('invoices.index');

    Route::get('/account', AccountSettings::class)->name('account.settings');
    Route::get('/team', TeamIndex::class)->name('team.index');
    Route::get('/dealership', DealershipParticulars::class)->name('dealership.particulars');
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

    /*
    |----------------------------------------------------------------------
    | Administration. The overview is for all licensing staff; every other
    | page is owner-only, enforced in each component via
    | User::canConfigure().
    |----------------------------------------------------------------------
    */
    Route::get('/admin', AdminOverview::class)->name('admin.overview');
    Route::get('/admin/users', AdminUsers::class)->name('admin.users');
    Route::get('/admin/client-accounts', AdminClientAccounts::class)->name('admin.client-accounts');
    Route::get('/admin/document-rules', AdminDocumentRules::class)->name('admin.document-rules');
    Route::get('/admin/document-types', AdminDocumentTypes::class)->name('admin.document-types');
    Route::get('/admin/fee-tables', AdminFeeTables::class)->name('admin.fee-tables');
    Route::get('/admin/fee-table-versions', AdminFeeTableVersions::class)->name('admin.fee-table-versions');
    Route::get('/admin/fee-table-versions/{version}', AdminFeeTableVersionEditor::class)->name('admin.fee-table-versions.edit');
    Route::get('/admin/fee-lines', AdminFeeLines::class)->name('admin.fee-lines');
    Route::get('/settings/branding', AdminBranding::class)->name('settings.branding');
    Route::get('/settings/system', AdminSystemSettings::class)->name('settings.system');
    Route::get('/audit', AdminAuditLog::class)->name('audit.index');
    Route::get('/platform/billing', AdminPlatformBilling::class)->name('platform.billing');
});
