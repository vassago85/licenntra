<?php

namespace App\Livewire\Portal\Admin;

use App\Enums\BillingMode;
use App\Enums\ClientAccountType;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Owner-facing dealer / fleet account management.
 */
#[Layout('layouts.portal')]
class ClientAccounts extends Component
{
    use RequiresConfigurator;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $typeFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'dealer';

    /** @var list<string> */
    public array $additionalTypes = [];

    public string $status = 'active';

    public string $brn = '';

    public bool $quoteAcceptanceAllowed = false;

    public bool $hasStandingAgreement = false;

    public string $markupBasisPoints = '0';

    public string $billingMode = 'pay_per_transaction';

    public string $paymentTermsDays = '';

    public string $creditLimitRands = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $primaryReviewerId = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $accountId): void
    {
        $account = ClientAccount::query()->findOrFail($accountId);

        $this->resetForm();
        $this->editingId = $account->id;
        $this->name = (string) $account->name;
        $this->type = $account->type instanceof ClientAccountType ? $account->type->value : (string) $account->type;
        $this->additionalTypes = collect($account->additional_types ?? [])
            ->map(fn ($value): string => $value instanceof ClientAccountType ? $value->value : (string) $value)
            ->values()
            ->all();
        $this->status = (string) ($account->status ?: 'active');
        $this->brn = (string) $account->brn;
        $this->quoteAcceptanceAllowed = (bool) $account->quote_acceptance_allowed;
        $this->hasStandingAgreement = (bool) $account->has_standing_agreement;
        $this->markupBasisPoints = (string) ($account->markup_basis_points ?? 0);
        $this->billingMode = $account->billing_mode instanceof BillingMode ? $account->billing_mode->value : (string) ($account->billing_mode ?: BillingMode::PayPerTransaction->value);
        $this->paymentTermsDays = $account->payment_terms_days !== null ? (string) $account->payment_terms_days : '';
        $this->creditLimitRands = $account->credit_limit_cents !== null ? number_format($account->credit_limit_cents / 100, 2, '.', '') : '';
        $this->contactName = (string) $account->contact_name;
        $this->contactEmail = (string) $account->contact_email;
        $this->contactPhone = (string) $account->contact_phone;
        $this->primaryReviewerId = $account->primary_reviewer_user_id ? (string) $account->primary_reviewer_user_id : '';
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->clearMessages();
        $isStatement = $this->billingMode === BillingMode::AccountStatement->value;
        $typeValues = array_map(fn (ClientAccountType $case): string => $case->value, ClientAccountType::cases());

        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in($typeValues)],
            'additionalTypes' => ['array'],
            'additionalTypes.*' => [Rule::in($typeValues)],
            'status' => ['required', Rule::in($this->statusOptions())],
            'brn' => ['nullable', 'string', 'max:40'],
            'quoteAcceptanceAllowed' => ['boolean'],
            'hasStandingAgreement' => ['boolean'],
            'markupBasisPoints' => ['required', 'integer', 'min:0', 'max:10000'],
            'billingMode' => ['required', Rule::in(array_map(fn (BillingMode $case): string => $case->value, BillingMode::cases()))],
            'paymentTermsDays' => ['nullable', 'integer', 'min:0', 'max:120'],
            'creditLimitRands' => ['nullable', 'numeric', 'min:0'],
            'contactName' => ['nullable', 'string', 'max:120'],
            'contactEmail' => ['nullable', 'email', 'max:160'],
            'contactPhone' => ['nullable', 'string', 'max:40'],
            'primaryReviewerId' => ['nullable', Rule::in($this->reviewerOptions()->keys()->map(fn ($id): string => (string) $id)->all())],
        ]);

        $additional = collect($data['additionalTypes'] ?? [])
            ->reject(fn (string $value): bool => $value === $data['type'])
            ->unique()
            ->values()
            ->all();

        $account = $this->editingId === null ? new ClientAccount : ClientAccount::query()->findOrFail($this->editingId);

        $account->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'additional_types' => $additional === [] ? null : $additional,
            'status' => $data['status'],
            'brn' => $data['brn'] ?: null,
            'quote_acceptance_allowed' => (bool) $data['quoteAcceptanceAllowed'],
            'has_standing_agreement' => (bool) $data['hasStandingAgreement'],
            'markup_basis_points' => (int) $data['markupBasisPoints'],
            'billing_mode' => $data['billingMode'],
            'payment_terms_days' => $isStatement && filled($data['paymentTermsDays']) ? (int) $data['paymentTermsDays'] : null,
            'credit_limit_cents' => $isStatement && filled($data['creditLimitRands']) ? (int) round(((float) $data['creditLimitRands']) * 100) : null,
            'contact_name' => $data['contactName'] ?: null,
            'contact_email' => $data['contactEmail'] ?: null,
            'contact_phone' => $data['contactPhone'] ?: null,
            'primary_reviewer_user_id' => filled($data['primaryReviewerId']) ? (int) $data['primaryReviewerId'] : null,
        ])->save();

        $this->statusMessage = $account->name.($this->editingId === null ? ' created.' : ' updated.');
        $this->resetForm();
    }

    /**
     * Accounts with history cannot be deleted - their applications, users
     * and invoices must stay attributable. Close them instead.
     */
    public function delete(int $accountId): void
    {
        $this->clearMessages();
        $account = ClientAccount::query()->withCount(['applications', 'users'])->findOrFail($accountId);

        if ($account->applications_count > 0 || $account->users_count > 0) {
            $this->errorMessage = $account->name.' has applications or users, so it cannot be deleted. Set its status to Closed instead.';

            return;
        }

        $account->delete();
        $this->statusMessage = $account->name.' deleted.';
    }

    public function render(): View
    {
        return view('livewire.portal.admin.client-accounts', [
            'accounts' => $this->query()->paginate(25),
            'types' => ClientAccountType::cases(),
            'billingModes' => BillingMode::cases(),
            'reviewers' => $this->reviewerOptions(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    private function query(): Builder
    {
        return ClientAccount::query()
            ->with('primaryReviewer')
            ->withCount(['applications', 'users'])
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('contact_email', 'like', $term)
                    ->orWhere('brn', 'like', $term));
            })
            ->when($this->typeFilter !== '', fn (Builder $query) => $query->where('type', $this->typeFilter))
            ->orderBy('name');
    }

    /**
     * @return Collection<int, string>
     */
    private function reviewerOptions(): Collection
    {
        return User::query()
            ->whereNull('client_account_id')
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['reviewer', 'owner']))
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * @return list<string>
     */
    private function statusOptions(): array
    {
        $persisted = $this->editingId !== null
            ? ClientAccount::query()->whereKey($this->editingId)->value('status')
            : null;

        return collect(['active', 'suspended', 'closed', $persisted])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->type = ClientAccountType::Dealer->value;
        $this->additionalTypes = [];
        $this->status = 'active';
        $this->brn = '';
        $this->quoteAcceptanceAllowed = false;
        $this->hasStandingAgreement = false;
        $this->markupBasisPoints = '0';
        $this->billingMode = BillingMode::PayPerTransaction->value;
        $this->paymentTermsDays = '';
        $this->creditLimitRands = '';
        $this->contactName = '';
        $this->contactEmail = '';
        $this->contactPhone = '';
        $this->primaryReviewerId = '';
        $this->resetErrorBag();
    }

    private function clearMessages(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
    }
}
