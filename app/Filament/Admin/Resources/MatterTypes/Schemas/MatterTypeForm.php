<?php

namespace App\Filament\Admin\Resources\MatterTypes\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MatterTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('matter_types.fields.code'))
                    ->required()
                    ->maxLength(10)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label(__('matter_types.fields.name'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('description')
                    ->label(__('matter_types.fields.description'))
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('matter_types.fields.is_active'))
                    ->default(true),
                TextInput::make('sort_order')
                    ->label(__('matter_types.fields.sort_order'))
                    ->numeric()
                    ->integer()
                    ->default(0)
                    ->required(),
            ]);
    }
}
