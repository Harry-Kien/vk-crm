<?php

namespace App\Filament\Admin\Resources\ClientUsers\Schemas;

use App\Filament\Admin\Support\VisibleClientOptions;
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
                // Review fix round 1, Important #2: KHÔNG liệt kê toàn bộ khách hàng văn phòng —
                // một lawyer có clientUser.manage nhưng không có client.manage chỉ được thấy
                // khách hàng của những vụ việc họ liệt kê được, cùng ranh giới
                // ClientPolicy::view (và PartiesRelationManager ở nơi khác dùng chung đúng một
                // App\Filament\Admin\Support\VisibleClientOptions này, không tự lặp lại luật).
                //
                // Task 2 (`roles/roles-01`, critical): client_id không đổi được sau khi tạo, với
                // BẤT KỲ ai — đổi khách nghĩa là tạo tài khoản mới. Ô này vì thế chỉ để ĐỌC trên
                // trang sửa (`disabled()` khi $operation === 'edit'). `dehydrated()` ép giữ field
                // này trong $data dù bị disabled — mặc định Filament NGỪNG dehydrate một field bị
                // disabled (`HasState::isDehydrated()` rơi về `isSaved()`, và `disabled()` đặt
                // `isSaved()` thành false) — để lớp phòng thủ THẬT nằm ở
                // `EditClientUser::mutateFormDataBeforeSave()` (độc lập với UI, không tin
                // `disabled()` — xem chú thích bảo mật ngay trong `CanBeDisabled::disabled()` của
                // Filament: "skilled users can manipulate Livewire's JavaScript to bypass the
                // disabled state") luôn nhận được client_id để ghi đè lại, thay vì im lặng không
                // có gì để ghi đè.
                Select::make('client_id')
                    ->label(__('client_users.fields.client'))
                    ->options(fn (): array => VisibleClientOptions::forCurrentUser())
                    ->searchable()
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(),
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
