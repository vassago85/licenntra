<?php

namespace App\Livewire\Portal;

use App\Actions\CalculateFees;
use App\Actions\SaveApplicationDraft;
use App\Actions\StoreDocument;
use App\Actions\SubmitApplication;
use App\Enums\LicenceFeeCategory;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\BusinessClient;
use App\Models\FleetVehicle;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.portal')]
class ApplicationForm extends Component
{
    use WithFileUploads;

    public ?Application $application = null;

    public string $request_type = '';

    public string $service_type = '';

    public string $vehicle_category = '';

    public string $licence_category = '';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $uploads = [];

    public string $owner_type = '';

    public string $province = '';

    public bool $is_financed = false;

    public bool $dangerous_goods = false;

    public string $business_client_id = '';

    public string $title_holder_business_client_id = '';

    public string $new_business_name = '';

    public string $new_registration_number = '';

    public string $new_proxy_name = '';

    public string $new_proxy_id_number = '';

    public string $new_address = '';

    // Inline "new title holder" fields - parallel to the owner "new business
    // client" fields above. A title holder is a separate BusinessClient
    // record (usable_as = title_holder) that typically has NO proxy ID of
    // its own; the proxy_contact field matters most for finance houses.
    public string $new_title_holder_business_name = '';

    public string $new_title_holder_registration_number = '';

    public string $new_title_holder_proxy_name = '';

    public string $new_title_holder_proxy_contact = '';

    public string $new_title_holder_proxy_id_number = '';

    public string $new_title_holder_address = '';

    public string $owner_name = '';

    public string $owner_identifier = '';

    public string $owner_address = '';

    public string $vin = '';

    public string $vehicle_register_number = '';

    public string $engine_number = '';

    public string $make = '';

    public string $model = '';

    public string $year = '';

    public string $body_type = '';

    public string $tare_kg = '';

    public function mount(?Application $application = null): void
    {
        if ($application === null) {
            $this->authorize('create', Application::class);

            $prefillId = request()->integer('prefill_fleet_vehicle');

            if ($prefillId > 0) {
                $this->createDraftFromFleetVehicle($prefillId);
            }

            return;
        }

        $this->authorize('update', $application);
        $this->application = $application->load(['vehicle', 'documents.documentType', 'parties']);
        $this->fillFromApplication();
    }

    /**
     * Build a licence-renewal draft from a {@see FleetVehicle} record so a
     * fleet user clicking a renewal link in the monthly email lands straight
     * on the edit page with the register number, VIN, make and category
     * already filled in. The draft is only created the first time the link is
     * opened.
     */
    private function createDraftFromFleetVehicle(int $vehicleId): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $vehicle = FleetVehicle::query()
            ->where('client_account_id', $user->client_account_id)
            ->whereNull('retired_at')
            ->find($vehicleId);

        if ($vehicle === null) {
            return;
        }

