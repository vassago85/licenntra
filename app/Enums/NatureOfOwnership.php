<?php

namespace App\Enums;

enum NatureOfOwnership: string
{
    case Private = 'private';
    case Business = 'business';
    case MdStock = 'md_stock';
    case MibStock = 'mib_stock';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Business => 'Business',
            self::MdStock => 'MD stock (motor dealer)',
            self::MibStock => 'MIB stock (motor industry body)',
        };
    }
}
