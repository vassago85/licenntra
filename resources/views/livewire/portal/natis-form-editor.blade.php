<div>
    @php
        $transaction = $values['transaction'] ?? [];
        $skippedSections = match (true) {
            ($transaction['type'] ?? '') === 'licensing' => ['title_holder', 'title_holder_proxy', 'title_holder_representative', 'title_holder_declaration'],
            (bool) ($transaction['owner_is_title_holder'] ?? false) => ['owner', 'owner_proxy', 'owner_representative', 'owner_declaration'],
            default => [],
        };
        $checkedBy = $application->natisFormCheckedBy;
    @endphp

    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="font-mono text-xs text-muted">{{ $application->reference }} · {{ $application->clientAccount?->name }}</p>
            <h1 class="text-xl font-semibold">Check the {{ $formType->formNumber() }}</h1>
            <p class="text-sm text-muted">{{ $formType->title() }}. Filled from the application: check every field, correct anything that is wrong, then save before printing.</p>
        </div>
        <a href="{{ route('review.show', $application) }}" class="inline-flex h-9 items-center rounded-md border border-line bg-white px-3 text-sm">Back to review</a>
    </div>

    @if ($statusMessage)
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $statusMessage }}</p>
    @endif

    @error('natis_form')
        <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <div class="mb-4 grid gap-3 md:grid-cols-2">
        <section class="rounded-md border border-line bg-white px-3 py-2 text-sm">
            <h2 class="font-semibold">Status</h2>
            @if (! $isChecked)
                <p class="mt-1 text-amber-800">Not checked yet. These values come straight from the application.</p>
            @elseif ($isOutOfDate)
                <p class="mt-1 text-amber-800">
                    Checked {{ $application->natis_form_checked_at?->format('d M Y H:i') }} by {{ $checkedBy?->name ?? 'unknown' }},
                    but the application has changed since. Refill from the application or confirm the values below and save again.
                </p>
            @else
                <p class="mt-1 text-emerald-800">Checked {{ $application->natis_form_checked_at?->format('d M Y H:i') }} by {{ $checkedBy?->name ?? 'unknown' }}.</p>
            @endif
        </section>
        <section @class(['rounded-md border px-3 py-2 text-sm', 'border-amber-300 bg-amber-50' => $missing !== [], 'border-line bg-white' => $missing === []])>
            @if ($missing === [])
                <h2 class="font-semibold">Essentials</h2>
                <p class="mt-1 text-emerald-800">Every field the department needs is filled in.</p>
            @else
                <h2 class="font-semibold text-amber-900">{{ count($missing) }} essential {{ \Illuminate\Support\Str::plural('field', count($missing)) }} blank</h2>
                <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-amber-900">
                    @foreach ($missing as $label)
                        <li>{{ $label }}</li>
                    @endforeach
                </ul>
                <p class="mt-1 text-xs text-amber-900">Blank fields print empty, so they can be completed by hand if needed.</p>
            @endif
        </section>
    </div>

    <form wire:submit="save" class="space-y-4">
        @foreach ($sections as $section)
            @php
                $sectionKey = $section['key'];
            @endphp
            <section class="rounded-md border border-line bg-white" wire:key="natis-section-{{ $sectionKey }}">
                <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-3 py-2">
                    <h2 class="text-sm font-semibold">
                        @if ($section['part'])
                            <span class="mr-1 inline-flex h-5 w-5 items-center justify-center rounded bg-ink text-[11px] font-bold text-white">{{ $section['part'] }}</span>
                        @endif
                        {{ $section['title'] }}
                    </h2>
                    @if ($section['hint'])
                        <span class="text-xs text-muted">{{ $section['hint'] }}</span>
                    @endif
                </header>

                @if (in_array($sectionKey, $skippedSections, true))
                    <p class="px-3 py-3 text-xs text-muted">Left blank on the printed form for this transaction.</p>
                @else
                    <div class="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($section['fields'] as $field)
                            @php
                                $path = $sectionKey.'.'.$field['key'];
                                $model = 'values.'.$path;
                                $isMissing = array_key_exists($path, $missing);
                                $isWide = ($field['wide'] ?? false) || $field['type'] === $choiceType;
                            @endphp
                            <div @class(['sm:col-span-2' => $isWide, 'lg:col-span-4' => $field['type'] === $choiceType && count($field['options']) > 4]) wire:key="natis-field-{{ $path }}">
                                @if ($field['type'] === $flagType)
                                    <label class="mt-5 inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model.live="{{ $model }}" class="h-4 w-4 rounded border-line">
                                        {{ $field['label'] }}
                                    </label>
                                @elseif ($field['type'] === $choiceType)
                                    <fieldset @class(['rounded-md border px-2 py-1.5', 'border-amber-400 bg-amber-50' => $isMissing, 'border-line' => ! $isMissing])>
                                        <legend class="px-1 text-xs text-muted">{{ $field['label'] }}</legend>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                            @foreach ($field['options'] as $optionValue => $optionLabel)
                                                <label class="inline-flex items-center gap-1.5">
                                                    <input type="radio" name="{{ $model }}" value="{{ $optionValue }}" wire:model.live="{{ $model }}" class="h-3.5 w-3.5 border-line">
                                                    {{ $optionLabel }}
                                                </label>
                                            @endforeach
                                            <label class="inline-flex items-center gap-1.5 text-muted">
                                                <input type="radio" name="{{ $model }}" value="" wire:model.live="{{ $model }}" class="h-3.5 w-3.5 border-line">
                                                None marked
                                            </label>
                                        </div>
                                    </fieldset>
                                @else
                                    <label class="block text-xs text-muted">
                                        {{ $field['label'] }}
                                        <input
                                            type="{{ $field['type'] === $dateType ? 'date' : 'text' }}"
                                            wire:model="{{ $model }}"
                                            @class(['mt-1 h-8 w-full rounded-md border px-2 text-sm text-ink', 'border-amber-400 bg-amber-50' => $isMissing, 'border-line bg-white' => ! $isMissing])
                                        >
                                    </label>
                                @endif
                                @error($model) <p class="mt-1 text-xs text-red-800">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        <div class="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-line bg-paper py-3">
            <button type="button" wire:click="refill" wire:confirm="Replace everything on screen with a fresh fill from the application? Unsaved edits are lost." class="h-9 rounded-md border border-line bg-white px-3 text-sm">Refill from application</button>
            @if ($isChecked)
                <a href="{{ route('review.natis-form.print', $application) }}" class="inline-flex h-9 items-center rounded-md border border-line bg-white px-3 text-sm">Print saved form</a>
            @endif
            <button type="submit" class="h-9 rounded-md border border-line bg-white px-3 text-sm font-medium">Save</button>
            <button type="button" wire:click="saveAndPrint" class="h-9 rounded-md px-3 text-sm font-medium text-white" style="background: var(--brand);">Save and print</button>
        </div>
    </form>
</div>
