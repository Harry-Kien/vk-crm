<?php

namespace App\Filament\Admin\Resources\Matters\Schemas;

use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\PartyRole;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\MatterType;
use App\Support\Normalizer;
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
            //
            // **`required()` không còn vô điều kiện (M6.5 Task 6, R4).** Một luật sư không có
            // client.manage không bao giờ thấy khách hàng MỚI trong danh sách này (VisibleClientOptions
            // chỉ liệt kê khách của những vụ họ đã liệt kê được — findings intake-03/roles-04) — hai
            // đường thay thế ở lawyerClientLookupFields() (tra đúng định danh, hoặc tạo mới ngay
            // trong form) mới là lối họ mở được vụ đầu tiên cho khách đó. Ô này KHÔNG bắt buộc khi
            // một trong hai đường đó đã có kết quả: đã tra được một hồ sơ (resolvedClientId), hoặc
            // đang điền dở khối "Tạo khách mới" (new_client.name). Không đổi gì cho ai vẫn chọn từ
            // danh sách (client.manage hoặc luật sư có khách cũ) — cả hai điều kiện trên đều rơi về
            // false, required() về lại y hệt trước bản sửa này.
            Select::make('client_id')
                ->label(__('matters.fields.client'))
                ->options(fn (): array => VisibleClientOptions::forCurrentUser())
                ->searchable()
                // Đổi khách hàng là đổi chính bên mà OpenMatter tự dựng từ hồ sơ Client, nên kết
                // quả kiểm tra đang hiện không còn nói về vụ việc này nữa.
                ->live()
                ->afterStateUpdated(static::forgetConflictResult())
                ->required(fn (CreateMatter $livewire, Get $get): bool => $livewire->resolvedClientId === null
                    && blank($get('new_client.name'))),
            ...static::lawyerClientLookupFields(),
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
                // `intake/intake-09`: `is_active` (toggle "Đang dùng" của MatterTypeForm) đánh
                // dấu một loại vụ việc đã ngưng dùng. Ô "Luật sư phụ trách" ngay dưới lọc đúng
                // cờ tương ứng (`leadLawyerOptions()`, `is_active` của User); ô này trước bản sửa
                // không lọc gì, nên một loại đã ngưng vẫn chọn được khi mở vụ mới.
                ->options(fn (): array => MatterType::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
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
     * Hai đường thay thế cho một actor KHÔNG có `client.manage` (M6.5 Task 6, R4 — findings
     * `intake/intake-03`, `roles/roles-04`): tra ĐÚNG số điện thoại/CCCD của một hồ sơ đã có
     * (`App\Actions\Client\FindClientByIdentifier`, qua `CreateMatter::lookupClient()`), hoặc điền
     * khối "Tạo khách mới" ngay dưới để tạo một hồ sơ mới (`App\Actions\Client\CreateClient`, qua
     * `CreateMatter::mutateFormDataBeforeCreate()`). Ẩn hẳn với ai có `client.manage` — người đó
     * đã có Select ngay trên để chọn/tìm bất kỳ khách hàng nào, không cần hai đường này.
     *
     * **Không có gợi ý, không có danh sách (R4).** Ô tra chỉ có MỘT kết quả: khớp tuyệt đối hoặc
     * không gì cả — xem docblock `FindClientByIdentifier` cho lý do ranh giới lộ thông tin.
     *
     * @return array<int, mixed>
     */
    private static function lawyerClientLookupFields(): array
    {
        $hiddenFromListPicker = fn (): bool => ! VisibleClientOptions::currentUserCanChooseFromList();

        return [
            Text::make(__('matters.create_form.client_lookup_intro'))
                ->color('gray')
                ->visible($hiddenFromListPicker)
                ->columnSpanFull(),
            TextInput::make('client_lookup_identifier')
                ->label(__('matters.create_form.client_lookup_identifier'))
                ->helperText(__('matters.create_form.client_lookup_identifier_help'))
                // onBlur, không từng ký tự: một lần tra là một dòng audit client_lookup (R4) —
                // gõ dở không nên sinh ra một lượt tra cho mỗi phím bấm.
                ->live(onBlur: true)
                ->afterStateUpdated(fn (CreateMatter $livewire, ?string $state) => $livewire->lookupClient($state))
                ->maxLength(30)
                ->visible($hiddenFromListPicker)
                ->columnSpanFull(),
            // Câu đã dịch sẵn ở CreateMatter::lookupClient() — xem docblock đó cho lý do #[Locked].
            View::make('filament.client-lookup-result')
                ->viewData(fn (CreateMatter $livewire): array => ['label' => $livewire->resolvedClientLabel])
                ->visible(fn (CreateMatter $livewire): bool => $hiddenFromListPicker() && $livewire->resolvedClientId !== null)
                ->columnSpanFull(),
            Text::make(__('matters.create_form.new_client_intro'))
                ->color('gray')
                ->visible(fn (CreateMatter $livewire): bool => $hiddenFromListPicker() && $livewire->resolvedClientId === null)
                ->columnSpanFull(),
            ...static::newClientFields($hiddenFromListPicker),
        ];
    }

    /**
     * "Tạo khách mới ngay trong form" (R4 b). Cùng RÀNG BUỘC dữ liệu với `ClientForm::fields()`
     * (bugfix `intake-08`: `type` live(), `representative_name` <= 120, `address` <= 300,
     * `id_number` không bắt buộc) nhưng viết LẠI ở đây thay vì tái dùng hàm đó trực tiếp: khối này
     * cần một điều kiện `visible()`/`required()` THỨ HAI ("chỉ khi luật sư chưa chọn/tra được hồ
     * sơ nào khác", `$livewire->resolvedClientId === null` VÀ ô Select `client_id` còn trống) đan
     * xen với điều kiện riêng của `representative_name` ("chỉ khi Tổ chức") — Filament không cộng
     * dồn được hai lời gọi `->visible()` liên tiếp trên CÙNG một field (lời gọi sau ghi đè lời gọi
     * trước, không AND lại với nhau), nên gọi `ClientForm::fields()` rồi cố "vá" thêm điều kiện
     * bằng cách gọi lại `->visible()` một lần nữa sẽ ÂM THẦM xoá mất điều kiện "chỉ khi Tổ chức" —
     * bảy trường ở đây và ở `ClientForm::fields()` phải đổi CÙNG NHAU nếu quy tắc SPEC §4.2 đổi,
     * cùng đánh đổi mà `CreateMatter::conflictSummary()` đã chọn cho lý do tương tự (xem docblock
     * ở đó).
     *
     * **`blank($get('client_id'))` — bắt buộc, không phải phòng thủ thừa.** Một luật sư có thể
     * KHÔNG có khách mới, nhưng vẫn chọn được một khách CŨ từ Select (`VisibleClientOptions` không
     * rỗng với vụ việc thứ hai trở đi — `intake-03`) mà không hề gọi `lookupClient()`. Không có
     * điều kiện này, `$livewire->resolvedClientId` vẫn `null` ở tình huống đó và khối "Tạo khách
     * mới" bị bắt buộc điền dù người dùng đã chọn khách xong ở Select — regressions thật, bắt
     * được bởi toàn bộ các test khác của tệp này dùng `clientVisibleTo()` (chọn qua Select, không
     * qua khối này).
     *
     * `App\Actions\Client\CreateClient::handle()` tự dò trùng theo số điện thoại/CCCD đã nhập ở
     * đây (so với các bên `is_our_client` đã lưu) — một luật sư gõ đúng định danh của khách hàng
     * trợ lý vừa tạo (mà không dùng ô tra ở trên) vẫn KHÔNG tạo ra một hồ sơ thứ hai (R4 b).
     *
     * **`dehydrated()` — chỉ ai KHÔNG có `client.manage` (fix round 1, Minor).** Khối này không
     * dành cho actor có `client.manage` (họ có Select ngay trên) — `visible($visible)` đã ẩn nó,
     * nhưng ẩn KHÔNG chắc chắn ngăn được dehydrate nếu một request bị chỉnh sửa tay gửi thẳng
     * `data.new_client.name`. Điều kiện dehydrate ở đây CỐ Ý chỉ hỏi quyền
     * (`currentUserCanChooseFromList()`), không hỏi `$visible` (vốn còn phụ thuộc
     * `resolvedClientId`/Select `client_id`): một actor có `client.manage` không bao giờ được
     * dehydrate khối này, bất kể họ đã chọn gì ở Select. Thiếu lớp này, một payload bị chỉnh tay
     * có thể buộc `CreateMatter::resolveClientId()` chạy nhánh "Tạo khách mới" LẼ RA không dành
     * cho họ — `App\Actions\Client\CreateClient::handle()` khi đó ném `DuplicateClientDetected`
     * cho một khách hàng trùng mà chính actor này chưa từng xác nhận.
     *
     * @return array<int, mixed>
     */
    private static function newClientFields(Closure $hiddenFromListPicker): array
    {
        $visible = fn (CreateMatter $livewire, Get $get): bool => $hiddenFromListPicker()
            && $livewire->resolvedClientId === null
            && blank($get('client_id'));

        $dehydrated = fn (): bool => ! VisibleClientOptions::currentUserCanChooseFromList();

        return [
            Select::make('new_client.type')
                ->label(__('clients.fields.type'))
                ->options(fn (): array => collect(ClientType::cases())
                    ->mapWithKeys(fn (ClientType $type) => [$type->value => $type->label()])
                    ->all())
                ->live()
                ->afterStateUpdated(static::forgetConflictResult())
                ->required($visible)
                ->visible($visible)
                ->dehydrated($dehydrated),
            TextInput::make('new_client.name')
                ->label(__('clients.fields.name'))
                ->live(onBlur: true)
                ->afterStateUpdated(static::forgetConflictResult())
                ->required($visible)
                ->visible($visible)
                ->dehydrated($dehydrated)
                ->maxLength(200),
            TextInput::make('new_client.id_number')
                ->label(__('clients.fields.id_number'))
                ->live(onBlur: true)
                ->afterStateUpdated(static::forgetConflictResult())
                ->visible($visible)
                ->dehydrated($dehydrated)
                ->maxLength(20),
            TextInput::make('new_client.phone')
                ->label(__('clients.fields.phone'))
                ->tel()
                ->live(onBlur: true)
                ->afterStateUpdated(static::forgetConflictResult())
                ->visible($visible)
                ->dehydrated($dehydrated)
                ->maxLength(20),
            TextInput::make('new_client.email')
                ->label(__('clients.fields.email'))
                ->email()
                ->visible($visible)
                ->dehydrated($dehydrated)
                ->maxLength(150),
            TextInput::make('new_client.representative_name')
                ->label(__('clients.fields.representative_name'))
                ->maxLength(120)
                ->dehydrated($dehydrated)
                ->visible(fn (CreateMatter $livewire, Get $get): bool => $visible($livewire, $get)
                    && $get('new_client.type') === ClientType::Organization->value),
            Textarea::make('new_client.address')
                ->label(__('clients.fields.address'))
                ->live(onBlur: true)
                ->afterStateUpdated(static::forgetConflictResult())
                ->visible($visible)
                ->dehydrated($dehydrated)
                ->maxLength(300)
                ->columnSpanFull(),
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
                    // `conflict-10`: regex mặc định của `->tel()` từ chối `(+84) 912 345 678` và
                    // `+84 (0) 912-345-678` mà `Normalizer::phone()` chuẩn hoá đúng cả hai — xem
                    // docblock `PartiesRelationManager::partyFields()` cho lý lẽ đầy đủ (cùng bản
                    // sửa, cùng lý do, hai form sinh đôi không được lệch nhau). `->regex(null)` GHI
                    // ĐÈ closure của `->tel()` (gọi SAU, `CanBeValidated::regex()` chỉ ghi đè
                    // `$regexPattern`) — giữ `type="tel"`, bỏ ràng buộc hình dạng chuỗi.
                    ->tel()
                    ->regex(null)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && Normalizer::phone($value) === null) {
                            $fail(__('matters.party_fields.phone_invalid'));
                        }
                    })
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
