<?php

namespace App\Livewire\Portal;

use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Models\Application;
use App\Models\LicenceEstimate;
use App\Models\User;
use App\Services\EstimateLicenceCost;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dealer-facing licence cost estimator.
 *
 * Scoped strictly to the signed-in user's own client account. Every
 * compute call goes through EstimateLicenceCost, which refuses to invent
 * numbers when the fee schedule is missing, ambiguous, or expired —
 * callers see "fee confirmation required" rather than a fake total.
 *
 * Available regardless of whether the quoting feature is enabled.
 */
#[Layout('layouts.portal')]
class LicenceCostEstimator extends Component
{
    public string $province = Province::Gauteng->value;

    public string $licence_category = LicenceFeeCategory::MotorCar->value;

    public ?int $tare_kg = 1200;

    public string $applicable_date;

    public ?int $application_id = null;

    public string $notes = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?int $editingEstimateId = null;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $user = $this->currentUser();
        abort_unless($user->client_account_id !== null, 403);

        $this->applicable_date = Carbon::now()->toDateString();
    }

    public function calculate(): void
    {
        $this->resetErrorBag();
        $this->editingEstimateId = null;

        $data = $this->validatedInputs();

        $this->result = app(EstimateLicenceCost::class)->compute(
            province: Province::from($data['province']),
            licenceCategory: LicenceFeeCategory::from($data['licence_category']),
            tareKg: $data['tare_kg'],
            applicableDate: Carbon::parse($data['applicable_date']),
        );
    }

    public function save(): void
    {
        if ($this->result === null) {
            $this->calculate();

            if ($this->result === null) {
                return;
            }
        }

        $user = $this->currentUser();
        $accountId = $user->client_account_id;
        abort_unless($accountId !== null, 403);

        $applicationId = $this->resolveApplicationId($accountId);

        $snapshot = $this->result;

        LicenceEstimate::query()->create([
            'client_account_id' => $accountId,
            'created_by_id' => $user->id,
            'application_id' => $applicationId,

            'province' => $snapshot['province']->value,
            'licence_category' => $snapshot['licence_category']->value,
            'tare_kg' => $snapshot['tare_kg'],
            'applicable_date' => $snapshot['applicable_date']->toDateString(),

            'fee_table_version_id' => $snapshot['fee_table_version_id'],
            'fee_table_version_number' => $snapshot['fee_table_version_number'],
            'fee_table_effective_from' => $snapshot['fee_table_effective_from']?->toDateString(),
            'fee_table_effective_until' => $snapshot['fee_table_effective_until']?->toDateString(),
            'fee_line_label' => $snapshot['fee_line_label'],
            'fee_line_tare_min_kg' => $snapshot['fee_line_tare_min_kg'],
            'fee_line_tare_max_kg' => $snapshot['fee_line_tare_max_kg'],

            'licence_fee_cents' => $snapshot['licence_fee_cents'],
            'licence_fee_tax_treatment' => $snapshot['licence_fee_tax_treatment']->value,
            'admin_charge_cents' => $snapshot['admin_charge_cents'],
            'admin_charge_tax_treatment' => $snapshot['admin_charge_tax_treatment']->value,
            'vat_basis_points' => $snapshot['vat_basis_points'],
            'vat_cents' => $snapshot['vat_cents'],
            'total_cents' => $snapshot['total_cents'],

            'status' => $snapshot['status'],
            'confirmation_reason' => $snapshot['confirmation_reason'],

            'notes' => $this->notes !== '' ? $this->notes : null,
            'computed_at' => $snapshot['computed_at'],
        ]);

        $this->statusMessage = 'Estimate saved. It is scoped to your account and remains unchanged by future fee updates.';
        $this->notes = '';
    }

    /**
     * Load a previously saved estimate into the viewer. The snapshot is
     * rendered verbatim — recalculating is an explicit user action.
     */
    public function view(int $estimateId): void
    {
        $user = $this->currentUser();

        $estimate = LicenceEstimate::query()
            ->where('client_account_id', $user->client_account_id)
            ->findOrFail($estimateId);

        $this->editingEstimateId = $estimate->id;
        $this->province = $estimate->province?->value ?? $this->province;
        $this->licence_category = $estimate->licence_category?->value ?? $this->licence_category;
        $this->tare_kg = $estimate->tare_kg;
        $this->applicable_date = $estimate->applicable_date->toDateString();
        $this->application_id = $estimate->application_id;
        $this->notes = $estimate->notes ?? '';

        $this->result = [
            'status' => $estimate->status,
            'confirmation_reason' => $estimate->confirmation_reason,
            'province' => $estimate->province,
            'licence_category' => $estimate->licence_category,
            'tare_kg' => $estimate->tare_kg,
            'applicable_date' => $estimate->applicable_date,
            'computed_at' => $estimate->computed_at,
            'fee_table_version_id' => $estimate->fee_table_version_id,
            'fee_table_version_number' => $estimate->fee_table_version_number,
            'fee_table_effective_from' => $estimate->fee_table_effective_from,
            'fee_table_effective_until' => $estimate->fee_table_effective_until,
            'fee_line_label' => $estimate->fee_line_label,
            'fee_line_tare_min_kg' => $estimate->fee_line_tare_min_kg,
            'fee_line_tare_max_kg' => $estimate->fee_line_tare_max_kg,
            'licence_fee_cents' => $estimate->licence_fee_cents,
            'licence_fee_tax_treatment' => $estimate->licence_fee_tax_treatment,
            'admin_charge_cents' => $estimate->admin_charge_cents,
            'admin_charge_tax_treatment' => $estimate->admin_charge_tax_treatment,
            'vat_basis_points' => $estimate->vat_basis_points,
            'vat_cents' => $estimate->vat_cents,
            'total_cents' => $estimate->total_cents,
        ];
    }

    public function render(): View
    {
        $user = $this->currentUser();

        $saved = LicenceEstimate::query()
            ->where('client_account_id', $user->client_account_id)
            ->with(['application:id,reference', 'createdBy:id,name'])
            ->latest('computed_at')
            ->limit(25)
            ->get();

        $draftApplications = Application::query()
            ->where('client_account_id', $user->client_account_id)
            ->where('stage', 'draft')
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['id', 'reference']);

        return view('livewire.portal.licence-cost-estimator', [
            'provinces' => collect(Province::cases())->mapWithKeys(fn (Province $p) => [$p->value => $p->label()]),
            'categories' => collect(LicenceFeeCategory::cases())->mapWithKeys(fn (LicenceFeeCategory $c) => [$c->value => $c->label()]),
            'saved' => $saved,
            'draftApplications' => $draftApplications,
        ]);
    }

    private function currentUser(): User
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_unless($user !== null, 401);

        return $user;
    }

    /**
     * @return array{province: string, licence_category: string, tare_kg: ?int, applicable_date: string, application_id: ?int}
     */
    private function validatedInputs(): array
    {
        return $this->validate([
            'province' => ['required', Rule::in(collect(Province::cases())->pluck('value')->all())],
            'licence_category' => ['required', Rule::in(collect(LicenceFeeCategory::cases())->pluck('value')->all())],
            'tare_kg' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'applicable_date' => ['required', 'date'],
            'application_id' => ['nullable', 'integer'],
        ]);
    }

    private function resolveApplicationId(int $accountId): ?int
    {
        if ($this->application_id === null) {
            return null;
        }

        $owned = Application::query()
            ->where('id', $this->application_id)
            ->where('client_account_id', $accountId)
            ->exists();

        return $owned ? $this->application_id : null;
    }
}
