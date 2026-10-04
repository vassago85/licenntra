<?php

namespace App\Filament\Resources\DocumentRules\Pages;

use App\Filament\Resources\DocumentRules\DocumentRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentRules extends ManageRecords
{
    protected static string $resource = DocumentRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
