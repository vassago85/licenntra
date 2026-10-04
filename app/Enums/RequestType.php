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
}