        try {
            $saved = app(SaveApplicationDraft::class)->handle($user, [
                'request_type' => RequestType::LicenceRenewal->value,
                'vehicle_category' => $vehicle->vehicle_category?->value,
                'vin' => $vehicle->vin,
                'vehicle_register_number' => $vehicle->vehicle_register_number,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->redirectRoute('applications.edit', $saved);
    }

    public function updated(string $name): void
    {
        $watched = [
            'request_type', 'service_type', 'vehicle_category', 'licence_category', 'owner_type', 'province',
            'is_financed', 'dangerous_goods', 'business_client_id', 'title_holder_business_client_id',
        ];

        if ($name === 'business_client_id' && $this->business_client_id === 'new') {
            return;
        }

        if ($name === 'title_holder_business_client_id' && $this->title_holder_business_client_id === 'new') {
            return;
        }

        if (in_array($name, $watched, true)) {
            $this->persist(false);
        }
    }

    public function save(): void
    {
        $this->persist(true);
        session()->flash('status', 'Draft saved.');
    }

    /**
     * Upload a required document straight from the application form so the
     * dealer doesn't have to leave the page they're filling in. Mirrors
     * the same flow used on ApplicationShow and goes through the same
     * StoreDocument action, so audit logging and scan dispatching are
     * identical.
     */
    public function upload(int $documentId): void
    {
        if ($this->application === null) {
            $this->addError('upload', 'Save the draft first before uploading documents.');

            return;
        }

        $document = $this->application->documents()->findOrFail($documentId);
        $this->authorize('upload', $document);

        $file = $this->uploads[$documentId] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            $this->addError('upload', 'Choose a PDF, JPG, or PNG.');

            return;
        }

        try {
            app(StoreDocument::class)->handle($document, $file, auth()->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        unset($this->uploads[$documentId]);
        $this->application->refresh();
    }

    public function submit(): void
    {
        $this->persist(false);

        if ($this->application === null) {
            return;
        }

        try {
            $application = app(SubmitApplication::class)->handle($this->application, auth()->user());
        } catch (InvalidTransition $exception) {
            $this->addError('submit', $exception->getMessage());

            return;
        }

        $this->redirectRoute('applications.show', $application);
    }

    public function render(): View
    {
        $documents = $this->application?->documents()
            ->with(['documentType', 'currentVersion'])
            ->orderBy('party_role')
            ->orderBy('id')
            ->get() ?? collect();

        $estimate = null;

        if ($this->application?->province) {
            $estimate = app(CalculateFees::class)->snapshot($this->application);
        }

        $user = auth()->user();

        return view('livewire.portal.application-form', [
            'requestTypes' => RequestType::cases(),
            'serviceTypes' => ServiceType::cases(),
            'categories' => VehicleCategory::cases(),
            'licenceCategories' => $this->dealerLicenceCategories(),
            'ownerTypes' => OwnerType::cases(),
            'provinces' => Province::cases(),
            'owners' => BusinessClient::query()->whereIn('usable_as', ['owner', 'both'])->orderBy('business_name')->get(),
            'titleHolders' => BusinessClient::query()->whereIn('usable_as', ['title_holder', 'both'])->orderBy('business_name')->get(),
            'documents' => $documents,
            'canUploadDocument' => fn ($document) => $user?->can('upload', $document) ?? false,
            'canDownloadDocument' => fn ($document) => $user?->can('download', $document) ?? false,
            'estimate' => $estimate,
            'money' => Money::class,
        ]);
    }

    /**
     * The licence-fee categories a dealer realistically picks on an
     * application. The gazette also prices things like dealer plates and
     * trade-plate permits, but those aren't a per-vehicle application -
     * they're issued separately.
     *
     * @return list<LicenceFeeCategory>
     */
    private function dealerLicenceCategories(): array
    {
        return [
            LicenceFeeCategory::MotorCar,
            LicenceFeeCategory::Motorcycle,
            LicenceFeeCategory::Minibus,
            LicenceFeeCategory::Bus,
            LicenceFeeCategory::Taxi,
            LicenceFeeCategory::Trailer,
            LicenceFeeCategory::Caravan,
            LicenceFeeCategory::BreakdownVehicle,
            LicenceFeeCategory::TractorPublicRoad,
            LicenceFeeCategory::SpecialClass,
        ];
    }

    private function persist(bool $flash): void
    {
        $user = auth()->user();

        if ($this->application === null) {
            $this->authorize('create', Application::class);
        } else {
            $this->authorize('update', $this->application);
        }

        try {
            $saved = app(SaveApplicationDraft::class)->handle($user, $this->payload(), $this->application);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $created = $this->application === null;
        $this->application = $saved->load(['vehicle', 'documents.documentType']);
        $this->new_business_name = '';
        $this->new_title_holder_business_name = '';

        // After a successful save, snap the dropdowns to the newly created
        // business client records so the inline forms collapse back to a
        // plain select showing the saved name.
        if ($this->business_client_id === 'new' && $saved->business_client_id !== null) {
            $this->business_client_id = (string) $saved->business_client_id;
        }
        if ($this->title_holder_business_client_id === 'new' && $saved->title_holder_business_client_id !== null) {
            $this->title_holder_business_client_id = (string) $saved->title_holder_business_client_id;
        }

        if ($created) {
            $this->redirectRoute('applications.edit', $saved);

            return;
        }

        if ($flash) {
            session()->flash('status', 'Draft saved.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'request_type' => $this->request_type,
            'service_type' => $this->service_type,
            'vehicle_category' => $this->vehicle_category,
            'licence_category' => $this->licence_category,
            'owner_type' => $this->owner_type,
            'province' => $this->province,
            'is_financed' => $this->is_financed,
            'dangerous_goods' => $this->dangerous_goods,
            'business_client_id' => $this->business_client_id === 'new' ? null : $this->business_client_id,
            'title_holder_business_client_id' => $this->title_holder_business_client_id === 'new' ? null : $this->title_holder_business_client_id,
            'new_business_name' => $this->business_client_id === 'new' ? $this->new_business_name : null,
            'new_registration_number' => $this->new_registration_number,
            'new_proxy_name' => $this->new_proxy_name,
            'new_proxy_id_number' => $this->new_proxy_id_number,
            'new_address' => $this->new_address,
            'new_title_holder_business_name' => $this->title_holder_business_client_id === 'new' ? $this->new_title_holder_business_name : null,
            'new_title_holder_registration_number' => $this->new_title_holder_registration_number,
            'new_title_holder_proxy_name' => $this->new_title_holder_proxy_name,
            'new_title_holder_proxy_contact' => $this->new_title_holder_proxy_contact,
            'new_title_holder_proxy_id_number' => $this->new_title_holder_proxy_id_number,
            'new_title_holder_address' => $this->new_title_holder_address,
            'owner_name' => $this->owner_name,
            'owner_identifier' => $this->owner_identifier,
            'owner_address' => $this->owner_address,
            'vin' => $this->vin,
            'vehicle_register_number' => $this->vehicle_register_number,
            'engine_number' => $this->engine_number,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year === '' ? null : (int) $this->year,
            'body_type' => $this->body_type,
            'tare_kg' => $this->tare_kg === '' ? null : (int) $this->tare_kg,
        ];
    }

    private function fillFromApplication(): void
    {
        $application = $this->application;
        $vehicle = $application?->vehicle;
        $owner = $application?->parties->firstWhere('role', 'owner');

        $this->request_type = $application?->request_type?->value ?? '';
        $this->service_type = $application?->service_type?->value ?? '';
        $this->vehicle_category = $application?->vehicle_category?->value ?? '';
        $this->licence_category = $application?->licence_category?->value ?? '';
        $this->owner_type = $application?->owner_type?->value ?? '';
        $this->province = $application?->province?->value ?? '';
        $this->is_financed = (bool) $application?->is_financed;
        $this->dangerous_goods = (bool) $application?->dangerous_goods;
        $this->business_client_id = (string) ($application?->business_client_id ?? '');
        $this->title_holder_business_client_id = (string) ($application?->title_holder_business_client_id ?? '');
        $this->vin = (string) ($vehicle?->vin ?? '');
        $this->vehicle_register_number = (string) ($vehicle?->vehicle_register_number ?? '');
        $this->engine_number = (string) ($vehicle?->engine_number ?? '');
        $this->make = (string) ($vehicle?->make ?? '');
        $this->model = (string) ($vehicle?->model ?? '');
        $this->year = (string) ($vehicle?->year ?? '');
        $this->body_type = (string) ($vehicle?->body_type ?? '');
        $this->tare_kg = (string) ($vehicle?->tare_kg ?? '');
        $this->owner_name = (string) ($owner?->name ?? '');
        $this->owner_identifier = (string) ($owner?->identifier ?? '');
        $this->owner_address = (string) ($owner?->address ?? '');
    }
}
