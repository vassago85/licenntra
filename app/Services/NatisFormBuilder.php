<?php

namespace App\Services;

use App\Enums\BodyDescription;
use App\Enums\ClientAccountType;
use App\Enums\DriveType;
use App\Enums\EconomicSector;
use App\Enums\FuelType;
use App\Enums\IdentificationType;
use App\Enums\LicenceFeeCategory;
use App\Enums\MainColour;
use App\Enums\NatisFormType;
use App\Enums\NatureOfOwnership;
use App\Enums\OdometerType;
use App\Enums\OwnerType;
use App\Enums\ReasonForRegistration;
use App\Enums\RequestType;
use App\Enums\SteeringPosition;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleUsage;
use App\Models\Application;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\Party;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Fills the ALV(9) / RLV(5) from what the application already knows, and
 * describes the form's fields so the staff check screen and the printout
 * render the same thing. Saved staff edits always win over derived values.
 */
class NatisFormBuilder
{
    public const TYPE_TEXT = 'text';

    public const TYPE_CHOICE = 'choice';

    public const TYPE_FLAG = 'flag';

    public const TYPE_DATE = 'date';

    private const MAX_LENGTH = 255;

    public function formTypeFor(Application $application): ?NatisFormType
    {
        return NatisFormType::forRequestType($application->request_type);
    }

    /**
     * The form's sections in printed order.
     *
     * @return list<array{key: string, part: ?string, title: string, hint: ?string, fields: list<array{key: string, label: string, type: string, options?: array<string, string>, wide?: bool}>}>
     */
    public function sections(NatisFormType $type): array
    {
        if ($type === NatisFormType::Alv) {
            return [
                $this->section('owner', null, 'Particulars of owner', null, $this->personFields(false)),
                $this->section('owner_proxy', null, "Organisation's proxy", null, $this->delegateFields()),
                $this->section('owner_representative', null, "Organisation's representative", null, $this->delegateFields()),
                $this->section('vehicle', null, 'Identification of motor vehicle', null, $this->alvVehicleFields()),
                $this->section('declaration', null, 'Declaration', null, $this->declarationFields(['owner' => 'Owner', 'proxy' => "Organisation's proxy", 'representative' => "Organisation's representative"])),
            ];
        }

        return [
            $this->section('transaction', null, 'Transaction', null, [
                $this->choice('type', 'Application for', [
                    'registration' => 'Registration of motor vehicle by title holder (parts A, B, C)',
                    'licensing' => 'Licensing of motor vehicle by owner (parts B, C)',
                ], wide: true),
                $this->flag('owner_is_title_holder', 'Owner is the title holder (Part B is left blank)'),
            ]),
            $this->section('title_holder', 'A', 'Particulars of title holder', 'The bank or finance house, or the cash buyer.', $this->personFields(true)),
            $this->section('title_holder_proxy', 'A', "Organisation's proxy", null, $this->delegateFields()),
            $this->section('title_holder_representative', 'A', "Organisation's representative", 'Only if different from the proxy.', $this->delegateFields()),
            $this->section('title_holder_declaration', 'A', 'Declaration', null, $this->declarationFields(['title_holder' => 'Title holder', 'proxy' => "Organisation's proxy", 'representative' => "Organisation's representative", 'motor_dealer' => 'Motor dealer'])),
            $this->section('owner', 'B', 'Particulars of owner', 'Only if different from Part A.', $this->personFields(true)),
            $this->section('owner_proxy', 'B', "Organisation's proxy", null, $this->delegateFields()),
            $this->section('owner_representative', 'B', "Organisation's representative", 'Only if different from the proxy.', $this->delegateFields()),
            $this->section('owner_declaration', 'B', 'Declaration', null, $this->declarationFields(['owner' => 'Owner', 'proxy' => "Organisation's proxy", 'representative' => "Organisation's representative"])),
            $this->section('vehicle', 'C', 'Particulars of motor vehicle', null, $this->rlvVehicleFields()),
        ];
    }

