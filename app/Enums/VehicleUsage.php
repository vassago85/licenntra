<?php

namespace App\Enums;

enum VehicleUsage: string
{
    case Passengers = 'passengers';
    case PersonsForReward = 'persons_for_reward';
    case DangerousGoods = 'dangerous_goods';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Passengers => 'Passengers',
            self::PersonsForReward => 'Persons for reward (e.g. taxi, ambulance)',
            self::DangerousGoods => 'Dangerous goods',
            self::Other => 'Other',
        };
    }
}
