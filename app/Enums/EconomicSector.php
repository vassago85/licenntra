<?php

namespace App\Enums;

enum EconomicSector: string
{
    case Private = 'private';
    case Agriculture = 'agriculture';
    case Manufacturing = 'manufacturing';
    case Services = 'services';
    case WholesaleRetail = 'wholesale_retail';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Agriculture => 'Agriculture',
            self::Manufacturing => 'Manufacturing',
            self::Services => 'Services',
            self::WholesaleRetail => 'Wholesale, retail',
            self::Other => 'Other',
        };
    }
}
