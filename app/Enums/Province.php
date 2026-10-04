<?php

namespace App\Enums;

enum Province: string
{
    use LabelsEnum;

    case EasternCape = 'eastern_cape';
    case FreeState = 'free_state';
    case Gauteng = 'gauteng';
    case KwaZuluNatal = 'kwa_zulu_natal';
    case Limpopo = 'limpopo';
    case Mpumalanga = 'mpumalanga';
    case NorthernCape = 'northern_cape';
    case NorthWest = 'north_west';
    case WesternCape = 'western_cape';

    public function label(): string
    {
        return match ($this) {
            self::EasternCape => 'Eastern Cape',
            self::FreeState => 'Free State',
            self::Gauteng => 'Gauteng',
            self::KwaZuluNatal => 'KwaZulu-Natal',
            self::Limpopo => 'Limpopo',
            self::Mpumalanga => 'Mpumalanga',
            self::NorthernCape => 'Northern Cape',
            self::NorthWest => 'North West',
            self::WesternCape => 'Western Cape',
        };
    }
}
