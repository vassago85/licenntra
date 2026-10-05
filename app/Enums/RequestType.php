<?php

namespace App\Enums;

enum RequestType: string
{
    use LabelsEnum;

    case NewRegistration = 'new_registration';
    case LicenceRenewal = 'licence_renewal';
    case ChangeOfOwnership = 'change_of_ownership';
    case DealerStock = 'dealer_stock';
    case DuplicateDisc = 'duplicate_disc';
    case Deregistration = 'deregistration';
    case DataChange = 'data_change';
    case Mib = 'mib';
    case Import = 'import';
    case Export = 'export';
    case Custom = 'custom';

    /**
     * Human-readable label for the picker. Overrides the trait default
     * so dealers see "Data fix" (their jargon) and "MIB" (acronym stays
     * capitalised) instead of the uc-first-of-underscored version.
     */
    public function label(): string
    {
        return match ($this) {
            self::NewRegistration => 'New registration',
            self::LicenceRenewal => 'Licence renewal',
            self::ChangeOfOwnership => 'Change of ownership',
            self::DealerStock => 'Dealer stock',
            self::DuplicateDisc => 'Duplicate disc',
            self::Deregistration => 'Deregistration',
            self::DataChange => 'Data fix',
            self::Mib => 'MIB',
            self::Import => 'Import',
            self::Export => 'Export',
            self::Custom => 'Custom',
        };
    }

    public function needsQuoteByDefault(): bool
    {
        return $this === self::Import || $this === self::Export;
    }

    /**
     * Whether the gazette workflow for this request type ever involves a
     * title holder. First registrations and ownership changes do - the
     * RLV must name the bank/finance house that holds title. Everything
     * else (renewals, duplicate discs, deregistrations, data fixes,
     * imports, exports, dealer stock, MIB) uses the title holder already
     * on record at eNaTIS - or has none at all, in the dealer-stock case
     * where the dealership (or fleet) owns the vehicle outright.
     */
    public function requiresTitleHolder(): bool
    {
        return $this === self::NewRegistration || $this === self::ChangeOfOwnership;
    }

    /**
     * Dealer-stock transactions are a dealership moving a vehicle into
     * its own inventory, or into a fleet customer's name on behalf of
     * the fleet (common for fleet-claim arrangements where the fleet
     * must be the registered owner from day one for warranty / insurance
     * reasons). The owner is always a business - the dealership itself
     * or a fleet BusinessClient - and never has a separate title holder,
     * because the owner has paid for the vehicle outright at this point.
     */
    public function isDealerStock(): bool
    {
        return $this === self::DealerStock;
    }
}
