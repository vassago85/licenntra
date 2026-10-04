<?php

namespace App\Filament\Resources\FeeTables\Pages;

use App\Filament\Resources\FeeTables\FeeTableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFeeTables extends ManageRecords
{
    protected static string $resource = FeeTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
