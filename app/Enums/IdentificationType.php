<?php

namespace App\Enums;

/**
 * Identification types accepted on the ALV / RLV declaration blocks for
 * owners, title holders, proxies and representatives.
 */
enum IdentificationType: string
{
    case TrafficRegister = 'traffic_register';
    case RsaId = 'rsa_id';
    case ForeignId = 'foreign_id';
    case BusinessReg = 'business_reg';

    public function label(): string
    {
        return match ($this) {
            self::TrafficRegister => 'Traffic register number',
            self::RsaId => 'RSA ID',
            self::ForeignId => 'Foreign ID',
            self::BusinessReg => 'Business reg. no.',
        };
    }
}
