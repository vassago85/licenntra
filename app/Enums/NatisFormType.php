<?php

namespace App\Enums;

/**
 * The eNaTIS application form lodged with the registering authority.
 * RLV(5) registers and licenses a vehicle in a new title holder's name;
 * ALV(9) renews the licence of a vehicle already registered.
 */
enum NatisFormType: string
{
    case Alv = 'alv';
    case Rlv = 'rlv';

    public static function forRequestType(?RequestType $requestType): ?self
    {
        return match ($requestType) {
            RequestType::NewRegistration,
            RequestType::ChangeOfOwnership,
            RequestType::DealerStock,
            RequestType::Mib,
            RequestType::Import => self::Rlv,
            RequestType::LicenceRenewal => self::Alv,
            default => null,
        };
    }

    public function code(): string
    {
        return match ($this) {
            self::Alv => 'ALV',
            self::Rlv => 'RLV',
        };
    }

    public function formNumber(): string
    {
        return match ($this) {
            self::Alv => 'ALV(9)',
            self::Rlv => 'RLV(5)',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Alv => 'Application for licensing of motor vehicle',
            self::Rlv => 'Application for registration and licensing of motor vehicle',
        };
    }
}
