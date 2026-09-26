<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Enums\Confidentiality;
use App\Enums\PartyRole;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\MatterType;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Form mở vụ việc mới (SPEC §6.10 "trước khi lưu vụ việc mới", §4.6, §13 tiêu chí M3). Chỉ dùng
 * cho trang tạo — `MatterResource` cố ý không có trang sửa, và việc lưu đi qua
 * `App\Actions\OpenMatter` chứ không qua `Model::create()` mặc định của Filament (xem
 * `CreateMatter::handleRecordCreation()`).
 *
 * Form KHÔNG hỏi `code`, `stage`, `stage_entered_at`: `Matter::creating()` tự sinh mã qua
 * `CodeSequence` (SPEC §6.1, mã không bao giờ đổi) và tự đặt giai đoạn đầu tiên của loại vụ việc.
 * Hỏi người dùng những thứ đó chỉ tạo ra cơ hội nhập sai một giá trị mà hệ thống đã biết chắc.
 *
 * **Về hai ô định danh của mỗi bên (`id_number`, `phone`) — chủ ý, không phải trang trí.**
 * `RunConflictCheck` có ba bậc đối chiếu: băm số căn cước (chắc chắn), số điện thoại đã chuẩn hoá
 * (rất khả nghi), tên đã chuẩn hoá (cần người xem xét). Hai bậc mạnh nhất đều đòi đúng hai ô này.
 * Một form để chúng trông như hai ô tuỳ chọn bình thường sẽ sinh ra hàng loạt kết quả XANH không có
 * giá trị chứng minh gì. Hai ô này vẫn KHÔNG bắt buộc — có những bị đơn mà văn phòng thật sự chưa
 * biết số căn cước, và `OpenMatter` đã có đường xử lý đúng cho trường hợp đó
 * (`ConflictCheckResult::hasIncompleteParties()` bắt xác nhận trước khi lưu) — nhưng form phải nói
 * thẳng cái giá của việc bỏ trống, ngay tại chỗ, bằng `identityMissingWarning()`.
 */
