<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Document\AddChecklistItem;
use App\Actions\Document\ChecklistProgress;
use App\Actions\Document\MarkChecklistItemNotApplicable;
use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Notification\NotifyClientOfChecklistItemRejected;
use App\Enums\ChecklistItemStatus;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\Scopes\ClientPortalScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Tab "Danh mục hồ sơ" (SPEC §7.2): bảng `matter_checklist_items` kèm thanh tiến độ `X/Y`, ba
 * thao tác ngay trên dòng — duyệt, từ chối (có ba mẫu lý do bấm một cái là điền, SPEC §6.7), và
 * đánh dấu không cần nộp (SPEC §4.10) — và một nút đầu bảng, "Thêm đầu mục" (M6.5 Task 15, SPEC
 * §4.10/§7.4), để thêm một giấy tờ riêng cho ĐÚNG vụ việc này.
 *
 * **"Thêm đầu mục" tồn tại vì trước Task 15 không có đường nào khác ghi một dòng MỚI vào
 * `matter_checklist_items`.** Xem docblock {@see AddChecklistItem} cho hậu
 * quả đầy đủ (finding `intake-02`/`checklist-02`/`roles-06`/`spec-gap-04`, critical): ba loại vụ
 * việc không có mẫu mở ra với 0 đầu mục vĩnh viễn, và khách của các vụ đó không nộp được gì qua
 * cổng.
 *
 * **Lớp này không có một dòng nghiệp vụ nào.** Mọi lần ghi đi qua `ReviewChecklistItem`,
 * `MarkChecklistItemNotApplicable`, hoặc `AddChecklistItem` (CLAUDE.md: nghiệp vụ chỉ ở
 * `app/Actions/`). Những gì ở đây là: cột nào hiện, nút nào hiện cho ai, và một lời từ chối của
 * Action đến được mắt người dùng bằng tiếng Việt thay vì thành trang 500 — xem
 * `ReportsActionFailures`.
 *
 * **Hai cổng khác nhau trên mỗi nút, cố ý tách rời.** `->authorize()` hỏi `Gate` về QUYỀN
 * (`MatterChecklistItemPolicy::review`, tức `checklist.review` cộng khả năng thấy hồ sơ);
 * `->visible()` hỏi về TRẠNG THÁI bản ghi. Hai câu hỏi khác nhau, và Action cũng tách chúng ra
 * đúng như vậy (`OpensChecklistItem` cho cái thứ nhất, `guardDecisionAgainstState()` cho cái thứ
 * hai). Không cổng nào ở đây là cổng thật: Action tự hỏi lại tất cả, không tin màn hình đã lọc.
 *
 * **Cổng trạng thái BẤT ĐỐI XỨNG, chép theo Action chứ không tự nghĩ ra.** "Đã nhận" hiện ở MỌI
 * trạng thái (khách mang giấy tờ ra tận văn phòng là chuyện hằng ngày, và lúc đó đầu mục vẫn
 * `missing`); "Cần nộp lại" chỉ hiện khi có thứ gì đang chờ, vì `rejection_reason` là một câu nói
 * thẳng với khách về thứ họ đã gửi; "Không cần nộp" ẩn khi đang `pending_review`, vì gạt một tệp
 * vừa gửi lên sang "không cần" là vứt lần nộp ấy vào im lặng. Lý lẽ đầy đủ nằm trong docblock
 * `ReviewChecklistItem::guardDecisionAgainstState()` và `ChecklistItemNotReviewable::
 * awaitingReview()`; ở đây chỉ nói lại rằng ba cái nút phải phản ánh đúng ba luật đó, nếu không
 * người dùng bấm một nút để nhận về một lời từ chối.
 *
 * **Không có `isReadOnly(): false` ở đây, và đó là một kết quả ĐO ĐƯỢC chứ không phải một sơ
 * suất.** `PartiesRelationManager` phải tắt nó vì nó dùng `CreateAction`; bản đầu của lớp này
 * chép theo, kèm một câu docblock nói rằng mặc định `true` "từ chối MỌI thao tác bất kể policy".
 * Câu đó SAI. `RelationManager::getDefaultActionAuthorizationResponse()` chỉ hỏi `isReadOnly()`
 * cho các lớp action dựng sẵn của Filament (`CreateAction`, `EditAction`, `DeleteAction`,
 * `AttachAction`, …); mọi thứ khác rơi vào `default => null`. Cả bốn thao tác ở đây (ba trên
 * dòng, cộng "Thêm đầu mục" đầu bảng) đều là `Filament\Actions\Action` thuần, nên phương thức đó
 * không đổi được gì — một mutation probe đặt nó thành `true` đã để cả bộ test xanh. Nếu một ngày
 * có ai thêm `DeleteAction` vào bảng này thì mới cần tắt nó, và lúc đó nó sẽ có test đi kèm.
 *
 * Đúng vì `isReadOnly()` không chạm tới, "Thêm đầu mục" PHẢI tự khai `->authorize()` — nguyên tắc
 * của task này: mặc định trang/Action tự viết luôn CHO PHÉP, mỗi Action phải tự hỏi policy. Cổng
 * đó hỏi thẳng `MatterChecklistItemPolicy::create()` (uỷ cho `MatterPolicy::update()`), KHÔNG
 * phải `checklist.review` như ba nút kia — thêm một giấy tờ vào danh mục là một việc khác với
 * duyệt một giấy tờ khách đã nộp (xem docblock `AddChecklistItem`), và trợ lý thêm được vì họ có
 * sẵn `matter.update` (`Role::Assistant->permissions()`), không phải vì họ có `checklist.review`.
 */
class ChecklistRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'checklistItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.checklist');
    }

    /**
     * Thanh tiến độ như nó hiện ra. Tách static để test được mà không dựng cả bảng, cùng thành
     * ngữ với `StageLogsRelationManager::renderInternalNote()`.
     *
     * **Con số thì không tính ở đây.** Luật đếm `X/Y` của SPEC §4.10 (kèm đính chính
     * 2026-09-16) nằm ở {@see ChecklistProgress}, vì nó là một luật nghiệp vụ và vì M5 hiện đúng
     * con số này cho chính khách hàng — xem docblock lớp đó. Ở đây chỉ còn việc vẽ.
     *
     * Mẫu số bằng 0 có câu RIÊNG chứ không hiện "0/0" kèm một thanh rỗng: một hồ sơ chưa có gì để
     * theo dõi và một hồ sơ khách chưa nộp gì là hai tình huống khác hẳn nhau, và một thanh 0%
     * nói nhầm tình huống thứ nhất thành tình huống thứ hai. Phép chia cũng không bao giờ chạm
     * vào 0 ở nhánh này.
     *
     * **Kiểu dáng viết thẳng bằng `style=` chứ không bằng lớp Tailwind, và đó là bắt buộc trong
     * dự án này.** Không có bước dựng CSS (CLAUDE.md: máy dev chỉ có PHP trong Docker), panel
     * dùng `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn, và bộ đó chỉ chứa những
     * lớp tiện ích Filament tự dùng — `bg-gray-200`, `bg-primary-600`, `text-gray-500` không có
     * trong đó, nên một thanh tiến độ viết bằng chúng hiện ra là một dòng chữ trần không có
     * thanh nào.
     *
     * **Màu đã được ĐO trên trình duyệt thật, vì cho tới vòng rà soát cuối M4 đây là biến màu
     * duy nhất của nhánh này chỉ có một lời khẳng định đứng sau.** Trên `VK-2026-DS-0003` của
     * dữ liệu mẫu, tab "Danh mục hồ sơ", thanh `Đã nộp 2/3`:
     *
     *  - phần đã nộp — `background-color: var(--primary-500)` — tính ra
     *    `oklch(0.554 0.046 257.417)`, rộng `386.578px` trên nền rãnh `577px` (đúng 67%), cao
     *    `8px`: nó thật sự hiện ra, không phải một `var()` rỗng;
     *  - rãnh nền — `color-mix(in srgb, currentColor 15%, transparent)` — tính ra
     *    `color(srgb 0.0354 0.0354 0.0443 / 0.15)` ở chế độ sáng.
     *
     * Bật lớp `.dark` trên `<html>` rồi đo lại: phần đã nộp GIỮ NGUYÊN
     * `oklch(0.554 0.046 257.417)` (bảng `--primary-*` của Filament không tự đảo chiều, đúng như
     * bảng `--gray-*` mà `StageLogsRelationManager::renderInternalNote()` đã đo), còn rãnh nền
     * lật sang `color(srgb 1 1 1 / 0.15)` vì `currentColor` lật theo màu chữ — trên nền trang
     * `oklch(0.141 0.005 285.823)`. Đó là lý do một luật đủ cho cả hai chế độ: thứ cần đổi thì
     * `currentColor` tự đổi, thứ không cần đổi là màu thương hiệu.
     *
     * `StageLogPaintingTest` và `ChecklistRelationManagerTest` giữ nốt nửa còn lại mà trình duyệt
     * không giữ được: `--primary-500` phải là một sắc độ `FilamentColor` thật sự đăng ký, nếu
     * không `var()` rỗng và thanh biến mất.
     */
    public static function progressBar(Matter $matter): Htmlable
    {
        ['submitted' => $submitted, 'total' => $total] = app(ChecklistProgress::class)->handle($matter);

        if ($total === 0) {
            return new HtmlString(sprintf(
                '<p style="font-size:0.875rem;opacity:0.7">%s</p>',
                e(__('checklist.tab.progress_empty')),
            ));
        }

        $percent = (int) round($submitted / $total * 100);

        return new HtmlString(sprintf(
            '<p style="font-size:0.875rem;font-weight:600;margin-bottom:0.25rem">%s</p>'
            .'<div style="height:0.5rem;width:100%%;border-radius:999px;overflow:hidden;'
            .'background-color:color-mix(in srgb, currentColor 15%%, transparent)">'
            .'<div style="height:100%%;width:%d%%;background-color:var(--primary-500)"></div></div>',
            e(__('checklist.tab.progress', ['submitted' => $submitted, 'total' => $total])),
            $percent,
        ));
    }

    /** Ba mẫu lý do từ chối của SPEC §6.7 — khoá dịch => nhãn ngắn của nút điền mẫu. */
    public static function rejectionTemplates(): array
    {
        return [
            'blurred' => __('checklist.tab.template_labels.blurred'),
            'uncertified_copy' => __('checklist.tab.template_labels.uncertified_copy'),
            'wrong_document' => __('checklist.tab.template_labels.wrong_document'),
        ];
    }

    /** Màu badge của từng trạng thái. Chỉ hiển thị — không luật nghiệp vụ nào đọc nó. */
    public static function statusColor(ChecklistItemStatus $status): string
    {
        return match ($status) {
            ChecklistItemStatus::Missing => 'gray',
            ChecklistItemStatus::PendingReview => 'warning',
            ChecklistItemStatus::Accepted => 'success',
            ChecklistItemStatus::Rejected => 'danger',
            ChecklistItemStatus::NotApplicable => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            // Thanh tiến độ đọc từ `$this->getOwnerRecord()` chứ không từ truy vấn của bảng: bảng
            // phân trang và lọc được, còn `X/Y` là con số của CẢ hồ sơ (và M5 hiện đúng con số
            // này cho khách). Một thanh tiến độ tính theo trang đang xem là một con số khác.
            ->description(fn (): Htmlable => static::progressBar($this->getOwnerRecord()))
            ->columns([
                TextColumn::make('name')
                    ->label(__('checklist.tab.columns.name'))
                    ->wrap()
                    ->searchable(),
                IconColumn::make('is_required')
                    ->label(__('checklist.tab.columns.is_required'))
                    ->boolean(),
                TextColumn::make('status')
                    ->label(__('checklist.tab.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (ChecklistItemStatus $state): string => $state->label())
                    ->color(fn (ChecklistItemStatus $state): string => static::statusColor($state)),
                TextColumn::make(ChecklistProgress::CLIENT_SUBMITTED_DOCUMENT_COUNT_ALIAS)
                    ->label(__('checklist.tab.columns.documents_count')),
                // Câu này khách đang đọc trên portal của họ, nên nó hiện đầy đủ ở đây — người
                // duyệt phải đọc lại được chính xác thứ văn phòng đã nói, không phải một bản rút
                // gọn.
                TextColumn::make('rejection_reason')
                    ->label(__('checklist.tab.columns.rejection_reason'))
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('reviewer.name')
                    ->label(__('checklist.tab.columns.reviewer'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->label(__('checklist.tab.columns.reviewed_at'))
                    ->dateTime('H:i d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                $this->addItemAction(),
            ])
            ->recordActions([
                $this->acceptAction(),
                $this->rejectAction(),
                $this->markNotApplicableAction(),
            ])
            // Bộ đếm tài liệu của CỘT là đúng bộ đếm mà mẫu số của thanh tiến độ dùng, lấy từ
            // `ChecklistProgress` chứ không viết lại: một `withCount` thứ hai ở đây là cách để
            // cột "Số tài liệu" và con số `X/Y` ngay trên đầu bảng nói hai chuyện khác nhau về
            // cùng một dòng.
            ->modifyQueryUsing(fn (Builder $query): Builder => ChecklistProgress::countClientSubmittedDocuments(
                static::scopeToVisibleMatters($query)
            )->with('reviewer'));
    }

    /**
     * "Thêm đầu mục" (M6.5 Task 15) — đầu bảng, không gắn với một dòng nào. Ba ô đủ cho việc xin
     * thêm MỘT giấy tờ riêng cho vụ việc này: tên, mô tả cho khách (câu này khách đọc trên portal
     * — xem `MatterProgress::checklistItems()`), và có bắt buộc hay không. Không có ô "thứ tự":
     * `AddChecklistItem` tự nối đầu mục mới vào CUỐI danh mục hiện có, người thêm không cần biết
     * số thứ tự hiện tại của những dòng khác.
     *
     * `->maxLength(200)` khớp đúng cột `matter_checklist_items.name` (migration
     * `2026_09_14_000011`) — MariaDB strict mode biến việc vượt quá thành lỗi 500, SQLite của bộ
     * test thì không thấy (CLAUDE.md, bài học `intake/intake-08`).
     */
    private function addItemAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('addItem')
            ->label(__('checklist.tab.actions.add_item'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('checklist.tab.actions.add_item_heading'))
            ->modalSubmitActionLabel(__('checklist.tab.actions.add_item_submit'))
            // Cổng THẬT — không có nó, một `Filament\Actions\Action` thuần mặc định CHO PHÉP mọi
            // người (xem docblock lớp). Hỏi `MatterChecklistItemPolicy::create()`, KHÔNG phải
            // `checklist.review`: đây là "xin thêm giấy tờ", không phải "duyệt giấy tờ đã nộp".
            ->authorize(fn (): bool => Gate::allows('create', [MatterChecklistItem::class, $matter]))
            ->schema([
                TextInput::make('name')
                    ->label(__('checklist.tab.fields.item_name'))
                    ->required()
                    ->maxLength(200),
                Textarea::make('description')
                    ->label(__('checklist.tab.fields.item_description'))
                    ->columnSpanFull(),
                Toggle::make('is_required')
                    ->label(__('checklist.tab.fields.item_is_required'))
                    ->default(false),
            ])
            ->successNotificationTitle(__('checklist.tab.actions.add_item_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(AddChecklistItem::class)->handle(
                    matter: $matter,
                    actor: Auth::user(),
                    name: $data['name'],
                    description: $data['description'] ?? null,
                    isRequired: (bool) ($data['is_required'] ?? false),
                ),
            ));
    }

    /**
     * R11 (M6.5 Task 17, checklist-04) — hai thành phần dùng chung cho CẢ HAI hộp duyệt (đã nhận,
     * cần nộp lại), vì cả hai đều là "một quyết định duyệt" theo đúng nghĩa
     * `ReviewChecklistItem::handle()` dùng chữ đó:
     *
     *  1. **Danh sách tệp** ({@see self::documentsList()}) — để người duyệt THẤY đúng cái mình
     *     sắp quyết định, kèm liên kết mở TỪNG tệp (`Document::downloadUrlFor()`, route đã ký,
     *     SPEC §10.4), thay vì phải rời tab này sang tab "Tài liệu" rồi quay lại.
     *  2. **Ô ẩn `document_ids`** — chụp lại đúng tập id mà danh sách trên vừa vẽ ra, TẠI THỜI
     *     ĐIỂM MỞ HỘP (`default()` chạy lúc mount action, không chạy lại khi submit). Gửi kèm lên
     *     `ReviewChecklistItem::handle()`, nơi nó được so lại với tập HIỆN TẠI dưới khoá — khác
     *     nhau thì bị chặn. Đây là CƠ CHẾ; luật thuộc về Action, không thuộc về màn hình.
     *
     * Cùng một nguồn — {@see ReviewChecklistItem::currentDocumentIds()} — dựng cả hai, nên danh
     * sách người duyệt THẤY và tập id gửi lên LUÔN khớp nhau; viết lại truy vấn đó lần thứ hai ở
     * đây là cách chắc chắn nhất để một ngày chúng lệch nhau.
     *
     * @return array<int, Component>
     */
    private function documentsSchema(): array
    {
        return [
            Placeholder::make('documents')
                ->label(__('checklist.tab.fields.documents_label'))
                ->content(fn (MatterChecklistItem $record): Htmlable => static::documentsList($record)),
            Hidden::make('document_ids')
                ->default(fn (MatterChecklistItem $record): array => ReviewChecklistItem::currentDocumentIds($record)),
        ];
    }

    /**
     * Vẽ danh sách tệp của {@see self::documentsSchema()} — tách riêng để test được mà không
     * dựng cả action, cùng thành ngữ `progressBar()`.
     *
     * `withoutGlobalScope(ClientPortalScope::class)`, tường minh: cùng lý do với
     * `ReviewChecklistItem::currentDocumentIds()` — một nhân sự đang mở cả hai panel trong cùng
     * trình duyệt không được nhận một danh sách rỗng chỉ vì guard `client` cũng đang xác thực.
     *
     * `downloadUrlFor(Auth::user())` — route đã ký, hết hạn sau 5 phút (SPEC §10.4), ký cho ĐÚNG
     * người đang mở hộp này. Cùng thành ngữ `DocumentsRelationManager::downloadAction()`.
     *
     * Tên hiện ra là tên TỆP KHÁCH ĐÃ ĐẶT (`Media::name`, qua `FileGuard::safeName()`), không
     * phải `Document::title` — hai (hoặc nhiều) tệp của cùng một lần nộp (R10) đều mang chung một
     * `title` (tên đầu mục), nên chỉ tên tệp mới phân biệt được "mặt trước" với "mặt sau".
     *
     * `public static`, cùng thành ngữ `progressBar()`: test được trực tiếp mà không phải dựng cả
     * action/modal — bài học từ chính vòng sửa này, nơi `->html()` của một action đã MOUNT không
     * chắc mang theo nội dung modal trong bộ test hiện có của dự án.
     */
    public static function documentsList(MatterChecklistItem $item): Htmlable
    {
        $ids = ReviewChecklistItem::currentDocumentIds($item);

        if ($ids === []) {
            return new HtmlString(sprintf(
                '<p style="opacity:0.7">%s</p>',
                e(__('checklist.tab.fields.documents_empty')),
            ));
        }

        $viewer = Auth::user();

        $rows = Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Document $document): int => array_search($document->getKey(), $ids, true))
            ->map(fn (Document $document): string => sprintf(
                '<li><a href="%s" target="_blank" rel="noopener" style="color:var(--primary-600);text-decoration:underline;">%s</a></li>',
                e($document->downloadUrlFor($viewer)),
                e($document->getFirstMedia('file')?->name ?? $document->title),
            ))
            ->implode('');

        return new HtmlString(sprintf('<ul style="margin:0;padding-left:1.25rem;">%s</ul>', $rows));
    }

    private function acceptAction(): Action
    {
        return Action::make('accept')
            ->label(__('checklist.tab.actions.accept'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('checklist.tab.actions.accept_heading'))
            ->modalDescription(__('checklist.tab.actions.accept_description'))
            ->authorize(fn (MatterChecklistItem $record): bool => Gate::allows('review', $record))
            ->schema($this->documentsSchema())
            ->successNotificationTitle(__('checklist.tab.actions.accept_success'))
            ->action(fn (Action $action, MatterChecklistItem $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReviewChecklistItem::class)->handle(
                    checklistItem: $record,
                    actor: Auth::user(),
                    decision: ChecklistItemStatus::Accepted,
                    documentIds: $data['document_ids'] ?? [],
                ),
            ));
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('checklist.tab.actions.reject'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->modalHeading(__('checklist.tab.actions.reject_heading'))
            ->authorize(fn (MatterChecklistItem $record): bool => Gate::allows('review', $record))
            // Cổng TRẠNG THÁI, chép từ `ReviewChecklistItem::guardDecisionAgainstState()`: không
            // có gì đang chờ thì không có gì để từ chối.
            ->visible(fn (MatterChecklistItem $record): bool => static::hasSomethingToReject($record))
            ->schema([
                // Ba mẫu của SPEC §6.7, mỗi mẫu một nút, bấm một cái là điền vào ô lý do bên
                // dưới. Chúng là `Action` của schema nên Filament tự lo việc ghi state đúng chỗ
                // (state của một action đang mounted nằm ở `mountedActions.N.data`, không ở
                // `$this->data`) — cùng cái bẫy `PartiesRelationManager::forgetConflictResult()`
                // phải tự đi vòng.
                //
                // **checklist-06 — mẫu `wrong_document` tự điền `[tên đầu mục]`.** Tên đầu mục đã
                // nằm sẵn trên chính dòng đang mở (`$record->name`), nên màn hình không có lý do
                // gì bắt người duyệt gõ lại một chuỗi nó đã biết. `[tên tài liệu đã nộp]` thì
                // KHÔNG được tự điền theo cùng cách: màn hình không biết khách đã gửi ĐÚNG tệp
                // gì, chỉ người duyệt — sau khi mở hộp và xem danh sách tệp bên dưới — mới biết,
                // nên nó vẫn là một chỗ trống phải điền tay. Bấm mẫu rồi gửi luôn mà quên sửa vẫn
                // bị `ReviewChecklistItem::resolveRejectionReason()` chặn lại vì còn `[tên` —
                // hai lớp cùng canh một luật, không phải luật nói hai lần: lớp NÀY tránh một lần
                // gõ tay không cần thiết, lớp KIA là cổng thật không tin màn hình đã điền đúng.
                SchemaActions::make(collect(static::rejectionTemplates())
                    ->map(fn (string $label, string $key): Action => Action::make('fill_'.$key)
                        ->label($label)
                        ->link()
                        ->action(function (Set $set, MatterChecklistItem $record) use ($key): void {
                            $template = __('checklist.rejection_templates.'.$key);

                            if ($key === 'wrong_document') {
                                $template = str_replace('[tên đầu mục]', $record->name, $template);
                            }

                            $set('rejection_reason', $template);
                        }))
                    ->values()
                    ->all())
                    ->key('rejection_templates')
                    ->label(__('checklist.tab.fields.templates')),
                Textarea::make('rejection_reason')
                    ->label(__('checklist.tab.fields.rejection_reason'))
                    // Fix round 1 (finding Important 2) + fix round 2 (finding 1, 2): câu này nói
                    // khách sẽ đọc lý do ở ĐÂU — email và cổng, chỉ cổng, hay không ở đâu cả — xem
                    // docblock `successNotificationTitle()` bên dưới và `self::rejectionNoticeCopy()`.
                    ->helperText(fn (MatterChecklistItem $record): string => static::rejectionNoticeCopy(
                        $record,
                        emailed: 'checklist.tab.fields.rejection_reason_help',
                        portalOnly: 'checklist.tab.fields.rejection_reason_help_no_notice',
                        hidden: 'checklist.tab.fields.rejection_reason_help_portal_hidden',
                    ))
                    ->rows(4)
                    ->columnSpanFull()
                    // Nửa "cho người dùng thấy" của luật SPEC §4.10 ("tối thiểu 20 ký tự"); nửa
                    // gác cổng thật vẫn ở `ReviewChecklistItem`, đếm bằng `mb_strlen`. Hai nửa
                    // có thể lệch nhau ở tiếng Việt nhiều byte — `minLength()` của Laravel cũng
                    // đếm ký tự trên chuỗi UTF-8, nên hôm nay chúng khớp; lời từ chối của Action
                    // vẫn tới được người dùng qua `runAction()` nếu một ngày chúng lệch.
                    ->required()
                    ->minLength(20),
                // R11 (M6.5 Task 17, checklist-04) — xem docblock `self::documentsSchema()`.
                ...$this->documentsSchema(),
            ])
            /**
             * Fix round 1 (finding Important 2). Bản trước hứa VÔ ĐIỀU KIỆN "một email đã được
             * gửi báo khách về việc này" — SAI khi khách không có tài khoản portal đủ điều kiện
             * (chưa có tài khoản, chưa kích hoạt, hoặc bị khoá) HAY khi vụ việc đang tắt công tắc
             * portal (`is_published_to_portal`, chính lỗ hổng Critical 1 vừa lấp ở
             * `NotifyClientOfChecklistItemRejected`): `handle()` sẽ trả về `0`, không gửi gì,
             * nhưng nhân sự đã tin lời hứa cũ và không tự liên hệ khách bằng kênh khác — đúng lớp
             * lời hứa sai mà `CopyPromisesTest` tồn tại để chặn.
             *
             * Fix round 2: cùng lớp lỗi, hai chỗ fix round 1 còn sót — vụ việc ĐÃ ĐÓNG (finding 1:
             * `handle()` dừng ở `open()`, toast vẫn hứa email), và câu "không email" nói lý do
             * hiện trên cổng cả khi vụ ẩn khỏi cổng (finding 2). Giờ ba câu, chọn ở
             * {@see self::rejectionNoticeCopy()}.
             */
            ->successNotificationTitle(fn (MatterChecklistItem $record): string => static::rejectionNoticeCopy(
                $record,
                emailed: 'checklist.tab.actions.reject_success',
                portalOnly: 'checklist.tab.actions.reject_success_no_notice',
                hidden: 'checklist.tab.actions.reject_success_portal_hidden',
            ))
            ->action(fn (Action $action, MatterChecklistItem $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReviewChecklistItem::class)->handle(
                    checklistItem: $record,
                    actor: Auth::user(),
                    decision: ChecklistItemStatus::Rejected,
                    rejectionReason: $data['rejection_reason'] ?? null,
                    documentIds: $data['document_ids'] ?? [],
                ),
                'rejection_reason',
            ));
    }

    private function markNotApplicableAction(): Action
    {
        return Action::make('markNotApplicable')
            ->label(__('checklist.tab.actions.not_applicable'))
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('checklist.tab.actions.not_applicable_heading'))
            ->modalDescription(__('checklist.tab.actions.not_applicable_description'))
            ->authorize(fn (MatterChecklistItem $record): bool => Gate::allows('review', $record))
            ->visible(fn (MatterChecklistItem $record): bool => $record->status !== ChecklistItemStatus::PendingReview)
            ->successNotificationTitle(__('checklist.tab.actions.not_applicable_success'))
            ->action(fn (Action $action, MatterChecklistItem $record) => $this->runAction(
                $action,
                fn () => app(MarkChecklistItemNotApplicable::class)->handle(
                    checklistItem: $record,
                    actor: Auth::user(),
                ),
            ));
    }

    /**
     * Cổng trạng thái của nhánh từ chối.
     *
     * **Đây là một BẢN SAO của danh sách trong `ReviewChecklistItem::guardDecisionAgainstState()`,
     * không phải cùng một danh sách** — danh sách kia là một biến cục bộ `private`, màn hình không
     * đọc được. Bản sao này chỉ quyết định cái nút có hiện hay không; cổng thật vẫn là Action, và
     * nếu hai bên lệch nhau thì hậu quả là một cái nút dẫn tới một lời từ chối (`runAction()` hiện
     * nó ra bằng tiếng Việt), không phải một lần từ chối bị bỏ qua. Được ghim bởi
     * `it('offers accept from every state but offers reject only when something is waiting')`,
     * chạy qua cả năm trạng thái.
     */
    public static function hasSomethingToReject(MatterChecklistItem $item): bool
    {
        return ! in_array(
            $item->status,
            [ChecklistItemStatus::Missing, ChecklistItemStatus::NotApplicable],
            true,
        );
    }

    /**
     * "Khách sẽ biết về lần từ chối này bằng đường nào" — chọn MỘT trong ba khoá câu chữ cho toast
     * và helper text của nút "Cần nộp lại":
     *
     *  - `$emailed`: thư `client.document_rejected` sẽ đi (vụ còn mở, bật công bố portal, khách có
     *    tài khoản đã kích hoạt) — và lý do hiện trên cổng.
     *  - `$portalOnly`: không thư (vụ đã đóng, hoặc khách chưa có tài khoản đã kích hoạt), nhưng lý
     *    do VẪN hiện trên cổng khách hàng.
     *  - `$hidden`: không thư, VÀ cổng giấu cả vụ lẫn lý do (tắt công bố portal, khách hàng đã
     *    xoá) — luật sư phải tự liên hệ khách.
     *
     * Hai câu hỏi đều gọi thẳng `NotifyClientOfChecklistItemRejected`
     * ({@see NotifyClientOfChecklistItemRejected::hasEligibleRecipient()},
     * {@see NotifyClientOfChecklistItemRejected::isShownOnPortal()}) — KHÔNG chép điều kiện nào ra
     * đây, cùng lý do `BuildsStageUpdateSchema::noActivatedAccountWarning()` dùng lại
     * `NotifyClientOfStageUpdate::hasEligibleRecipient()`: câu trên màn hình không bao giờ được
     * phép lệch với chính Action gửi thư, hay với chính cổng khách hàng. Truyền `$item` (không
     * `$item->matter`): Action tự đọc vụ việc theo `matter_id`, nên một quan hệ `null` (vụ đã xoá
     * mềm) không thể thành lỗi kiểu ở đây.
     */
    private static function rejectionNoticeCopy(MatterChecklistItem $item, string $emailed, string $portalOnly, string $hidden): string
    {
        $notifier = app(NotifyClientOfChecklistItemRejected::class);

        return __(match (true) {
            $notifier->hasEligibleRecipient($item) => $emailed,
            $notifier->isShownOnPortal($item) => $portalOnly,
            default => $hidden,
        });
    }
}
