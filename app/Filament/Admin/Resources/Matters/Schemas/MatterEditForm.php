<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Actions\Matter\UpdateMatterDetails;
use App\Enums\Confidentiality;
use App\Models\Matter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

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
 * cổng); vắng mặt khỏi schema và khỏi `EditMatter::handleRecordUpdate()` (Action chỉ nhận đúng
 * năm khoá tường minh, xem docblock của nó) là cách duy nhất không phụ thuộc vào chi tiết đó.
 *
 * **`confidentiality` và `summary_for_client` bị `disabled()` cho ai không có quyền, nhưng KHÔNG
 * vắng mặt.** Người bị khoá vẫn cần ĐỌC được giá trị hiện tại và hiểu vì sao ô bị khoá.
 * Fix round 1 (finding I2/minor): `disabled()` gọi THẲNG `Gate::allows()` — không còn một điều
 * kiện vai trò tự viết tay (`hasRole(Assistant)`) lặp lại luật đã sống trong `MatterPolicy` —
 * nên khi luật đổi (ví dụ I2 đổi `updateConfidentiality` từ "không phải trợ lý" sang "chỉ lead/
 * admin"), ô này tự động đổi theo, không cần sửa hai chỗ. `Action` vẫn tự hỏi lại đúng ability đó
 * khi giá trị thật sự đổi, nên đây chỉ là lớp UI — cùng nguyên tắc "không tin `disabled()` một
 * mình" mà `EditClientUser::mutateFormDataBeforeSave()` đã ghi lại cho `client_id`.
 *
 * `?Matter $record` được Filament tự bơm vào closure theo kiểu tham số — cùng thiết bị bơm
 * `$livewire`/`$get` mà `MatterForm::forgetConflictResult()` đã dùng, chỉ khác object được bơm.
 * `null` chỉ xảy ra khi schema chưa gắn với bản ghi nào (không phải tình huống thật của trang
 * sửa, luôn có `$record`) — khoá lại cho an toàn kiểu, không phải một nhánh có ý nghĩa nghiệp vụ.
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
                ->columnSpanFull()
                // Fix round 1, finding I1: cùng quyền `stageLog.publish` như công bố cho khách.
                ->disabled(fn (?Matter $record): bool => $record === null
                    || Gate::denies('updateSummaryForClient', $record))
                ->helperText(fn (?Matter $record): ?string => ($record !== null && Gate::denies('updateSummaryForClient', $record))
                    ? __('matters.edit_form.summary_for_client_denied')
                    : null),
            // Làn fm A3: ghi chú nội bộ nhập lúc mở vụ — trước đây không màn hình nào sửa được.
            Textarea::make('description_internal')
                ->label(__('matters.transition_form.internal_note'))
                ->helperText(__('matters.transition_form.internal_note_hint'))
                ->rows(4)
                ->maxLength(UpdateMatterDetails::DESCRIPTION_INTERNAL_MAX)
                ->columnSpanFull(),
            // Làn fm A3: ngày mở hồ sơ gõ nhầm sửa được; `maxDate()` chỉ là tiện lợi, cổng thật (không
            // sau hôm nay, không sau ngày kết thúc) ở `UpdateMatterDetails`.
            DatePicker::make('opened_at')
                ->label(__('matters.overview_fields.opened_at'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->maxDate(today())
                ->required(),
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
                // Fix round 1, finding I2: chỉ lead của CHÍNH vụ việc này hoặc admin — xem
                // docblock lớp.
                ->disabled(fn (?Matter $record): bool => $record === null
                    || Gate::denies('updateConfidentiality', $record))
                ->helperText(fn (?Matter $record): ?string => ($record !== null && Gate::denies('updateConfidentiality', $record))
                    ? __('matters.edit_form.confidentiality_denied')
                    : null),
        ];
    }
}
