<?php

namespace App\Livewire\Portal;

use App\Actions\UploadFleetLicence;
use App\Enums\ClientAccountType;
use App\Models\ClientAccount;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.portal')]
class FleetReviewQueue extends Component
{
    use WithFileUploads;

    public string $uploadFleetId = '';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $uploads = [];

    public function mount(): void
    {
        $this->authorize('reviewAny', FleetVehicle::class);
    }

    public function uploadLicence(): void
    {
        $this->authorize('reviewAny', FleetVehicle::class);

        $fleet = ClientAccount::query()
            ->ofType(ClientAccountType::FleetOperator)
            ->find($this->uploadFleetId);

        if ($fleet === null) {
            $this->addError('uploadFleetId', 'Pick a fleet.');

            return;
        }

        $this->authorize('uploadFor', [FleetVehicle::class, $fleet]);

        $files = array_filter($this->uploads, fn ($file): bool => $file instanceof TemporaryUploadedFile);

        if ($files === []) {
            $this->addError('uploads', 'Choose at least one PDF, JPG, or PNG.');

            return;
        }

        try {
            foreach ($files as $file) {
                app(UploadFleetLicence::class)->handle($fleet, $file, auth()->user());
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->reset('uploads');
        session()->flash('status', count($files).' licence '.(count($files) === 1 ? 'file' : 'files').' uploaded. OCR is running in the background.');
    }

    public function render(): View
    {
        $pending = FleetVehicleDocument::query()
            ->with(['fleetVehicle.clientAccount', 'documentVersion', 'uploadedBy'])
            ->whereNull('confirmed_at')
            ->orderByDesc('id')
            ->get();

        $fleets = ClientAccount::query()
            ->ofType(ClientAccountType::FleetOperator)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.portal.fleet-review-queue', [
            'pending' => $pending,
            'fleets' => $fleets,
        ]);
    }
}
