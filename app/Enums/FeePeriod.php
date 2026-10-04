<?php

namespace App\Enums;

enum FeePeriod: string
{
    use LabelsEnum;

    case OnceOff = 'once_off';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::OnceOff => 'Once off',
            self::Annual => 'Annual',
        };
    }
}
