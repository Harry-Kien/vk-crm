<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Document\MarkDocumentSignedFiled;
use App\Actions\Document\PublishDocument;
use App\Actions\Document\RegroupDocument;
use App\Actions\Document\ReturnDocumentToDraft;
use App\Actions\Document\SubmitDocumentForApproval;
use App\Actions\Document\UploadStaffDocument;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Permission;
use App\Filament\Admin\Concerns\ExplainsStaffUploadRefusal;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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
 * Tab "Tài liệu" (SPEC §7.2): danh sách `documents` của vụ việc, nhóm theo A/B/C/D, với bảy thao
 * tác — đưa tệp vào hồ sơ, trình duyệt, đánh dấu đã ký và đã nộp, trả về bản nháp, công bố cho
 * khách, chuyển nhóm, và tải tệp về. Ba thao tác giữa (Task 16, phán quyết R9, mở rộng ở vòng
 * sửa 1) là toàn bộ vòng đời văn bản nhóm B (SPEC §4.11), gồm cả đường ĐI NGƯỢC, mà tới trước đó
 * không có Action, nút hay ô nào ghi được — xem docblock `SubmitDocumentForApproval`,
 * `MarkDocumentSignedFiled` và `ReturnDocumentToDraft` cho lý do đầy đủ.
 *
 * **Lớp này không có một dòng nghiệp vụ nào.** Bảy thao tác gọi `UploadStaffDocument`,
 * `SubmitDocumentForApproval`, `MarkDocumentSignedFiled`, `ReturnDocumentToDraft`,
 * `PublishDocument`, `RegroupDocument` và route tải tệp có chữ ký của Task 5. Lời từ chối của các
 * Action đi ra qua `ReportsActionFailures` (xem docblock trait đó cho bốn họ exception và vì sao
 * không họ nào được ánh xạ sang một mã HTTP riêng).
 *
 * **Nhóm D, ba lớp hiển thị chứ không một.** SPEC §7.2 đòi "nền khác màu rõ rệt" VÀ nhãn "Chỉ nội
 * bộ — không bao giờ hiện cho khách". Ở đây có: (1) một cột luôn hiện, mang nguyên văn cái nhãn
 * đó dưới dạng badge màu `danger` — badge là thành phần của chính Filament nên nó chắc chắn có
 * kiểu dáng, và `danger` ở panel này là màu đỏ thương hiệu; (2) `recordClasses()` gắn lớp
 * `vk-internal-document` lên thẻ `<tr>` của dòng, và luật CSS đi kèm tô nền các Ô của dòng đó —
 * KHÔNG tô chính cái `<tr>`, vì đo được là cái `<tr>` không nhận `background-color` (các `<td>`
 * phủ kín hàng); xem {@see self::internalRowStyle()} cho phép đo; (3) tiêu đề nhóm trong chế độ
 * gộp nhóm. Lớp (1) là lớp không tắt được: người dùng bỏ gộp nhóm hay đổi cột thì nó vẫn ở đó.
 *
 * **Vì sao luật CSS của lớp (2) được in ra từ đây chứ không nằm trong một tệp CSS.** Dự án này
 * KHÔNG có bước dựng CSS: máy dev và máy chủ chỉ có PHP trong Docker (CLAUDE.md), panel dùng
 * `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn, và bộ CSS đó chỉ chứa những lớp
 * tiện ích mà chính Filament dùng. Kiểm tra được: `bg-gray-100`, `bg-gray-200`, `bg-primary-600`,
 * `text-amber-600` KHÔNG có trong tệp đó, nên một lớp Tailwind viết tay trong mã PHP không tô
 * được gì cả. Luật thay thế in ra ở {@see self::internalRowStyle()}, đi cùng bảng qua
 * `->description()` — chỗ Filament in ra MỘT lần ngay trên bảng. Cách tiêm CSS không cần bước
 * dựng này là cách dự án đã dùng từ M3 cho `resources/views/brand/theme.blade.php`.
 *
 * Bộ chọn của luật đó nhắm vào các Ô của dòng chứ không vào cái `<tr>`, và nó đã phải sửa một
 * lần vì lý do đo được chứ không suy ra được — xem docblock `internalRowStyle()`.
 *
 * Màu là `--danger-500` của Filament pha loãng bằng `color-mix`, nên không có mã màu nào viết
 * cứng: nó tự đổi theo bảng màu của panel (ở đây `danger` là màu đỏ thương hiệu), và vì nó trong
 * suốt một phần nên nó phủ đúng lên nền sáng lẫn nền tối mà không cần hai luật khác nhau.
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
    use ExplainsStaffUploadRefusal;
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.documents');
    }

    /**
     * Lớp `recordClasses()` gắn lên `<tr>` của một dòng nhóm D. Cái NỀN thì nằm trên các ô con
     * của dòng đó, không trên chính `<tr>` — xem {@see self::internalRowStyle()}.
     */
    private const INTERNAL_ROW_CLASS = 'vk-internal-document';

    /**
     * Nhãn nguyên văn SPEC §7.2 cho một dòng nhóm D, `null` cho mọi nhóm khác. Tách static để
     * test được mà không dựng cả bảng — cùng thành ngữ `StageLogsRelationManager::
     * renderInternalNote()`.
     *
     * Trả về chuỗi THUẦN chứ không HTML: nó được đưa vào một `TextColumn` đã `->badge()`, nên
     * kiểu dáng do chính Filament lo — không có gì phải tự tô, và vì thế không có gì để tô sai.
     * Đó là điểm khác có chủ đích so với `StageLogsRelationManager::renderInternalNote()`, thứ
     * tự dựng HTML và phải tự mang kiểu dáng theo. Nói cho đúng, vì bản trước của câu này viết
     * rằng hàm kia "dựng HTML kèm lớp Tailwind": từ `6e5dcf5` nó dùng `style=` viết thẳng với
     * biến màu của Filament, đúng vì một lớp Tailwind viết tay không tô được gì trong dự án
     * không có bước dựng CSS này (xem docblock lớp).
     */
    public static function internalMarkerLabel(DocumentGroup $group): ?string
    {
        return $group->isInternal() ? __('documents.tab.internal_marker') : null;
    }

    /**
     * Luật CSS tô nền dòng nhóm D, in ra một lần ngay trên bảng. Lý do nó nằm ở đây chứ không
     * trong một tệp CSS: xem docblock lớp.
     *
     * **Luật này tô các Ô, không tô cái `<tr>`, và đó là một phép đo chứ không phải một sở
     * thích.** Bản đầu (Task 6) nhắm vào `.fi-ta-content-ctn .fi-ta-content .fi-ta-record`, bộ
     * chọn đọc được từ chính `theme.css`. Nó sai hai lần, và Task 7 đo được cả hai trên trình
     * duyệt thật: khối luật đó thuộc về BỐ CỤC DẠNG LƯỚI/DANH SÁCH của Filament, còn bảng thường
     * render `<tr class="fi-ta-row">` bên trong `.fi-ta-content-ctn` mà KHÔNG có `.fi-ta-content`
     * ở giữa — nên bộ chọn không khớp một dòng nào. Và kể cả khi khớp, nó vẫn không tô được gì:
     * đo trực tiếp, `background-color:red !important` đặt lên chính cái `<tr>` đó vẫn cho
     * `getComputedStyle().backgroundColor === "oklab(0 0 0 / 0)"`, vì các ô `<td>` phủ kín hàng.
     * Nền của SPEC §7.2 vì vậy phải nằm trên `.fi-ta-cell`. Đo lại sau khi sửa: các ô nhóm D cho
     * `color(srgb 0.939489 0.399615 0.423563 / 0.12)`, tức nó thật sự hiện ra.
     *
     * Không có bản `:where(.dark,.dark *)` riêng: luật giống hệt nhau ở hai chế độ, vì màu là một
     * lớp phủ TRONG SUỐT một phần trên nền của ô — sáng hay tối nó đều đúng bằng một luật. Bản
     * đầu viết hai nhánh giống nhau y hệt.
     */
    public static function internalRowStyle(): HtmlString
    {
        return new HtmlString(sprintf(
            '<style>.fi-ta-content-ctn .fi-ta-row.%1$s>.fi-ta-cell'
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
     * **Hỏi Action, không chép lại.** Bản trước giữ một hằng số `[A]` chép tay, vì
     * `StoresDocumentFile::defaultsFor()` và `releasesToClientAtCreation()` đều `protected`. Hai
     * bản chép bằng nhau hôm nay; ngày bảng §4.11 đổi, ô chọn mời một nhóm mà Action từ chối —
     * và lời từ chối đó là một `AuthorizationException`, thứ người dùng đọc thành một câu "màn
     * hình không còn khớp" chứ không thành một câu giải thích được. Trait nay có
     * `groupsReleasedToClientAtCreation()` công khai, nên sự thật chỉ còn một bản.
     *
     * @return list<DocumentGroup>
     */
    private static function releasedAtCreation(): array
    {
        return app(UploadStaffDocument::class)->groupsReleasedToClientAtCreation();
    }

    /**
     * Các nhóm cho ô chọn của lần ĐƯA TÀI LIỆU VÀO hồ sơ. Hai điều kiện loại bớt, cả hai là giới
     * hạn HIỂN THỊ chứ không phải luật nghiệp vụ mới — Action không đổi và vẫn tự hỏi lại tất cả.
     * Luật `in:` mà `Select::options()` tự cài là cổng phía máy chủ cho chính danh sách này.
     *
     *  - **Nhóm D với ai không có `document.viewInternal`:** họ tạo được (policy cho phép) nhưng
     *    không đọc lại được (`DocumentPolicy::view` loại nhóm D), nên bày nó ra là bày một cái
     *    bẫy — tệp biến mất ngay sau khi lưu.
     *  - **Nhóm ra tới khách ngay lúc tạo, với ai không công bố được:** xem
     *    {@see self::releasedAtCreation()}.
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
        $releasedAtCreation = static::releasedAtCreation();

        return collect(static::visibleGroups())
            ->reject(fn (DocumentGroup $group): bool => in_array($group, $releasedAtCreation, true)
                && ! Gate::allows('publish', static::transientDocument($group, $matter)))
            ->mapWithKeys(fn (DocumentGroup $group): array => [$group->value => $group->label()])
            ->all();
    }

    /**
     * Các nhóm cho ô chọn của lần CHUYỂN NHÓM — lọc theo CHÍNH bản ghi (vòng sửa 1, `I1`).
     *
     * **Nhóm D là ĐÍCH cho MỌI nhóm nguồn: luôn mời, kể cả cho ai không có
     * `document.viewInternal` — ngoại lệ có chủ đích với `visibleGroups()` (vòng sửa 1, MỞ RỘNG
     * ở vòng sửa 2 — phán quyết (a) nói "bất kể nhóm nguồn", bản vòng sửa 1 chỉ áp cho nguồn B).**
     * Rút một tài liệu lỡ công bố vào D là đường DUY NHẤT thu hồi nó trước khi `RetractDocument`
     * (M7) tồn tại, và nó chỉ đòi `document.update` — MỘT trợ lý phát hiện một tài liệu NHÓM A
     * (`ClientProvided`, khách tự nộp) công bố nhầm cũng phải rút được nó ngay, cùng lý lẽ với
     * nhóm B, không chỉ nhóm B. Điều kiện ở đây vì vậy là "actor có `document.update`" — không
     * còn gắn với nhóm NGUỒN cụ thể nào — mà bất kỳ ai mở được màn hình này đều có, nên D được
     * mời cho MỌI nguồn. Vẫn giữ `$canSeeInternal` làm lối vào THAY THẾ (không phải điều kiện
     * CỘNG THÊM): ai đã có `document.viewInternal` thấy D bất kể có `document.update` hay không,
     * đúng lý lẽ cũ. `document.update` bên dưới hỏi lại đúng CÂU HỎI mà `->authorize()` của Action
     * cũng hỏi — không lệch nhau.
     *
     * **Rời nhóm D (nguồn là D): không lọc.** Giữ đúng lý lẽ cũ — "siết thêm không phải nới ra",
     * và bắt một trợ lý đi tìm luật sư để dời một tài liệu xếp nhầm sẽ để nó nằm ở chỗ rộng hơn
     * trong lúc chờ. Điều kiện rời nhóm D vẫn nằm ở `->authorize()` của chính thao tác.
     *
     * **Rời nhóm B (SANG A hoặc C — không phải D): lọc theo `document.publish`.** Trước vòng sửa
     * này, danh sách không lọc gì cho nhóm B cả, nên một trợ lý (không có `document.publish`) mở
     * ô chọn của một tài liệu B THẤY được A và C — hai lựa chọn `RegroupDocument` LUÔN từ chối họ,
     * bất kể có nhập lý do hay không (cổng đòi `document.publish`, không có đường vòng bằng lý do).
     * Bày ra hai lựa chọn luôn thất bại là bày một cái bẫy, cùng lý lẽ `groupOptions()` đã áp cho
     * lần TẠO tài liệu. Nhóm B (giữ nguyên) thì KHÔNG bị lọc.
     *
     * Không lọc theo TRẠNG THÁI (`signed_filed`/`published`/lý do): một actor có `document.publish`
     * vẫn cần thấy A/C để CÓ THỂ nhập lý do sửa nhầm nhóm — ẩn chúng đi vì tài liệu chưa ký sẽ che
     * mất đúng con đường hợp lệ đó.
     *
     * @return array<string, string>
     */
    public static function regroupOptions(Document $record): array
    {
        $canSeeInternal = Auth::user()?->can(Permission::DocumentViewInternal->value) ?? false;
        $canUpdate = Gate::allows('update', $record);
        $canPublish = Gate::allows('publish', $record);
        $isFromGroupB = $record->group === DocumentGroup::Issued;

        return collect(DocumentGroup::cases())
            ->reject(function (DocumentGroup $group) use ($canSeeInternal, $canUpdate, $canPublish, $isFromGroupB): bool {
                if ($group->isInternal()) {
                    return ! $canSeeInternal && ! $canUpdate;
                }

                return $isFromGroupB
                    && ! in_array($group, [DocumentGroup::Issued, DocumentGroup::Internal], true)
                    && ! $canPublish;
            })
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
                $this->submitForApprovalAction(),
                $this->markSignedFiledAction(),
                $this->returnToDraftAction(),
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
                    // **Chú thích trước ĐÂY sai, và `docs/docs-7` ghi lại đúng chỗ sai.** Nó nói
                    // hai luật dưới đây "đọc Content-Type do client gửi". Không đúng: bản thân
                    // `acceptedFileTypes()` chỉ cài luật `mimetypes:...` của Laravel
                    // (`BaseFileUpload::acceptedFileTypes()`), và luật đó gọi
                    // `TemporaryUploadedFile::getMimeType()`. Ở PRODUCTION, hàm đó đi thẳng tới
                    // `detectMimeTypeFromContents()` — tức `finfo` chạy trên 64 KB đầu của NỘI
                    // DUNG tệp đã lưu trên đĩa tạm, không đọc header `Content-Type` nào của
                    // request. Chỉ khi `app()->runningUnitTests()` (bộ test) thì hàm đó mới trả
                    // `metaFileData['type']` — MIME suy từ ĐUÔI tệp của `UploadedFile::fake()` —
                    // nên một test tải lên một `.pdf` giả nội dung không phải PDF vẫn "qua" được
                    // ô này, và `FileGuard` (chạy production thật) mới là nơi chặn nó. `maxSize()`
                    // thì không liên quan gì tới `Content-Type` cả — nó so kích thước tệp thật
                    // (`TemporaryUploadedFile::getSize()`) với một con số, ở CẢ hai tầng (trình
                    // duyệt qua FilePond, và máy chủ qua luật `max:`).
                    //
                    // Cổng an ninh THẬT vẫn là `FileGuard`: nó đọc MIME bằng `finfo` trên nội dung
                    // tệp ở mọi môi trường, kể cả bộ test (không có nhánh `runningUnitTests()` nào
                    // trong `FileGuard`). Ô này chỉ chặn SỚM, tiện cho người dùng — và với `docx`/
                    // `xlsx`, nó phải đồng ý với `FileGuard` về việc `application/zip` là hợp lệ,
                    // xem {@see self::acceptedMimeTypes()}.
                    ->acceptedFileTypes(static::acceptedMimeTypes())
                    ->maxSize(static::maxUploadMegabytes() * 1024)
                    ->required()
                    // `docs/docs-7`: không có mảng này, một lần bị chặn ở CHÍNH Ô (trước khi
                    // `FileGuard` kịp chạy) hiện câu mặc định của Laravel liệt kê nguyên văn chuỗi
                    // MIME kỹ thuật ("The file field must be a file of type: ..."), không phải
                    // tiếng Việt và không nói việc cần làm tiếp theo (SPEC §8.4). `max` mượn đúng
                    // câu `documents.file_guard.too_large` mà `FileRejected::tooLarge()` cũng
                    // dùng, để người dùng đọc CÙNG một câu dù lời từ chối đến từ cửa nào — cùng
                    // thành ngữ `SubmitDocument::form()` (portal) đã dùng.
                    ->validationMessages([
                        'required' => __('documents.upload.file_required'),
                        'mimetypes' => __('documents.upload.file_type'),
                        'max' => __('documents.file_guard.too_large', ['max' => static::maxUploadMegabytes()]),
                    ]),
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

    /**
     * "Trình duyệt" — bước ĐẦU của vòng đời văn bản nhóm B (SPEC §4.11, phán quyết R9): chỉ hiện
     * trên một dòng nhóm B đang `internal_draft`, và đòi đúng quyền `SubmitDocumentForApproval`
     * đòi (`document.update`, qua `DocumentPolicy::update`) — trợ lý bấm được nút này.
     */
    private function submitForApprovalAction(): Action
    {
        return Action::make('submitForApproval')
            ->label(__('documents.tab.actions.submit_for_approval'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('documents.tab.actions.submit_for_approval_heading'))
            ->modalDescription(__('documents.tab.actions.submit_for_approval_description'))
            ->authorize(fn (Document $record): bool => Gate::allows('update', $record))
            ->visible(fn (Document $record): bool => $record->group === DocumentGroup::Issued
                && $record->status === DocumentStatus::InternalDraft)
            ->successNotificationTitle(__('documents.tab.actions.submit_for_approval_success'))
            ->action(fn (Action $action, Document $record) => $this->runAction(
                $action,
                fn () => app(SubmitDocumentForApproval::class)->handle(document: $record, actor: Auth::user()),
            ));
    }

    /**
     * "Đánh dấu đã ký, đã nộp" — bước THỨ HAI của vòng đời văn bản nhóm B, và bước cuối trước khi
     * nó công bố được (SPEC §4.11, §6.5 bước 2, phán quyết R9). Chỉ hiện trên một dòng nhóm B đang
     * `pending_approval`, và đòi `document.publish` — trợ lý KHÔNG có quyền này, khác nút "Trình
     * duyệt" ngay phía trên.
     */
    private function markSignedFiledAction(): Action
    {
        return Action::make('markSignedFiled')
            ->label(__('documents.tab.actions.mark_signed_filed'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('documents.tab.actions.mark_signed_filed_heading'))
            ->modalDescription(__('documents.tab.actions.mark_signed_filed_description'))
            ->authorize(fn (Document $record): bool => Gate::allows('publish', $record))
            ->visible(fn (Document $record): bool => $record->group === DocumentGroup::Issued
                && $record->status === DocumentStatus::PendingApproval)
            ->successNotificationTitle(__('documents.tab.actions.mark_signed_filed_success'))
            ->action(fn (Action $action, Document $record) => $this->runAction(
                $action,
                fn () => app(MarkDocumentSignedFiled::class)->handle(document: $record, actor: Auth::user()),
            ));
    }

    /**
     * "Trả về bản nháp" — ruling vòng sửa 1: đi ngược một bước, `pending_approval` →
     * `internal_draft`. Chỉ hiện trên một dòng nhóm B đang `pending_approval`, và đòi
     * `document.publish` — cùng cổng với "Đánh dấu đã ký, đã nộp", không phải "Trình duyệt". Xem
     * docblock `ReturnDocumentToDraft` cho lý do quyền này nặng hơn "Trình duyệt".
     */
    private function returnToDraftAction(): Action
    {
        return Action::make('returnToDraft')
            ->label(__('documents.tab.actions.return_to_draft'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('documents.tab.actions.return_to_draft_heading'))
            ->modalDescription(__('documents.tab.actions.return_to_draft_description'))
            ->authorize(fn (Document $record): bool => Gate::allows('publish', $record))
            ->visible(fn (Document $record): bool => $record->group === DocumentGroup::Issued
                && $record->status === DocumentStatus::PendingApproval)
            ->successNotificationTitle(__('documents.tab.actions.return_to_draft_success'))
            ->action(fn (Action $action, Document $record) => $this->runAction(
                $action,
                fn () => app(ReturnDocumentToDraft::class)->handle(document: $record, actor: Auth::user()),
            ));
    }

    /**
     * SPEC §6.5. Nhóm D không bao giờ có nút này — xem docblock lớp.
     *
     * **`fillForm` đọc từ CHÍNH bản ghi, không phải hai cờ cố định (`docs/docs-4`).** Bản trước
     * `->default(true)` trên cả hai ô, không đọc trạng thái hiện tại: mở lại hộp thoại của một
     * tài liệu đã công bố "chỉ xem, không tải" (`client_can_download = false`) vẫn thấy ô "Cho
     * khách tải về" đang BẬT, và bấm xác nhận là lặng lẽ mở lại quyền tải mà không ai chủ ý.
     *
     * Vẫn giữ đúng lý do `client_can_view` mặc định bật cho một tài liệu CHƯA từng ra tới khách —
     * `PublishDocument` từ chối công bố với cờ đó tắt, nên một lần công bố ĐẦU TIÊN với ô này tắt
     * sẵn không phải một thao tác, nó là một lời từ chối đã biết trước. `isReleasedToPortal()` là
     * đúng lằn ranh: tài liệu ĐÃ ra tới khách thì form phải trung thực với hai cờ hiện có (kể cả
     * khi đó là một lần công bố lại), còn CHƯA thì form vẫn gợi ý bộ mặc định thuận tiện cũ.
     *
     * **Ba ô ẩn `mounted_*` — kiểm tra optimistic, vòng sửa 1, MỞ RỘNG vòng sửa 2 (N1).**
     * `PublishDocument` không tự biết hộp thoại này đã mở TỪ LÚC NÀO — nó chỉ thấy dữ liệu gửi lên
     * khi bấm xác nhận. Ba ô ẩn này mang đúng ẢNH CHỤP trạng thái công bố mà `fillForm()` ở trên
     * đã đọc LÚC MỞ hộp thoại, đi kèm (không thay thế) hai `Toggle` — người dùng có thể đổi
     * `Toggle`, nhưng ba ô ẩn giữ nguyên giá trị lúc mount.
     *
     * **Luôn gửi giá trị THẬT — không còn `null` làm dấu hiệu "lần đầu" (vòng sửa 2 sửa đúng lỗ
     * hổng N1).** Bản vòng sửa 1 gửi `mounted_client_can_view`/`mounted_client_can_download` là
     * `null` cho một tài liệu chưa release, và `PublishDocument` đọc `null` đó thành "đừng so gì
     * cả" — bỏ lọt đúng lúc TRẠNG THÁI CÔNG BỐ đổi giữa lúc mount và lúc xác nhận (hai tab cùng
     * công bố lần đầu; hoặc một vòng thu hồi-rồi-trả-lại xảy ra giữa chừng). Nay ba ô LUÔN mang
     * giá trị hiện có của chính bản ghi (`$record->client_can_view`/`client_can_download`, và
     * `$record->wasPublishedToClient()` cho `mounted_is_released`) — kể cả khi tài liệu chưa từng
     * release (khi đó cả ba đều `false`, KHÔNG phải bộ mặc định tiện lợi `true`/`true` mà hai
     * `Toggle` hiển thị). `PublishDocument` so sánh LUÔN chạy, xem docblock lớp đó.
     */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('documents.tab.actions.publish'))
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->modalHeading(__('documents.tab.actions.publish_heading'))
            ->authorize(fn (Document $record): bool => Gate::allows('publish', $record))
            ->visible(fn (Document $record): bool => ! $record->group->isInternal())
            ->fillForm(fn (Document $record): array => [
                'client_can_view' => $record->isReleasedToPortal() ? $record->client_can_view : true,
                'client_can_download' => $record->isReleasedToPortal() ? $record->client_can_download : true,
                'mounted_client_can_view' => $record->client_can_view,
                'mounted_client_can_download' => $record->client_can_download,
                'mounted_is_released' => $record->wasPublishedToClient(),
            ])
            ->schema([
                // Hai cờ ĐỘC LẬP (SPEC §6.5 bước 3): cho khách biết đã có tài liệu mà chưa cho
                // giữ bản sao là một trường hợp hợp lệ. Giá trị BAN ĐẦU của ô do `fillForm()` ở
                // trên quyết định — xem docblock ngay phía trên hàm này.
                Toggle::make('client_can_view')
                    ->label(__('documents.tab.fields.client_can_view'))
                    ->helperText(__('documents.tab.fields.client_can_view_help')),
                Toggle::make('client_can_download')
                    ->label(__('documents.tab.fields.client_can_download'))
                    ->helperText(__('documents.tab.fields.client_can_download_help')),
                // Ảnh chụp lúc mount — xem docblock hàm này. Không hiện trên màn hình, chỉ đi
                // theo request để `PublishDocument` so sánh.
                Hidden::make('mounted_client_can_view'),
                Hidden::make('mounted_client_can_download'),
                Hidden::make('mounted_is_released'),
            ])
            ->successNotificationTitle(__('documents.tab.actions.publish_success'))
            ->action(fn (Action $action, Document $record, array $data) => $this->runAction(
                $action,
                fn () => app(PublishDocument::class)->handle(
                    document: $record,
                    actor: Auth::user(),
                    clientCanView: (bool) ($data['client_can_view'] ?? false),
                    clientCanDownload: (bool) ($data['client_can_download'] ?? false),
                    expectedClientCanView: (bool) ($data['mounted_client_can_view'] ?? false),
                    expectedClientCanDownload: (bool) ($data['mounted_client_can_download'] ?? false),
                    expectedIsReleased: (bool) ($data['mounted_is_released'] ?? false),
                ),
            ));
    }

    /**
     * Cửa DUY NHẤT để một tài liệu rời nhóm D (`RegroupDocument`, và hàng rào `saving` của
     * `Document` là thứ làm cho nó là duy nhất).
     *
     * **Điều kiện hiển thị chép đúng HAI cổng của Action — không phải BA, và đó là chủ đích, không
     * phải một chỗ sót (vòng sửa 1 sửa lại câu cũ, thứ khẳng định sai điều này).** Hai cổng được
     * chép: `update` luôn, cộng `publish` khi tài liệu ĐANG ở nhóm D. Cổng THỨ BA — rời nhóm B
     * sang A/C đòi `publish` CỘNG (đã ký/nộp/công bố HOẶC một lý do hợp lệ) — KHÔNG chép vào đây,
     * vì nó không phải một câu hỏi "có/không" cho cả nút: nó phụ thuộc nhóm ĐÍCH người dùng SẼ
     * chọn, thứ chưa ai chọn tại thời điểm nút hiện ra. Chép nó vào `->authorize()` sẽ ẨN CẢ NÚT
     * khỏi một trợ lý đang đứng trên một dòng nhóm B — trong khi trợ lý đó CÓ MỘT lựa chọn hợp lệ
     * (rời vào nhóm D, phán quyết R9 mở rộng: luôn được phép với `document.update`). Cách đúng là
     * để nút hiện, và lọc NGAY TRONG Ô CHỌN NHÓM ĐÍCH — xem {@see self::regroupOptions()}, thứ
     * trước vòng sửa này không lọc gì cho nhóm B, nên một trợ lý mở ô chọn của một tài liệu B THẤY
     * được A và C, hai lựa chọn Action luôn từ chối họ.
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
                    // Câu RIÊNG, không dùng lại `group_help` của lần đưa tài liệu vào hồ sơ: ở
                    // đây có một hậu quả mà lần tạo mới không có — đi vào nhóm D thu hồi quyền
                    // xem và quyền tải của khách, và đi ra không trả lại. Xem hook `saving` của
                    // `Document`.
                    ->helperText(__('documents.tab.fields.target_group_help'))
                    ->options(fn (Document $record): array => static::regroupOptions($record))
                    ->default(fn (Document $record): string => $record->group->value)
                    ->live()
                    ->required(),
                // R9 mở rộng (vòng sửa 1): chỉ hiện khi lựa chọn HIỆN TẠI trong ô trên thật sự
                // cần nó — tài liệu đang nhóm B, nhóm ĐÍCH là A hoặc C, và tài liệu CHƯA đi hết
                // vòng đời (chưa `signed_filed`/`published`). Với một tài liệu đã ký/nộp/công bố,
                // ô này không hiện — không có gì để "giải thích", chuyển nhóm đơn giản là hợp lệ.
                Textarea::make('reason')
                    ->label(__('documents.tab.fields.regroup_reason'))
                    ->helperText(__('documents.tab.fields.regroup_reason_help'))
                    ->visible(fn (Get $get, Document $record): bool => static::regroupReasonNeeded($record, $get('group')))
                    ->required(fn (Get $get, Document $record): bool => static::regroupReasonNeeded($record, $get('group')))
                    ->minLength(10)
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('documents.tab.actions.regroup_success'))
            ->action(fn (Action $action, Document $record, array $data) => $this->runAction(
                $action,
                fn () => app(RegroupDocument::class)->handle(
                    document: $record,
                    actor: Auth::user(),
                    group: DocumentGroup::from($data['group']),
                    reason: ($data['reason'] ?? null) ?: null,
                ),
            ));
    }

    /**
     * "Ô lý do chuyển nhóm có cần hiện cho lựa chọn hiện tại không" — tách static để hai closure
     * `->visible()`/`->required()` của {@see self::regroupAction()} hỏi đúng MỘT câu hỏi, không
     * lệch nhau nếu một trong hai bị sửa riêng. Không gọi `Gate` ở đây: đây là một gợi ý GIAO
     * DIỆN, cổng THẬT nằm trong chính Action (một actor không có `document.publish` gõ đủ 10 ký tự
     * vào ô này vẫn bị Action từ chối ở bước `Gate::authorize('publish')`).
     *
     * Final review X7: hỏi thẳng `RegroupDocument::needsMisfilingReason()` — cùng câu Action hỏi
     * dưới khoá (kể cả một bản nháp B đã đi qua D, và `published` tính là đã hết vòng đời), không
     * chép lại điều kiện ở đây.
     */
    private static function regroupReasonNeeded(Document $record, mixed $selectedGroup): bool
    {
        $target = is_string($selectedGroup) ? DocumentGroup::tryFrom($selectedGroup) : null;

        return $target !== null && RegroupDocument::needsMisfilingReason($record, $target);
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
     * Danh sách MIME cho luật `mimetypes:` mà `acceptedFileTypes()` cài (SPEC §6.6 bước 2).
     *
     * **`docs/docs-7` ĐÃ chứng minh câu "KHÔNG phải một cổng an ninh" — ở dạng cũ của tài liệu
     * này — là sai, và vòng sửa 1 sửa lại câu đó.** Ô này KHÔNG phải ranh giới an ninh DUY NHẤT
     * hay CUỐI CÙNG (`FileGuard::ALLOWED` vẫn là ranh giới thật, chạy sau và không tin danh sách
     * này), nhưng bản thân nó VẪN LÀ MỘT CỔNG thật sự chặn được tệp: luật `mimetypes:` chạy TRƯỚC
     * `FileGuard`, trên MIME đọc bằng `finfo` (production) hoặc suy từ đuôi (bộ test, xem docblock
     * `->acceptedFileTypes()` tại `uploadAction()`), và một tệp không khớp danh sách này KHÔNG BAO
     * GIỜ tới được `UploadStaffDocument`/`FileGuard` — bị chặn hẳn ở đây, với câu tiếng Việt của
     * `validationMessages()`. Hai test minh chứng ngược nhau đang giữ cho câu này đúng:
     * `DocumentsRelationManagerTest` có cả "một tệp mà nội dung thật KHÔNG khớp danh sách này bị
     * từ chối NGAY TẠI Ô" (chưa từng chạm `FileGuard`) LẪN "một docx thật mà libmagic đọc ra
     * `application/zip` (đã có trong danh sách) đi lọt qua ô, chạm được `FileGuard`". Gọi nó
     * "không phải cổng an ninh" chỉ đúng cho MỘT ý hẹp: nó không phải ranh giới CUỐI CÙNG, vì
     * không kiểm cấu trúc gói OOXML hay quét virus — những việc `FileGuard`/`VirusScanner` làm.
     *
     * **`application/zip` có mặt vì một docx/xlsx THẬT (`docs/docs-7`).** Office Open XML là một
     * gói ZIP: `finfo` đôi khi chỉ đọc ra `application/zip` cho một `.docx`/`.xlsx` hợp lệ
     * (libmagic chỉ nhận ra MIME OOXML cụ thể khi thứ tự các mục trong gói hợp với heuristic của
     * nó, và heuristic đó khác nhau giữa các bản libmagic — xem `FileGuard::verifyOfficePackage()`,
     * nơi ĐÃ có logic chấp nhận trường hợp này bằng cách mở gói ra và đòi đúng mục bắt buộc, chứ
     * không tin riêng MIME). Không có nó, một docx thật, hợp lệ, bị chặn oan ngay tại ô — hai cổng
     * bất đồng, và cổng sớm hơn thắng.
     *
     * `application/zip` KHÔNG nới ranh giới thật: một tệp `.zip` trần (đuôi `zip`) vẫn bị
     * `FileGuard::check()` từ chối ở bước ĐUÔI (`extensionNotAllowed()`), trước khi MIME được xét
     * tới — nó chỉ nới cho hai ĐUÔI `docx`/`xlsx` đã có trong danh sách trắng, đúng những đuôi mà
     * `FileGuard::OFFICE_PACKAGE_ENTRIES` đòi mở gói ra kiểm tra thêm.
     *
     * **Ba MIME OLE2 cũ (`x-ole-storage`, `x-cfb`, `CDFV2`) — vòng sửa 1.** `.doc`/`.xls` thật
     * (định dạng nhị phân cũ, không phải OOXML) đôi khi được `finfo` nhận diện bằng một trong ba
     * chuỗi MIME chung này thay vì `application/msword`/`application/vnd.ms-excel` cụ thể — cùng
     * lý do libmagic-theo-heuristic với `application/zip` phía trên, và `FileGuard::ALLOWED` đã
     * chấp nhận cả ba cho `doc`/`xls` từ trước. Câu gợi ý của ô (`documents.upload.file_type`) đã
     * nói "chấp nhận DOC, XLS"; thiếu ba MIME này, câu đó nói dối cho đúng loại tệp `.doc`/`.xls`
     * mà `finfo` đọc ra một trong ba chuỗi đó.
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
            'application/zip',
            'application/x-ole-storage',
            'application/x-cfb',
            'application/CDFV2',
        ];
    }
}
