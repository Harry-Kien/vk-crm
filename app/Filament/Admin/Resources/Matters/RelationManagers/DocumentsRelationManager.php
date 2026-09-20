<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Document\PublishDocument;
use App\Actions\Document\RegroupDocument;
use App\Actions\Document\UploadStaffDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Tab "Tài liệu" (SPEC §7.2): danh sách `documents` của vụ việc, nhóm theo A/B/C/D, với bốn thao
 * tác — đưa tệp vào hồ sơ, công bố cho khách, chuyển nhóm, và tải tệp về.
 *
 * **Lớp này không có một dòng nghiệp vụ nào.** Bốn thao tác gọi `UploadStaffDocument`,
 * `PublishDocument`, `RegroupDocument` và route tải tệp có chữ ký của Task 5. Lời từ chối của các
 * Action đi ra qua `ReportsActionFailures` (xem docblock trait đó cho ba họ exception và vì sao
 * không họ nào được ánh xạ sang một mã HTTP riêng).
 *
 * **Nhóm D, ba lớp hiển thị chứ không một.** SPEC §7.2 đòi "nền khác màu rõ rệt" VÀ nhãn "Chỉ nội
 * bộ — không bao giờ hiện cho khách". Ở đây có: (1) một cột luôn hiện, mang nguyên văn cái nhãn
 * đó dưới dạng badge màu `danger` — badge là thành phần của chính Filament nên nó chắc chắn có
 * kiểu dáng, và `danger` ở panel này là màu đỏ thương hiệu; (2) `recordClasses()` tô nền CẢ
 * DÒNG, dùng lớp `vk-internal-document` do chính lớp này định nghĩa; (3) tiêu đề nhóm trong chế
 * độ gộp nhóm. Lớp (1) là lớp không tắt được: người dùng bỏ gộp nhóm hay đổi cột thì nó vẫn ở đó.
 *
 * **Vì sao luật CSS của lớp (2) được in ra từ đây chứ không nằm trong một tệp CSS.** Dự án này
 * KHÔNG có bước dựng CSS: máy dev và máy chủ chỉ có PHP trong Docker (CLAUDE.md), panel dùng
 * `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn, và bộ CSS đó chỉ chứa những lớp
 * tiện ích mà chính Filament dùng. Kiểm tra được: `bg-gray-100`, `bg-gray-200`, `bg-primary-600`,
 * `text-amber-600` KHÔNG có trong tệp đó, nên một lớp Tailwind viết tay trong mã PHP không tô
 * được gì cả. Thêm vào đó, `.fi-ta-content-ctn .fi-ta-content .fi-ta-record` đã đặt
 * `background-color` với độ ưu tiên (0,3,0), nên kể cả khi lớp tiện ích tồn tại thì một lớp đơn
 * cũng thua. Luật in ra ở {@see self::internalRowStyle()} vì vậy viết đủ dài để thắng, và đi
 * cùng bảng qua `->description()` — chỗ Filament in ra MỘT lần ngay trên bảng. Cách tiêm CSS
 * không cần bước dựng này là cách dự án đã dùng từ M3 cho `resources/views/brand/theme.blade.php`.
 *
 * Màu là `--danger-500` của Filament pha loãng bằng `color-mix`, nên không có mã màu nào viết
 * cứng: nó tự đổi theo bảng màu của panel (ở đây `danger` là màu đỏ thương hiệu), và vì nó trong
 * suốt một phần nên nó phủ đúng lên nền trắng của chế độ sáng lẫn nền `--gray-900` của chế độ
 * tối mà không cần hai luật khác nhau.
 *
 * **Và nút công bố KHÔNG BAO GIỜ hiện trên một dòng nhóm D.** `PublishDocument` chặn tuyệt đối
 * (SPEC §6.5 bước 1) nên một cái nút ở đó chỉ dẫn tới một lời từ chối, nhưng lý do thật sự để ẩn
 * nó không phải là tiết kiệm một cú bấm: một cái nút "Công bố cho khách" nằm trên cùng một dòng
 * với dòng chữ "không bao giờ hiện cho khách" là một màn hình tự mâu thuẫn, và cái người dùng tin
 * là cái nút. Đường duy nhất ra khỏi nhóm D là "Chuyển nhóm" → `RegroupDocument`, thao tác có ghi
 * lại ai chuyển và chuyển từ đâu.
 *
 * **Không một tệp nào ra khỏi đĩa `private` bằng đường nào khác ngoài route đã ký.** Cụ thể ở
 * lớp này: nút tải dùng `Document::downloadUrlFor()` (route `documents.download`, hết hạn sau 5
 * phút, controller vẫn kiểm tra policy — SPEC §10.4), không `Storage::url()` và không
 * `temporaryUrl()`. Ô chọn tệp là `FileUpload` THUẦN với `storeFiles(false)`, KHÔNG
 * `SpatieMediaLibraryFileUpload`: với `storeFiles(false)` trạng thái của ô luôn là một
 * `TemporaryUploadedFile` trên disk tạm của Livewire, và `BaseFileUpload::getUploadedFiles()` trả
 * thẳng `null` cho mọi `TemporaryUploadedFile` — nó không bao giờ chạm tới `getDisk()`, nên không
 * có URL nào được sinh ra và đĩa `private` không hề bị đụng tới cho tới khi Action gọi
 * `addMedia()`. `SpatieMediaLibraryFileUpload` thì ngược lại: nó đọc media đã lưu và sinh URL
 * xem trước TRÊN ĐĨA của collection, tức đĩa `private`.
 *
 * Không có `isReadOnly(): false` ở đây, cùng lý do đã đo được ở `ChecklistRelationManager`: mặc
 * định đó chỉ gác các lớp action dựng sẵn của Filament, và bảng này không dùng cái nào.
 */
class DocumentsRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.documents');
    }

    /** Lớp CSS gắn lên `<tr>` của một dòng nhóm D — xem {@see self::internalRowStyle()}. */
    private const INTERNAL_ROW_CLASS = 'vk-internal-document';

    /**
     * Nhãn nguyên văn SPEC §7.2 cho một dòng nhóm D, `null` cho mọi nhóm khác. Tách static để
     * test được mà không dựng cả bảng — cùng thành ngữ `StageLogsRelationManager::
     * renderInternalNote()`.
     *
     * Trả về chuỗi THUẦN chứ không HTML: nó được đưa vào một `TextColumn` đã `->badge()`, nên
     * kiểu dáng do chính Filament lo. Đó là điểm khác có chủ đích so với `renderInternalNote()`
     * ở tab Tiến độ, thứ tự dựng HTML kèm lớp Tailwind — xem docblock lớp cho lý do những lớp
     * đó không tô được gì trong dự án không có bước dựng CSS này.
     */
    public static function internalMarkerLabel(DocumentGroup $group): ?string
    {
        return $group->isInternal() ? __('documents.tab.internal_marker') : null;
    }

    /**
     * Luật CSS tô nền dòng nhóm D, in ra một lần ngay trên bảng. Lý do nó nằm ở đây chứ không
     * trong một tệp CSS: xem docblock lớp.
     *
     * Bộ chọn phải nhắc lại `.fi-ta-content-ctn .fi-ta-content` vì luật nền mặc định của Filament
     * dùng đúng chuỗi đó; thiếu nó thì luật này thua và dòng nhóm D trông y hệt mọi dòng khác —
     * tức là SPEC §7.2 không được đáp ứng trong khi mã trông như đã đáp ứng.
     */
    public static function internalRowStyle(): HtmlString
    {
        return new HtmlString(sprintf(
            '<style>.fi-ta-content-ctn .fi-ta-content .fi-ta-record.%1$s,'
            .'.fi-ta-content-ctn .fi-ta-content .fi-ta-record.%1$s:where(.dark,.dark *)'
            .'{background-color:color-mix(in srgb, var(--danger-500) 12%%, transparent);}</style>',
            self::INTERNAL_ROW_CLASS,
        ));
    }

    /**
     * Câu trả lời cho "khách thấy được gì" của một dòng, đọc từ hai cờ độc lập của SPEC §6.5
     * bước 3 — trừ nhóm D, vốn có câu riêng: SPEC §4.11 gọi nó là ranh giới tuyệt đối, nên hiện
     * "Khách chưa thấy" ở đó sẽ đọc như thể chỉ cần bật lên là xong.
     */
    public static function clientAccessLabel(Document $document): string
    {
        return match (true) {
            $document->group->isInternal() => __('documents.tab.client_access.never'),
            ! $document->isReleasedToPortal() => __('documents.tab.client_access.none'),
            $document->client_can_download => __('documents.tab.client_access.view_and_download'),
            default => __('documents.tab.client_access.view_only'),
        };
    }

    /**
     * Những nhóm mà bộ mặc định SPEC §4.11 đưa tài liệu RA TỚI KHÁCH ngay lúc tạo, nên một lần
     * nộp vào đó là một lần công bố và `UploadStaffDocument` bước 3 đòi thêm `document.publish`.
     *
     * **Đây là bản sao của một sự thật sống ở chỗ khác, và nó được ghim chứ không được tin.**
     * Nguồn duy nhất là `StoresDocumentFile::defaultsFor()` cộng `releasesToClientAtCreation()`,
     * cả hai `protected`, nên màn hình không hỏi được. Không có danh sách này thì một trợ lý —
     * người `DocumentPolicy::create` cho phép tạo tài liệu — chọn nhóm A và nhận về một
     * `AuthorizationException`: trang 403 tiếng Anh, đúng thứ SPEC §8.4 cấm, và là họ exception
     * THỨ TƯ mà không màn hình nào bắt. Cái giá của việc chép: nó có thể lệch. Cái chặn việc
     * lệch: `it('offers exactly the groups an assistant can actually upload into')` chạy THẬT
     * Action cho cả bốn nhóm với một trợ lý và so kết quả đo được với danh sách này.
     *
     * @var list<DocumentGroup>
     */
    private const RELEASED_AT_CREATION = [DocumentGroup::ClientProvided];

    /**
     * Các nhóm cho ô chọn của lần ĐƯA TÀI LIỆU VÀO hồ sơ. Hai điều kiện loại bớt, cả hai là giới
     * hạn HIỂN THỊ chứ không phải luật nghiệp vụ mới — Action không đổi và vẫn tự hỏi lại tất cả.
     * Luật `in:` mà `Select::options()` tự cài là cổng phía máy chủ cho chính danh sách này.
     *
     *  - **Nhóm D với ai không có `document.viewInternal`:** họ tạo được (policy cho phép) nhưng
     *    không đọc lại được (`DocumentPolicy::view` loại nhóm D), nên bày nó ra là bày một cái
     *    bẫy — tệp biến mất ngay sau khi lưu.
     *  - **Nhóm ra tới khách ngay lúc tạo, với ai không công bố được:** xem
     *    {@see self::RELEASED_AT_CREATION}.
     *
     * Câu hỏi thứ hai được hỏi qua `Gate` trên một `Document` CHƯA LƯU mang nhóm và quan hệ
     * `matter` — cùng thành ngữ `UploadStaffDocument` dùng để hỏi đúng câu đó, nên màn hình
     * không chép một điều kiện nào của `DocumentPolicy::publish` (hồ sơ đã xoá mềm, khả năng
     * thấy tài liệu, `document.publish`) và tự siết theo khi policy siết.
     *
     * @return array<string, string>
     */
    public static function groupOptions(Matter $matter): array
    {
        return collect(static::visibleGroups())
            ->reject(fn (DocumentGroup $group): bool => in_array($group, self::RELEASED_AT_CREATION, true)
                && ! Gate::allows('publish', static::transientDocument($group, $matter)))
            ->mapWithKeys(fn (DocumentGroup $group): array => [$group->value => $group->label()])
            ->all();
    }

    /**
     * Các nhóm cho ô chọn của lần CHUYỂN NHÓM. Khác `groupOptions()` ở đúng một điểm và có chủ
     * đích: KHÔNG lọc theo `document.publish`. `RegroupDocument` đòi quyền đó để RỜI nhóm D,
     * không để vào một nhóm nào — "siết thêm không phải nới ra", và bắt một trợ lý đi tìm luật sư
     * để dời một tài liệu xếp nhầm sẽ để nó nằm ở chỗ rộng hơn trong lúc chờ. Điều kiện rời nhóm
     * D nằm ở `->authorize()` của chính thao tác, không ở danh sách này.
     *
     * @return array<string, string>
     */
    public static function regroupOptions(): array
    {
        return collect(static::visibleGroups())
            ->mapWithKeys(fn (DocumentGroup $group): array => [$group->value => $group->label()])
            ->all();
    }

    /** @return list<DocumentGroup> bốn nhóm, trừ nhóm D với ai không đọc được nhóm D. */
    private static function visibleGroups(): array
    {
        $canSeeInternal = Auth::user()?->can(Permission::DocumentViewInternal->value) ?? false;

        return collect(DocumentGroup::cases())
            ->reject(fn (DocumentGroup $group): bool => $group->isInternal() && ! $canSeeInternal)
            ->values()
            ->all();
    }

    /**
     * Một `Document` chưa lưu, chỉ mang đủ thứ để `DocumentPolicy` trả lời: nhóm, và hồ sơ.
     * Không bao giờ được lưu — nó tồn tại để hỏi một câu giả định ("nếu tài liệu này ở nhóm đó
     * trên hồ sơ này thì tôi có công bố được không") mà không dựng một dòng dữ liệu nào.
     */
    private static function transientDocument(DocumentGroup $group, Matter $matter): Document
    {
        return (new Document)
            ->forceFill(['group' => $group])
            ->setRelation('matter', $matter);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('group')
                    ->label(__('documents.tab.columns.group'))
                    ->badge()
                    ->formatStateUsing(fn (DocumentGroup $state): string => $state->label())
                    ->color(fn (DocumentGroup $state): string => $state->isInternal() ? 'gray' : 'primary'),
                TextColumn::make('title')
                    ->label(__('documents.tab.columns.title'))
                    ->wrap()
                    ->searchable(),
                // Lớp hiển thị KHÔNG TẮT ĐƯỢC của nhãn nhóm D (xem docblock lớp). Cột rỗng trên
                // mọi dòng khác, nên nó không chiếm chỗ của hồ sơ bình thường.
                TextColumn::make('internal_marker')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->wrap()
                    ->state(fn (Document $record): ?string => static::internalMarkerLabel($record->group)),
                TextColumn::make('status')
                    ->label(__('documents.tab.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state): string => $state->label())
                    ->color(fn (DocumentStatus $state): string => $state === DocumentStatus::Published ? 'success' : 'gray'),
                TextColumn::make('client_access')
                    ->label(__('documents.tab.columns.client_access'))
                    ->state(fn (Document $record): string => static::clientAccessLabel($record)),
                TextColumn::make('version')
                    ->label(__('documents.tab.columns.version')),
                TextColumn::make('checklistItem.name')
                    ->label(__('documents.tab.columns.checklist_item'))
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('documents.tab.columns.uploaded_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label(__('documents.tab.columns.issued_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('group')
                    ->label(__('documents.tab.columns.group'))
                    ->getTitleFromRecordUsing(fn (Document $record): string => $record->group->label())
                    ->getDescriptionFromRecordUsing(fn (Document $record): ?string => $record->group->isInternal()
                        ? __('documents.tab.internal_marker')
                        : null),
            ])
            // Nhóm theo A/B/C/D là cách SPEC §7.2 mô tả tab này, nên nó là trạng thái MẶC ĐỊNH
            // chứ không phải một tuỳ chọn người dùng phải tự tìm ra.
            ->defaultGroup('group')
            ->defaultSort('created_at', 'desc')
            ->description(fn (): HtmlString => static::internalRowStyle())
            ->recordClasses(fn (Document $record): ?string => $record->group->isInternal()
                ? self::INTERNAL_ROW_CLASS
                : null)
            ->headerActions([
                $this->uploadAction(),
            ])
            ->recordActions([
                $this->downloadAction(),
                $this->publishAction(),
                $this->regroupAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                // **Nhóm D phải bị loại KHỎI TRUY VẤN, không chỉ khỏi các nút.** Filament dựng
                // bảng bằng chính truy vấn quan hệ này và KHÔNG hỏi `DocumentPolicy::view` cho
                // từng dòng — không có điều kiện dưới đây, một trợ lý (SPEC §5 không cấp
                // `document.viewInternal` cho họ) đọc được tiêu đề mọi hồ sơ công việc nội bộ của
                // vụ việc ngay trên tab này. Đo được bằng một test đỏ, không suy ra từ đọc mã.
                //
                // Điều kiện chép đúng nhánh nhân sự của `DocumentPolicy::view`; nó KHÔNG thay thế
                // policy (policy vẫn là cổng của từng thao tác và của route tải tệp), nó chỉ nói
                // lại cùng một luật ở tầng mà bảng thật sự đọc. Phía khách hàng không cần điều
                // kiện này: `Document::applyClientPortalConstraints()` đã loại nhóm D khỏi mọi
                // truy vấn dưới guard `client` (SPEC §5, §11).
                ->unless(
                    Auth::user()?->can(Permission::DocumentViewInternal->value) ?? false,
                    fn (Builder $visible): Builder => $visible->where(
                        $visible->qualifyColumn('group'),
                        '!=',
                        DocumentGroup::Internal->value,
                    ),
                )
                // `media` cho nút tải (nó hỏi tài liệu có tệp không trên từng dòng) và
                // `checklistItem` cho cột đầu mục — không có hai lần nạp sẵn này thì mỗi dòng của
                // bảng là hai truy vấn nữa.
                ->with(['media', 'checklistItem']));
    }

    /**
     * SPEC §4.11 "nhân viên nộp thay" và các văn bản nhóm B/C/D. Cổng quyền hỏi `create` KÈM
     * chính hồ sơ đang mở: `DocumentPolicy::create()` có một nhánh KHÔNG ngữ cảnh trả lời một
     * câu hỏi khác hẳn ("vai trò này về nguyên tắc có tạo tài liệu được không"), và MỌI lần
     * Filament tự hỏi ability này đều không kèm ngữ cảnh. Không truyền hồ sơ vào đây thì nút hiện
     * ra trên một vụ việc đã xoá mềm và trên một vụ việc người dùng không được sửa. Cùng thành
     * ngữ `PartiesRelationManager` dùng cho nút thêm bên.
     */
    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('documents.tab.actions.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('documents.tab.actions.upload_heading'))
            ->authorize(fn (): bool => Gate::allows('create', [Document::class, $this->getOwnerRecord()]))
            ->schema([
                FileUpload::make('file')
                    ->label(__('documents.tab.fields.file'))
                    ->helperText(__('documents.tab.fields.file_help', ['max' => static::maxUploadMegabytes()]))
                    // `storeFiles(false)`: ô này KHÔNG tự lưu tệp đi đâu cả. Trạng thái của nó ở
                    // lại là một `TemporaryUploadedFile` và được truyền thẳng cho Action, nơi
                    // `FileGuard` + `VirusScanner` + medialibrary quyết định tệp có được vào đĩa
                    // `private` hay không. Đây là điều kiện để Action còn là cổng duy nhất: một ô
                    // tự lưu sẽ đặt tệp lên đĩa trước khi có ai kiểm tra nó.
                    ->storeFiles(false)
                    // Hai luật dưới đây chỉ là tiện lợi phía trình duyệt (chặn sớm, báo ngay tại
                    // ô) và chúng đọc `Content-Type` do client gửi — thứ SPEC §6.6 bước 3 nói
                    // thẳng là không được tin. Cổng thật là `FileGuard`, và nó đọc MIME bằng
                    // `finfo` trên nội dung tệp.
                    ->acceptedFileTypes(static::acceptedMimeTypes())
                    ->maxSize(static::maxUploadMegabytes() * 1024)
                    ->required(),
                TextInput::make('title')
                    ->label(__('documents.tab.fields.title'))
                    ->helperText(__('documents.tab.fields.title_help'))
                    ->required()
                    ->maxLength(250),
                Select::make('group')
                    ->label(__('documents.tab.fields.group'))
                    ->helperText(__('documents.tab.fields.group_help'))
                    ->options(fn (): array => static::groupOptions($this->getOwnerRecord()))
                    ->required(),
                Select::make('matter_checklist_item_id')
                    ->label(__('documents.tab.fields.checklist_item'))
                    ->helperText(__('documents.tab.fields.checklist_item_help'))
                    ->placeholder(__('documents.tab.fields.checklist_item_none'))
                    ->options(fn (): array => static::checklistItemOptions($this->getOwnerRecord())),
                DatePicker::make('issued_at')
                    ->label(__('documents.tab.fields.issued_at'))
                    ->native(false),
            ])
            ->successNotificationTitle(__('documents.tab.actions.upload_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(UploadStaffDocument::class)->handle(
                    matter: $this->getOwnerRecord(),
                    actor: Auth::user(),
                    file: $data['file'],
                    group: DocumentGroup::from($data['group']),
                    title: $data['title'],
                    checklistItem: $this->resolveChecklistItem($data['matter_checklist_item_id'] ?? null),
                    // `?:` chứ không `??`: một `DatePicker` để trống gửi lên chuỗi rỗng, không
                    // `null`. Action đọc chuỗi rỗng là "không có ngày" nên hai đường cho cùng kết
                    // quả — viết ra ở đây để không ai phải đi đọc Action mới biết, cùng cách
                    // `BuildsStageUpdateSchema` đã làm.
                    issuedAt: ($data['issued_at'] ?? null) ?: null,
                ),
                fileField: 'file',
            ));
    }

    /** SPEC §6.5. Nhóm D không bao giờ có nút này — xem docblock lớp. */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('documents.tab.actions.publish'))
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->modalHeading(__('documents.tab.actions.publish_heading'))
            ->authorize(fn (Document $record): bool => Gate::allows('publish', $record))
            ->visible(fn (Document $record): bool => ! $record->group->isInternal())
            ->schema([
                // Hai cờ ĐỘC LẬP (SPEC §6.5 bước 3): cho khách biết đã có tài liệu mà chưa cho
                // giữ bản sao là một trường hợp hợp lệ. `client_can_view` mặc định bật vì
                // `PublishDocument` từ chối công bố mà không cho xem — một lần công bố với ô đó
                // tắt không phải một thao tác, nó là một lời từ chối đã biết trước.
                Toggle::make('client_can_view')
                    ->label(__('documents.tab.fields.client_can_view'))
                    ->helperText(__('documents.tab.fields.client_can_view_help'))
                    ->default(true),
                Toggle::make('client_can_download')
                    ->label(__('documents.tab.fields.client_can_download'))
                    ->helperText(__('documents.tab.fields.client_can_download_help'))
                    ->default(true),
            ])
            ->successNotificationTitle(__('documents.tab.actions.publish_success'))
            ->action(fn (Action $action, Document $record, array $data) => $this->runAction(
                $action,
                fn () => app(PublishDocument::class)->handle(
                    document: $record,
                    actor: Auth::user(),
                    clientCanView: (bool) ($data['client_can_view'] ?? false),
                    clientCanDownload: (bool) ($data['client_can_download'] ?? false),
                ),
            ));
    }

    /**
     * Cửa DUY NHẤT để một tài liệu rời nhóm D (`RegroupDocument`, và hàng rào `saving` của
     * `Document` là thứ làm cho nó là duy nhất).
     *
     * Điều kiện hiển thị chép đúng hai cổng của Action: `update` luôn, cộng `publish` khi tài
     * liệu ĐANG ở nhóm D. Nói thẳng một điều đã đo được ở vòng rà soát Task 3: với bảng vai trò
     * SPEC §5 hôm nay, vế thứ hai không loại thêm được ai — không vai trò nào có
     * `document.viewInternal` mà thiếu `document.publish`, và thiếu `viewInternal` thì
     * `DocumentPolicy::view` (và qua đó `update`) đã từ chối từ trước. Nó ở đây để màn hình và
     * Action không lệch nhau nếu bảng quyền đổi, không phải vì nó đang chặn ai.
     */
    private function regroupAction(): Action
    {
        return Action::make('regroup')
            ->label(__('documents.tab.actions.regroup'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading(__('documents.tab.actions.regroup_heading'))
            ->authorize(fn (Document $record): bool => Gate::allows('update', $record)
                && (! $record->group->isInternal() || Gate::allows('publish', $record)))
            ->schema([
                Select::make('group')
                    ->label(__('documents.tab.fields.target_group'))
                    ->helperText(__('documents.tab.fields.group_help'))
                    ->options(fn (): array => static::regroupOptions())
                    ->default(fn (Document $record): string => $record->group->value)
                    ->required(),
            ])
            ->successNotificationTitle(__('documents.tab.actions.regroup_success'))
            ->action(fn (Action $action, Document $record, array $data) => $this->runAction(
                $action,
                fn () => app(RegroupDocument::class)->handle(
                    document: $record,
                    actor: Auth::user(),
                    group: DocumentGroup::from($data['group']),
                ),
            ));
    }

    /**
     * SPEC §10.4: đường duy nhất tới một tệp là route có chữ ký, hết hạn sau 5 phút, và
     * `DocumentDownloadController` vẫn hỏi policy sau khi xác minh chữ ký.
     *
     * `->url()` chứ không `->action()`: đây là một liên kết thật, mở trong tab mới, không phải
     * một vòng Livewire. Và chữ ký được ký cho ĐÚNG người đang đăng nhập
     * (`downloadUrlFor(Auth::user())`), nên dòng `document_downloads` ghi đúng tên người được
     * trao tệp chứ không phải tên người bấm chuột.
     *
     * Điều kiện hiển thị có hai vế, và chúng KHÔNG cùng sức nặng — nói thẳng vì một mutation
     * probe đã chỉ ra điều đó:
     *
     *  - **`Gate::allows('download', ...)` hôm nay không loại được nhân sự nào.**
     *    `DocumentPolicy::download()` trả `view($user, $document)` cho mọi `User` và chỉ đòi
     *    thêm `client_can_download` cho `ClientUser` — mà khách không bao giờ mở màn hình này.
     *    Một dòng đã hiện ra trong bảng thì đã qua `view()` rồi, nên vế này luôn đúng ở đây. Xoá
     *    nó đi bộ test vẫn xanh, và không có nhân chứng nào dựng được bằng cách cấp quyền khác
     *    đi, vì nhánh nhân sự của policy không đọc cột nào. Giữ lại như một lưới hồi quy: ngày
     *    policy siết thêm (ví dụ M6 thêm trạng thái `retracted`), cái nút này siết theo mà không
     *    ai phải nhớ tới nó. Ghi ra đây để không ai đọc nó như một bằng chứng.
     *  - **"tài liệu này có tệp không" thì KHÔNG vô nghĩa**, và nó có test: `UploadStaffDocument`
     *    tạo bản ghi rồi mới gắn tệp, nên một `Document` không tệp tồn tại được, và
     *    `DocumentDownloadController` trả 404 cho nó — tức một cái nút dẫn tới một trang lỗi.
     */
    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('documents.tab.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize(fn (Document $record): bool => Gate::allows('download', $record))
            ->visible(fn (Document $record): bool => $record->getMedia('file')->isNotEmpty())
            ->url(fn (Document $record): string => $record->downloadUrlFor(Auth::user()))
            ->openUrlInNewTab();
    }

    /**
     * Đầu mục còn trong danh mục của ĐÚNG hồ sơ đang mở. `UploadStaffDocument` tự kiểm tra lại cả
     * hai điều kiện (khác hồ sơ, đã xoá mềm) và có câu tiếng Việt riêng cho từng cái; danh sách
     * này chỉ để người dùng không phải gặp hai câu đó.
     *
     * @return array<int, string>
     */
    public static function checklistItemOptions(Matter $matter): array
    {
        return $matter->checklistItems()
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Đổi id từ form thành bản ghi cho Action. Một id gửi lên mà không tìm thấy bản ghi nào KHÔNG
     * được lặng lẽ thành "không gắn vào đầu mục nào": người dùng đã chọn một đầu mục, và lưu tài
     * liệu ra ngoài danh mục rồi báo thành công là nói dối về thứ vừa xảy ra. Câu lỗi mượn nguyên
     * của Action cho cùng tình huống (đầu mục đã bị xoá khỏi hồ sơ), vì đó là nguyên nhân thật sự
     * hay gặp: một trang đã mở lâu.
     *
     * Đọc KHÔNG giới hạn theo hồ sơ, có chủ đích: `UploadStaffDocument` bước 2 là cổng thật cho
     * "đầu mục thuộc hồ sơ khác" và nó có câu riêng nói đúng chuyện đó. Lọc sẵn ở đây sẽ biến một
     * id của hồ sơ khác thành câu "đã bị xoá" — sai, và khó lần ra.
     */
    private function resolveChecklistItem(mixed $checklistItemId): ?MatterChecklistItem
    {
        if (blank($checklistItemId)) {
            return null;
        }

        $item = MatterChecklistItem::query()->find($checklistItemId);

        if ($item === null) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('documents.upload.checklist_item_deleted')],
            ]);
        }

        return $item;
    }

    /** Cùng con số mà `FileGuard` đọc (SPEC §6.6 bước 4), lấy từ một chỗ duy nhất. */
    public static function maxUploadMegabytes(): int
    {
        $configured = config('vkcrm.upload_max_mb');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 20;
    }

    /**
     * Gợi ý cho hộp thoại chọn tệp của trình duyệt, theo danh sách trắng SPEC §6.6 bước 2. KHÔNG
     * phải một cổng an ninh — xem bình luận ở `FileUpload::acceptedFileTypes()`.
     *
     * @return array<int, string>
     */
    public static function acceptedMimeTypes(): array
    {
        return [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
