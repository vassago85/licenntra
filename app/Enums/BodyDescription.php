<?php

namespace App\Enums;

enum BodyDescription: string
{
    case Sedan = 'sedan';
    case HatchBack = 'hatch_back';
    case Pickup = 'pickup';
    case ChassisCab = 'chassis_cab';
    case Chassis = 'chassis';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Sedan => 'Sedan (closed top)',
            self::HatchBack => 'Hatch back',
            self::Pickup => 'Pick-up (bakkie)',
            self::ChassisCab => 'Chassis-cab',
            self::Chassis => 'Chassis',
            self::Other => 'Other',
        };
    }
}
