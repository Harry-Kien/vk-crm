<?php

namespace App\Filament\Admin\Resources\ClientUsers\Schemas;

use App\Models\Client;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ClientUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label(__('client_users.fields.client'))
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->label(__('client_users.fields.name'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('email')
                    ->label(__('client_users.fields.email'))
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(150),
                TextInput::make('phone')
                    ->label(__('client_users.fields.phone'))
                    ->tel()
                    ->maxLength(20),
                TextInput::make('password')
                    ->label(__('client_users.fields.password'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label(__('client_users.fields.is_active'))
                    ->default(true),
                Toggle::make('must_change_password')
                    ->label(__('client_users.fields.must_change_password'))
                    ->default(true),
                DateTimePicker::make('activated_at')
                    ->label(__('client_users.fields.activated_at')),
            ]);
    }
}
