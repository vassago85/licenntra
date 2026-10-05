<?php

namespace App\Livewire\Portal;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Client-dealership view of every invoice their account has received,
 * with Outstanding / Paid tabs so the dealer can tell at a glance what
 * they still owe. Totals use the parent application's fee snapshot
 * because invoices do not carry their own amount.
 */
#[Layout('layouts.portal')]
class InvoiceIndex extends Component
{
    use WithPagination;

    /** @var 'outstanding'|'paid'|'all' */
    #[Url(as: 'tab', keep: false)]
    public string $filter = 'outstanding';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->is_active || ! $user->isClient()) {
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

    public function render(): View
    {
        $user = auth()->user();
        $accountId = (int) $user->client_account_id;

        $base = Invoice::query()->whereHas(
            'application',
            fn ($q) => $q->where('client_account_id', $accountId),
        );

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

        $counts = [
            'outstanding' => (clone $base)->outstanding()->count(),
            'paid' => (clone $base)->paid()->count(),
            'all' => (clone $base)->count(),
        ];

        $rows = (clone $base)
            ->with(['application.vehicle', 'uploader', 'paidBy'])
            ->when($this->filter === 'outstanding', fn ($q) => $q->outstanding())
            ->when($this->filter === 'paid', fn ($q) => $q->paid())
            ->latest('uploaded_at')
            ->paginate(15);

        return view('livewire.portal.invoice-index', [
            'rows' => $rows,
            'counts' => $counts,
            'outstandingCents' => $outstandingCents,
            'paidCents' => $paidCents,
            'money' => Money::class,
        ]);
    }
}
