<?php

namespace App\Enums;

/**
 * Documents the licensing company physically received back from the
 * authority and uploaded against an application, ready for the dealer
 * to collect or forward to their customer.
 */
enum DeliverableKind: string
{
    case NatisCertificate = 'natis_certificate';
    case LicenceDisc = 'licence_disc';
    case DeregistrationCertificate = 'deregistration_certificate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NatisCertificate => 'NaTIS registration certificate',
            self::LicenceDisc => 'Licence disc',
            self::DeregistrationCertificate => 'Deregistration certificate',
            self::Other => 'Other document',
        };
    }
}
