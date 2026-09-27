<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\AmendContract;
use App\Actions\Billing\CancelContract;
use App\Actions\Billing\CompleteContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\UpdateDraftContract;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Enums\ChecklistItemStatus;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\PaymentMethod;
use App\Exceptions\PaymentAlreadyVoided;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use App\Support\Billing\Vat;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Tab "Hợp đồng và thanh toán" trên trang vụ việc (M9 Task 7; SPEC §7.2 đính chính M9 Task 3):
 * người dùng là luật sư trên vụ của mình, quản lý, admin — tức người mở được `ViewMatter`. **Kế
 * toán không mở được trang vụ việc** (`MatterPolicy::view` đòi `matter.view`), nên màn hình của
 * kế toán là trang "Công nợ" (Task 8), không phải tab này.
 *
 * **Lớp này không có một dòng nghiệp vụ nào** (CLAUDE.md): chín Action của Task 4/5/7 —
 * {@see DraftContract}, {@see UpdateDraftContract}, {@see ActivateContract}, {@see AmendContract},
 * {@see CompleteContract}, {@see CancelContract}, {@see RecordPayment}, {@see WaiveInstalment},
 * {@see VoidPayment} — là nơi DUY NHẤT ghi. Mọi `DomainException`/`ValidationException`/
 * `AuthorizationException` của chúng đi ra qua {@see ReportsActionFailures}, đúng thành ngữ
 * {@see DocumentsRelationManager}/{@see DeadlinesRelationManager}.
 *
 * # Cổng của cả TAB — {@see self::canViewForRecord()}, KHÔNG dùng bản mặc định của Filament
 *
 * `RelationManager::canViewForRecord()` mặc định hỏi `Gate::allows('viewAny', $modelClass)` —
 * KHÔNG NGỮ CẢNH. Với bốn policy tiền, `viewAny` không ngữ cảnh chỉ hỏi `billing.view` (câu trả
 * lời cho "người này có màn hình tiền nào không", xem docblock `ChecksBillingAccess::
 * canListBilling()`) — nên bản mặc định sẽ cho một admin thấy tab tiền trên MỘT VỤ VIỆC ĐÃ XOÁ
 * MỀM, vì `viewAny` không ngữ cảnh không hề đọc `deleted_at`/`confidentiality` của vụ nào cả. Ở
 * đây hỏi LẠI, có ngữ cảnh: `Gate::allows('viewAny', [Contract::class, $ownerRecord])` — đúng
 * định nghĩa DUY NHẤT "ai thấy tiền của vụ nào" ({@see ChecksBillingAccess}),
 * và nó từ chối một vụ đã xoá mềm dù người hỏi là admin.
 *
 * # Cơ sở của bảng KHÔNG phải một quan hệ `hasMany` của `Matter`
 *
 * `Matter::contract()` là `HasOne`; nội dung của tab là LỊCH THU (`instalments`) của hợp đồng đó,
 * không phải chính hợp đồng. {@see self::getRelationship()} vì vậy override thẳng, trả một
 * `HasManyThrough` dựng TAY (matter → contracts → instalments, qua `matter_id`/`contract_id`) —
 * không đặt `static::$relationship`, vì lớp này cũng override {@see self::canViewForRecord()} nên
 * không nơi nào khác trong `RelationManager` còn cần đọc `getRelationshipName()` (đối chiếu
 * `vendor/filament/filament/…/RelationManager.php`: hai nơi DUY NHẤT gọi nó là `getRelationship()`
 * mặc định — bị override — và `canViewForRecord()` mặc định — cũng bị override).
 *
 * **Không phải một `Builder<Instalment>` trần, và đó là một phép đo, không phải một lựa chọn
 * phong cách.** Bản đầu trả thẳng `Instalment::query()->where('contract_id', ...)` — hợp lệ theo
 * khai báo kiểu `Relation|Builder` của `getRelationship()`, nhưng vendor
 * `HasQuery::getRelationshipQuery()` gọi THẲNG `$this->getRelationship()->getQuery()` và giả định
 * kết quả là một `Relation`: gọi `->getQuery()` trên một `Builder` lột thêm một lớp, trả về
 * `Illuminate\Database\Query\Builder` (query builder THÔ của chính Eloquent builder đó) — vỡ khai
 * báo kiểu `?Builder` của framework, `ViewException` ngay khi vẽ bảng. Đo được bằng test, không
 * suy ra được từ đọc chữ ký hàm. `HasManyThrough` là một `Relation` thật, tự nhiên rỗng khi vụ
 * chưa có hợp đồng (không cần một hợp đồng "giả" nào để dò `where`).
 *
 * `ScopesToVisibleMatters` vẫn được gọi trên truy vấn bảng (qua `contract.matter`) làm lớp phòng
 * thủ thứ hai, cùng thành ngữ mọi relation manager khác — dù cổng thật của TAB đã là
 * `canViewForRecord()` và cơ sở quan hệ đã tự giới hạn đúng một vụ việc.
 *
 * # Ba nút ghi trên mỗi dòng, một cụm nút trên đầu bảng cho hợp đồng
 *
 * Đầu bảng: soạn hợp đồng ({@see self::draftContractAction()}, khi vụ chưa có hợp đồng), sửa bản
 * nháp ({@see self::updateDraftContractAction()}), kích hoạt, ký phụ lục, hoàn tất, huỷ — tất cả
 * qua `ContractPolicy::update` (hay `::create` cho soạn mới), thành ngữ `->authorize()` của
 * {@see TeamRelationManager}/{@see DeadlinesRelationManager}.
 *
 * Mỗi dòng (một đợt thanh toán): ghi khoản thu, miễn, huỷ khoản thu gần nhất. **Nút ghi khoản thu
 * hỏi quyền kèm NGỮ CẢNH VỤ VIỆC**, đúng thành ngữ tab Tài liệu (M4): `->authorize(fn () =>
 * Gate::allows('create', [Payment::class, $this->getOwnerRecord()]))` — không phải
 * `Gate::allows('update', $this->getOwnerRecord())` như tab Các bên, vì câu hỏi ở đây là
 * `payment.record` (kế toán/admin, cộng luật sư phụ trách của vụ `restricted`), một trục khác hẳn
 * `contract.manage`.
 *
 * # Ba màu có nghĩa, luôn kèm chữ
 *
 * Cột trạng thái là một `badge` Filament: xanh (`success`) đã thu, vàng (`warning`) đang chờ (kể
 * cả `scheduled`/`due`/`partially_paid`), đỏ (`danger`) quá hạn, xám (`gray`) đã miễn/đã huỷ. Badge
 * luôn mang nhãn chữ của {@see InstalmentState::label()} — không có ô màu nào đứng một mình.
 *
 * # Ô tiền
 *
 * Mọi ô tiền (`total_amount`, `new_total_amount`, `amount` của một đợt hay một khoản thu) là
 * `TextInput` nhận chuỗi có dấu chấm phân nhóm nghìn, được đưa qua {@see Money::parse()}
 * NGAY TRONG closure gọi Action — bên trong {@see ReportsActionFailures::runAction()} — nên một
 * chuỗi hỏng ra lỗi gắn đúng ô, đúng field path Filament (`instalments.0.amount`,
 * `instalment_changes.0.amount`), không khác gì lỗi mà chính Action ném ra. `maxLength(15)` khớp
 * `"999.999.999.999"` (`Money::MAX` đã định dạng) — trần thật vẫn là hằng số đó, dùng chung cho
 * form và Action.
 *
 * # Gợi ý đầu mục danh mục — {@see self::checklistNudge()}
 *
 * Sau khi hợp đồng rời `draft` (đã kích hoạt ít nhất một lần), nếu đầu mục bắt buộc "Hợp đồng dịch
 * vụ pháp lý và giấy uỷ quyền" (SPEC §4.9) còn ở trạng thái `missing`, tab hiện một dòng nhắc tải
 * bản đã ký lên đúng đầu mục đó ở tab Tài liệu. **Không** tự đánh dấu đầu mục — nó nhận diện CHỈ
 * bằng tên (SPEC không cho một cách khác), và việc đánh dấu "đã nhận" là việc của
 * `ReviewChecklistItem` (M4), không phải của tab này.
 *
 * # Dải cảnh báo hồ sơ đã kết thúc còn công nợ
 *
 * `$matter->closed_at !== null` (M6.5 R8 — "đã kết thúc" là `closed_at`, không phải một cột
 * `is_terminal`) VÀ {@see BillingSummary::hasOutstandingBalance()} — đúng
 * quyết định của kế hoạch M9 ("Tiền trên một vụ việc đã đóng…"): KHÔNG chặn gì, chỉ phải NHÌN
 * THẤY. Nợ vẫn tính cho tới khi thu xong hoặc miễn tường minh.
 *
 * # N+1 có chủ đích, có giới hạn
 *
 * `Instalment::outstanding()` chạy một truy vấn mỗi dòng — chấp nhận được trên một bảng của MỘT
 * vụ việc (kế hoạch M9 Task 7, "Do not call BillingSummary per row repeatedly"). Ba con số TỔNG
 * (đã thu / còn phải thu / quá hạn) ở đầu bảng tính MỘT LẦN qua
 * {@see BillingSummary} cộng hai truy vấn tổng hợp riêng — không lặp lại
 * `outstanding()` của từng dòng để cộng dồn.
 */
class BillingRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    /** SPEC §4.9 — nhận diện CHỈ bằng tên, xem docblock lớp. */
    public const REQUIRED_CHECKLIST_ITEM_NAME = 'Hợp đồng dịch vụ pháp lý và giấy uỷ quyền';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('billing.tab.title');
    }

    /** Cổng của cả TAB — xem docblock lớp. Không gọi `parent::canViewForRecord()`. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', [Contract::class, $ownerRecord]);
    }

    /**
     * `HasQuery::getRelationshipQuery()` (vendor) gọi thẳng `$this->getRelationship()->getQuery()`
     * và giả định kết quả là một `Relation` — trả một `Illuminate\Database\Eloquent\Builder` trần
     * ở đây (như bản đầu của lớp này làm) khiến `->getQuery()` lột thêm một lớp và trả về
     * `Illuminate\Database\Query\Builder` (query builder THÔ của CHÍNH cái Eloquent builder đó),
     * vỡ ngay khai báo kiểu `?Builder` của framework — `ViewException` khi vẽ bảng, đo được bằng
     * test. Phải là một `Relation` THẬT.
     *
     * `Contract::instalments()` là `HasMany` thật, nhưng nó cần một `Contract` — vụ chưa có hợp
     * đồng thì không có gì để gọi nó lên. `HasManyThrough` dựng THẲNG ở đây (matter → contracts →
     * instalments, qua `matter_id`/`contract_id`) giải quyết cả hai: có contract hay không, quan
     * hệ luôn là một `Relation` thật, và khi chưa có hợp đồng nó tự nhiên rỗng (không hợp đồng nào
     * của vụ này) — không cần một hợp đồng "giả" nào để dò where. Không thêm quan hệ nào vào
     * `Matter`/`Contract` (ngoài phạm vi tệp của Task 7).
     */
    public function getRelationship(): Relation
    {
        $matter = $this->getOwnerRecord();

        return (new HasManyThrough(
            Instalment::query(),
            $matter,
            new Contract,
            'matter_id',
            'contract_id',
            'id',
            'id',
        ))->orderBy('sequence');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description(fn (): HtmlString => static::summaryBlock($this->getOwnerRecord()))
            ->emptyStateHeading(fn (): string => $this->getOwnerRecord()->contract === null
                ? __('billing.tab.no_contract')
                : __('billing.tab.no_instalments'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('billing.tab.columns.name'))
                    ->wrap(),
                TextColumn::make('trigger')
                    ->label(__('billing.tab.columns.trigger'))
                    ->state(fn (Instalment $record): string => static::triggerLabel($record)),
                TextColumn::make('due_date')
                    ->label(__('billing.tab.columns.due_date'))
                    ->date('d/m/Y')
                    ->placeholder(__('billing.tab.due_date_scheduled')),
                TextColumn::make('amount')
                    ->label(__('billing.tab.columns.amount'))
                    ->state(fn (Instalment $record): string => Money::format($record->amount)),
                TextColumn::make('collected')
                    ->label(__('billing.tab.columns.collected'))
                    ->state(fn (Instalment $record): string => Money::format(
                        (int) $record->payments()->whereNull('voided_at')->sum('amount')
                    )),
                TextColumn::make('outstanding')
                    ->label(__('billing.tab.columns.outstanding'))
                    ->state(fn (Instalment $record): string => Money::format($record->outstanding())),
                TextColumn::make('state')
                    ->label(__('billing.tab.columns.state'))
                    ->badge()
                    ->state(fn (Instalment $record): InstalmentState => $record->state())
                    ->formatStateUsing(fn (InstalmentState $state): string => $state->label())
                    ->color(fn (InstalmentState $state): string => static::stateColor($state)),
                TextColumn::make('payments_list')
                    ->label(__('billing.tab.columns.payments'))
                    ->html()
                    ->wrap()
                    ->state(fn (Instalment $record): HtmlString => static::paymentsBlock($record)),
            ])
            ->headerActions([
                $this->draftContractAction(),
                $this->updateDraftContractAction(),
                $this->activateContractAction(),
                $this->amendContractAction(),
                $this->completeContractAction(),
                $this->cancelContractAction(),
            ])
            ->recordActions([
                $this->recordPaymentAction(),
                $this->waiveInstalmentAction(),
                $this->voidPaymentAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query, 'contract.matter')
                ->with(['payments' => fn ($payments) => $payments->with('attributedLawyer')]));
    }

    // =============================================================================================
    // Nội dung đầu bảng — hợp đồng, VAT, dải cảnh báo, gợi ý danh mục, phụ lục, ba con số tổng.
    // =============================================================================================

    public static function summaryBlock(Matter $matter): HtmlString
    {
        $contract = $matter->contract;

        if ($contract === null) {
            return new HtmlString('<p>'.e(__('billing.tab.no_contract')).'</p>');
        }

        $lines = [];
        $lines[] = sprintf(
            '<p><strong>%s</strong> — %s: %s</p>',
            e($contract->code),
            e(__('billing.tab.summary.status')),
            e($contract->status->label()),
        );
        $lines[] = static::amountsLine($contract);
        $lines[] = static::totalsLine($matter, $contract);

        if ($matter->closed_at !== null && BillingSummary::hasOutstandingBalance($matter->id)) {
            $lines[] = sprintf(
                '<p style="color:var(--danger-600);font-weight:600">%s</p>',
                e(__('billing.tab.closed_with_balance_warning')),
            );
        }

        $nudge = static::checklistNudge($matter, $contract);

        if ($nudge !== null) {
            $lines[] = sprintf('<p style="color:var(--warning-600);font-weight:600">%s</p>', e($nudge));
        }

        $lines[] = static::amendmentsBlock($contract);

        return new HtmlString(implode('', $lines));
    }

    private static function amountsLine(Contract $contract): string
    {
        $vatRate = $contract->vat_rate_percent;
        $vatText = $vatRate === null
            ? __('billing.tab.summary.vat_none')
            : __('billing.tab.summary.vat_rate_value', ['rate' => $vatRate]);

        return sprintf(
            '<p>%s: %s — %s: %s — %s: %s — %s: %s</p>',
            e(__('billing.tab.summary.total_amount')), e(Money::format($contract->total_amount)),
            e(__('billing.tab.summary.vat_rate')), e($vatText),
            e(__('billing.tab.summary.vat_tax')), e(Money::format(Vat::tax($contract->total_amount, $vatRate))),
            e(__('billing.tab.summary.vat_net')), e(Money::format(Vat::net($contract->total_amount, $vatRate))),
        );
    }

    private static function totalsLine(Matter $matter, Contract $contract): string
    {
        $collected = (int) Payment::query()
            ->whereHas('instalment', fn (Builder $query) => $query->where('contract_id', $contract->id))
            ->whereNull('voided_at')
            ->sum('amount');

        $outstanding = BillingSummary::outstandingForMatter($matter->id)['amount'];

        $overdue = (int) Instalment::query()
            ->where('contract_id', $contract->id)
            ->overdue()
            ->get()
            ->sum(fn (Instalment $instalment): int => $instalment->outstanding());

        return sprintf(
            '<p style="font-weight:600">%s: <span style="color:var(--success-600)">%s</span> — %s: <span style="color:var(--warning-600)">%s</span> — %s: <span style="color:var(--danger-600)">%s</span></p>',
            e(__('billing.tab.totals.collected')), e(Money::format($collected)),
            e(__('billing.tab.totals.outstanding')), e(Money::format($outstanding)),
            e(__('billing.tab.totals.overdue')), e(Money::format($overdue)),
        );
    }

    /** `null` khi hợp đồng còn `draft` (chưa từng kích hoạt) hoặc đầu mục không còn thiếu. */
    private static function checklistNudge(Matter $matter, Contract $contract): ?string
    {
        if ($contract->status === ContractStatus::Draft) {
            return null;
        }

        $item = $matter->checklistItems()->where('name', self::REQUIRED_CHECKLIST_ITEM_NAME)->first();

        if ($item === null || $item->status !== ChecklistItemStatus::Missing) {
            return null;
        }

        return __('billing.tab.checklist_nudge');
    }

    private static function amendmentsBlock(Contract $contract): string
    {
        $amendments = $contract->amendments()->get();

        if ($amendments->isEmpty()) {
            return '';
        }

        $items = $amendments->map(fn (ContractAmendment $amendment): string => sprintf(
            '<li>%s</li>',
            e(__('billing.tab.amendments.line', [
                'sequence' => $amendment->sequence,
                'previous' => Money::format($amendment->previous_total_amount),
                'new' => Money::format($amendment->new_total_amount),
                'date' => $amendment->signed_at->format('d/m/Y'),
                'reason' => $amendment->reason,
            ])),
        ))->implode('');

        return sprintf(
            '<p style="font-weight:600">%s</p><ul>%s</ul>',
            e(__('billing.tab.amendments.heading')),
            $items,
        );
    }

    // =============================================================================================
    // Nội dung mỗi dòng.
    // =============================================================================================

    public static function triggerLabel(Instalment $instalment): string
    {
        return match ($instalment->trigger_type) {
            InstalmentTrigger::OnSigning => __('billing.tab.trigger.on_signing'),
            InstalmentTrigger::DueDate => __('billing.tab.trigger.due_date'),
            InstalmentTrigger::Stage => __('billing.tab.trigger.stage', [
                'stage' => $instalment->contract->matter->matterType->stage($instalment->trigger_stage_key)?->label
                    ?? $instalment->trigger_stage_key,
            ]),
        };
    }

    public static function stateColor(InstalmentState $state): string
    {
        return match ($state) {
            InstalmentState::Paid => 'success',
            InstalmentState::Overdue => 'danger',
            InstalmentState::Waived, InstalmentState::Cancelled => 'gray',
            InstalmentState::Scheduled, InstalmentState::Due, InstalmentState::PartiallyPaid => 'warning',
        };
    }

    public static function paymentsBlock(Instalment $instalment): HtmlString
    {
        $payments = $instalment->relationLoaded('payments') ? $instalment->payments : $instalment->payments()->with('attributedLawyer')->get();

        if ($payments->isEmpty()) {
            return new HtmlString('<em>'.e(__('billing.tab.payments_empty')).'</em>');
        }

        $lines = $payments->map(function (Payment $payment): string {
            $key = $payment->voided_at !== null ? 'billing.tab.payment_line_voided' : 'billing.tab.payment_line';

            return e(__($key, [
                'date' => $payment->paid_on->format('d/m/Y'),
                'amount' => Money::format($payment->amount),
                'method' => $payment->method->label(),
                'lawyer' => $payment->attributedLawyer?->name ?? '—',
                'reason' => $payment->void_reason ?? '',
            ]));
        })->implode('<br/>');

        return new HtmlString($lines);
    }

    // =============================================================================================
    // Hành động đầu bảng — vòng đời hợp đồng (Task 4).
    // =============================================================================================

    /**
     * Soạn hợp đồng cho vụ chưa có hợp đồng nào. Chỉ `fixed_fee` — không có ô chọn cách tính phí,
     * vì M9 chỉ cài đặt một loại đó ({@see BillingModelNotSupported} vì vậy không tới được từ màn
     * hình này, chỉ từ dữ liệu ghi thẳng).
     */
    private function draftContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('draftContract')
            ->label(__('billing.tab.actions.draft'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading(__('billing.tab.actions.draft_heading'))
            ->visible(fn (): bool => $matter->contract === null)
            ->authorize(fn (): bool => Gate::allows('create', [Contract::class, $matter]))
            ->schema(static::contractScheduleFields())
            ->successNotificationTitle(__('billing.tab.actions.draft_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(DraftContract::class)->handle(
                    Auth::user(),
                    $matter,
                    ['total_amount' => Money::parse((string) ($data['total_amount'] ?? ''), 'total_amount'), 'vat_rate_percent' => static::intOrNull($data['vat_rate_percent'] ?? null)],
                    static::parsedInstalmentRows($data['instalments'] ?? [], 'instalments'),
                ),
            ));
    }

    private function updateDraftContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('updateDraftContract')
            ->label(__('billing.tab.actions.update_draft'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('billing.tab.actions.update_draft_heading'))
            ->visible(fn (): bool => $matter->contract?->status === ContractStatus::Draft)
            ->authorize(fn (): bool => $matter->contract !== null && Gate::allows('update', $matter->contract))
            ->schema(static::contractScheduleFields())
            ->fillForm(function () use ($matter): array {
                $contract = $matter->contract;

                return [
                    'total_amount' => $contract === null ? null : Money::format($contract->total_amount),
                    'vat_rate_percent' => $contract?->vat_rate_percent,
                    'instalments' => $contract === null ? [] : $contract->instalments->map(fn (Instalment $i): array => [
                        'name' => $i->name,
                        'amount' => Money::format($i->amount),
                        'percent_basis' => $i->percent_basis,
                        'trigger_type' => $i->trigger_type->value,
                        'trigger_stage_key' => $i->trigger_stage_key,
                        'due_date' => $i->due_date?->toDateString(),
                        'due_days_after_trigger' => $i->due_days_after_trigger,
                        'note' => $i->note,
                    ])->all(),
                ];
            })
            ->successNotificationTitle(__('billing.tab.actions.update_draft_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(UpdateDraftContract::class)->handle(
                    Auth::user(),
                    $matter->contract,
                    ['total_amount' => Money::parse((string) ($data['total_amount'] ?? ''), 'total_amount'), 'vat_rate_percent' => static::intOrNull($data['vat_rate_percent'] ?? null)],
                    static::parsedInstalmentRows($data['instalments'] ?? [], 'instalments'),
                ),
            ));
    }

    private function activateContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('activateContract')
            ->label(__('billing.tab.actions.activate'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->modalHeading(__('billing.tab.actions.activate_heading'))
            // Chỉ lọc CẤU TRÚC (có hợp đồng hay không), KHÔNG lọc theo trạng thái — cùng thành
            // ngữ `DocumentsRelationManager::publishAction()`: "trạng thái nào thì làm được việc
            // gì" là câu của CHÍNH ACTION (`ContractStatusConflict::notDraft`), không phải của
            // nút. **Đo được, không chỉ suy luận:** `->visible()` bị hỏi lại NGAY TRƯỚC LÚC CHẠY,
            // không chỉ lúc mount — `callMountedAction()` (vendor) từ chối khi
            // `$action->isDisabled()`, và `CanBeDisabled::isDisabled()` đọc thẳng `$this->
            // isHidden()`. Một nút lọc theo TRẠNG THÁI đang được test (hợp đồng đổi trạng thái
            // giữa lúc màn hình vẽ ra và lúc bấm — đúng race hai tab cùng mở) sẽ tự ẩn NGAY LÚC
            // BẤM, khiến `callMountedAction()` bỏ ngang TRƯỚC KHI chạm tới Action — không thông
            // báo, không lỗi, im lặng còn tệ hơn một lời từ chối. Điều kiện ở đây vì vậy chỉ được
            // đọc những gì KHÔNG đổi vì chính cái race đang thử (còn hợp đồng hay không), không
            // đọc trạng thái mà Action bên dưới đang giữ quyền từ chối.
            ->visible(fn (): bool => $matter->contract !== null)
            ->authorize(fn (): bool => $matter->contract !== null && Gate::allows('update', $matter->contract))
            ->schema([
                DatePicker::make('signed_at')
                    ->label(__('billing.tab.fields.signed_at'))
                    ->default(today()->toDateString())
                    ->native(false)
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.activate_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(ActivateContract::class)->handle(Auth::user(), $matter->contract, $data['signed_at'] ?? ''),
            ));
    }

    private function completeContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('completeContract')
            ->label(__('billing.tab.actions.complete'))
            ->icon(Heroicon::OutlinedFlag)
            ->requiresConfirmation()
            ->modalHeading(__('billing.tab.actions.complete_heading'))
            // Cấu trúc, không trạng thái — xem chú thích ở `activateContractAction()`.
            ->visible(fn (): bool => $matter->contract !== null)
            ->authorize(fn (): bool => $matter->contract !== null && Gate::allows('update', $matter->contract))
            ->successNotificationTitle(__('billing.tab.actions.complete_success'))
            ->action(fn (Action $action) => $this->runAction(
                $action,
                fn () => app(CompleteContract::class)->handle(Auth::user(), $matter->contract),
            ));
    }

    private function cancelContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('cancelContract')
            ->label(__('billing.tab.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(__('billing.tab.actions.cancel_heading'))
            // Cấu trúc, không trạng thái — xem chú thích ở `activateContractAction()`.
            ->visible(fn (): bool => $matter->contract !== null)
            ->authorize(fn (): bool => $matter->contract !== null && Gate::allows('update', $matter->contract))
            ->schema([
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.cancel_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(CancelContract::class)->handle(Auth::user(), $matter->contract, $data['reason'] ?? ''),
            ));
    }

    /**
     * Ký phụ lục — form rút gọn của `AmendContract::$instalmentChanges` (xem docblock Action đó):
     * mỗi dòng chọn một loại thay đổi (`add`/`update`/`cancel`) rồi hiện đúng những ô loại đó cần.
     */
    private function amendContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('amendContract')
            ->label(__('billing.tab.actions.amend'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->modalHeading(__('billing.tab.actions.amend_heading'))
            ->modalWidth('4xl')
            // Cấu trúc, không trạng thái — xem chú thích ở `activateContractAction()`.
            ->visible(fn (): bool => $matter->contract !== null)
            ->authorize(fn (): bool => $matter->contract !== null && Gate::allows('update', $matter->contract))
            ->schema([
                TextInput::make('new_total_amount')
                    ->label(__('billing.tab.fields.new_total_amount'))
                    ->helperText(__('billing.tab.fields.total_amount_help'))
                    ->maxLength(15)
                    ->required(),
                DatePicker::make('signed_at')
                    ->label(__('billing.tab.fields.signed_at'))
                    ->default(today()->toDateString())
                    ->native(false)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required()
                    ->columnSpanFull(),
                Repeater::make('instalment_changes')
                    ->label(__('billing.tab.amendments.heading'))
                    ->addActionLabel(__('billing.tab.actions.add_change'))
                    ->defaultItems(0)
                    ->columns(2)
                    ->schema([
                        Select::make('action')
                            ->label(__('billing.tab.fields.change_action'))
                            ->options([
                                'add' => __('billing.tab.fields.change_action_add'),
                                'update' => __('billing.tab.fields.change_action_update'),
                                'cancel' => __('billing.tab.fields.change_action_cancel'),
                            ])
                            ->live()
                            ->required(),
                        Select::make('instalment_id')
                            ->label(__('billing.tab.fields.target_instalment'))
                            ->options(fn (): array => $matter->contract === null ? [] : $matter->contract->instalments()
                                ->where('status', InstalmentStatus::Pending->value)
                                ->pluck('name', 'id')
                                ->all())
                            ->visible(fn (Get $get): bool => in_array($get('action'), ['update', 'cancel'], true)),
                        TextInput::make('name')
                            ->label(__('billing.tab.fields.instalment_name'))
                            ->maxLength(150)
                            ->visible(fn (Get $get): bool => $get('action') === 'add'),
                        TextInput::make('amount')
                            ->label(__('billing.tab.fields.instalment_amount'))
                            ->maxLength(15)
                            ->visible(fn (Get $get): bool => in_array($get('action'), ['add', 'update'], true)),
                        Select::make('trigger_type')
                            ->label(__('billing.tab.fields.trigger_type'))
                            ->options(static::triggerOptions())
                            ->live()
                            ->visible(fn (Get $get): bool => $get('action') === 'add'),
                        DatePicker::make('due_date')
                            ->label(__('billing.tab.fields.due_date'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('action') === 'add' && $get('trigger_type') === 'due_date'),
                        Select::make('trigger_stage_key')
                            ->label(__('billing.tab.fields.trigger_stage_key'))
                            ->options(fn (): array => static::stageOptions($matter))
                            ->visible(fn (Get $get): bool => $get('action') === 'add' && $get('trigger_type') === 'stage'),
                        TextInput::make('due_days_after_trigger')
                            ->label(__('billing.tab.fields.due_days_after_trigger'))
                            ->numeric()
                            ->default(0)
                            ->visible(fn (Get $get): bool => $get('action') === 'add' && $get('trigger_type') !== 'due_date' && $get('trigger_type') !== null),
                    ]),
            ])
            ->successNotificationTitle(__('billing.tab.actions.amend_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                fn () => app(AmendContract::class)->handle(
                    Auth::user(),
                    $matter->contract,
                    Money::parse((string) ($data['new_total_amount'] ?? ''), 'new_total_amount'),
                    static::parsedAmendmentChanges($data['instalment_changes'] ?? []),
                    $data['reason'] ?? '',
                    $data['signed_at'] ?? '',
                ),
            ));
    }

    // =============================================================================================
    // Hành động của mỗi dòng — khoản thu (Task 5).
    // =============================================================================================

    /**
     * **Cổng quyền hỏi kèm ngữ cảnh vụ việc**, thành ngữ tab Tài liệu (M4) — xem docblock lớp.
     */
    private function recordPaymentAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('recordPayment')
            ->label(__('billing.tab.actions.record_payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading(__('billing.tab.actions.record_payment_heading'))
            // Không lọc theo trạng thái đợt — `InstalmentNotPayable` là câu của chính
            // `RecordPayment` (xem chú thích `activateContractAction()`); nút chỉ hỏi QUYỀN.
            ->authorize(fn (): bool => Gate::allows('create', [Payment::class, $matter]))
            ->schema([
                TextInput::make('amount')
                    ->label(__('billing.tab.fields.amount'))
                    ->helperText(__('billing.tab.fields.total_amount_help'))
                    ->maxLength(15)
                    ->required(),
                DatePicker::make('paid_on')
                    ->label(__('billing.tab.fields.paid_on'))
                    ->default(today()->toDateString())
                    ->native(false)
                    ->required(),
                Select::make('method')
                    ->label(__('billing.tab.fields.method'))
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $m): array => [$m->value => $m->label()])->all())
                    ->default(PaymentMethod::BankTransfer->value)
                    ->required(),
                TextInput::make('reference')
                    ->label(__('billing.tab.fields.reference'))
                    ->maxLength(RecordPayment::MAX_REFERENCE_LENGTH),
                Select::make('receipt_document_id')
                    ->label(__('billing.tab.fields.receipt_document'))
                    ->options(fn (): array => static::internalDocumentOptions($matter)),
                Textarea::make('note')
                    ->label(__('billing.tab.fields.payment_note')),
            ])
            ->successNotificationTitle(__('billing.tab.actions.record_payment_success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                fn () => app(RecordPayment::class)->handle(
                    Auth::user(),
                    $record,
                    Money::parse((string) ($data['amount'] ?? ''), 'amount'),
                    $data['paid_on'] ?? '',
                    PaymentMethod::from($data['method']),
                    $data['reference'] ?? null,
                    static::resolveDocument($data['receipt_document_id'] ?? null),
                    $data['note'] ?? null,
                ),
            ));
    }

    private function waiveInstalmentAction(): Action
    {
        return Action::make('waiveInstalment')
            ->label(__('billing.tab.actions.waive'))
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('gray')
            ->modalHeading(__('billing.tab.actions.waive_heading'))
            // Không lọc theo trạng thái đợt — cùng lý do `recordPaymentAction()`.
            ->authorize(fn (Instalment $record): bool => Gate::allows('waive', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.waive_success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                fn () => app(WaiveInstalment::class)->handle(Auth::user(), $record, $data['reason'] ?? ''),
            ));
    }

    /**
     * Huỷ khoản thu GẦN NHẤT chưa huỷ của đợt này. Đơn giản hoá có chủ đích: một đợt CÓ THỂ có
     * nhiều khoản thu chưa huỷ (thu nhiều lần một phần), nhưng nút này không dựng một ô chọn giữa
     * chúng — huỷ khoản GẦN NHẤT trước, đúng thứ tự "sửa nhầm gần đây nhất" hay gặp. Muốn huỷ một
     * khoản cũ hơn thì huỷ tuần tự từ khoản gần nhất trở lên.
     */
    /**
     * Đợt CÓ THỂ có nhiều khoản thu chưa huỷ (thu nhiều lần một phần); nút này huỷ khoản GẦN NHẤT
     * trước — đơn giản hoá có chủ đích, xem docblock lớp.
     *
     * **`payment_id` chốt lúc MOUNT, không tính lại lúc bấm lưu.** Trường `Hidden` đọc "khoản gần
     * nhất chưa huỷ" một lần khi modal mở — và Action gọi đúng khoá đó khi submit, KỂ CẢ khi
     * khoản đó đã bị một người khác huỷ trong lúc modal đang mở. Đó chính là đường để
     * {@see PaymentAlreadyVoided} còn tới được màn hình: nếu ở đây lại đi hỏi
     * "khoản gần nhất chưa huỷ" một lần NỮA lúc submit, một khoản vừa bị huỷ sẽ biến mất khỏi câu
     * hỏi đó và không bao giờ chạm tới `VoidPayment`, chỉ dừng ở lời từ chối riêng của TAB (không
     * còn gì để chọn) — hai lời từ chối khác nhau cho cùng một tình huống đua.
     */
    private function voidPaymentAction(): Action
    {
        return Action::make('voidPayment')
            ->label(__('billing.tab.actions.void_payment'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(__('billing.tab.actions.void_payment_heading'))
            // **`->visible()` KHÔNG được phụ thuộc "có khoản CHƯA HUỶ nào không", và đây là một
            // phép đo, không phải một sở thích.** Khác mọi nút khác của tab (xem chú thích
            // `activateContractAction()`: "`->visible()` không phải cổng thật, chỉ ảnh hưởng lúc
            // MOUNT"), nút này ĐO ĐƯỢC là `->visible()` CÒN bị hỏi lại lúc CHẠY:
            // `callMountedAction()` (vendor) từ chối thẳng khi `$action->isDisabled()`, và
            // `CanBeDisabled::isDisabled()` đọc `$this->isHidden()` — tức đọc lại `->visible()`
            // NGAY TRƯỚC KHI gọi `action()`. Bản đầu hỏi "còn khoản chưa huỷ không" ở đây: một
            // khoản bị huỷ NGOÀI LUỒNG giữa lúc mở modal và lúc bấm lưu làm câu đó rớt xuống
            // `false` NGAY LÚC BẤM, `isDisabled()` thành `true`, và `callMountedAction()` BỎ NGANG
            // — không gọi tới `action()`, không thông báo, không lỗi, `PaymentAlreadyVoided`
            // không bao giờ tới nơi. Im lặng còn tệ hơn một lời từ chối, nên câu ở đây đổi thành
            // "đợt này có khoản thu nào không, huỷ hay chưa" — câu đó KHÔNG đổi vì lần huỷ ngoài
            // luồng đang được test. Đợt còn khoản NHƯNG đã huỷ hết thì nút vẫn hiện, và Action bên
            // dưới tự nói "không còn gì để huỷ" ({@see self::latestActivePayment()} trả `null`).
            ->visible(fn (Instalment $record): bool => $record->payments()->exists())
            // `PaymentPolicy::void()` không đọc `voided_at` (chỉ đọc vụ việc qua
            // `$payment->instalment->contract->matter`), nên hỏi trên một `Payment` CHƯA LƯU chỉ
            // mang quan hệ `instalment` cho cùng câu trả lời, không phụ thuộc một khoản cụ thể.
            ->authorize(fn (Instalment $record): bool => Gate::allows('void', (new Payment)->setRelation('instalment', $record)))
            ->schema([
                Hidden::make('payment_id')
                    ->default(fn (Instalment $record): ?int => static::latestActivePayment($record)?->id),
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.void_payment_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                function () use ($data): Payment {
                    $payment = Payment::query()->find($data['payment_id'] ?? null);

                    if ($payment === null) {
                        throw ValidationException::withMessages(['reason' => [__('billing.tab.no_payment_to_void')]]);
                    }

                    return app(VoidPayment::class)->handle(Auth::user(), $payment, $data['reason'] ?? '');
                },
            ));
    }

    private static function latestActivePayment(Instalment $instalment): ?Payment
    {
        return $instalment->payments()->whereNull('voided_at')->latest('paid_on')->latest('id')->first();
    }

    // =============================================================================================
    // Các hàm dựng ô nhập dùng chung.
    // =============================================================================================

    /** @return array<int, mixed> */
    private static function contractScheduleFields(): array
    {
        return [
            TextInput::make('total_amount')
                ->label(__('billing.tab.fields.total_amount'))
                ->helperText(__('billing.tab.fields.total_amount_help'))
                ->maxLength(15)
                ->required(),
            TextInput::make('vat_rate_percent')
                ->label(__('billing.tab.fields.vat_rate_percent'))
                ->helperText(__('billing.tab.fields.vat_rate_percent_help'))
                ->numeric(),
            Repeater::make('instalments')
                ->label(__('billing.tab.columns.name'))
                ->addActionLabel(__('billing.tab.actions.add_instalment'))
                ->defaultItems(1)
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('billing.tab.fields.instalment_name'))
                        ->helperText(__('billing.tab.fields.instalment_name_help'))
                        ->maxLength(150)
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('amount')
                        ->label(__('billing.tab.fields.instalment_amount'))
                        ->maxLength(15)
                        ->required(),
                    TextInput::make('percent_basis')
                        ->label(__('billing.tab.fields.instalment_percent_basis')),
                    Select::make('trigger_type')
                        ->label(__('billing.tab.fields.trigger_type'))
                        ->options(static::triggerOptions())
                        ->live()
                        ->required(),
                    DatePicker::make('due_date')
                        ->label(__('billing.tab.fields.due_date'))
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('trigger_type') === 'due_date'),
                    Select::make('trigger_stage_key')
                        ->label(__('billing.tab.fields.trigger_stage_key'))
                        ->options(fn (RelationManager $livewire): array => static::stageOptions($livewire->getOwnerRecord()))
                        ->visible(fn (Get $get): bool => $get('trigger_type') === 'stage'),
                    TextInput::make('due_days_after_trigger')
                        ->label(__('billing.tab.fields.due_days_after_trigger'))
                        ->numeric()
                        ->default(0)
                        ->visible(fn (Get $get): bool => $get('trigger_type') !== 'due_date' && $get('trigger_type') !== null),
                    Textarea::make('note')
                        ->label(__('billing.tab.fields.note'))
                        ->columnSpanFull(),
                ]),
        ];
    }

    /** @return array<string, string> */
    private static function triggerOptions(): array
    {
        return collect(InstalmentTrigger::cases())
            ->mapWithKeys(fn (InstalmentTrigger $trigger): array => [$trigger->value => $trigger->label()])
            ->all();
    }

    /** Giai đoạn của loại vụ việc, TRỪ giai đoạn đầu (không thể gắn đợt vào đó — xem `ValidatesBillingInput`). */
    private static function stageOptions(Matter $matter): array
    {
        $firstKey = $matter->matterType->firstStage()?->key;

        return $matter->matterType->stages()
            ->get()
            ->reject(fn ($stage): bool => $stage->key === $firstKey)
            ->pluck('label', 'key')
            ->all();
    }

    /** Tài liệu nội bộ (nhóm D) của ĐÚNG vụ này — bản scan biên lai/phụ lục chỉ chọn được trong số đó. */
    private static function internalDocumentOptions(Matter $matter): array
    {
        return Document::query()
            ->where('matter_id', $matter->id)
            ->where('group', DocumentGroup::Internal->value)
            ->pluck('title', 'id')
            ->all();
    }

    private static function resolveDocument(mixed $id): ?Document
    {
        return blank($id) ? null : Document::query()->find($id);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    /**
     * Một danh sách dòng lịch thu từ form (`DraftContract`/`UpdateDraftContract`): mỗi dòng đi qua
     * `Money::parse()` cho `amount`, gắn field path `"{$prefix}.{i}.amount"` để một chuỗi hỏng ra
     * lỗi cạnh đúng ô — phần còn lại (tên, loại kích hoạt, ngày…) ĐỂ NGUYÊN cho chính Action kiểm
     * qua `ValidatesBillingInput::instalmentAttributes()`, không kiểm hai lần.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function parsedInstalmentRows(array $rows, string $prefix): array
    {
        return collect(array_values($rows))->map(fn (array $row, int $index): array => [
            ...$row,
            'amount' => Money::parse((string) ($row['amount'] ?? ''), "{$prefix}.{$index}.amount"),
            'due_days_after_trigger' => static::intOrNull($row['due_days_after_trigger'] ?? null) ?? 0,
        ])->all();
    }

    /**
     * `AmendContract::$instalmentChanges` — cùng phép biến đổi, tiền tố `instalment_changes`
     * (đúng tiền tố `AmendContract::plan()` đã dùng cho lỗi của chính nó).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function parsedAmendmentChanges(array $rows): array
    {
        return collect(array_values($rows))->map(function (array $row, int $index): array {
            $prefix = "instalment_changes.{$index}";
            $change = ['action' => $row['action'] ?? null];

            if ($change['action'] === 'add') {
                $change = [
                    ...$change,
                    'name' => $row['name'] ?? null,
                    'amount' => Money::parse((string) ($row['amount'] ?? ''), "{$prefix}.amount"),
                    'percent_basis' => $row['percent_basis'] ?? null,
                    'trigger_type' => $row['trigger_type'] ?? null,
                    'trigger_stage_key' => $row['trigger_stage_key'] ?? null,
                    'due_date' => $row['due_date'] ?? null,
                    'due_days_after_trigger' => static::intOrNull($row['due_days_after_trigger'] ?? null) ?? 0,
                ];
            } elseif ($change['action'] === 'update') {
                $change = [
                    ...$change,
                    'instalment_id' => static::intOrNull($row['instalment_id'] ?? null),
                    'amount' => Money::parse((string) ($row['amount'] ?? ''), "{$prefix}.amount"),
                    'percent_basis' => $row['percent_basis'] ?? null,
                ];
            } else {
                $change['instalment_id'] = static::intOrNull($row['instalment_id'] ?? null);
            }

            return $change;
        })->all();
    }
}
