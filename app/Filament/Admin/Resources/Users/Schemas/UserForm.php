<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\UserPosition;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('users.fields.name'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('email')
                    ->label(__('users.fields.email'))
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(150),
                Select::make('position')
                    ->label(__('users.fields.position'))
                    ->options(fn () => collect(UserPosition::cases())->mapWithKeys(fn (UserPosition $position) => [$position->value => $position->label()]))
                    ->required()
                    ->native(false)
                    // R7 (M6.5 Task 4): luật THẬT nằm ở EditUser::handleRecordUpdate() (server, không
                    // vòng qua được) — chỉ hiện khi đang SỬA một quản trị viên, đúng ca duy nhất câu
                    // này áp dụng, để không doạ người đang tạo mới hoặc sửa một chức danh khác.
                    ->helperText(fn (?User $record): ?string => $record?->position === UserPosition::Admin
                        ? __('users.offboarding.position_hint')
                        : null),
                TextInput::make('phone')
                    ->label(__('users.fields.phone'))
                    ->tel()
                    ->maxLength(20),
                TextInput::make('bar_number')
                    ->label(__('users.fields.bar_number'))
                    ->maxLength(50),
                // Chỉ nhận mật khẩu MỚI: bỏ trống khi sửa nghĩa là giữ nguyên (không bao giờ
                // hiện lại giá trị cũ, kể cả đã băm — SPEC §10 và ràng buộc của task này).
                TextInput::make('password')
                    ->label(__('users.fields.password'))
                    ->hint(__('users.password_hint'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label(__('users.fields.is_active'))
                    ->default(true)
                    // R7: luật THẬT ở EditUser::handleRecordUpdate(). $record là null lúc TẠO —
                    // chưa ai đứng tên việc gì trên một tài khoản chưa tồn tại, nên câu này chỉ có
                    // nghĩa lúc SỬA.
                    ->helperText(fn (?User $record): ?string => $record !== null
                        ? __('users.offboarding.is_active_hint')
                        : null),
            ]);
    }
}
