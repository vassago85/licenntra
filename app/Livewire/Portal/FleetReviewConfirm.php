<?php

namespace App\Livewire\Portal;

use App\Actions\ConfirmFleetVehicle;
use App\Enums\VehicleCategory;
use App\Models\FleetVehicle;
use App\Models\FleetVehicleDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.portal')]
class FleetReviewConfirm extends Component
{
    public FleetVehicleDocument $document;

    public string $vehicle_register_number = '';

    public string $vin = '';

    public string $make = '';

    public string $model = '';

    public string $vehicle_category = '';

    public string $licence_expires_on = '';

    public function mount(FleetVehicleDocument $document): void
    {
        $this->authorize('reviewAny', FleetVehicle::class);

        $document->load(['fleetVehicle.clientAccount', 'documentVersion']);
        $this->document = $document;

        $vehicle = $document->fleetVehicle;
        $this->vehicle_register_number = $document->ocr_register_candidate
            ?? $vehicle?->vehicle_register_number
            ?? '';
        $this->vin = $document->ocr_vin_candidate ?? $vehicle?->vin ?? '';
        $this->make = $vehicle?->make ?? '';
        $this->model = $vehicle?->model ?? '';
        $this->vehicle_category = $vehicle?->vehicle_category?->value ?? VehicleCategory::Commercial->value;
        $this->licence_expires_on = $document->ocr_expiry_candidate?->format('Y-m-d')
            ?? $vehicle?->licence_expires_on?->format('Y-m-d')
            ?? '';
    }

    public function confirm(): void
    {
        $this->authorize('reviewAny', FleetVehicle::class);

        try {
            $vehicle = app(ConfirmFleetVehicle::class)->handle(
                $this->document,
                auth()->user(),
                [
                    'vehicle_register_number' => $this->vehicle_register_number,
                    'vin' => $this->vin,
                    'make' => $this->make,
                    'model' => $this->model,
                    'vehicle_category' => $this->vehicle_category,
                    'licence_expires_on' => $this->licence_expires_on,
                ],
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        session()->flash('status', 'Vehicle '.($vehicle->vehicle_register_number ?? '').' confirmed.');
        $this->redirectRoute('fleet.review.queue');
    }

    public function render(): View
    {
        return view('livewire.portal.fleet-review-confirm', [
            'categories' => VehicleCategory::cases(),
        ]);
    }
}