    /**
     * Validation rules for the check screen, keyed "values.section.field".
     *
     * @return array<string, list<mixed>>
     */
    public function rules(NatisFormType $type): array
    {
        $rules = [];

        foreach ($this->sections($type) as $section) {
            foreach ($section['fields'] as $field) {
                $rules['values.'.$section['key'].'.'.$field['key']] = match ($field['type']) {
                    self::TYPE_CHOICE => ['nullable', 'string', Rule::in(array_keys($field['options']))],
                    self::TYPE_FLAG => ['boolean'],
                    self::TYPE_DATE => ['nullable', 'date_format:Y-m-d'],
                    default => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
                };
            }
        }

        return $rules;
    }

    /**
     * Human labels for validation messages, keyed like rules().
     *
     * @return array<string, string>
     */
    public function attributeLabels(NatisFormType $type): array
    {
        $labels = [];

        foreach ($this->sections($type) as $section) {
            foreach ($section['fields'] as $field) {
                $labels['values.'.$section['key'].'.'.$field['key']] = Str::lower($field['label']);
            }
        }

        return $labels;
    }

    /**
     * What prints: the checked form when there is one, otherwise the
     * values derived from the application right now.
     *
     * @return array<string, array<string, string|bool>>
     */
    public function values(Application $application): array
    {
        $type = $this->formTypeFor($application);

        if ($type === null) {
            return [];
        }

        if ($this->isChecked($application)) {
            return $this->normalise($type, $application->natis_form['values']);
        }

        return $this->derive($application);
    }

    /**
     * Every field filled from the application's own records, ignoring any
     * saved check.
     *
     * @return array<string, array<string, string|bool>>
     */
    public function derive(Application $application): array
    {
        $type = $this->formTypeFor($application);

        if ($type === null) {
            return [];
        }

        $application->loadMissing(['clientAccount', 'vehicle', 'businessClient', 'titleHolder', 'parties']);
        $owner = $this->ownerParticulars($application);
        $vehicle = $this->vehicleParticulars($application, $type, $owner['is_organisation']);

        if ($type === NatisFormType::Alv) {
            return $this->normalise($type, [
                'owner' => $owner['person'],
                'owner_proxy' => $owner['proxy'],
                'owner_representative' => $owner['representative'],
                'vehicle' => $vehicle,
                'declaration' => ['declarant' => $owner['is_organisation'] ? 'proxy' : 'owner'],
            ]);
        }

        $titleHolder = $application->is_financed && $application->titleHolder !== null
            ? $this->businessClientParticulars($application->titleHolder)
            : null;
        $partA = $titleHolder ?? $owner;
        $ownerIsTitleHolder = $titleHolder === null;

        $values = [
            'transaction' => ['type' => 'registration', 'owner_is_title_holder' => $ownerIsTitleHolder],
            'title_holder' => $partA['person'],
            'title_holder_proxy' => $partA['proxy'],
            'title_holder_representative' => $partA['representative'],
            'title_holder_declaration' => ['declarant' => $this->titleHolderDeclarant($application, $partA['is_organisation'])],
            'vehicle' => $vehicle,
        ];

        if (! $ownerIsTitleHolder) {
            $values['owner'] = $owner['person'];
            $values['owner_proxy'] = $owner['proxy'];
            $values['owner_representative'] = $owner['representative'];
            $values['owner_declaration'] = ['declarant' => $owner['is_organisation'] ? 'proxy' : 'owner'];
        }

        return $this->normalise($type, $values);
    }

    /**
     * Everything the print view needs for one application's form, or null
     * when its request type is not lodged on an ALV or RLV.
     *
     * @return array{type: NatisFormType, sections: list<array<string, mixed>>, values: array<string, array<string, string|bool>>, isChecked: bool, application: Application}|null
     */
    public function printable(Application $application): ?array
    {
        $type = $this->formTypeFor($application);

        if ($type === null) {
            return null;
        }

        return [
            'type' => $type,
            'sections' => $this->sections($type),
            'values' => $this->printableValues($type, $this->values($application)),
            'isChecked' => $this->isChecked($application),
            'application' => $application,
        ];
    }

    /**
     * True when staff have checked and saved this application's form.
     */
    public function isChecked(Application $application): bool
    {
        $type = $this->formTypeFor($application);
        $saved = $application->natis_form;

        return $type !== null
            && is_array($saved)
            && ($saved['type'] ?? null) === $type->value
            && is_array($saved['values'] ?? null);
    }

    /**
     * True when the application's data has changed since the form was
     * checked, so the saved form may no longer match it.
     */
    public function isOutOfDate(Application $application): bool
    {
        return $this->isChecked($application)
            && ($application->natis_form['source_hash'] ?? null) !== $this->sourceHash($application);
    }

