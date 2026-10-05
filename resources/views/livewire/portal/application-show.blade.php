<div>
@php($order = ['draft','submitted','document_review','changes_requested','quote_required','quote_sent','quote_accepted','payment_pending','payment_verified','datafix_in_progress','submitted_to_authority','authority_query','approved','ready_for_collection','completed'])
<div class="mb-4 flex items-end justify-between gap-4">
    <div>
        <p class="font-mono text-xs text-muted">{{ $application->reference }}</p>
        <h1 class="text-xl font-semibold">{{ $application->vehicle?->make }} {{ $application->vehicle?->model }}</h1>
        @if ($application->dangerous_goods)
            <p class="text-sm font-semibold">Dangerous goods requested</p>
        @endif
    </div>
    @if ($application->stage === \App\Enums\ApplicationStage::Draft || $application->stage === \App\Enums\ApplicationStage::ChangesRequested)
        <a href="{{ route('applications.edit', $application) }}" class="text-sm">Edit draft</a>
    @endif
</div>

@if ($errors->any())
    <p class="mb-4 rounded-md border border-line bg-white px-3 py-2 text-sm text-red-800">{{ $errors->first() }}</p>
@endif

<ol class="mb-4 flex flex-wrap gap-2 text-xs">
    @foreach ($stages as $stage)
        @continue(! in_array($stage->value, $order, true))
        <li class="rounded-md border border-line px-2 py-1 {{ $stage === $application->stage ? 'bg-white font-semibold' : 'text-muted' }}">{{ $stage->label() }}</li>
    @endforeach
</ol>

