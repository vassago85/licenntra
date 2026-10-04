<?php

namespace App\Enums;

trait LabelsEnum
{
    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