    /**
     * Fingerprint of the derived form, stored with each check.
     */
    public function sourceHash(Application $application): string
    {
        return hash('sha256', (string) json_encode($this->derive($application)));
    }

    /**
     * Keeps only the form's own fields, each cast to its type. Unknown
     * choices become blank so a stale value never prints a wrong box.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array<string, string|bool>>
     */
    public function normalise(NatisFormType $type, array $values): array
    {
        $clean = [];

        foreach ($this->sections($type) as $section) {
            foreach ($section['fields'] as $field) {
                $raw = data_get($values, $section['key'].'.'.$field['key']);

                $clean[$section['key']][$field['key']] = match ($field['type']) {
                    self::TYPE_FLAG => (bool) $raw,
                    self::TYPE_CHOICE => is_scalar($raw) && array_key_exists((string) $raw, $field['options']) ? (string) $raw : '',
                    default => is_scalar($raw) ? Str::limit(trim((string) $raw), self::MAX_LENGTH, '') : '',
                };
            }
        }

        return $clean;
    }

    /**
     * The values as they go on paper: parts the chosen transaction leaves
     * out print blank.
     *
     * @param  array<string, array<string, string|bool>>  $values
     * @return array<string, array<string, string|bool>>
     */
    public function printableValues(NatisFormType $type, array $values): array
    {
        if ($type !== NatisFormType::Rlv) {
            return $values;
        }

        $blank = $this->normalise($type, []);
        $blankSections = [];

        if (($values['transaction']['type'] ?? '') === 'licensing') {
            $blankSections = ['title_holder', 'title_holder_proxy', 'title_holder_representative', 'title_holder_declaration'];
        } elseif ($values['transaction']['owner_is_title_holder'] ?? false) {
            $blankSections = ['owner', 'owner_proxy', 'owner_representative', 'owner_declaration'];
        }

        foreach ($blankSections as $section) {
            $values[$section] = $blank[$section];
        }

        return $values;
    }

