<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Document\MarkChecklistItemNotApplicable;
use App\Actions\Document\ReviewChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions as SchemaActions;
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
 * Tab "Danh mục hồ sơ" (SPEC §7.2): bảng `matter_checklist_items` kèm thanh tiến độ `X/Y` và ba
 * thao tác ngay trên dòng — duyệt, từ chối (có ba mẫu lý do bấm một cái là điền, SPEC §6.7), và
 * đánh dấu không cần nộp (SPEC §4.10).
 *
 * **Lớp này không có một dòng nghiệp vụ nào.** Mọi lần ghi đi qua `ReviewChecklistItem` hoặc
 * `MarkChecklistItemNotApplicable` (CLAUDE.md: nghiệp vụ chỉ ở `app/Actions/`). Những gì ở đây
 * là: cột nào hiện, nút nào hiện cho ai, và một lời từ chối của Action đến được mắt người dùng
 * bằng tiếng Việt thay vì thành trang 500 — xem `ReportsActionFailures`.
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
 * `AttachAction`, …); mọi thứ khác rơi vào `default => null`. Cả ba thao tác ở đây là
 * `Filament\Actions\Action` thuần, nên phương thức đó không đổi được gì — một mutation probe
 * đặt nó thành `true` đã để cả bộ test xanh. Nếu một ngày có ai thêm `DeleteAction` vào bảng này
 * thì mới cần tắt nó, và lúc đó nó sẽ có test đi kèm.
 */
class ChecklistRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    protected static string $relationship = 'checklistItems';

    /**
     * Bí danh của bộ đếm tài liệu gắn vào một đầu mục, KHÔNG kể nhóm D — xem
     * {@see self::progressFor()} cho lý do nhóm D bị loại khỏi phép đếm "đã có tài liệu".
     */
    private const DOCUMENT_COUNT_ALIAS = 'client_facing_documents_count';

    /**
     * Hai trạng thái được tính là "đã xong" ở tử số `X`. `not_applicable` nằm cùng hạng với
     * `accepted` vì cả hai đều trả lời "văn phòng không còn chờ gì ở đầu mục này" — thứ duy nhất
     * thanh tiến độ nói.
     *
     * @var list<ChecklistItemStatus>
     */
    private const SETTLED_STATUSES = [ChecklistItemStatus::Accepted, ChecklistItemStatus::NotApplicable];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.checklist');
    }

    /**
     * Thanh tiến độ `X/Y` của SPEC §7.2, tính theo SPEC §4.10 — và cách đọc đó đã phải sửa một
     * lần, nên nó được viết ra đầy đủ ở đây.
     *
     * SPEC §4.10 định nghĩa `Y` là "số item `is_required = true` cộng số item không bắt buộc
     * nhưng đã có tài liệu". Đó là một TẬP HỢP các dòng cụ thể, không phải một con số rời — và
     * `X` phải đếm BÊN TRONG tập đó. Bản kế hoạch đầu viết "`X` = số đầu mục có `status` thuộc
     * {`accepted`, `not_applicable`}, không cần nhìn tới bảng `documents` chút nào", và câu đó
     * cho ra một thanh tiến độ LỚN HƠN MẪU SỐ trên chính dữ liệu mẫu: `MatterSeeder` đánh dấu
     * mọi đầu mục KHÔNG bắt buộc là `not_applicable` và không gắn tài liệu nào — đúng nghĩa của
     * trạng thái đó — nên những dòng ấy nằm trong tử số mà không nằm trong mẫu số. Một vụ `DS`
     * với 3 mục bắt buộc và 2 mục không bắt buộc hiện ra "Đã nộp 5/3", trên đúng con số mà M5 sẽ
     * đưa lên thẻ hồ sơ của chính khách hàng.
     *
     * Seeder KHÔNG sai và không phải sửa: một đầu mục không bắt buộc, không tài liệu, được đánh
     * dấu "không cần nộp" thì đơn giản là không xuất hiện trên thanh tiến độ. Đó cũng là thứ
     * khách cần thấy.
     *
     * **Nhóm D bị loại khỏi vế "đã có tài liệu".** Một tài liệu nhóm D (hồ sơ công việc nội bộ)
     * GẮN ĐƯỢC vào một đầu mục danh mục và đó là việc hợp lệ — một ghi chú nội bộ về đúng giấy tờ
     * đó. Nếu nó được tính là "đầu mục này đã có tài liệu" thì một ghi chú công việc của văn
     * phòng tự kéo một đầu mục không bắt buộc vào mẫu số, tức là tự thêm một việc vào danh sách
     * khách phải làm. Điều kiện của `X` chỉ đọc cột `status` (thứ mà `UploadStaffDocument`,
     * `ReviewChecklistItem` và `MarkChecklistItemNotApplicable` ghi) và không hỏi bảng
     * `documents` một câu nào — nhưng `X` VẪN phụ thuộc vào bảng đó, vì nó chỉ chạy trên các dòng
     * đã nằm trong `Y`. Nói cho đúng như vậy: chỗ duy nhất `documents` được hỏi là định nghĩa của
     * `Y`, và nó phải được hỏi ở đó, vì chính SPEC §4.10 định nghĩa `Y` bằng chữ "đã có tài liệu".
     *
     * `withCount` áp global scope của `Document`, nên một tài liệu đã xoá mềm không còn đếm là
     * "đã có tài liệu" — đúng: dòng đó không còn trong hồ sơ.
     *
     * @return array{submitted: int, total: int}
     */
    public static function progressFor(Matter $matter): array
    {
        $items = $matter->checklistItems()
            ->withCount([
                'documents as '.self::DOCUMENT_COUNT_ALIAS => fn (Builder $query): Builder => $query
                    ->where('group', '!=', DocumentGroup::Internal->value),
            ])
            ->get();

        $counted = $items->filter(fn (MatterChecklistItem $item): bool => $item->is_required
            || ($item->{self::DOCUMENT_COUNT_ALIAS} ?? 0) > 0);

        return [
            'submitted' => $counted
                ->filter(fn (MatterChecklistItem $item): bool => in_array($item->status, self::SETTLED_STATUSES, true))
                ->count(),
            'total' => $counted->count(),
        ];
    }

    /**
     * Thanh tiến độ như nó hiện ra. Tách static để test được mà không dựng cả bảng, cùng thành
     * ngữ với `StageLogsRelationManager::renderInternalNote()`.
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
     * thanh nào. Màu lấy từ biến CSS của Filament (`--primary-500`) và từ `currentColor` pha
     * loãng, nên nó đúng ở cả chế độ sáng lẫn tối mà không cần hai luật.
     */
    public static function progressBar(Matter $matter): Htmlable
    {
        ['submitted' => $submitted, 'total' => $total] = static::progressFor($matter);

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
                TextColumn::make(self::DOCUMENT_COUNT_ALIAS)
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
            ->recordActions([
                $this->acceptAction(),
                $this->rejectAction(),
                $this->markNotApplicableAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->withCount([
                    'documents as '.self::DOCUMENT_COUNT_ALIAS => fn (Builder $documents): Builder => $documents
                        ->where('group', '!=', DocumentGroup::Internal->value),
                ])
                ->with('reviewer'));
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
            ->successNotificationTitle(__('checklist.tab.actions.accept_success'))
            ->action(fn (Action $action, MatterChecklistItem $record) => $this->runAction(
                $action,
                fn () => app(ReviewChecklistItem::class)->handle(
                    checklistItem: $record,
                    actor: Auth::user(),
                    decision: ChecklistItemStatus::Accepted,
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
                SchemaActions::make(collect(static::rejectionTemplates())
                    ->map(fn (string $label, string $key): Action => Action::make('fill_'.$key)
                        ->label($label)
                        ->link()
                        ->action(fn (Set $set) => $set('rejection_reason', __('checklist.rejection_templates.'.$key))))
                    ->values()
                    ->all())
                    ->key('rejection_templates')
                    ->label(__('checklist.tab.fields.templates')),
                Textarea::make('rejection_reason')
                    ->label(__('checklist.tab.fields.rejection_reason'))
                    ->helperText(__('checklist.tab.fields.rejection_reason_help'))
                    ->rows(4)
                    ->columnSpanFull()
                    // Nửa "cho người dùng thấy" của luật SPEC §4.10 ("tối thiểu 20 ký tự"); nửa
                    // gác cổng thật vẫn ở `ReviewChecklistItem`, đếm bằng `mb_strlen`. Hai nửa
                    // có thể lệch nhau ở tiếng Việt nhiều byte — `minLength()` của Laravel cũng
                    // đếm ký tự trên chuỗi UTF-8, nên hôm nay chúng khớp; lời từ chối của Action
                    // vẫn tới được người dùng qua `runAction()` nếu một ngày chúng lệch.
                    ->required()
                    ->minLength(20),
            ])
            ->successNotificationTitle(__('checklist.tab.actions.reject_success'))
            ->action(fn (Action $action, MatterChecklistItem $record, array $data) => $this->runAction(
                $action,
                fn () => app(ReviewChecklistItem::class)->handle(
                    checklistItem: $record,
                    actor: Auth::user(),
                    decision: ChecklistItemStatus::Rejected,
                    rejectionReason: $data['rejection_reason'] ?? null,
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
}
