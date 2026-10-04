<?php

namespace App\Enums;

enum OwnerType: string
{
    use LabelsEnum;

    case Individual = 'individual';
    case Business = 'business';
}
