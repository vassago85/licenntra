<?php

namespace App\Enums;

enum ClientAccountType: string
{
    use LabelsEnum;

    case Oem = 'oem';
    case Dealer = 'dealer';
    case BodyBuilder = 'body_builder';
    case FleetOperator = 'fleet_operator';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Oem => 'OEM',
            self::Dealer => 'Dealer',
            self::BodyBuilder => 'Body builder',
            self::FleetOperator => 'Fleet operator',
            self::Other => 'Other',
        };
    }
}
