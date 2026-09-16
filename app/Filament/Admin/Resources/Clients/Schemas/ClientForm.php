<?php

namespace App\Filament\Admin\Resources\Clients\Schemas;

use App\Enums\ClientType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('clients.fields.type'))
                    ->options(fn () => collect(ClientType::cases())->mapWithKeys(fn (ClientType $type) => [$type->value => $type->label()]))
                    ->required(),
                TextInput::make('name')
                    ->label(__('clients.fields.name'))
                    ->required()
                    ->maxLength(150),
                TextInput::make('id_number')
                    ->label(__('clients.fields.id_number'))
                    ->required()
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label(__('clients.fields.phone'))
                    ->tel()
                    ->maxLength(20),
                TextInput::make('email')
                    ->label(__('clients.fields.email'))
                    ->email()
                    ->maxLength(150),
                TextInput::make('representative_name')
                    ->label(__('clients.fields.representative_name'))
                    ->maxLength(150)
                    ->visible(fn (callable $get) => $get('type') === ClientType::Organization->value),
                Textarea::make('address')
                    ->label(__('clients.fields.address'))
                    ->columnSpanFull(),
                Textarea::make('note')
                    ->label(__('clients.fields.note'))
                    ->hint(__('clients.note_hint'))
                    ->columnSpanFull(),
            ]);
    }
}
