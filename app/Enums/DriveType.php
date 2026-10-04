<?php

namespace App\Enums;

enum DriveType: string
{
    case SelfPropelled = 'self_propelled';
    case Trailer = 'trailer';
    case SemiTrailer = 'semi_trailer';
    case TrailerDrawnByTractor = 'trailer_drawn_by_tractor';

    public function label(): string
    {
        return match ($this) {
            self::SelfPropelled => 'Self-propelled',
            self::Trailer => 'Trailer (drawn)',
            self::SemiTrailer => 'Semi-trailer',
            self::TrailerDrawnByTractor => 'Trailer drawn by tractor',
        };
    }
}