    /**
     * Blank fields the department will reject the form without, as
     * "section.field" keys mapped to a readable label.
     *
     * @param  array<string, array<string, string|bool>>  $values
     * @return array<string, string>
     */
    public function missingEssentials(NatisFormType $type, array $values): array
    {
        $values = $this->printableValues($type, $values);
        $required = [];

        $principals = $type === NatisFormType::Alv
            ? ['owner' => 'Owner']
            : ['title_holder' => 'Part A title holder', 'owner' => 'Part B owner'];

        foreach ($principals as $section => $label) {
            if ($type === NatisFormType::Rlv && ! $this->partIsFilledIn($section, $values)) {
                continue;
            }

            $required[$section.'.id_type'] = $label.': type of identification';
            $required[$section.'.id_number'] = $label.': identification number';
            $required[$section.'.surname'] = $label.': surname or name of organisation';
            $required[$section.'.street_address'] = $label.': street address';

            if (($values[$section]['id_type'] ?? '') === IdentificationType::BusinessReg->value) {
                $required[$section.'_proxy.id_type'] = $label.' proxy: type of identification';
                $required[$section.'_proxy.id_number'] = $label.' proxy: identification number';
                $required[$section.'_proxy.surname'] = $label.' proxy: surname';
                $required[$section.'_proxy.initials'] = $label.' proxy: initials';
            }
        }

        $required['vehicle.vin'] = 'Vehicle: chassis number / VIN';
        $required['vehicle.make'] = 'Vehicle: make';

        if ($type === NatisFormType::Alv) {
            $required['vehicle.licence_number'] = 'Vehicle: licence number';
        } else {
            $required['vehicle.series_name'] = 'Vehicle: series name';
            $required['vehicle.category'] = 'Vehicle: category';
            $required['vehicle.tare_kg'] = 'Vehicle: tare';

            if (! ($values['vehicle']['licence_not_allocated'] ?? false)) {
                $required['vehicle.licence_number'] = 'Vehicle: licence number (or tick "not yet allocated")';
            }

            if (! ($values['vehicle']['no_engine'] ?? false)) {
                $required['vehicle.engine_number'] = 'Vehicle: engine number (or tick "no engine")';
            }
        }

        return array_filter(
            $required,
            fn (string $label, string $key): bool => blank(data_get($values, $key)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, array<string, string|bool>>  $values
     */
    private function partIsFilledIn(string $section, array $values): bool
    {
        $transaction = $values['transaction'] ?? [];

        return match ($section) {
            'title_holder' => ($transaction['type'] ?? '') !== 'licensing',
            'owner' => ($transaction['type'] ?? '') === 'licensing' || ! ($transaction['owner_is_title_holder'] ?? false),
            default => true,
        };
    }

    private function titleHolderDeclarant(Application $application, bool $titleHolderIsOrganisation): string
    {
        $account = $application->clientAccount;

        if ($account instanceof ClientAccount && $account->hasType(ClientAccountType::Dealer)) {
            return 'motor_dealer';
        }

        return $titleHolderIsOrganisation ? 'proxy' : 'title_holder';
    }

    /**
     * @return array{is_organisation: bool, person: array<string, string>, proxy: array<string, string>, representative: array<string, string>}
     */
    private function ownerParticulars(Application $application): array
    {
        if ($application->businessClient instanceof BusinessClient) {
            return $this->businessClientParticulars($application->businessClient);
        }

        $account = $application->clientAccount;
        $isDealerStock = $application->is_dealer_stock || $application->request_type === RequestType::DealerStock;

        if ($isDealerStock && $account instanceof ClientAccount) {
            return $this->dealershipParticulars($account);
        }

        $party = $application->parties->firstWhere('role', 'owner');

        if (! $party instanceof Party) {
            return ['is_organisation' => $application->owner_type === OwnerType::Business, 'person' => [], 'proxy' => [], 'representative' => []];
        }

        if ($party->party_type === OwnerType::Business->value) {
            return [
                'is_organisation' => true,
                'person' => $this->organisationPerson((string) $party->name, $party->identifier) + $this->addressFields('street', $party->address),
                'proxy' => [],
                'representative' => [],
            ];
        }

        return [
            'is_organisation' => false,
            'person' => $this->individualPerson($party->name, $party->identifier) + $this->addressFields('street', $party->address),
            'proxy' => [],
            'representative' => [],
        ];
    }

    /**
     * @return array{is_organisation: bool, person: array<string, string>, proxy: array<string, string>, representative: array<string, string>}
     */
    private function businessClientParticulars(BusinessClient $client): array
    {
        $person = $this->organisationPerson((string) $client->business_name, $client->registration_number)
            + $this->addressFields('street', $client->address);
        $contact = trim((string) $client->proxy_contact);

        if ($contact !== '') {
            $person[str_contains($contact, '@') ? 'email' : 'phone_day'] = $contact;
        }

        return [
            'is_organisation' => true,
            'person' => $person,
            'proxy' => $this->delegate($client->proxy_name, null, null, $client->proxy_id_number, null),
            'representative' => [],
        ];
    }

    /**
     * @return array{is_organisation: bool, person: array<string, string>, proxy: array<string, string>, representative: array<string, string>}
     */
    private function dealershipParticulars(ClientAccount $account): array
    {
        $person = $this->organisationPerson((string) $account->name, $account->brn)
            + $this->addressFields('street', $account->street_address)
            + $this->addressFields('postal', $account->postal_address);
        $person['email'] = (string) $account->contact_email;
        $person['phone_day'] = (string) $account->contact_phone;

        return [
            'is_organisation' => true,
            'person' => $person,
            'proxy' => $this->delegate($account->proxy_name, $account->proxy_initials, $account->proxy_id_type, $account->proxy_id_number, $account->proxy_id_country),
            'representative' => $this->delegate($account->representative_name, $account->representative_initials, $account->representative_id_type, $account->representative_id_number, $account->representative_id_country),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function organisationPerson(string $name, ?string $registrationNumber): array
    {
        $nature = match (true) {
            (bool) preg_match('/\(pty\)|proprietary|\bltd\b|limited/i', $name) => 'private_company',
            (bool) preg_match('/\bcc\b|close corporation/i', $name) => 'close_corporation',
            default => '',
        };

        return [
            'id_type' => filled($registrationNumber) ? IdentificationType::BusinessReg->value : '',
            'id_number' => (string) $registrationNumber,
            'nature' => $nature,
            'surname' => $name,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function individualPerson(?string $name, ?string $identifier): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $name));
        $words = $name === '' ? [] : explode(' ', $name);
        $surname = (string) array_pop($words);
        $firstNames = array_slice($words, 0, 3);
        $identifier = (string) $identifier;
        $person = [
            'surname' => $surname,
            'first_names' => implode(' ', $firstNames),
            'initials' => implode('', array_map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)), $firstNames)),
            'id_number' => $identifier,
            'id_type' => '',
        ];

        $birth = $this->birthDateFromRsaId($identifier);

        if ($birth !== null) {
            $person['id_type'] = IdentificationType::RsaId->value;
            $person['date_of_birth'] = $birth->format('Y-m-d');
            $person['nature'] = (int) substr($identifier, 6, 4) >= 5000 ? 'male' : 'female';
        }

        return $person;
    }

    /**
     * @return array<string, string>
     */
    private function delegate(?string $name, ?string $initials, ?IdentificationType $idType, ?string $idNumber, ?string $country): array
    {
        $name = trim((string) $name);
        $surname = $name;

        if (blank($initials) && str_contains($name, ' ')) {
            $words = explode(' ', (string) preg_replace('/\s+/', ' ', $name));
            $surname = (string) array_pop($words);
            $initials = implode('', array_map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)), $words));
        }

        if ($idType === null && $this->birthDateFromRsaId((string) $idNumber) !== null) {
            $idType = IdentificationType::RsaId;
        }

        return [
            'id_type' => $idType?->value ?? '',
            'id_number' => (string) $idNumber,
            'id_country' => (string) $country,
            'surname' => $surname,
            'initials' => (string) $initials,
        ];
    }

    /**
     * A 13-digit SA ID number starts with the holder's date of birth.
     */
    private function birthDateFromRsaId(string $identifier): ?Carbon
    {
        $digits = preg_replace('/\s+/', '', $identifier);

        if (! preg_match('/^\d{13}$/', (string) $digits)) {
            return null;
        }

        $year = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        $century = $year > (int) now()->format('y') ? 1900 : 2000;

        if (! checkdate($month, $day, $century + $year)) {
            return null;
        }

        return Carbon::create($century + $year, $month, $day);
    }

    /**
     * Splits a free-text address into the form's street, suburb, city and
     * code lines. Addresses that do not split cleanly stay on the street line.
     *
     * @return array<string, string>
     */
    private function addressFields(string $prefix, ?string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $address) ?: [])));
        $code = '';

        if ($parts !== [] && preg_match('/^(.*?)\s*(\d{4})$/', end($parts), $matches)) {
            $code = $matches[2];
            array_pop($parts);

            if ($matches[1] !== '') {
                $parts[] = $matches[1];
            }
        }

        $city = count($parts) >= 2 ? (string) array_pop($parts) : '';
        $suburb = count($parts) >= 2 ? (string) array_pop($parts) : '';

        $fields = [
            $prefix.'_address' => implode(', ', $parts),
            $prefix.'_suburb' => $suburb,
            $prefix.'_city' => $city,
            $prefix.'_code' => $code,
        ];

        if ($prefix === 'street' && $fields['street_address'] !== '') {
            $fields['notices_to'] = 'street';
        }

        return $fields;
    }

    /**
     * @return array<string, string|bool>
     */
    private function vehicleParticulars(Application $application, NatisFormType $type, bool $ownerIsOrganisation): array
    {
        $vehicle = $application->vehicle;
        $driven = $vehicle?->drive_type?->value ?? (in_array($application->licence_category, [LicenceFeeCategory::Trailer, LicenceFeeCategory::Caravan], true)
            ? DriveType::Trailer->value
            : DriveType::SelfPropelled->value);
        $isDrawn = $driven !== DriveType::SelfPropelled->value;

        $common = [
            'licence_number' => '',
            'register_number' => (string) $vehicle?->vehicle_register_number,
            'vin' => (string) $vehicle?->vin,
            'make' => (string) $vehicle?->make,
            'series_name' => (string) $vehicle?->model,
            'odometer_reading' => (string) $vehicle?->odometer_reading,
            'odometer_type' => $vehicle?->odometer_type?->value ?? ($isDrawn ? OdometerType::None->value : ''),
            'steering' => $vehicle?->steering_position?->value ?? ($isDrawn ? SteeringPosition::Drawn->value : SteeringPosition::Right->value),
        ];

        if ($type === NatisFormType::Alv) {
            return $common;
        }

        $isNewToNatis = in_array($application->request_type, [RequestType::NewRegistration, RequestType::DealerStock, RequestType::Import, RequestType::Mib], true);

        return $common + [
            'licence_not_allocated' => $isNewToNatis,
            'natis_model_number' => (string) $vehicle?->natis_model_number,
            'category' => $this->vehicleCategory($application, $vehicle),
            'driven' => $driven,
            'description' => $vehicle?->body_description?->value ?? (filled($vehicle?->body_type) ? BodyDescription::Other->value : ''),
            'description_other' => (string) ($vehicle?->body_description_other ?? ($vehicle?->body_description === null ? $vehicle?->body_type : '')),
            'engine_number' => (string) $vehicle?->engine_number,
            'no_engine' => $isDrawn && blank($vehicle?->engine_number),
            'net_power_kw' => (string) $vehicle?->net_power_kw,
            'engine_cc' => (string) $vehicle?->engine_capacity_cc,
            'fuel' => $vehicle?->fuel_type?->value ?? match (true) {
                $isDrawn => FuelType::None->value,
                $application->vehicle_category === VehicleCategory::Commercial => FuelType::Diesel->value,
                default => '',
            },
            'tare_kg' => (string) $vehicle?->tare_kg,
            'gvm_kg' => (string) $vehicle?->gvm_kg,
            'transmission' => $vehicle?->transmission?->value ?? ($isDrawn ? Transmission::None->value : ''),
            'colour' => $vehicle?->main_colour?->value ?? (filled($vehicle?->colour_other) ? MainColour::Other->value : ''),
            'colour_other' => (string) $vehicle?->colour_other,
            'usage' => $vehicle?->vehicle_usage?->value ?? match (true) {
                $application->dangerous_goods => VehicleUsage::DangerousGoods->value,
                $application->vehicle_category === VehicleCategory::Passenger => VehicleUsage::Passengers->value,
                default => '',
            },
            'usage_other' => (string) $vehicle?->vehicle_usage_other,
            'sector' => $vehicle?->economic_sector?->value ?? ($ownerIsOrganisation ? '' : EconomicSector::Private->value),
            'sector_other' => (string) $vehicle?->economic_sector_other,
            'date_liable' => (string) $vehicle?->date_liable?->format('Y-m-d'),
            'nature_of_ownership' => $vehicle?->nature_of_ownership?->value ?? $this->natureOfOwnership($application, $ownerIsOrganisation),
            'public_road' => $vehicle?->used_on_public_road === false ? 'no' : 'yes',
            'reason' => $vehicle?->reason_for_registration?->value ?? match ($application->request_type) {
                RequestType::ChangeOfOwnership => ReasonForRegistration::OwnershipChange->value,
                RequestType::NewRegistration, RequestType::DealerStock, RequestType::Import, RequestType::Mib => ReasonForRegistration::FirstRegistration->value,
                default => '',
            },
        ] + $this->keptAddress($vehicle);
    }

    /**
     * @return array<string, string>
     */
    private function keptAddress(?Vehicle $vehicle): array
    {
        $fields = $this->addressFields('kept', $vehicle?->address_where_kept);
        unset($fields['notices_to']);

        return $fields;
    }

    private function natureOfOwnership(Application $application, bool $ownerIsOrganisation): string
    {
        return match (true) {
            $application->request_type === RequestType::Mib => NatureOfOwnership::MibStock->value,
            $application->is_dealer_stock || $application->request_type === RequestType::DealerStock => NatureOfOwnership::MdStock->value,
            $ownerIsOrganisation => NatureOfOwnership::Business->value,
            default => NatureOfOwnership::Private->value,
        };
    }

    /**
     * NaTIS vehicle category from the licence category, then the GVM.
     */
    private function vehicleCategory(Application $application, ?Vehicle $vehicle): string
    {
        $gvm = $vehicle?->gvm_kg;

        return match (true) {
            $application->licence_category === LicenceFeeCategory::Motorcycle => 'A',
            in_array($application->licence_category, [LicenceFeeCategory::Minibus, LicenceFeeCategory::Bus, LicenceFeeCategory::Taxi], true) => 'C',
            in_array($application->licence_category, [LicenceFeeCategory::TractorPublicRoad, LicenceFeeCategory::SpecialClass, LicenceFeeCategory::BreakdownVehicle], true) => 'U',
            $application->vehicle_category === VehicleCategory::Passenger => 'B',
            $gvm === null => '',
            $gvm <= 3500 => 'K',
            default => 'L',
        };
    }

    /**
     * @param  list<array{key: string, label: string, type: string, options?: array<string, string>, wide?: bool}>  $fields
     * @return array{key: string, part: ?string, title: string, hint: ?string, fields: list<array{key: string, label: string, type: string, options?: array<string, string>, wide?: bool}>}
     */
    private function section(string $key, ?string $part, string $title, ?string $hint, array $fields): array
    {
        return ['key' => $key, 'part' => $part, 'title' => $title, 'hint' => $hint, 'fields' => $fields];
    }

    /**
     * @return array{key: string, label: string, type: string, wide: bool}
     */
    private function text(string $key, string $label, bool $wide = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => self::TYPE_TEXT, 'wide' => $wide];
    }

    /**
     * @param  array<string, string>  $options
     * @return array{key: string, label: string, type: string, options: array<string, string>, wide: bool}
     */
    private function choice(string $key, string $label, array $options, bool $wide = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => self::TYPE_CHOICE, 'options' => $options, 'wide' => $wide];
    }

    /**
     * @return array{key: string, label: string, type: string, wide: bool}
     */
    private function flag(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => self::TYPE_FLAG, 'wide' => false];
    }

    /**
     * @return array{key: string, label: string, type: string, wide: bool}
     */
    private function date(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => self::TYPE_DATE, 'wide' => false];
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return array<string, string>
     */
    private function enumOptions(string $enum): array
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $options[$case->value] = method_exists($case, 'label') ? $case->label() : Str::headline($case->name);
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function personFields(bool $withNatureAndBirth): array
    {
        $fields = [
            $this->choice('id_type', 'Type of identification', $this->enumOptions(IdentificationType::class), wide: true),
            $this->text('id_number', 'Identification number'),
            $this->text('id_country', 'Country of issue (foreign ID)'),
        ];

        if ($withNatureAndBirth) {
            $fields[] = $this->choice('nature', 'Gender / nature of organisation', [
                'male' => 'Male',
                'female' => 'Female',
                'one_man_business' => 'One-man business',
                'private_company' => 'Private company',
                'close_corporation' => 'Close corporation',
                'other' => 'Other',
            ], wide: true);
            $fields[] = $this->text('nature_other', 'Other nature (specify)');
        }

        $fields[] = $this->text('surname', 'Surname / name of organisation', wide: true);
        $fields[] = $this->text('initials', 'Initials');
        $fields[] = $this->text('first_names', 'First names (not more than 3)');

        if ($withNatureAndBirth) {
            $fields[] = $this->date('date_of_birth', 'Date of birth (natural person)');
        }

        return array_merge($fields, [
            $this->text('email', 'E-mail address'),
            $this->text('phone_home', 'Telephone number at home'),
            $this->text('phone_day', 'Contact telephone number during day'),
            $this->text('fax', 'Facsimile number'),
            $this->text('cell', 'Cellphone number'),
            $this->text('postal_address', 'Postal address', wide: true),
            $this->text('postal_suburb', 'Postal suburb'),
            $this->text('postal_city', 'Postal city / town'),
            $this->text('postal_code', 'Postal code'),
            $this->text('street_address', 'Street address', wide: true),
            $this->text('street_suburb', 'Street suburb'),
            $this->text('street_city', 'Street city / town'),
            $this->text('street_code', 'Street postal code'),
            $this->choice('notices_to', 'Address where notices must be served', ['postal' => 'Postal address', 'street' => 'Street address']),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function delegateFields(): array
    {
        $idTypes = $this->enumOptions(IdentificationType::class);
        unset($idTypes[IdentificationType::BusinessReg->value]);

        return [
            $this->choice('id_type', 'Type of identification', $idTypes, wide: true),
            $this->text('id_number', 'Identification number'),
            $this->text('id_country', 'Country of issue (foreign ID)'),
            $this->text('surname', 'Surname'),
            $this->text('initials', 'Initials'),
        ];
    }

    /**
     * @param  array<string, string>  $declarants
     * @return list<array<string, mixed>>
     */
    private function declarationFields(array $declarants): array
    {
        return [
            $this->choice('declarant', 'I, the', $declarants, wide: true),
            $this->text('place', 'Place'),
            $this->date('date', 'Date'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alvVehicleFields(): array
    {
        return [
            $this->text('licence_number', 'Licence number'),
            $this->text('register_number', 'Vehicle register number'),
            $this->text('vin', 'Chassis number / VIN'),
            $this->text('make', 'Make'),
            $this->text('series_name', 'Series name (describe in full)', wide: true),
            $this->text('odometer_reading', 'Odometer reading'),
            $this->choice('odometer_type', 'Odometer', $this->enumOptions(OdometerType::class)),
            $this->choice('steering', 'Position of steering wheel', $this->enumOptions(SteeringPosition::class), wide: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rlvVehicleFields(): array
    {
        return [
            $this->text('licence_number', 'Licence number'),
            $this->flag('licence_not_allocated', 'Licence number not yet allocated'),
            $this->text('register_number', 'Vehicle register number'),
            $this->text('vin', 'Chassis number / VIN'),
            $this->text('make', 'Make'),
            $this->text('series_name', 'Series name (describe in full)', wide: true),
            $this->text('natis_model_number', 'NaTIS model number'),
            $this->choice('category', 'Vehicle category', [
                'A' => 'A - Motor cycle / tricycle / quadrucycle',
                'B' => 'B - Light passenger vehicle (less than 12 persons)',
                'C' => 'C - Heavy passenger vehicle (12 or more persons)',
                'K' => 'K - Light load vehicle (GVM 3 500 kg or less)',
                'L' => 'L - Heavy load vehicle (GVM > 3 500 kg, not to draw)',
                'U' => 'U - Special vehicle',
                'M' => 'M - Heavy load vehicle (GVM > 3 500 kg, equipped to draw)',
            ], wide: true),
            $this->choice('driven', 'Driven', $this->enumOptions(DriveType::class), wide: true),
            $this->choice('description', 'Vehicle description', $this->enumOptions(BodyDescription::class), wide: true),
            $this->text('description_other', 'Other description (specify)'),
            $this->text('engine_number', 'Engine number'),
            $this->flag('no_engine', 'No engine'),
            $this->text('net_power_kw', 'Net power (kW)'),
            $this->text('engine_cc', 'Engine capacity (cm³)'),
            $this->choice('fuel', 'Fuel type', $this->enumOptions(FuelType::class), wide: true),
            $this->text('fuel_other', 'Other fuel (specify)'),
            $this->text('tare_kg', 'Tare (kg)'),
            $this->text('gvm_kg', 'Gross vehicle mass (kg)'),
            $this->choice('transmission', 'Transmission', $this->enumOptions(Transmission::class), wide: true),
            $this->choice('colour', 'Main colour', $this->enumOptions(MainColour::class), wide: true),
            $this->text('colour_other', 'Other colour (specify)'),
            $this->choice('usage', 'Used for the transportation of', $this->enumOptions(VehicleUsage::class), wide: true),
            $this->text('usage_other', 'Other transportation (specify)'),
            $this->choice('sector', 'Economic sector in which used', $this->enumOptions(EconomicSector::class), wide: true),
            $this->text('sector_other', 'Other sector (specify)'),
            $this->text('odometer_reading', 'Odometer reading'),
            $this->choice('odometer_type', 'Odometer', $this->enumOptions(OdometerType::class)),
            $this->choice('steering', 'Position of steering wheel', $this->enumOptions(SteeringPosition::class), wide: true),
            $this->text('kept_address', 'Street address where vehicle is kept (if different from owner)', wide: true),
            $this->text('kept_suburb', 'Suburb'),
            $this->text('kept_city', 'City / town'),
            $this->text('kept_code', 'Postal code'),
            $this->date('date_liable', 'Date liable for registration / licensing'),
            $this->choice('nature_of_ownership', 'Nature of ownership', $this->enumOptions(NatureOfOwnership::class), wide: true),
            $this->choice('public_road', 'Is vehicle used on a public road?', ['yes' => 'Yes', 'no' => 'No']),
            $this->choice('reason', 'Reason for registration', $this->enumOptions(ReasonForRegistration::class), wide: true),
        ];
    }
}
