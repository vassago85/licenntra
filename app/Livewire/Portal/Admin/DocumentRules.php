<?php

namespace App\Livewire\Portal\Admin;

use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\Admin\Concerns\RequiresConfigurator;
use App\Models\DocumentRule;
use App\Models\DocumentType;
use BackedEnum;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Decides which documents an application needs. A rule matches when every
 * non-empty condition equals the application's value; empty means "any".
 */
#[Layout('layouts.portal')]
class DocumentRules extends Component
{
    use RequiresConfigurator;

    public const PARTY_ROLES = [
        'vehicle' => 'Vehicle',
        'owner' => 'Owner',
        'title_holder' => 'Title holder',
    ];

    public const REQUIREMENTS = [
        'required' => 'Required',
        'optional' => 'Optional',
    ];

    #[Url(as: 'request', except: '')]
    public string $requestTypeFilter = '';

    #[Url(as: 'document', except: '')]
    public string $documentTypeFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $documentTypeId = '';

    public string $requestType = '';

    public string $vehicleCategory = '';

    public string $ownerType = '';

    public string $province = '';

    public string $isFinanced = '';

    public string $isDealerStock = '';

    public string $partyRole = 'vehicle';

    public string $requirement = 'required';

    public string $sortOrder = '0';

    public bool $active = true;

    public ?string $statusMessage = null;

    public function create(): void
    {
        $this->resetForm();
        $this->requestType = $this->requestTypeFilter;
        $this->documentTypeId = $this->documentTypeFilter;
        $this->showForm = true;
    }

    public function edit(int $ruleId): void
    {
        $rule = DocumentRule::query()->findOrFail($ruleId);

        $this->resetForm();
        $this->editingId = $rule->id;
        $this->documentTypeId = (string) $rule->document_type_id;
        $this->requestType = $this->enumValue($rule->request_type);
        $this->vehicleCategory = $this->enumValue($rule->vehicle_category);
        $this->ownerType = $this->enumValue($rule->owner_type);
        $this->province = $this->enumValue($rule->province);
        $this->isFinanced = $rule->is_financed === null ? '' : ((bool) $rule->is_financed ? '1' : '0');
        $this->isDealerStock = $rule->is_dealer_stock === null ? '' : ($rule->is_dealer_stock ? '1' : '0');
        $this->partyRole = (string) $rule->party_role;
        $this->requirement = (string) $rule->requirement;
        $this->sortOrder = (string) $rule->sort_order;
        $this->active = (bool) $rule->active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->statusMessage = null;

        $data = $this->validate([
            'documentTypeId' => ['required', Rule::exists('document_types', 'id')],
            'requestType' => ['nullable', Rule::enum(RequestType::class)],
            'vehicleCategory' => ['nullable', Rule::enum(VehicleCategory::class)],
            'ownerType' => ['nullable', Rule::enum(OwnerType::class)],
            'province' => ['nullable', Rule::enum(Province::class)],
            'isFinanced' => ['nullable', Rule::in(['0', '1'])],
            'isDealerStock' => ['nullable', Rule::in(['0', '1'])],
            'partyRole' => ['required', Rule::in(array_keys(self::PARTY_ROLES))],
            'requirement' => ['required', Rule::in(array_keys(self::REQUIREMENTS))],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
            'active' => ['boolean'],
        ]);

        $rule = $this->editingId === null ? new DocumentRule : DocumentRule::query()->findOrFail($this->editingId);
        $rule->fill([
            'document_type_id' => (int) $data['documentTypeId'],
            'request_type' => $data['requestType'] ?: null,
            'vehicle_category' => $data['vehicleCategory'] ?: null,
            'owner_type' => $data['ownerType'] ?: null,
            'province' => $data['province'] ?: null,
            'is_financed' => filled($data['isFinanced']) ? (bool) $data['isFinanced'] : null,
            'is_dealer_stock' => filled($data['isDealerStock']) ? (bool) $data['isDealerStock'] : null,
            'party_role' => $data['partyRole'],
            'requirement' => $data['requirement'],
            'sort_order' => (int) $data['sortOrder'],
            'active' => (bool) $data['active'],
        ])->save();

        $this->statusMessage = $this->editingId === null ? 'Rule added.' : 'Rule updated.';
        $this->resetForm();
    }

    public function toggleActive(int $ruleId): void
    {
        $rule = DocumentRule::query()->findOrFail($ruleId);
        $rule->update(['active' => ! $rule->active]);
        $this->statusMessage = 'Rule '.($rule->active ? 'enabled.' : 'disabled.');
    }

    public function delete(int $ruleId): void
    {
        DocumentRule::query()->findOrFail($ruleId)->delete();
        $this->statusMessage = 'Rule deleted.';
    }

    public function render(): View
    {
        $rules = DocumentRule::query()
            ->with('documentType')
            ->when($this->requestTypeFilter !== '', fn (Builder $query) => $query->where('request_type', $this->requestTypeFilter))
            ->when($this->documentTypeFilter !== '', fn (Builder $query) => $query->where('document_type_id', $this->documentTypeFilter))
            ->orderByRaw('request_type is null')
            ->orderBy('request_type')
            ->orderBy('sort_order')
            ->get();

        return view('livewire.portal.admin.document-rules', [
            'rules' => $rules,
            'documentTypes' => DocumentType::query()->orderBy('name')->pluck('name', 'id'),
            'requestTypes' => RequestType::cases(),
            'vehicleCategories' => VehicleCategory::cases(),
            'ownerTypes' => OwnerType::cases(),
            'provinces' => Province::cases(),
            'partyRoles' => self::PARTY_ROLES,
            'requirements' => self::REQUIREMENTS,
        ]);
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) ($value ?? '');
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->documentTypeId = '';
        $this->requestType = '';
        $this->vehicleCategory = '';
        $this->ownerType = '';
        $this->province = '';
        $this->isFinanced = '';
        $this->isDealerStock = '';
        $this->partyRole = 'vehicle';
        $this->requirement = 'required';
        $this->sortOrder = '0';
        $this->active = true;
        $this->resetErrorBag();
    }
}
