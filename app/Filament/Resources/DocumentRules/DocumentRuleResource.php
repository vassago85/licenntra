<?php

namespace App\Filament\Resources\DocumentRules;

use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Filament\Concerns\OnlyConfigurators;
use App\Filament\Resources\DocumentRules\Pages\ManageDocumentRules;
use App\Models\DocumentRule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentRuleResource extends Resource
{
    use OnlyConfigurators;

    protected static ?string $model = DocumentRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Documents';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('document_type_id')
                    ->relationship('documentType', 'name')
                    ->required(),
                Select::make('request_type')
                    ->options(RequestType::class),
                Select::make('vehicle_category')
                    ->options(VehicleCategory::class),
                Select::make('owner_type')
                    ->options(OwnerType::class),
                Select::make('province')
                    ->options(Province::class),
                Select::make('is_financed')
                    ->options([
                        '1' => 'Financed',
                        '0' => 'Cash',
                    ])
                    ->placeholder('Any'),
                TextInput::make('party_role')
                    ->required()
                    ->default('vehicle'),
                TextInput::make('requirement')
                    ->required()
                    ->default('required'),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('documentType.name')
                    ->searchable(),
                TextColumn::make('request_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('vehicle_category')
                    ->badge()
                    ->searchable(),
                TextColumn::make('owner_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('province')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_financed')
                    ->boolean(),
                TextColumn::make('party_role')
                    ->searchable(),
                TextColumn::make('requirement')
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentRules::route('/'),
        ];
    }
}