class MatterForm
{
    public static function configure(Schema $schema): Schema
    {
        // columnSpanFull() trên cả ba mục: trang tạo của Filament xếp các thành phần cấp cao
        // nhất vào lưới hai cột, nên nếu không ép, mục "Các bên khác" bị dồn vào nửa màn hình bên
        // phải — repeater nhiều ô của nó trở nên khó đọc, và bảng kết quả kiểm tra xung đột (thứ
        // SPEC §6.10 bắt buộc người dùng phải đọc được) cũng bị bóp còn nửa bề ngang.
        return $schema
            ->components([
                Section::make(__('matters.create_form.sections.details'))
                    ->description(__('matters.create_form.sections.details_description'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema(static::detailFields()),
                Section::make(__('matters.create_form.sections.other_parties'))
                    ->description(__('matters.create_form.sections.other_parties_description'))
                    ->columnSpanFull()
                    ->schema([static::otherPartiesRepeater()]),
                Section::make(__('matters.conflict.section'))
                    ->description(__('matters.conflict.section_description'))
                    ->columnSpanFull()
                    ->schema(static::conflictFields()),
            ]);
    }

    /** @return array<int, mixed> */
    private static function detailFields(): array
    {
        return [
            // Ô chọn khách hàng dùng chung VisibleClientOptions với mọi ô "Khách hàng" khác của
            // panel: ai không có client.manage chỉ thấy khách hàng của những vụ việc mình liệt kê
            // được. Đây là cổng HIỂN THỊ; cổng thật nằm ở CreateMatter::mutateFormDataBeforeCreate().
            Select::make('client_id')
                ->label(__('matters.fields.client'))
                ->options(fn (): array => VisibleClientOptions::forCurrentUser())
                ->searchable()
                // Đổi khách hàng là đổi chính bên mà OpenMatter tự dựng từ hồ sơ Client, nên kết
                // quả kiểm tra đang hiện không còn nói về vụ việc này nữa.
                ->live()
                ->afterStateUpdated(static::forgetConflictResult())
                ->required(),
            // KHÔNG có ->default(): SPEC §6.10 và OpenMatter bước 2 đều đòi vai này được chọn có ý
            // thức. Một mặc định ngầm (từng là `plaintiff`) làm mọi vụ mà khách hàng là bị đơn bị
            // tính sai vai đối lập, hạ mức đỏ xuống vàng trong im lặng.
            // native(false) ở đây và ở hai ô chọn khác của form: một <select> gốc mang thuộc tính
            // HTML `required` nên trình duyệt chặn submit bằng thông điệp của CHÍNH nó — tiếng Anh
            // ("Please select an item in the list"), giữa một form tiếng Việt. Với ô này thì càng
            // không chấp nhận được: đây đúng là trường mà SPEC §6.10 đòi người dùng chọn có ý thức,
            // nên lời từ chối phải đọc được. Bản JS của Filament để Livewire xác thực và hiện thông
            // điệp tiếng Việt, đồng thời trông giống hệt ba ô searchable ngay bên cạnh.
            Select::make('client_role')
                ->label(__('matters.create_form.client_role'))
                ->helperText(__('matters.create_form.client_role_help'))
                // R13(f)/`conflict-12` (M6.5 Task 8): "Luật sư đối phương" bị loại khỏi vai của
                // CHÍNH khách hàng — xem `clientRolePartyOptions()`. Ô "Vai của bên đó" trong danh
                // sách các bên KHÁC (`otherPartiesRepeater()`, dưới) vẫn dùng `partyRoleOptions()`
                // đầy đủ: một bên không phải khách hàng của văn phòng (luật sư đối phương thật ở
                // một vụ khác) có thể chính đáng mang vai đó.
                ->options(static::clientRolePartyOptions())
                ->native(false)
                // Vai của khách hàng quyết định bên nào là bên ĐỐI LẬP, tức quyết định mức đỏ có
                // nổ hay không: đổi nó có thể lật thẳng vàng thành đỏ.
                ->live()
                ->afterStateUpdated(static::forgetConflictResult())
                ->required(),
            Select::make('matter_type_id')
                ->label(__('matters.fields.matter_type'))
                ->options(fn (): array => MatterType::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
            Select::make('lead_lawyer_id')
                ->label(__('matters.fields.lead_lawyer'))
                ->options(fn (): array => CreateMatter::leadLawyerOptions())
                ->searchable()
                ->required(),
            TextInput::make('title')
                ->label(__('matters.fields.title'))
                ->required()
                ->maxLength(250)
                ->columnSpanFull(),
            Textarea::make('description_internal')
                ->label(__('matters.transition_form.internal_note'))
                ->helperText(__('matters.transition_form.internal_note_hint'))
                ->rows(3)
                ->columnSpanFull(),
            Textarea::make('summary_for_client')
                ->label(__('matters.fields.summary_for_client'))
                ->rows(2)
                ->columnSpanFull(),
            DatePicker::make('opened_at')
                ->label(__('matters.overview_fields.opened_at'))
                ->default(today())
                ->native(false)
                ->displayFormat('d/m/Y')
                ->required(),
            Select::make('confidentiality')
                ->label(__('matters.overview_fields.confidentiality'))
                ->options(collect(Confidentiality::cases())
                    ->mapWithKeys(fn (Confidentiality $case) => [$case->value => $case->label()])
                    ->all())
                ->default(Confidentiality::Normal->value)
                ->native(false)
                ->required(),
            TextInput::make('court_name')
                ->label(__('matters.overview_fields.court_name'))
                ->maxLength(200),
            TextInput::make('case_number')
                ->label(__('matters.overview_fields.case_number'))
                ->maxLength(80),
            Toggle::make('is_published_to_portal')
                ->label(__('matters.fields.is_published_to_portal'))
                ->default(false),
        ];
    }

    /**
     * Các bên KHÁC ngoài khách hàng của vụ việc. Khách hàng chính không có mặt ở đây có chủ đích:
     * `OpenMatter` tự dựng bên đó từ hồ sơ `Client` đã khoá dòng, và cố ý không tin số căn cước/
     * điện thoại do form gửi lên cho bên đó (xem `OpenMatter::buildOwnClientParty()`).
     */
    private static function otherPartiesRepeater(): Repeater
    {
        return Repeater::make('other_parties')
            ->label(__('matters.tabs.parties'))
            ->addActionLabel(__('matters.create_form.add_party'))
            ->defaultItems(0)
            ->itemLabel(fn (array $state): string => $state['name'] ?? __('matters.create_form.unnamed_party'))
            ->columns(2)
            // MỘT hook duy nhất cho cả danh sách bên: mọi ô con bên dưới đều `live()`, và Filament
            // bong `afterStateUpdated` từ con lên component cha — nên thêm bên, xoá bên, đổi thứ
            // tự hay sửa bất kỳ ô nào của bất kỳ bên nào đều rơi vào đây.
            ->afterStateUpdated(static::forgetConflictResult())
            ->schema([
                Select::make('role')
                    ->label(__('matters.party_fields.role'))
                    ->options(static::partyRoleOptions())
                    ->native(false)
                    ->live()
                    ->required(),
                TextInput::make('name')
                    ->label(__('matters.party_fields.name'))
                    ->live(onBlur: true)
                    ->required()
                    ->maxLength(200),
                // live(onBlur:) để cảnh báo thiếu định danh bên dưới xuất hiện/biến mất ngay khi
                // rời ô, chứ không phải chỉ sau khi bấm lưu — lúc đó đã muộn.
                TextInput::make('id_number')
                    ->label(__('matters.party_fields.id_number'))
                    ->helperText(__('matters.create_form.id_number_help'))
                    ->live(onBlur: true)
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label(__('matters.party_fields.phone'))
                    ->helperText(__('matters.create_form.phone_help'))
                    ->tel()
                    ->live(onBlur: true)
                    ->maxLength(20),
                static::identityMissingWarning(),
                Toggle::make('is_our_client')
                    ->label(__('matters.party_fields.is_our_client'))
                    ->live()
                    ->default(false),
                // `required()` khi công tắc bật (I-2): `BuildsMatterParties` TỪ CHỐI một bên tự
                // nhận là khách hàng của văn phòng mà không chỉ ra hồ sơ nào — luật nằm ở đó vì
                // nó đúng cả với seeder/job/console. Ô này chỉ giải thích luật tại chỗ, bằng một
                // lỗi gắn đúng ô thay vì một ngoại lệ nghiệp vụ dội lên giữa màn hình.
                Select::make('client_id')
                    ->label(__('matters.party_fields.client'))
                    ->options(fn (): array => VisibleClientOptions::forCurrentUser())
                    ->searchable()
                    ->live()
                    ->required(fn (Get $get): bool => (bool) $get('is_our_client'))
                    ->visible(fn (Get $get): bool => (bool) $get('is_our_client')),
                // `address`/`note` không phải tầng đối chiếu nào cả, nhưng vẫn `live()`: chúng nằm
                // trong cùng một dòng bên, và một quy tắc "ô nào trong danh sách bên cũng làm mất
                // hiệu lực bảng kết quả" thì không ai phải nhớ ô nào mới đúng là ô quan trọng.
                TextInput::make('address')
                    ->label(__('matters.party_fields.address'))
                    ->live(onBlur: true)
                    ->maxLength(300),
                Textarea::make('note')
                    ->label(__('matters.party_fields.note'))
                    ->live(onBlur: true)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Cảnh báo tại chỗ khi một bên không có CẢ số căn cước LẪN số điện thoại — đúng điều kiện
     * `RunConflictCheck` dùng để xếp bên đó vào `incompleteParties`. Viết thẳng ra rằng một kết quả
     * xanh với bên như vậy không đáng tin, thay vì để người dùng tự suy ra từ một helperText mờ
     * nhạt (xem docblock lớp).
     */
    private static function identityMissingWarning(): Text
    {
        return Text::make(__('matters.create_form.identity_missing_warning'))
            ->color('warning')
            ->visible(fn (Get $get): bool => blank($get('id_number')) && blank($get('phone')))
            ->columnSpanFull();
    }

    /** @return array<int, mixed> */
    private static function conflictFields(): array
    {
        return [
            // Bảng kết quả của SPEC §6.10 đoạn "Giao diện". Chỉ hiện sau khi đã có một lần kiểm
            // tra thật — trước đó không có gì để nói, và một bảng rỗng mang màu xanh sẽ là lời
            // khẳng định sai rằng đã kiểm tra.
            View::make('filament.conflict-check-result')
                ->viewData(fn (CreateMatter $livewire): array => $livewire->conflictResultViewData())
                ->visible(fn (CreateMatter $livewire): bool => $livewire->conflictResult !== null)
                ->columnSpanFull(),
            // Cả hai ô quyết định chỉ TỒN TẠI sau khi đã có một kết quả kiểm tra thật hiện ra —
            // xem docblock lớp `CreateMatter` (Critical C-1). Một trường ẩn không được dehydrate,
            // nên đây là cổng phía máy chủ chứ không phải trang trí.
            Toggle::make('acknowledge_conflict')
                ->label(__('matters.conflict.acknowledge'))
                ->helperText(__('matters.conflict.acknowledge_help'))
                ->visible(fn (CreateMatter $livewire): bool => $livewire->conflictResult !== null)
                ->default(false),
            // Hiện cho MỌI vai trò nhưng khoá với ai không được ghi đè: người dùng cần đọc được
            // luật ("chỉ trưởng phòng/quản trị") ngay tại chỗ, và lỗi mức đỏ cần một ô để bám vào.
            // Một trường disabled() KHÔNG dehydrate (`disabled()` gọi `saved(false)`, và
            // `isDehydrated()` rơi về `isSaved()` — đã đối chiếu vendor), nên khoá
            // `override_reason` biến mất khỏi $data: không có cách nào một luật sư gửi lên được lý
            // do ghi đè qua form này, và kể cả gửi được thì `OpenMatter` vẫn tự kiểm tra lại vai
            // trò của actor. `visible()` và `disabled()` chồng lên nhau chứ không triệt tiêu nhau:
            // ẩn thì không dehydrate, khoá thì cũng không dehydrate — luật sư ở lượt 2 vẫn ĐỌC
            // được ô và câu giải thích ai mới ghi đè được, nhưng không gửi được gì qua nó.
            //
            // `visible()` hỏi `redResultShown()`, KHÔNG `conflictResult !== null` (I-A): ghi đè chỉ
            // tồn tại cho mức đỏ, và một ô mở trong một vòng VÀNG là đúng thứ cho phép một câu viết
            // cho vòng vàng sống sót sang một lần kiểm tra ĐỎ kế tiếp — xem docblock
            // `CreateMatter::redResultShown()`. Ô "đã xem xét" ngay trên thì giữ nguyên điều kiện
            // cũ: một vòng vàng cần đúng dấu tích đó để đi tiếp.
            Textarea::make('override_reason')
                ->label(__('matters.conflict.override_reason'))
                ->helperText(fn (CreateMatter $livewire): string => $livewire->canOverrideRedConflict()
                    ? __('matters.conflict.override_reason_help_allowed')
                    : __('matters.conflict.override_reason_help_denied'))
                ->visible(fn (CreateMatter $livewire): bool => $livewire->redResultShown())
                ->disabled(fn (CreateMatter $livewire): bool => ! $livewire->canOverrideRedConflict())
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Hook gắn vào mọi trường có thể làm đổi kết quả kiểm tra xung đột: đổi trường đó thì bảng kết
     * quả đang hiện không còn mô tả đúng form nữa, nên phải quên nó đi (Minor 3/4 của bản xem xét
     * — xem `CreateMatter::forgetConflictResult()` cho lý do đầy đủ, gồm cả vì sao dấu tích "đã
     * xem xét" phải rơi theo).
     *
     * `live()` đi kèm là bắt buộc chứ không phải tuỳ chọn: một trường không `live()` hoàn toàn
     * không gửi gì về máy chủ cho tới lúc bấm lưu, nên `afterStateUpdated` của nó không bao giờ
     * chạy. Với các ô TRONG repeater thì chỉ cần con `live()` — Filament tự BONG lên cha
     * (`Component::callAfterStateUpdated()` gọi tiếp lên component cha), nên một hook duy nhất đặt
     * trên chính repeater bắt được mọi thay đổi bên trong, kể cả thêm/xoá/nhân bản/đổi thứ tự
     * dòng (`Repeater` gọi thẳng `callAfterStateUpdated()` ở cả bốn action đó).
     *
     * `onBlur: true` cho các ô gõ tay: gửi từng ký tự về máy chủ chỉ để xoá một mảng thường đã
     * null là lãng phí, còn rời ô đã đủ sớm — bảng chỉ hiện lại ở lượt bấm lưu kế tiếp.
     */
    private static function forgetConflictResult(): Closure
    {
        return function (CreateMatter $livewire): void {
            $livewire->forgetConflictResult();
        };
    }

    /** @return array<string, string> */
    private static function partyRoleOptions(): array
    {
        return collect(PartyRole::cases())
            ->mapWithKeys(fn (PartyRole $role) => [$role->value => $role->label()])
            ->all();
    }

    /**
     * Vai của CHÍNH khách hàng (ô `client_role`) — R13(f)/`conflict-12` (M6.5 Task 8): danh sách
     * `partyRoleOptions()` đầy đủ trừ `PartyRole::OpposingCounsel`. Khách hàng của văn phòng không
     * bao giờ chính là "luật sư đối phương" của chính vụ việc mình đang là khách hàng — cho phép
     * chọn vai đó không chỉ vô nghĩa mà còn âm thầm TẮT hẳn mức đỏ của vụ việc: `RunConflictCheck::
     * isOpposing()` chỉ coi `plaintiff`/`defendant` là đối lập, nên chọn `opposing_counsel` (hay
     * `related`/`third_party`) cho khách hàng khiến `$ourClientRoles` không bao giờ chứa
     * `plaintiff`/`defendant`, và một bị đơn trùng CCCD với đúng khách hàng đó chỉ còn lên vàng.
     */
    private static function clientRolePartyOptions(): array
    {
        return collect(PartyRole::cases())
            ->reject(fn (PartyRole $role): bool => $role === PartyRole::OpposingCounsel)
            ->mapWithKeys(fn (PartyRole $role) => [$role->value => $role->label()])
            ->all();
    }
}
