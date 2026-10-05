<?php

namespace App\Livewire\Portal\Admin;

use App\Actions\ApproveFeeTableVersion;
use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\TaxTreatment;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\FeeLine;
use App\Models\FeeTableVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Edit a draft fee table version: its effective dates and every fee line.
 * Live and superseded versions open read-only.
 */
#[Layout('layouts.portal')]
class FeeTableVersionEditor extends Component
{
    use RequiresConfigurator;

    public FeeTableVersion $version;

    public string $effectiveFrom = '';

    public string $effectiveUntil = '';

    public string $notes = '';

    public bool $showLineForm = false;

    public ?int $editingLineId = null;

    /**
     * @var array{code: string, label: string, amount_rand: string, tax_treatment: string, period: string, licence_category: string, tare_min_kg: string, tare_max_kg: string, client_visible: bool, service_type: string, vehicle_category: string, request_type: string, sort_order: string}
     */
    public array $line = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(FeeTableVersion $version): void
    {
        $this->version = $version;
        $this->effectiveFrom = $version->effective_from?->toDateString() ?? '';
        $this->effectiveUntil = $version->effective_until?->toDateString() ?? '';
        $this->notes = (string) $version->notes;
        $this->line = $this->blankLine();
    }

    public function saveDetails(): void
    {
        $this->guardEditable();
        $this->clearMessages();

        $data = $this->validate([
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveUntil' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->version->update([
            'effective_from' => $data['effectiveFrom'] ?: null,
            'effective_until' => $data['effectiveUntil'] ?: null,
            'notes' => $data['notes'] ?: null,
        ]);

        $this->statusMessage = 'Version details saved.';
    }

    public function addLine(): void
    {
        $this->guardEditable();
        $this->editingLineId = null;
        $this->line = $this->blankLine();
        $this->line['sort_order'] = (string) (((int) $this->version->lines()->max('sort_order')) + 10);
        $this->resetErrorBag();
        $this->showLineForm = true;
    }

    public function editLine(int $lineId): void
    {
        $this->guardEditable();
        $line = $this->version->lines()->findOrFail($lineId);

        $this->editingLineId = $line->id;
        $this->line = [
            'code' => (string) $line->code,
            'label' => (string) $line->label,
            'amount_rand' => number_format($line->amount_cents / 100, 2, '.', ''),
            'tax_treatment' => $line->tax_treatment?->value ?? TaxTreatment::Exempt->value,
            'period' => $line->period?->value ?? FeePeriod::Annual->value,
            'licence_category' => $line->licence_category?->value ?? '',
            'tare_min_kg' => $line->tare_min_kg !== null ? (string) $line->tare_min_kg : '',
            'tare_max_kg' => $line->tare_max_kg !== null ? (string) $line->tare_max_kg : '',
            'client_visible' => (bool) $line->client_visible,
            'service_type' => (string) $line->service_type,
            'vehicle_category' => (string) $line->vehicle_category,
            'request_type' => (string) $line->request_type,
            'sort_order' => (string) ($line->sort_order ?? 0),
        ];
        $this->resetErrorBag();
        $this->showLineForm = true;
    }

    public function cancelLine(): void
    {
        $this->showLineForm = false;
        $this->editingLineId = null;
        $this->line = $this->blankLine();
        $this->resetErrorBag();
    }

