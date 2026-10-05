<?php

namespace App\Livewire\Portal;

use App\Actions\MarkInvoicePaid;
use App\Actions\MarkInvoiceUnpaid;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Licensing-company view of every invoice the deployment has raised, with
 * Outstanding / Paid tabs, per-dealership totals and inline Mark paid /
 * Mark unpaid buttons so finance can reconcile without leaving the page.
 */
#[Layout('layouts.portal')]
class FinanceInvoiceQueue extends Component
{
    use WithPagination;

    /** @var 'outstanding'|'paid'|'all' */
    #[Url(as: 'tab', keep: false)]
    public string $filter = 'outstanding';

    public string $search = '';

    public ?int $referencingInvoiceId = null;

    public string $paidReference = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->is_active || ! $user->hasAnyRole(['finance', 'customer_admin', 'super_admin'])) {
            abort(403);
        }
    }

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['outstanding', 'paid', 'all'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startMarkPaid(int $invoiceId): void
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->authorize('markPaid', $invoice);
        $this->referencingInvoiceId = $invoice->id;
        $this->paidReference = '';
    }

    public function cancelMarkPaid(): void
    {
        $this->referencingInvoiceId = null;
        $this->paidReference = '';
    }

    public function markPaid(): void
    {
        if ($this->referencingInvoiceId === null) {
            return;
        }

        $invoice = Invoice::query()->findOrFail($this->referencingInvoiceId);
        $this->authorize('markPaid', $invoice);

        try {
            app(MarkInvoicePaid::class)->handle(
                $invoice,
                auth()->user(),
                $this->paidReference !== '' ? $this->paidReference : null,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->referencingInvoiceId = null;
        $this->paidReference = '';
        session()->flash('status', 'Invoice marked paid.');
    }

    public function markUnpaid(int $invoiceId): void
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->authorize('markUnpaid', $invoice);

        app(MarkInvoiceUnpaid::class)->handle($invoice, auth()->user());

        session()->flash('status', 'Invoice marked unpaid.');
    }

    public function render(): View
    {
        $base = Invoice::query()->whereHas('application');

        $counts = [
            'outstanding' => (clone $base)->outstanding()->count(),
            'paid' => (clone $base)->paid()->count(),
            'all' => (clone $base)->count(),
        ];

        $outstandingCents = (int) (clone $base)
            ->outstanding()
            ->with('application:id,fee_snapshot')
            ->get()
            ->sum(fn (Invoice $invoice): int => $invoice->amountCents());

        $paidCents = (int) (clone $base)
            ->paid()
            ->with('application:id,fee_snapshot')
            ->get()
            ->sum(fn (Invoice $invoice): int => $invoice->amountCents());

        $rows = (clone $base)
            ->with(['application.clientAccount', 'application.vehicle', 'uploader', 'paidBy'])
            ->when($this->filter === 'outstanding', fn ($q) => $q->outstanding())
            ->when($this->filter === 'paid', fn ($q) => $q->paid())
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('invoice_number', 'like', $term)
                        ->orWhereHas('application', fn ($app) => $app->where('reference', 'like', $term))
                        ->orWhereHas('application.clientAccount', fn ($acc) => $acc->where('name', 'like', $term));
                });
            })
            ->latest('uploaded_at')
            ->paginate(20);

        $outstandingByAccount = (clone $base)
            ->outstanding()
            ->with(['application:id,client_account_id,fee_snapshot', 'application.clientAccount:id,name'])
            ->get()
            ->groupBy(fn (Invoice $invoice) => $invoice->application?->clientAccount?->name ?? '—')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'cents' => (int) $group->sum(fn (Invoice $invoice): int => $invoice->amountCents()),
            ])
            ->sortByDesc(fn (array $row): int => $row['cents']);

        return view('livewire.portal.finance-invoice-queue', [
            'rows' => $rows,
            'counts' => $counts,
            'outstandingCents' => $outstandingCents,
            'paidCents' => $paidCents,
            'outstandingByAccount' => $outstandingByAccount,
            'money' => Money::class,
        ]);
    }
}
