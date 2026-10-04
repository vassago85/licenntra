<?php

namespace App\Enums;

enum FleetVehicleOcrStatus: string
{
    use LabelsEnum;

    case Pending = 'pending';
    case Clean = 'clean';
    case Unreadable = 'unreadable';
    case Failed = 'failed';
}
