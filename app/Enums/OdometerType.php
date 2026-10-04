<?php

namespace App\Enums;

enum OdometerType: string
{
    use LabelsEnum;

    case None = 'none';
    case Km = 'km';
    case Hour = 'hour';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No odometer',
            self::Km => 'Kilometres',
            self::Hour => 'Hours',
        };
    }
}
