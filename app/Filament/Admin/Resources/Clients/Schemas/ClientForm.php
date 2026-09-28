<?php

namespace App\Filament\Admin\Resources\Clients\Schemas;

use App\Enums\ClientType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Form khách hàng (SPEC §4.2). Dùng cho `ClientResource` (tạo/sửa) — `configure()` — và tái dùng
 * qua `fields()` cho khối "Tạo khách mới" của `Matters\Schemas\MatterForm` (M6.5 Task 6, R4 b).
 *
 * **Bugfix `intake/intake-08` (M6.5 Task 6):**
 *  - `type` giờ `live()` — trước bản sửa này ô "Người đại diện" không bao giờ hiện trên màn hình
 *    TẠO khi chọn "Tổ chức" (không có `live()` thì chọn trên trình duyệt không gửi request nào),
 *    chỉ hiện sau khi lưu rồi mở lại trang Sửa.
 *  - `representative_name` giờ `maxLength(120)` — cột `clients.representative_name` là
 *    `string(120)`; form trước cho tới 150 ký tự, MariaDB strict báo lỗi 1406 thành trang 500.
 *  - `address` giờ có `maxLength(300)` — cột `clients.address` là `string(300)`; `Textarea` trước
 *    không giới hạn gì, cùng hạng lỗi 500 trên MariaDB strict với địa chỉ trên 300 ký tự.
 *  - `id_number` bỏ `required()` — SPEC §4.2 ghi nullable.
 *  - `name` giờ `maxLength(200)` đúng bằng cột `clients.name` (trước là 150, thấp hơn cột nên
 *    không phải lỗi 500, nhưng lệch với quy tắc "độ dài form phải bằng độ dài cột DB").
 */
class ClientForm
{
    /**
     * @param  array<int, mixed>  $extra  Các component thêm vào SAU bảy trường chuẩn — dùng bởi
     *                                    `Clients\Pages\CreateClient` để gắn khối cảnh báo trùng
     *                                    (R4) mà không phải viết lại bảy trường ở trên.
     */
    public static function configure(Schema $schema, array $extra = []): Schema
    {
        return $schema->components([...static::fields(), ...$extra]);
    }

    /**
     * Bảy trường chuẩn của SPEC §4.2, để tên trường tường minh (không dùng tiền tố khi gọi trần).
     *
     * **Không dùng lại nguyên vẹn cho khối "Tạo khách mới" của `MatterForm` (M6.5 Task 6).** Khối
     * đó cần một điều kiện `visible()`/`required()` THỨ HAI ("chỉ khi luật sư chưa tra được hồ sơ
     * nào") đan xen với điều kiện riêng của `representative_name` ("chỉ khi Tổ chức") — Filament
     * không cộng dồn được hai lời gọi `->visible()` liên tiếp trên CÙNG một field (lời gọi sau ghi
     * đè lời gọi trước, không AND lại) — nên `MatterForm::newClientFields()` viết lại bảy trường
     * này với đúng cùng nhãn/độ dài, thay vì gọi hàm này rồi cố "vá" thêm điều kiện. Bảy trường ở
     * hai nơi phải đổi CÙNG NHAU nếu quy tắc SPEC §4.2 đổi — cùng đánh đổi mà
     * `CreateMatter::conflictSummary()` đã chọn cho lý do tương tự (xem docblock ở đó).
     *
     * @return array<int, mixed>
     */
    public static function fields(): array
    {
        return [
            Select::make('type')
                ->label(__('clients.fields.type'))
                ->options(fn () => collect(ClientType::cases())->mapWithKeys(fn (ClientType $type) => [$type->value => $type->label()]))
                ->live()
                ->required(),
            TextInput::make('name')
                ->label(__('clients.fields.name'))
                ->required()
                ->maxLength(200),
            TextInput::make('id_number')
                ->label(__('clients.fields.id_number'))
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
                ->maxLength(120)
                ->visible(fn (callable $get) => $get('type') === ClientType::Organization->value),
            Textarea::make('address')
                ->label(__('clients.fields.address'))
                ->maxLength(300)
                ->columnSpanFull(),
            Textarea::make('note')
                ->label(__('clients.fields.note'))
                ->hint(__('clients.note_hint'))
                ->columnSpanFull(),
        ];
    }
}
