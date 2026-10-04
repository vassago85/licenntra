<?php

namespace App\Enums;

enum LicenceExpirySource: string
{
    use LabelsEnum;

    case Scan = 'scan';
    case Typed = 'typed';
}
