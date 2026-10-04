<?php

namespace App\Enums;

enum MainColour: string
{
    use LabelsEnum;

    case White = 'white';
    case Red = 'red';
    case Blue = 'blue';
    case Other = 'other';
}
