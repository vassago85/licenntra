<?php

namespace App\Enums;

enum VehicleCategory: string
{
    use LabelsEnum;

    case Passenger = 'passenger';
    case Commercial = 'commercial';
}
