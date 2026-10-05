<?php

namespace App\Enums;

enum OffboardReason: string
{
    case Resigned = 'resigned';
    case Dismissed = 'dismissed';
    case Retired = 'retired';
    case ContractEnded = 'contract_ended';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resigned => 'Resigned',
            self::Dismissed => 'Dismissed',
            self::Retired => 'Retired',
            self::ContractEnded => 'Contract ended',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
