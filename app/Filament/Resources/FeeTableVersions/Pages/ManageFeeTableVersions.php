<?php

namespace App\Filament\Resources\FeeTableVersions\Pages;

use App\Filament\Resources\FeeTableVersions\FeeTableVersionResource;
use App\Models\FeeTableVersion;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFeeTableVersions extends ManageRecords
{
    protected static string $resource = FeeTableVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['created_by'] = auth()->id();
                    $data['status'] = 'draft';
                    $data['approved_by'] = null;
                    $data['approved_at'] = null;
                    $latest = FeeTableVersion::query()->where('fee_table_id', $data['fee_table_id'])->max('version');
                    $data['version'] = ((int) $latest) + 1;

                    return $data;
                }),
        ];
    }
}
