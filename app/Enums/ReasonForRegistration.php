<?php

namespace App\Enums;

enum ReasonForRegistration: string
{
    case FirstRegistration = 'first_registration';
    case OwnershipChange = 'ownership_change';
    case ReRegistration = 're_registration';
    case Repossessed = 'repossessed';
    case Amalgamation = 'amalgamation';
    case BuiltUp = 'built_up';
    case Recovered = 'recovered';
    case Estate = 'estate';

    public function label(): string
    {
        return match ($this) {
            self::FirstRegistration => 'First registration',
            self::OwnershipChange => 'Change of ownership',
            self::ReRegistration => 'Re-registration',
            self::Repossessed => 'Repossessed',
            self::Amalgamation => 'Amalgamation',
            self::BuiltUp => 'Built-up',
            self::Recovered => 'Recovered',
            self::Estate => 'Estate',
        };
    }

    /**
     * Sensible pre-fill for a brand-new application.
     *
     * Dealership-submitted new-vehicle registrations are almost always
     * first registrations; the licensing-company reviewer picks a
     * different reason only in the exception.
     */
    public static function defaultFor(RequestType $requestType): self
    {
        return match ($requestType) {
            RequestType::NewRegistration => self::FirstRegistration,
            RequestType::ChangeOfOwnership => self::OwnershipChange,
            default => self::OwnershipChange,
        };
    }
}
