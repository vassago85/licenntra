<?php

namespace App\Enums;

enum SteeringPosition: string
{
    case Drawn = 'drawn';
    case Left = 'left';
    case Centre = 'centre';
    case Right = 'right';

    public function label(): string
    {
        return match ($this) {
            self::Drawn => 'Drawn (no steering)',
            self::Left => 'Left',
            self::Centre => 'Centre',
            self::Right => 'Right',
        };
    }
}