<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
    <section class="rounded-md border border-line bg-white">
        <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">Documents</h2>
        <ul class="divide-y divide-line text-sm">
            @foreach ($application->documents as $document)
                <li class="px-3 py-3" wire:key="doc-{{ $document->id }}">
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ $document->label() }}</span>
                        <span class="font-mono text-xs">{{ $document->status->label() }}</span>
                    </div>
                    @if ($document->dangerous_goods_stamped)
                        <p class="mt-1 text-xs font-semibold">Stamped Dangerous goods</p>
                    @endif
                    @if ($document->status === \App\Enums\DocumentStatus::Rejected)
                        <p class="mt-1 text-xs text-red-800">{{ $document->rejection_reason?->label() }}. {{ $document->reviewer_comment }}</p>
                    @endif
                    @can('upload', $document)
                        <div class="mt-2 flex items-center gap-2">
                            <input type="file" wire:model="uploads.{{ $document->id }}" class="text-xs">
                            <button type="button" wire:click="upload({{ $document->id }})" class="h-8 rounded-md border border-line px-2 text-xs">Upload</button>
                        </div>
                    @endcan
                    @if ($document->currentVersion && auth()->user()->can('download', $document))
                        <a class="mt-1 inline-block text-xs" href="{{ route('documents.download', $document->currentVersion) }}">Download</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <div class="space-y-4">
        <section class="rounded-md border border-line bg-white p-3 text-sm">
            <h2 class="font-semibold">Deliverable</h2>
            <p class="mt-2">{{ $application->deliverableLabel() }}</p>
            <p class="mt-1 text-muted">{{ $application->stage->label() }}</p>
        </section>

        @if ($application->deliverables->isNotEmpty() || $canUploadDeliverable)
            <section class="rounded-md border border-line bg-white">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-3 py-2">
                    <h2 class="text-sm font-semibold">Returned from the authority</h2>
                    @if ($canSendToCustomer && $application->deliverables->isNotEmpty() && ! $showSendForm)
                        <button type="button" wire:click="openSendForm" class="h-8 rounded-md px-3 text-xs font-semibold text-white" style="background: var(--brand)">
                            Email to customer
                        </button>
                    @endif
                </div>

                @if ($sendStatus !== '')
                    <p class="mx-3 mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">{{ $sendStatus }}</p>
                @endif

                @if ($showSendForm)
                    <form wire:submit.prevent="sendDeliverablesToCustomer" class="space-y-3 border-b border-line bg-paper px-3 py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Email to customer</p>
                            <button type="button" wire:click="closeSendForm" class="text-xs text-muted hover:underline">Cancel</button>
                        </div>

                        <div>
                            <p class="text-xs text-muted">Documents to attach</p>
                            <div class="mt-1 space-y-1">
                                @foreach ($application->deliverables as $d)
                                    <label class="flex items-start gap-2 text-xs" wire:key="send-opt-{{ $d->id }}">
                                        <input type="checkbox" wire:model="sendDeliverableIds" value="{{ $d->id }}" class="mt-0.5">
                                        <span>
                                            <span class="font-semibold">{{ $d->displayLabel() }}</span>
                                            <span class="ml-1 text-muted">· {{ $d->original_filename }} · {{ number_format($d->size_bytes / 1024, 0) }} KB</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('deliverable_ids')
                                <p class="mt-1 text-xs text-red-800">{{ $message }}</p>
                            @enderror
                        </div>

                        <label class="block">
                            <span class="text-xs text-muted">Customer email</span>
                            <input type="email" wire:model="sendRecipientEmail" maxlength="254" required class="mt-1 h-9 w-full rounded-md border border-line bg-white px-2 text-sm" placeholder="customer@example.com">
                            @error('recipient_email')
                                <p class="mt-1 text-xs text-red-800">{{ $message }}</p>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="text-xs text-muted">Message to include (optional)</span>
                            <textarea wire:model="sendMessage" rows="2" maxlength="2000" class="mt-1 w-full rounded-md border border-line bg-white px-2 py-2 text-sm" placeholder="e.g. Your disc and NaTIS are attached. Keep the original NaTIS in the vehicle at all times."></textarea>
                            @error('dealer_message')
                                <p class="mt-1 text-xs text-red-800">{{ $message }}</p>
                            @enderror
                        </label>

                        <div class="flex items-center justify-end gap-2">
                            <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Send email</button>
                        </div>
                    </form>
                @endif

                @if ($application->deliverables->isEmpty())
                    <p class="px-3 py-3 text-sm text-muted">No documents uploaded yet.</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($application->deliverables->sortByDesc('uploaded_at') as $deliverable)
                            <li class="px-3 py-3" wire:key="deliverable-{{ $deliverable->id }}">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="font-medium">{{ $deliverable->displayLabel() }}</p>
                                        <p class="mt-0.5 text-xs text-muted">
                                            {{ $deliverable->original_filename }}
                                            · {{ number_format($deliverable->size_bytes / 1024, 0) }} KB
                                            · {{ $deliverable->uploaded_at?->format('d M Y H:i') }}
                                            @if ($deliverable->uploader)
                                                · {{ $deliverable->uploader->name }}
                                            @endif
                                        </p>
                                        @if ($deliverable->handover_notes)
                                            <p class="mt-1 text-xs">{{ $deliverable->handover_notes }}</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @can('download', $deliverable)
                                            <a class="h-8 rounded-md border border-line px-2 py-1.5 text-xs" href="{{ route('deliverables.download', $deliverable) }}">Download</a>
                                        @endcan
                                        @can('delete', $deliverable)
                                            <button type="button" wire:click="deleteDeliverable({{ $deliverable->id }})" wire:confirm="Remove this document?" class="h-8 rounded-md border border-line px-2 py-1.5 text-xs text-red-900">Remove</button>
                                        @endcan
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canUploadDeliverable)
                    <form wire:submit.prevent="storeDeliverable" class="space-y-2 border-t border-line px-3 py-3 text-sm">
                        <p class="text-xs font-semibold text-muted">Upload a document returned by the authority</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs text-muted">Document type</span>
                                <select wire:model="newDeliverableKind" class="mt-1 h-9 w-full rounded-md border border-line px-2 text-sm">
                                    @foreach ($deliverableKinds as $kind)
                                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs text-muted">Label (optional)</span>
                                <input type="text" wire:model="newDeliverableLabel" maxlength="160" class="mt-1 h-9 w-full rounded-md border border-line px-2 text-sm" placeholder="e.g. New disc, valid 2027">
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-xs text-muted">File (PDF, JPG, or PNG, max 15 MB)</span>
                            <input type="file" wire:model="newDeliverable" accept="application/pdf,image/jpeg,image/png" class="mt-1 block w-full text-xs">
                        </label>
                        <label class="block">
                            <span class="text-xs text-muted">Handover note (optional)</span>
                            <textarea wire:model="newDeliverableNotes" rows="2" maxlength="500" class="mt-1 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="e.g. Collected from licensing dept on 12 Jul; new disc valid until 2027-07-31"></textarea>
                        </label>
                        <div class="flex items-center justify-end gap-2">
                            <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Upload</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if ($application->invoices->isNotEmpty() || $canUploadInvoice)
            <section class="rounded-md border border-line bg-white">
                <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">Invoices</h2>
                @if ($application->invoices->isEmpty())
                    <p class="px-3 py-3 text-sm text-muted">No invoices uploaded yet.</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($application->invoices->sortByDesc('uploaded_at') as $invoice)
                            <li class="px-3 py-3" wire:key="invoice-{{ $invoice->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-medium font-mono">{{ $invoice->invoice_number }}</p>
                                        <p class="mt-0.5 text-xs text-muted">
                                            {{ $invoice->original_filename }}
                                            · {{ number_format($invoice->size_bytes / 1024, 0) }} KB
                                            · {{ $invoice->uploaded_at?->format('d M Y H:i') }}
                                            @if ($invoice->uploader)
                                                · {{ $invoice->uploader->name }}
                                            @endif
                                        </p>
                                        @if ($invoice->recipient)
                                            <p class="mt-1 text-xs">
                                                <span class="text-muted">To:</span>
                                                <span class="font-semibold">{{ $invoice->recipient->name }}</span>
                                                @if ($stockControllerId && $invoice->recipient_user_id === $stockControllerId)
                                                    <span class="ml-1 inline-block rounded-full border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-900">Stock controller</span>
                                                @endif
                                            </p>
                                        @endif
                                        <p class="mt-1">
                                            @if ($invoice->isPaid())
                                                <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-900">Paid</span>
                                                @if ($invoice->paid_at)
                                                    <span class="ml-1 text-xs text-muted">{{ $invoice->paid_at->format('d M Y') }}</span>
                                                @endif
                                                @if ($invoice->paid_reference)
                                                    <span class="ml-1 font-mono text-xs text-muted">{{ $invoice->paid_reference }}</span>
                                                @endif
                                            @else
                                                <span class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">Outstanding</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        @can('download', $invoice)
                                            <a class="h-8 rounded-md border border-line px-2 py-1.5 text-xs" href="{{ route('invoices.download', $invoice) }}">Download</a>
                                        @endcan
                                        @if ($invoice->isPaid())
                                            @can('markUnpaid', $invoice)
                                                <button type="button" wire:click="markInvoiceUnpaid({{ $invoice->id }})" wire:confirm="Mark this invoice as unpaid?" class="h-8 rounded-md border border-line px-2 py-1.5 text-xs">Mark unpaid</button>
                                            @endcan
                                        @else
                                            @can('markPaid', $invoice)
                                                <button type="button" wire:click="startInvoicePayment({{ $invoice->id }})" class="h-8 rounded-md px-2 py-1.5 text-xs font-semibold text-white" style="background: var(--brand)">Mark paid</button>
                                            @endcan
                                        @endif
                                        @can('delete', $invoice)
                                            <button type="button" wire:click="deleteInvoice({{ $invoice->id }})" wire:confirm="Remove this invoice? This cannot be undone." class="h-8 rounded-md border border-line px-2 py-1.5 text-xs text-red-900">Remove</button>
                                        @endcan
                                    </div>
                                </div>
                                @if ($invoicePayingId === $invoice->id)
                                    <form wire:submit="markInvoicePaid" class="mt-3 flex flex-wrap items-end gap-2 rounded-md border border-line bg-paper px-3 py-2">
                                        <label class="flex-1">
                                            <span class="text-xs text-muted">Payment reference (optional)</span>
                                            <input type="text" wire:model="invoicePaidReference" maxlength="100" class="mt-1 h-9 w-full rounded-md border border-line px-2 text-sm font-mono" placeholder="e.g. EFT-2026-00412">
                                        </label>
                                        <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Confirm paid</button>
                                        <button type="button" wire:click="cancelInvoicePayment" class="h-9 rounded-md border border-line px-3 text-sm">Cancel</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canUploadInvoice)
                    <form wire:submit.prevent="storeInvoice" class="space-y-2 border-t border-line px-3 py-3 text-sm">
                        <p class="text-xs font-semibold text-muted">Upload a tax invoice for this transaction</p>
                        <label class="block">
                            <span class="text-xs text-muted">Invoice number</span>
                            <input type="text" wire:model="newInvoiceNumber" maxlength="40" class="mt-1 h-9 w-full rounded-md border border-line px-2 text-sm font-mono" placeholder="e.g. INV-2026-01284">
                            @error('invoice_number') <span class="text-xs text-red-800">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-xs text-muted">Addressed to (at the dealership)</span>
                            <select wire:model="newInvoiceRecipientUserId" class="mt-1 h-9 w-full rounded-md border border-line px-2 text-sm">
                                <option value="">No specific recipient</option>
                                @foreach ($dealershipUsers as $dealershipUser)
                                    <option value="{{ $dealershipUser->id }}">
                                        {{ $dealershipUser->name }}{{ $stockControllerId === $dealershipUser->id ? ' (Stock controller)' : '' }} - {{ $dealershipUser->email }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($stockControllerId)
                                <span class="mt-1 block text-[11px] text-muted">Defaults to the dealership's stock controller. Override only when a specific invoice needs to go to someone else.</span>
                            @else
                                <span class="mt-1 block text-[11px] text-amber-800">No stock controller is nominated for this dealership. Ask the client_admin to pick one from Team or choose a recipient manually.</span>
                            @endif
                            @error('recipient_user_id') <span class="text-xs text-red-800">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-xs text-muted">File (PDF, JPG, or PNG, max 15 MB)</span>
                            <input type="file" wire:model="newInvoice" accept="application/pdf,image/jpeg,image/png" class="mt-1 block w-full text-xs">
                            @error('invoice') <span class="text-xs text-red-800">{{ $message }}</span> @enderror
                        </label>
                        <div class="flex items-center justify-end gap-2">
                            <button type="submit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Upload invoice</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if ($quote)
            <section class="rounded-md border border-line bg-white p-3 text-sm">
                <h2 class="font-semibold">Quote</h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($quote->lines as $line)
                        <li class="flex justify-between"><span>{{ $line->description }}</span><span class="font-mono">{{ $money::rands($line->client_price_cents) }}</span></li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-muted">Expires {{ $quote->expires_at?->format('d M Y H:i') }}</p>
                @if ($quote->client_notes)
                    <p class="mt-2">{{ $quote->client_notes }}</p>
                @endif
                @can('acceptQuote', $application)
                    <button type="button" wire:click="acceptQuote" class="mt-3 h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Accept quote</button>
                @endcan
            </section>
        @endif

        @if ($application->stage === \App\Enums\ApplicationStage::ChangesRequested)
            <button type="button" wire:click="resubmit" class="h-9 rounded-md px-3 text-sm font-semibold text-white" style="background: var(--brand)">Send back for review</button>
        @endif

        <section class="rounded-md border border-line bg-white p-3">
            <h2 class="text-sm font-semibold">Notes</h2>
            <ul class="mt-2 space-y-2 text-sm">
                @foreach ($application->notes->where('visibility', 'client') as $note)
                    <li>
                        <p>{{ $note->body }}</p>
                        <p class="text-xs text-muted">{{ $note->author?->name }} · {{ $note->created_at?->format('d M H:i') }}</p>
                    </li>
                @endforeach
            </ul>
            <textarea wire:model="note" rows="3" class="mt-3 w-full rounded-md border border-line px-2 py-2 text-sm" placeholder="Note to the licensing company"></textarea>
            <button type="button" wire:click="addNote" class="mt-2 h-8 rounded-md border border-line px-2 text-sm">Add note</button>
        </section>
    </div>
</div>
</div>
