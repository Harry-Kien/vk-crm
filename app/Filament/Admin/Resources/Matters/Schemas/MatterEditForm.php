<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Enums\Confidentiality;
use App\Enums\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Form "Sửa vụ việc" (SPEC §4.6, §5; M6.5 Task 5, findings `intake/intake-06`,
 * `spec-gap/spec-gap-06`). Tệp riêng khỏi `MatterForm` (form MỞ vụ việc): hai màn hình không
 * chung một luật nào — `MatterForm` còn kiểm tra xung đột lợi ích và dựng bên khách hàng,
 * `EditMatter` chỉ sửa năm cột đã có sẵn trên một vụ việc đang tồn tại. Gộp chung sẽ đẻ ra một
 * schema đầy điều kiện `visible()`/`disabled()` phân biệt "đang tạo" và "đang sửa", khó đọc hơn
 * chính hai tệp riêng nó thay thế.
 *
 * **Chỉ NĂM trường, đúng SPEC §4.6 liệt kê cho màn hình sửa.** `client_id`, `matter_type_id`,
 * `lead_lawyer_id` KHÔNG có mặt ở đây — không phải `disabled()`, mà VẮNG MẶT hoàn toàn khỏi
 * schema, vì `App\Actions\Matter\UpdateMatterDetails` không nhận ba khoá đó trong chữ ký
 * `handle()` của nó (xem docblock Action đó). Một trường `disabled()` vẫn có thể bị Filament gửi
 * lên (dù không dehydrate — nhưng "không dehydrate" là một chi tiết framework, không phải một
 * cổng); vắng mặt khỏi schema và khỏi `EditMatter::mutateFormDataBeforeSave()` là cách duy nhất
 * không phụ thuộc vào chi tiết đó.
 *
 * **`confidentiality` bị `disabled()` với trợ lý (R5), nhưng KHÔNG vắng mặt.** Trợ lý cần ĐỌC
 * được mức bảo mật hiện tại và hiểu vì sao ô bị khoá; `Action` vẫn tự hỏi lại
 * `MatterPolicy::updateConfidentiality` khi giá trị thật sự đổi, nên đây chỉ là lớp UI — cùng
 * nguyên tắc "không tin `disabled()` một mình" mà `EditClientUser::mutateFormDataBeforeSave()` đã
 * ghi lại cho `client_id`.
 */
class MatterEditForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('matters.edit_form.section'))
                    ->columns(2)
                    ->schema(static::fields()),
            ]);
    }

    /** @return array<int, mixed> */
    private static function fields(): array
    {
        return [
            TextInput::make('title')
                ->label(__('matters.fields.title'))
                ->required()
                ->maxLength(250)
                ->columnSpanFull(),
            Textarea::make('summary_for_client')
                ->label(__('matters.fields.summary_for_client'))
                ->rows(2)
                ->columnSpanFull(),
            TextInput::make('court_name')
                ->label(__('matters.overview_fields.court_name'))
                ->maxLength(200),
            TextInput::make('case_number')
                ->label(__('matters.overview_fields.case_number'))
                ->maxLength(80),
            Select::make('confidentiality')
                ->label(__('matters.overview_fields.confidentiality'))
                ->options(collect(Confidentiality::cases())
                    ->mapWithKeys(fn (Confidentiality $case) => [$case->value => $case->label()])
                    ->all())
                ->native(false)
                ->required()
                // R5: chỉ lớp UI — xem docblock lớp. Action tự hỏi lại Gate là cổng THẬT.
                ->disabled(fn (): bool => Auth::user()?->hasRole(Role::Assistant->value) ?? true)
                ->helperText(fn (): ?string => Auth::user()?->hasRole(Role::Assistant->value)
                    ? __('matters.edit_form.confidentiality_denied')
                    : null),
        ];
    }
}
