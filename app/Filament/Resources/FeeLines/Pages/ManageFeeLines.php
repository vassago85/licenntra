<?php

namespace App\Filament\Resources\FeeLines\Pages;

use App\Filament\Resources\FeeLines\FeeLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFeeLines extends ManageRecords
{
    protected static string $resource = FeeLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
