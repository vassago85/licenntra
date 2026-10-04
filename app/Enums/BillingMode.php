<?php

namespace App\Enums;

enum BillingMode: string
{
    use LabelsEnum;

    /**
     * Fee is quoted, paid up-front and verified before documents move onwards.
     * This is the default for private clients, OEMs and fleet operators.
     */
    case PayPerTransaction = 'pay_per_transaction';

    /**
     * Documents are handed over immediately and the fee is added to the
     * client's monthly statement. Payment arrives later within agreed terms.
     * Typical for dealership accounts.
     */
    case AccountStatement = 'account_statement';

    public function label(): string
    {
        return match ($this) {
            self::PayPerTransaction => 'Pay per transaction',
            self::AccountStatement => 'Account statement',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PayPerTransaction => 'Up-front',
            self::AccountStatement => 'On statement',
        };
    }
}
