<?php

namespace App\Livewire\Portal\Admin;

use App\Models\BrandingSetting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Portal replacement for the former Filament "Branding" page.
 *
 * Scoped to users who can configure the licensing company (super_admin,
 * customer_admin). Everyone else gets 403 at mount.
 */
#[Layout('layouts.portal')]
class Branding extends Component
{
    use WithFileUploads;

    public string $company_name = '';

    public string $reference_prefix = '';

    public string $primary_colour = '#146d61';

    public ?string $support_email = null;

    public ?string $support_phone = null;

    public ?string $address = null;

    /** Existing stored logo path. */
    public ?string $logo_path = null;

    /** Pending new upload (temporary file). */
    public ?UploadedFile $logo_upload = null;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $user = $this->currentUser();
        abort_unless($user->canConfigure(), 403);

        $branding = BrandingSetting::current();

        $this->company_name = (string) $branding->company_name;
        $this->reference_prefix = (string) $branding->reference_prefix;
        $this->primary_colour = (string) ($branding->primary_colour ?: '#146d61');
        $this->support_email = $branding->support_email;
        $this->support_phone = $branding->support_phone;
        $this->address = $branding->address;
        $this->logo_path = $branding->logo_path;
    }

    public function save(): void
    {
        $this->resetErrorBag();

        $data = $this->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'reference_prefix' => ['required', 'string', 'max:12'],
            'primary_colour' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:160'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'logo_upload' => ['nullable', 'image', 'max:1024'],
        ]);

        $branding = BrandingSetting::current();

        $payload = [
            'company_name' => $data['company_name'],
            'reference_prefix' => $data['reference_prefix'],
            'primary_colour' => $data['primary_colour'],
            'support_email' => $data['support_email'] ?: null,
            'support_phone' => $data['support_phone'] ?: null,
            'address' => $data['address'] ?: null,
        ];

        if ($this->logo_upload instanceof UploadedFile) {
            $stored = $this->logo_upload->store('branding', 'public');

            if ($stored !== false) {
                if ($branding->logo_path && Storage::disk('public')->exists($branding->logo_path)) {
                    Storage::disk('public')->delete($branding->logo_path);
                }

                $payload['logo_path'] = $stored;
                $this->logo_path = $stored;
            }
        }

        $branding->update($payload);

        $this->logo_upload = null;
        $this->statusMessage = 'Branding saved. Clients will see the new values the next time they load a page.';
    }

    public function removeLogo(): void
    {
        $branding = BrandingSetting::current();

        if ($branding->logo_path && Storage::disk('public')->exists($branding->logo_path)) {
            Storage::disk('public')->delete($branding->logo_path);
        }

        $branding->update(['logo_path' => null]);

        $this->logo_path = null;
        $this->logo_upload = null;
        $this->statusMessage = 'Logo removed.';
    }

    public function render(): View
    {
        return view('livewire.portal.admin.branding');
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
