<?php

namespace App\Enums;

/**
 * Vehicle categories used when pricing an annual provincial motor-vehicle
 * licence. These are distinct from the business-level VehicleCategory
 * (passenger / commercial), which is only used to route applications.
 */
enum LicenceFeeCategory: string
{
    use LabelsEnum;

    case Motorcycle = 'motorcycle';
    case MotorCar = 'motor_car';
    case Minibus = 'minibus';
    case Bus = 'bus';
    case GoodsVehicle = 'goods_vehicle';
    case Trailer = 'trailer';
    case Caravan = 'caravan';
    case Dealer = 'dealer';
    case Taxi = 'taxi';
    case BreakdownVehicle = 'breakdown_vehicle';
    case TractorPublicRoad = 'tractor_public_road';
    case TradePlateMotorcycle = 'trade_plate_motorcycle';
    case TradePlateOther = 'trade_plate_other';
    case PermitTemporary = 'permit_temporary';
    case PermitSpecial = 'permit_special';
    case SpecialClass = 'special_class';
    case RegistrationFee = 'registration_fee';
    case TradePlateApplication = 'trade_plate_application';
    case TransactionFee = 'transaction_fee';

    public function label(): string
    {
        return match ($this) {
            self::Motorcycle => 'Motorcycle',
            self::MotorCar => 'Rigid vehicle (motor car, bakkie, truck, bus)',
            self::Minibus => 'Minibus',
            self::Bus => 'Bus',
            self::GoodsVehicle => 'Goods vehicle',
            self::Trailer => 'Trailer / semi-trailer',
            self::Caravan => 'Caravan',
            self::Dealer => 'Dealer plates',
            self::Taxi => 'Taxi',
            self::BreakdownVehicle => 'Breakdown vehicle',
            self::TractorPublicRoad => 'Tractor on public road',
            self::TradePlateMotorcycle => 'Trade plate (motorcycle)',
            self::TradePlateOther => 'Trade plate (other vehicles)',
            self::PermitTemporary => 'Temporary permit',
            self::PermitSpecial => 'Special permit',
            self::SpecialClass => 'Special class (farming tractor / construction machinery not on public road)',
            self::RegistrationFee => 'Motor vehicle registration fee',
            self::TradePlateApplication => 'Application for motor trade plate number',
            self::TransactionFee => 'RTMC transaction fee',
        };
    }
}