    public function saveLine(): void
    {
        $this->guardEditable();
        $this->clearMessages();

        $data = $this->validate([
            'line.code' => ['required', 'string', 'max:64'],
            'line.label' => ['required', 'string', 'max:255'],
            'line.amount_rand' => ['required', 'numeric', 'min:0'],
            'line.tax_treatment' => ['required', Rule::enum(TaxTreatment::class)],
            'line.period' => ['required', Rule::enum(FeePeriod::class)],
            'line.licence_category' => ['nullable', Rule::enum(LicenceFeeCategory::class)],
            'line.tare_min_kg' => ['nullable', 'integer', 'min:0'],
            'line.tare_max_kg' => ['nullable', 'integer', 'min:0'],
            'line.client_visible' => ['boolean'],
            'line.service_type' => ['nullable', 'string', 'max:64'],
            'line.vehicle_category' => ['nullable', 'string', 'max:64'],
            'line.request_type' => ['nullable', 'string', 'max:64'],
            'line.sort_order' => ['required', 'integer', 'min:0'],
        ], [], [
            'line.code' => 'code',
            'line.label' => 'label',
            'line.amount_rand' => 'amount',
            'line.tare_max_kg' => 'tare to',
        ])['line'];

        if (filled($data['tare_min_kg']) && filled($data['tare_max_kg']) && (int) $data['tare_max_kg'] < (int) $data['tare_min_kg']) {
            $this->addError('line.tare_max_kg', 'Tare to must be at least tare from.');

            return;
        }

        $attributes = [
            'code' => $data['code'],
            'label' => $data['label'],
            'amount_cents' => (int) round(((float) $data['amount_rand']) * 100),
            'tax_treatment' => $data['tax_treatment'],
            'period' => $data['period'],
            'licence_category' => $data['licence_category'] ?: null,
            'tare_min_kg' => filled($data['tare_min_kg']) ? (int) $data['tare_min_kg'] : null,
            'tare_max_kg' => filled($data['tare_max_kg']) ? (int) $data['tare_max_kg'] : null,
            'client_visible' => (bool) $data['client_visible'],
            'service_type' => $data['service_type'] ?: null,
            'vehicle_category' => $data['vehicle_category'] ?: null,
            'request_type' => $data['request_type'] ?: null,
            'sort_order' => (int) $data['sort_order'],
        ];

        if ($this->editingLineId === null) {
            $this->version->lines()->create($attributes);
            $this->statusMessage = 'Line added.';
        } else {
            $this->version->lines()->findOrFail($this->editingLineId)->update($attributes);
            $this->statusMessage = 'Line updated.';
        }

        $this->cancelLine();
    }

    public function duplicateLine(int $lineId): void
    {
        $this->guardEditable();
        $line = $this->version->lines()->findOrFail($lineId);
        $copy = $line->replicate();
        $copy->label = $line->label.' (copy)';
        $copy->save();

        $this->editLine($copy->id);
    }

    public function deleteLine(int $lineId): void
    {
        $this->guardEditable();
        $this->clearMessages();
        $this->version->lines()->findOrFail($lineId)->delete();

        if ($this->editingLineId === $lineId) {
            $this->cancelLine();
        }

        $this->statusMessage = 'Line deleted.';
    }

    public function approve(ApproveFeeTableVersion $approve): void
    {
        $this->guardEditable();
        $this->clearMessages();

        try {
            $approve->handle($this->version, $this->configurator());
        } catch (ValidationException $exception) {
            $this->errorMessage = $exception->validator->errors()->first();

            return;
        }

        $this->version->refresh();
        $this->cancelLine();
        $this->statusMessage = 'Version approved. It is now the live price list.';
    }

    public function render(): View
    {
        $this->version->loadMissing(['feeTable', 'creator', 'approver']);

        return view('livewire.portal.admin.fee-table-version-editor', [
            'lines' => $this->version->lines()->orderBy('sort_order')->orderBy('id')->get(),
            'isEditable' => $this->version->isEditable(),
            'canApprove' => $this->version->isEditable() && (int) $this->version->created_by !== $this->configurator()->id,
            'taxTreatments' => TaxTreatment::cases(),
            'periods' => FeePeriod::cases(),
            'licenceCategories' => LicenceFeeCategory::cases(),
        ]);
    }

    public static function describeTareBand(FeeLine $line): string
    {
        if ($line->tare_min_kg === null && $line->tare_max_kg === null) {
            return '';
        }

        if ($line->tare_max_kg === null) {
            return 'over '.number_format($line->tare_min_kg).' kg';
        }

        if ($line->tare_min_kg === null) {
            return 'up to '.number_format($line->tare_max_kg).' kg';
        }

        return number_format($line->tare_min_kg).'–'.number_format($line->tare_max_kg).' kg';
    }

    private function guardEditable(): void
    {
        abort_unless($this->version->isEditable(), 403, 'Only draft versions can be changed.');
    }

    /**
     * @return array{code: string, label: string, amount_rand: string, tax_treatment: string, period: string, licence_category: string, tare_min_kg: string, tare_max_kg: string, client_visible: bool, service_type: string, vehicle_category: string, request_type: string, sort_order: string}
     */
    private function blankLine(): array
    {
        return [
            'code' => '',
            'label' => '',
            'amount_rand' => '0.00',
            'tax_treatment' => TaxTreatment::Exempt->value,
            'period' => FeePeriod::Annual->value,
            'licence_category' => '',
            'tare_min_kg' => '',
            'tare_max_kg' => '',
            'client_visible' => true,
            'service_type' => '',
            'vehicle_category' => '',
            'request_type' => '',
            'sort_order' => '0',
        ];
    }

    private function clearMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }
}
