<?php

namespace App\Livewire\Portal;

use App\Actions\SaveBusinessClient;
use App\Models\BusinessClient;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dealer-portal CRUD form for a BusinessClient.
 *
 * Mounted at both the create and edit routes. Authorises against the
 * BusinessClient policy, which already permits both client_admin and
 * client_user roles — a dealership "super user" and a normal dealer
 * user both land here.
 */
#[Layout('layouts.portal')]
class BusinessClientForm extends Component
{
    public ?BusinessClient $businessClient = null;

    public string $business_name = '';

    public string $registration_number = '';

    public string $proxy_name = '';

    public string $proxy_contact = '';

    public string $proxy_id_number = '';

    public string $address = '';

    public string $usable_as = 'owner';

    public bool $is_shared = false;

    public string $status = 'active';

    public function mount(?BusinessClient $businessClient = null): void
    {
        if ($businessClient !== null && $businessClient->exists) {
            $this->authorize('update', $businessClient);
            $this->businessClient = $businessClient;
            $this->business_name = (string) $businessClient->business_name;
            $this->registration_number = (string) ($businessClient->registration_number ?? '');
            $this->proxy_name = (string) ($businessClient->proxy_name ?? '');
            $this->proxy_contact = (string) ($businessClient->proxy_contact ?? '');
            $this->proxy_id_number = (string) ($businessClient->proxy_id_number ?? '');
            $this->address = (string) ($businessClient->address ?? '');
            $this->usable_as = (string) ($businessClient->usable_as ?? 'owner');
            $this->is_shared = (bool) $businessClient->is_shared;
            $this->status = (string) ($businessClient->status ?? 'active');

            return;
        }

        $this->authorize('create', BusinessClient::class);
    }

    public function save(): mixed
    {
        if ($this->businessClient !== null) {
            $this->authorize('update', $this->businessClient);
        } else {
            $this->authorize('create', BusinessClient::class);
        }

        try {
            $client = app(SaveBusinessClient::class)->handle(
                auth()->user(),
                [
                    'business_name' => trim($this->business_name),
                    'registration_number' => trim($this->registration_number) !== '' ? trim($this->registration_number) : null,
                    'proxy_name' => trim($this->proxy_name) !== '' ? trim($this->proxy_name) : null,
                    'proxy_contact' => trim($this->proxy_contact) !== '' ? trim($this->proxy_contact) : null,
                    'proxy_id_number' => trim($this->proxy_id_number) !== '' ? trim($this->proxy_id_number) : null,
                    'address' => trim($this->address) !== '' ? trim($this->address) : null,
                    'usable_as' => $this->usable_as,
                    'is_shared' => $this->is_shared,
                    'status' => $this->status,
                ],
                $this->businessClient,
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }

        session()->flash('status', $this->businessClient === null
            ? 'Business client added.'
            : 'Business client updated.');

        return redirect()->route('business-clients.show', $client);
    }

    public function render(): View
    {
        return view('livewire.portal.business-client-form');
    }
}
