<?php

namespace App\Enums;

enum FuelType: string
{
    case None = 'none';
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Petrol => 'Petrol',
            self::Diesel => 'Diesel',
            self::Other => 'Other',
        };
    }

    /**
     * Sensible pre-fill for a brand-new application.
     *
     * Almost every South African commercial vehicle on these forms is a
     * diesel, so default commercial to diesel and passenger to petrol.
     * The licensing-company reviewer can override before printing the pack.
     */
    public static function defaultFor(VehicleCategory $category): self
    {
        return match ($category) {
            VehicleCategory::Commercial => self::Diesel,
            VehicleCategory::Passenger => self::Petrol,
        };
    }
}
