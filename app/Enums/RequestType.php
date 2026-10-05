<?php

namespace App\Enums;

enum RequestType: string
{
    use LabelsEnum;

    case NewRegistration = 'new_registration';
    case LicenceRenewal = 'licence_renewal';
    case ChangeOfOwnership = 'change_of_ownership';
    case DuplicateDisc = 'duplicate_disc';
    case Deregistration = 'deregistration';
    case DataChange = 'data_change';
    case Import = 'import';
    case Export = 'export';
    case Custom = 'custom';

    public function needsQuoteByDefault(): bool
    {
        return $this === self::Import || $this === self::Export;
    }

    /**
     * Whether the gazette workflow for this request type ever involves a
     * title holder. First registrations and ownership changes do - the
     * RLV must name the bank/finance house that holds title. Everything
     * else (renewals, duplicate discs, deregistrations, data changes,
     * imports, exports) uses the title holder already on record at
     * eNaTIS, so dealers should not be asked to re-capture it.
     */
    public function requiresTitleHolder(): bool
    {
        return $this === self::NewRegistration || $this === self::ChangeOfOwnership;
    }
}
