<?php

namespace App\Livewire\Portal;

use App\Actions\RecordAudit;
use App\Enums\IdentificationType;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The dealership's registration number, addresses, proxy and representative,
 * captured by its own admin. These print on every RLV the licensing company
 * prepares when the dealership itself is the owner or motor dealer.
 */
#[Layout('layouts.portal')]
class DealershipParticulars extends Component
{
    private const DELEGATE_FIELDS = ['name', 'initials', 'id_type', 'id_number', 'id_country'];

    public string $brn = '';

    public string $street_address = '';

    public string $postal_address = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $proxy_name = '';

    public string $proxy_initials = '';

    public string $proxy_id_type = '';

    public string $proxy_id_number = '';

    public string $proxy_id_country = '';

    public string $representative_name = '';

    public string $representative_initials = '';

    public string $representative_id_type = '';

    public string $representative_id_number = '';

    public string $representative_id_country = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $account = $this->account();

        foreach (['brn', 'street_address', 'postal_address', 'contact_email', 'contact_phone'] as $field) {
            $this->{$field} = (string) $account->{$field};
        }

        foreach (['proxy', 'representative'] as $delegate) {
            foreach (self::DELEGATE_FIELDS as $field) {
                $value = $account->{$delegate.'_'.$field};
                $this->{$delegate.'_'.$field} = $value instanceof IdentificationType ? $value->value : (string) $value;
            }
        }
    }

    public function save(): void
    {
        $account = $this->account();
        $idTypes = array_values(array_diff(
            array_map(fn (IdentificationType $type): string => $type->value, IdentificationType::cases()),
            [IdentificationType::BusinessReg->value],
        ));

        $data = $this->validate([
            'brn' => ['required', 'string', 'max:60'],
            'street_address' => ['required', 'string', 'max:500'],
            'postal_address' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'proxy_name' => ['required', 'string', 'max:120'],
            'proxy_initials' => ['required', 'string', 'max:10'],
            'proxy_id_type' => ['required', Rule::in($idTypes)],
            'proxy_id_number' => ['required', 'string', 'max:40'],
            'proxy_id_country' => ['nullable', 'required_if:proxy_id_type,'.IdentificationType::ForeignId->value, 'string', 'max:60'],
            'representative_name' => ['nullable', 'string', 'max:120'],
            'representative_initials' => ['nullable', 'required_with:representative_name', 'string', 'max:10'],
            'representative_id_type' => ['nullable', 'required_with:representative_name', Rule::in($idTypes)],
            'representative_id_number' => ['nullable', 'required_with:representative_name', 'string', 'max:40'],
            'representative_id_country' => ['nullable', 'required_if:representative_id_type,'.IdentificationType::ForeignId->value, 'string', 'max:60'],
        ], [], [
            'brn' => 'business registration number',
            'proxy_name' => "proxy's surname",
            'proxy_id_type' => "proxy's type of identification",
            'proxy_id_number' => "proxy's identification number",
            'proxy_id_country' => "proxy's country of issue",
            'representative_initials' => "representative's initials",
            'representative_id_type' => "representative's type of identification",
            'representative_id_number' => "representative's identification number",
            'representative_id_country' => "representative's country of issue",
        ]);

        $data = array_map(fn (?string $value): ?string => filled($value) ? trim($value) : null, $data);
        $account->fill($data)->save();

        app(RecordAudit::class)->handle(
            $this->currentUser(),
            $account,
            'client_account.particulars_updated',
            $account->name.' updated its registration number, addresses, proxy and representative.',
        );

        $this->statusMessage = 'Dealership details saved. They print on the RLV for vehicles you register into stock.';
    }

    public function render(): View
    {
        $idTypes = collect(IdentificationType::cases())
            ->reject(fn (IdentificationType $type): bool => $type === IdentificationType::BusinessReg)
            ->values();

        return view('livewire.portal.dealership-particulars', [
            'account' => $this->account(),
            'idTypes' => $idTypes,
        ]);
    }

    private function account(): ClientAccount
    {
        $user = $this->currentUser();
        abort_unless($user->hasRole('customer_admin'), 403);

        $account = $user->clientAccount;
        abort_unless($account instanceof ClientAccount, 403);

        return $account;
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
