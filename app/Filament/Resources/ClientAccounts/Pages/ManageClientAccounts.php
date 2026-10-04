<?php

namespace App\Filament\Resources\ClientAccounts\Pages;

use App\Filament\Resources\ClientAccounts\ClientAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClientAccounts extends ManageRecords
{
    protected static string $resource = ClientAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
