<div>
<div class="mb-4">
    <h1 class="text-xl font-semibold">Payments</h1>
    <p class="text-sm text-muted">Applications waiting for a verified payment.</p>
</div>

@if ($errors->any())
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<section class="rounded-md border border-line bg-white">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted">
            <tr class="border-b border-line">
                <th class="px-3 py-2 font-medium">Reference</th>
                <th class="px-3 py-2 font-medium">Account</th>
                <th class="px-3 py-2 font-medium">Expected</th>
                <th class="px-3 py-2 font-medium"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-line last:border-0">
                    <td class="px-3 py-2 font-mono">{{ $row->reference }}</td>
                    <td class="px-3 py-2">{{ $row->clientAccount?->name }}</td>
                    <td class="px-3 py-2 font-mono">{{ $money::rands((int) ($row->fee_snapshot['total_cents'] ?? 0)) }}</td>
                    <td class="px-3 py-2 text-right"><button type="button" wire:click="selectApplication({{ $row->id }})" class="text-sm">Verify</button></td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-6 text-muted">No payments are waiting.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

@if ($selected)
    <form wire:submit="verify" class="mt-4 grid max-w-lg gap-3 rounded-md border border-line bg-white p-3 text-sm">
        <label>Amount (R) <input wire:model="amount" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
        <label>Method
            <select wire:model="method" class="mt-1 w-full rounded-md border border-line px-2 py-2">
                <option value="eft">EFT</option>
                <option value="card">Card</option>
                <option value="cash">Cash</option>
            </select>
        </label>
        <label>Reference <input wire:model="reference" class="mt-1 w-full rounded-md border border-line px-2 py-2 font-mono"></label>
        <label>Override reason, if the amount differs <input wire:model="override_reason" class="mt-1 w-full rounded-md border border-line px-2 py-2"></label>
        <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Mark payment verified</button>
    </form>
@endif
</div>
