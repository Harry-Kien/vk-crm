<?php

namespace App\Filament\Admin\Resources\ClientUsers\Schemas;

use App\Filament\Admin\Support\VisibleClientOptions;
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
                    // Final review wave 2, M-3: form TẠO chỉ mời khách người tạo được quản lý tài
                    // khoản cổng (luật X3), nên không ai chọn được một khách rồi nhận 403. Form SỬA
                    // giữ danh sách cũ — ô đã khoá, chỉ để hiện tên khách đang gắn.
                    ->options(fn (string $operation): array => $operation === 'create'
                        ? VisibleClientOptions::portalManageableForCurrentUser()
                        : VisibleClientOptions::forCurrentUser())
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
                // Task 3 (lỗ hổng nêu ở brief: "hôm nay tài khoản portal được tạo bằng cách một
                // luật sư gõ tay mật khẩu vào form rồi đọc cho khách qua điện thoại"): ô mật khẩu
                // BỎ HẲN khỏi form. `App\Actions\Client\IssuePortalAccess` (gọi từ
                // `CreateClientUser::afterCreate()` và nút "Cấp lại mật khẩu" trên trang sửa) sinh
                // một mật khẩu tạm và gửi qua thư `client.activation` — không ai gõ tay, không ai
                // đọc mật khẩu qua điện thoại nữa.
                Toggle::make('is_active')
                    ->label(__('client_users.fields.is_active'))
                    ->default(true),
                // Task 7 (R12, phát hiện `intake/intake-05`, `intake/intake-04`): bỏ hẳn công tắc
                // must_change_password và ô activated_at khỏi form — trước bản sửa này nhân sự tắt
                // được must_change_password ngay lúc tạo (khách dùng mãi mật khẩu nhân sự chọn,
                // vượt qua cánh cổng SPEC §8.1), và activated_at là một ô ngày giờ gõ tay không nói
                // lên được gì (nhân sự có thể gõ bất kỳ ngày nào, kể cả khi khách chưa từng đăng
                // nhập). must_change_password giờ LUÔN true khi tạo
                // (CreateClientUser::mutateFormDataBeforeCreate()) và bật lại khi nhân sự đặt lại
                // mật khẩu (EditClientUser::mutateFormDataBeforeSave()); activated_at chỉ hệ thống
                // ghi, đúng lúc khách tự tay đổi mật khẩu lần đầu thành công
                // (App\Filament\Portal\Pages\Auth\ChangePassword::changePassword()).
            ]);
    }
}
