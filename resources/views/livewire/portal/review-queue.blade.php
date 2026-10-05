<div>
<div class="mb-4 flex items-end justify-between gap-4">
    <div>
        <h1 class="text-xl font-semibold">Review queue</h1>
        <p class="text-sm text-muted">Submitted work, by stage and SLA.</p>
    </div>
</div>

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">Awaiting review</p>
        <p class="mt-1 text-xl font-semibold">{{ $stats['awaiting_review'] }}</p>
        <p class="mt-0.5 text-xs text-muted">Submitted or in document review</p>
    </div>
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">With client</p>
        <p class="mt-1 text-xl font-semibold">{{ $stats['with_client'] }}</p>
        <p class="mt-0.5 text-xs text-muted">Waiting on changes requested</p>
    </div>
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">Assigned to me</p>
        <p class="mt-1 text-xl font-semibold">{{ $stats['assigned_to_me'] }}</p>
        <p class="mt-0.5 text-xs text-muted">Your personal workload</p>
    </div>
    <div class="rounded-md border border-line bg-white p-3">
        <p class="text-xs uppercase tracking-wide text-muted">SLA at risk</p>
        <p class="mt-1 text-xl font-semibold {{ $stats['sla_at_risk'] > 0 ? 'text-[#9E2419]' : '' }}">{{ $stats['sla_at_risk'] }}</p>
        <p class="mt-0.5 text-xs text-muted">Past or near the handling target</p>
    </div>
</div>

<div class="mb-3 flex flex-wrap gap-2">
    <select wire:model.live="stage" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
        <option value="">All stages</option>
        @foreach ($stages as $option)
            <option value="{{ $option->value }}">{{ $option->label() }}</option>
        @endforeach
    </select>
    <select wire:model.live="assignment" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
        <option value="">Anyone</option>
        <option value="me">Assigned to me</option>
        <option value="unassigned">Unassigned</option>
    </select>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="slaRisk"> SLA risk</label>
    <input wire:model.live.debounce.300ms="search" placeholder="Reference" class="h-9 rounded-md border border-line bg-white px-2 text-sm">
</div>

<section class="rounded-md border border-line bg-white">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-muted">
            <tr class="border-b border-line">
                <th class="px-3 py-2 font-medium">Reference</th>
                <th class="px-3 py-2 font-medium">Account</th>
                <th class="px-3 py-2 font-medium">Stage</th>
                <th class="px-3 py-2 font-medium">Reviewer</th>
                <th class="px-3 py-2 font-medium">SLA</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-line last:border-0">
                    <td class="px-3 py-2">
                        <a class="font-mono" href="{{ route('review.show', $row) }}">{{ $row->reference }}</a>
                        @if ($row->dangerous_goods)
                            <span class="ml-2 text-xs font-semibold">Dangerous goods</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $row->clientAccount?->name }}</td>
                    <td class="px-3 py-2">{{ $row->stage->label() }}</td>
                    <td class="px-3 py-2">{{ $row->reviewer?->name ?? 'Unassigned' }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $row->slaFlag() ?? 'On track' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-muted">Nothing in this queue.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
</div>
