<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\AmendContract;
use App\Actions\Billing\CancelContract;
use App\Actions\Billing\CompleteContract;
use App\Actions\Billing\DeleteDraftContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RescheduleInstalment;
use App\Actions\Billing\UnwaiveInstalment;
use App\Actions\Billing\UpdateDraftContract;
use App\Actions\Billing\VoidPayment;
use App\Actions\Billing\WaiveInstalment;
use App\Enums\ChecklistItemStatus;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\PaymentMethod;
use App\Exceptions\PaymentAlreadyVoided;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\Payment;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use App\Support\Billing\SplitByPercent;
use App\Support\Billing\Vat;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
 * **Lớp này không có một dòng nghiệp vụ nào** (CLAUDE.md): mười Action của Task 4/5/7 và lượt rà
 * soát cuối — {@see DraftContract}, {@see UpdateDraftContract}, {@see DeleteDraftContract},
 * {@see ActivateContract}, {@see AmendContract}, {@see CompleteContract}, {@see CancelContract},
 * {@see RecordPayment}, {@see WaiveInstalment}, {@see VoidPayment} — là nơi DUY NHẤT ghi. Mọi `DomainException`/`ValidationException`/
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
 * nháp ({@see self::updateDraftContractAction()}), xoá bản nháp
 * ({@see self::deleteDraftContractAction()}), kích hoạt, ký phụ lục, hoàn tất, huỷ — tất cả qua
 * `ContractPolicy::update` (hay `::create` cho soạn mới, `::delete` cho xoá bản nháp), thành ngữ
 * `->authorize()` của {@see TeamRelationManager}/{@see DeadlinesRelationManager}.
 *
 * Mỗi dòng (một đợt thanh toán): ghi khoản thu, miễn, bỏ miễn (chỉ trên đợt đã miễn), huỷ một
 * khoản thu do người dùng chọn (làn fb, mục A2/A3). **Nút ghi khoản thu
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
 * **Dòng của hợp đồng KHÔNG `active` hiện trạng thái HỢP ĐỒNG, xám** (lượt rà soát cuối M9, I4 —
 * {@see self::displayState()}): ba con số tổng ở đầu bảng chỉ đọc hợp đồng `active`, nên một đợt
 * còn `pending` của hợp đồng đã huỷ/đã hoàn tất mà hiện "Quá hạn" (đỏ) cùng "Còn lại" dương sẽ nói
 * ngược với dòng "Còn phải thu: 0 ₫" ngay trên nó. "Còn lại" của những dòng đó là 0 vì chính
 * {@see Instalment::outstanding()} trả 0 khi hợp đồng không `active` — một chỗ, không phải một
 * điều kiện riêng của màn hình. "Đã thu" vẫn là số đã thu thật.
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
 * # Chia theo phần trăm và ba con số VAT — xem trước NGAY trong form (lượt rà soát cuối M9, I5)
 *
 * Mỗi dòng lịch thu (soạn/sửa bản nháp, và dòng thêm/sửa của phụ lục) nhập HOẶC phần trăm của tổng
 * giá trị HOẶC số tiền, không bao giờ cả hai: điền phần trăm thì ô số tiền bị khoá, để trống (và
 * không gửi lên — {@see self::percentOrAmountFields()}), số tiền TÍNH từ phần trăm bằng {@see SplitByPercent::amountsForRows()} — mọi dòng theo
 * phần trăm cộng đủ 100% thì đợt cuối nhận phần dư làm tròn, tổng các đợt bằng đúng giá trị; gõ số
 * tiền thì `percent_basis` để trống. Cùng MỘT hàm tính cho khung xem trước lúc gõ
 * ({@see self::schedulePreview()}, {@see self::amendmentPreview()}) và cho lúc lưu
 * ({@see self::parsedInstalmentRows()}, {@see self::parsedAmendmentChanges()}) — số người dùng
 * thấy là số được ghi. Khung xem trước còn in ba con số VAT của tổng qua {@see Vat} (tổng khách
 * trả, phần thuế nằm trong tổng, phần thực nhận). Không Action nào đổi: Action vẫn nhận `amount`
 * (số quyết định) và `percent_basis` (truy vết) như trước.
 *
 * # Gợi ý đầu mục danh mục — {@see self::checklistNudge()}
 *
 * Sau khi hợp đồng rời `draft` (đã kích hoạt ít nhất một lần), nếu đầu mục bắt buộc "Hợp đồng dịch
 * vụ pháp lý và giấy uỷ quyền" (SPEC §4.9) còn ở trạng thái `missing`, tab hiện một dòng nhắc tải
 * bản đã ký lên đúng đầu mục đó ở tab Tài liệu. **Không** tự đánh dấu đầu mục — nó nhận diện CHỈ
 * bằng tên (SPEC không cho một cách khác), và việc đánh dấu "đã nhận" là việc của
 * `ReviewChecklistItem` (M4), không phải của tab này. Không hiện trên vụ ĐÃ KẾT THÚC (việc sau gộp
 * M7, làn fu2): từ M7 Task 3 danh mục của vụ đó chỉ đọc, `UploadStaffDocument` lên đầu mục ném
 * `MatterChecklistReadOnly`, nên dòng nhắc chỉ dẫn tới một lời từ chối.
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
 * vụ việc (kế hoạch M9 Task 7, "Do not call BillingSummary per row repeatedly"); `contract` nạp
 * sẵn cho cả bảng (`outstanding()` và badge đọc trạng thái hợp đồng). Ba con số TỔNG (đã thu /
 * còn phải thu / quá hạn) ở đầu bảng tính MỘT LẦN qua {@see BillingSummary} — không lặp lại
 * `outstanding()` của từng dòng để cộng dồn.
 */
class BillingRelationManager extends RelationManager
{
    use ReportsActionFailures;
    use ScopesToVisibleMatters;

    /** SPEC §4.9 — nhận diện CHỈ bằng tên, xem docblock lớp. */
    public const REQUIRED_CHECKLIST_ITEM_NAME = Contract::SIGNED_CONTRACT_CHECKLIST_ITEM_NAME;

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
        ))
            // Làn fb, mục A1: vụ có thể có thêm những hợp đồng ĐÃ HUỶ trước bản hiện hành — bảng chỉ
            // là lịch thu của bản hiện hành (`Matter::contract()`, bản mới nhất); bản huỷ trước đó
            // hiện thành một dòng lịch sử ở đầu bảng ({@see self::previousContractsBlock()}).
            ->where('contracts.id', $matter->contract?->getKey())
            ->orderBy('sequence');
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
                    ->state(fn (Instalment $record): InstalmentState|ContractStatus => static::displayState($record))
                    ->formatStateUsing(fn (InstalmentState|ContractStatus $state): string => $state instanceof ContractStatus
                        ? __('billing.tab.contract_state_badge', ['status' => $state->label()])
                        : $state->label())
                    ->color(fn (Instalment $record): string => static::displayStateColor($record)),
                TextColumn::make('payments_list')
                    ->label(__('billing.tab.columns.payments'))
                    ->html()
                    ->wrap()
                    ->state(fn (Instalment $record): HtmlString => static::paymentsBlock($record)),
            ])
            ->headerActions([
                $this->draftContractAction(),
                $this->updateDraftContractAction(),
                $this->deleteDraftContractAction(),
                $this->activateContractAction(),
                $this->amendContractAction(),
                $this->completeContractAction(),
                $this->cancelContractAction(),
            ])
            ->recordActions([
                $this->recordPaymentAction(),
                $this->waiveInstalmentAction(),
                $this->unwaiveInstalmentAction(),
                $this->rescheduleInstalmentAction(),
                $this->voidPaymentAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query, 'contract.matter')
                ->with(['contract', 'payments' => fn ($payments) => $payments->with('attributedLawyer')]));
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
        if ($contract->status === ContractStatus::Cancelled) {
            $lines[] = sprintf(
                '<p style="color:var(--warning-600);font-weight:600">%s</p>',
                e(__('billing_corrections.redraft.cancelled_hint')),
            );
        }

        $lines[] = static::amountsLine($contract);
        $lines[] = static::totalsLine($matter, $contract);

        if ($matter->isClosed() && BillingSummary::hasOutstandingBalance($matter->id)) {
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
        $lines[] = static::previousContractsBlock($matter, $contract);

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

        // I1 (lượt rà soát cuối M9): phần CÒN LẠI của các đợt quá hạn (kể cả đợt đã thu một phần),
        // cộng trong CSDL bằng đúng công thức của biểu đồ doanh thu.
        $overdue = BillingSummary::sumOutstanding(
            BillingSummary::pendingInstalmentsQuery()->where('contract_id', $contract->id)->overdue()
        );

        return sprintf(
            '<p style="font-weight:600">%s: <span style="color:var(--success-600)">%s</span> — %s: <span style="color:var(--warning-600)">%s</span> — %s: <span style="color:var(--danger-600)">%s</span></p>',
            e(__('billing.tab.totals.collected')), e(Money::format($collected)),
            e(__('billing.tab.totals.outstanding')), e(Money::format($outstanding)),
            e(__('billing.tab.totals.overdue')), e(Money::format($overdue)),
        );
    }

    /**
     * `null` khi hợp đồng còn `draft` (chưa từng kích hoạt), khi vụ đã kết thúc (danh mục chỉ đọc —
     * xem docblock lớp), hoặc khi đầu mục không còn thiếu.
     */
    private static function checklistNudge(Matter $matter, Contract $contract): ?string
    {
        if ($contract->status === ContractStatus::Draft || $matter->isClosed()) {
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

    /**
     * Những hợp đồng TRƯỚC bản hiện hành của vụ — luôn là bản đã huỷ (làn fb, mục A1: vụ chỉ soạn
     * được hợp đồng mới khi mọi bản trước đã huỷ). Mỗi bản một dòng: mã, trạng thái, ngày kết thúc,
     * số đã thu chưa huỷ. Rỗng khi vụ chỉ có một hợp đồng.
     */
    private static function previousContractsBlock(Matter $matter, Contract $current): string
    {
        $previous = $matter->contracts()->whereKeyNot($current->getKey())->orderByDesc('id')->get();

        if ($previous->isEmpty()) {
            return '';
        }

        $items = $previous->map(fn (Contract $contract): string => sprintf(
            '<li>%s</li>',
            e(__('billing_corrections.redraft.previous_line', [
                'code' => $contract->code,
                'status' => $contract->status->label(),
                'date' => $contract->ended_at?->format('d/m/Y') ?? '—',
                'collected' => Money::format((int) Payment::query()
                    ->whereHas('instalment', fn (Builder $query) => $query->where('contract_id', $contract->id))
                    ->whereNull('voided_at')
                    ->sum('amount')),
            ])),
        ))->implode('');

        return sprintf(
            '<p style="font-weight:600">%s</p><ul>%s</ul>',
            e(__('billing_corrections.redraft.previous_heading')),
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

    /**
     * Cái badge của một dòng nói: trạng thái của ĐỢT khi hợp đồng `active`, trạng thái của HỢP ĐỒNG
     * khi không (I4 — xem docblock lớp). `contract` đã nạp sẵn cho cả bảng.
     */
    public static function displayState(Instalment $instalment): InstalmentState|ContractStatus
    {
        $contractStatus = $instalment->contract->status;

        return $contractStatus === ContractStatus::Active ? $instalment->state() : $contractStatus;
    }

    /** Màu của badge {@see self::displayState()}: xám trung tính cho hợp đồng không `active`. */
    public static function displayStateColor(Instalment $instalment): string
    {
        $state = static::displayState($instalment);

        return $state instanceof ContractStatus ? 'gray' : static::stateColor($state);
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
     * Soạn hợp đồng cho vụ chưa có hợp đồng nào — hoặc chỉ có hợp đồng ĐÃ HUỶ (làn fb, mục A1: nút
     * đổi nhãn thành "Soạn hợp đồng mới"). Chỉ `fixed_fee` — không có ô chọn cách tính phí,
     * vì M9 chỉ cài đặt một loại đó ({@see BillingModelNotSupported} vì vậy không tới được từ màn
     * hình này, chỉ từ dữ liệu ghi thẳng).
     */
    private function draftContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('draftContract')
            ->label(fn (): string => $matter->contract?->status === ContractStatus::Cancelled
                ? __('billing_corrections.redraft.label')
                : __('billing.tab.actions.draft'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading(__('billing.tab.actions.draft_heading'))
            ->visible(fn (): bool => $matter->contract === null || $matter->contract->status === ContractStatus::Cancelled)
            ->authorize(fn (): bool => Gate::allows('create', [Contract::class, $matter]))
            // M10 Task 4 (R3): vụ đến từ một lần tiếp nhận thì phí đã báo lúc đó hiện sẵn làm GỢI Ý tổng
            // giá trị — chỉ là giá trị mặc định của ô (`DraftContract` không đổi, người soạn sửa được).
            ->schema(static::contractScheduleFields(suggestedTotal: fn (): ?string => static::quotedAmountSuggestion($matter)))
            ->successNotificationTitle(__('billing.tab.actions.draft_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                function () use ($matter, $data): Contract {
                    $total = Money::parse((string) ($data['total_amount'] ?? ''), 'total_amount');

                    $contract = app(DraftContract::class)->handle(
                        Auth::user(),
                        $matter,
                        ['total_amount' => $total, 'vat_rate_percent' => static::intOrNull($data['vat_rate_percent'] ?? null)],
                        static::parsedInstalmentRows($data['instalments'] ?? [], $total, 'instalments'),
                    );

                    // Bản hiện hành vừa đổi (có thể từ một bản đã huỷ sang bản nháp mới) — quan hệ
                    // đã nạp trên vụ không được giữ bản cũ cho phần còn lại của lần vẽ này.
                    $matter->unsetRelation('contract');

                    return $contract;
                },
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

                // `Money::formatForInput()`, không `Money::format()`: ô nhập nhận đúng dạng
                // `Money::parse()` đọc được ("50.000.000"), không kèm "₫" — nếu không, mở form rồi
                // bấm lưu ngay mà không sửa gì cũng ra lỗi định dạng số tiền. Dòng theo phần trăm
                // KHÔNG điền sẵn số tiền: ô đó bị khoá, và số đã lưu thành số cũ ngay khi giá trị
                // hợp đồng đổi (xem `percentOrAmountFields()`).
                return [
                    'total_amount' => $contract === null ? null : Money::formatForInput($contract->total_amount),
                    'vat_rate_percent' => $contract?->vat_rate_percent,
                    'instalments' => $contract === null ? [] : $contract->instalments->map(fn (Instalment $i): array => [
                        'name' => $i->name,
                        'amount' => $i->percent_basis === null ? Money::formatForInput($i->amount) : null,
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
                function () use ($matter, $data): Contract {
                    $total = Money::parse((string) ($data['total_amount'] ?? ''), 'total_amount');

                    return app(UpdateDraftContract::class)->handle(
                        Auth::user(),
                        $matter->contract,
                        ['total_amount' => $total, 'vat_rate_percent' => static::intOrNull($data['vat_rate_percent'] ?? null)],
                        static::parsedInstalmentRows($data['instalments'] ?? [], $total, 'instalments'),
                    );
                },
            ));
    }

    /**
     * Xoá một hợp đồng còn nháp (lượt rà soát cuối M9, M9) qua {@see DeleteDraftContract} —
     * `ContractPolicy::delete`, nhật ký, và "xoá được không" là `Contract::assertDestroyable()`.
     *
     * `->visible()` đọc TRẠNG THÁI (còn nháp), cùng cách nút "Sửa hợp đồng" ngay trên: nút xoá bản
     * nháp trên một hợp đồng đã ký là một lời mời sai. Cái giá đã biết (chú thích ở
     * `activateContractAction()`): nếu hợp đồng được kích hoạt ở tab khác giữa lúc vẽ và lúc bấm,
     * nút tự ẩn lúc bấm và KHÔNG xoá gì — hỏng về phía an toàn.
     */
    private function deleteDraftContractAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('deleteDraftContract')
            ->label(__('billing.tab.actions.delete_draft'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('billing.tab.actions.delete_draft_heading'))
            ->modalDescription(__('billing.tab.actions.delete_draft_description'))
            ->visible(fn (): bool => $matter->contract?->status === ContractStatus::Draft)
            ->authorize(fn (): bool => $matter->contract !== null && Gate::allows('delete', $matter->contract))
            ->successNotificationTitle(__('billing.tab.actions.delete_draft_success'))
            ->action(fn (Action $action) => $this->runAction(
                $action,
                function () use ($matter): void {
                    app(DeleteDraftContract::class)->handle(Auth::user(), $matter->contract);

                    // Hàng đã xoá — quan hệ đã nạp trên vụ việc không được giữ nó lại cho phần
                    // còn lại của lần vẽ này.
                    $matter->unsetRelation('contract');
                },
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
            // Làn fb (mục A1 + B): modal nói hậu quả — số còn phải thu sẽ không còn được theo dõi,
            // không hoàn tác được, muốn ký lại thì soạn hợp đồng mới sau khi huỷ.
            ->modalDescription(fn (): string => __('billing_corrections.cancel.description', [
                'outstanding' => Money::format($matter->contract === null ? 0 : BillingSummary::sumOutstanding(
                    BillingSummary::pendingInstalmentsQuery()->where('contract_id', $matter->contract->getKey())
                )),
            ]))
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
                    ->required()
                    ->live(onBlur: true),
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
                    ->live()
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
                        ...static::percentOrAmountFields(
                            fn (Get $get): bool => in_array($get('action'), ['add', 'update'], true),
                            __('billing.tab.fields.change_percent_basis_help'),
                        ),
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
                TextEntry::make('amendment_preview')
                    ->label(__('billing.tab.preview.heading'))
                    ->state(fn (Get $get): HtmlString => static::amendmentPreview(
                        $matter->contract,
                        $get('new_total_amount'),
                        (array) ($get('instalment_changes') ?? []),
                    ))
                    ->html()
                    ->columnSpanFull(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.amend_success'))
            ->action(fn (Action $action, array $data) => $this->runAction(
                $action,
                function () use ($matter, $data) {
                    $newTotal = Money::parse((string) ($data['new_total_amount'] ?? ''), 'new_total_amount');

                    return app(AmendContract::class)->handle(
                        Auth::user(),
                        $matter->contract,
                        $newTotal,
                        static::parsedAmendmentChanges($data['instalment_changes'] ?? [], $newTotal),
                        $data['reason'] ?? '',
                        $data['signed_at'] ?? '',
                    );
                },
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
                    ->helperText(__('billing.tab.fields.payment_amount_help'))
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
                Textarea::make('note')
                    ->label(__('billing.tab.fields.payment_note')),
            ])
            ->successNotificationTitle(__('billing.tab.actions.record_payment_success'))
            // Không có ô chọn bản scan biên lai (lượt rà soát cuối M9, M3; phán quyết Task 8 (b):
            // `receipt_document_id` để trống, chưa dùng ở làn này — cùng cách trang "Công nợ").
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                fn () => app(RecordPayment::class)->handle(
                    Auth::user(),
                    $record,
                    Money::parse((string) ($data['amount'] ?? ''), 'amount'),
                    $data['paid_on'] ?? '',
                    PaymentMethod::from($data['method']),
                    $data['reference'] ?? null,
                    null,
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
            // Làn fb, mục A2: modal nói rõ đợt nào, giá trị, đã thu, và phần sẽ thôi đòi — trước
            // đây chỉ có một ô lý do, nên miễn nhầm dòng không ai nhận ra.
            ->modalDescription(fn (Instalment $record): string => static::waiveDescription($record))
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
     * "Bỏ miễn" (làn fb, mục A2) qua {@see UnwaiveInstalment} — cùng quyền với "Miễn"
     * (`InstalmentPolicy::waive`), lý do ≥ 20 ký tự, nhật ký `instalment_unwaived`.
     *
     * **Nút chỉ hiện trên đợt `waived`, và đó là lọc theo TRẠNG THÁI có chủ đích** — khác "Miễn"
     * (xem chú thích `activateContractAction()` về `->visible()` bị hỏi lại lúc chạy). Trường hợp
     * đua duy nhất làm nút tự ẩn lúc bấm là đợt đã RỜI `waived` giữa lúc vẽ và lúc bấm; chỉ "Bỏ
     * miễn" đưa một đợt ra khỏi `waived`, nên lúc đó đợt đã ở đúng trạng thái người bấm muốn — bỏ
     * ngang im lặng không để lại điều gì sai. Đổi lại, các dòng `pending`/`paid` không mang thêm
     * một nút vô nghĩa.
     */
    private function unwaiveInstalmentAction(): Action
    {
        return Action::make('unwaiveInstalment')
            ->label(__('billing_corrections.unwaive.label'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->modalHeading(__('billing_corrections.unwaive.heading'))
            ->modalDescription(fn (Instalment $record): string => __('billing_corrections.unwaive.description', [
                'name' => $record->name,
                'amount' => Money::format($record->amount),
                'previous_reason' => $record->waived_reason ?? '—',
            ]))
            ->visible(fn (Instalment $record): bool => $record->status === InstalmentStatus::Waived)
            ->authorize(fn (Instalment $record): bool => Gate::allows('waive', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing_corrections.unwaive.success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                fn () => app(UnwaiveInstalment::class)->handle(Auth::user(), $record, $data['reason'] ?? ''),
            ));
    }

    /**
     * "Dời hạn" (làn fb, mục B — khách xin khất) qua {@see RescheduleInstalment}: đổi `due_date` của
     * một đợt còn chờ thu, có lý do và nhật ký `instalment_rescheduled`.
     *
     * Hiện trên đợt `pending` đã có ngày đến hạn — lọc theo TRẠNG THÁI có chủ đích, cùng lý lẽ "Bỏ
     * miễn": cuộc đua duy nhất làm nút tự ẩn lúc bấm là đợt vừa rời `pending` (đã thu đủ, đã miễn,
     * đã huỷ) — lúc đó không còn gì để khất, bỏ ngang không để lại điều gì sai.
     */
    private function rescheduleInstalmentAction(): Action
    {
        return Action::make('rescheduleInstalment')
            ->label(__('billing_corrections.reschedule.label'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->modalHeading(__('billing_corrections.reschedule.heading'))
            ->modalDescription(fn (Instalment $record): string => __('billing_corrections.reschedule.description', [
                'name' => $record->name,
                'amount' => Money::format($record->amount),
                'due_date' => $record->due_date?->format('d/m/Y') ?? '—',
            ]))
            ->visible(fn (Instalment $record): bool => $record->status === InstalmentStatus::Pending && $record->due_date !== null)
            ->authorize(fn (Instalment $record): bool => Gate::allows('reschedule', $record))
            ->schema([
                DatePicker::make('due_date')
                    ->label(__('billing_corrections.reschedule.field'))
                    ->default(fn (Instalment $record): ?string => $record->due_date?->toDateString())
                    ->native(false)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing_corrections.reschedule.success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                fn () => app(RescheduleInstalment::class)->handle(Auth::user(), $record, $data['due_date'] ?? null, $data['reason'] ?? ''),
            ));
    }

    /** Câu mô tả của modal "Miễn": tên đợt, giá trị, đã thu, phần sẽ thôi đòi (làn fb, mục A2). */
    public static function waiveDescription(Instalment $instalment): string
    {
        $collected = (int) $instalment->payments()->whereNull('voided_at')->sum('amount');

        return __('billing_corrections.waive.description', [
            'name' => $instalment->name,
            'amount' => Money::format($instalment->amount),
            'collected' => Money::format($collected),
            'written_off' => Money::format(max(0, $instalment->amount - $collected)),
        ]);
    }

    /**
     * Huỷ MỘT khoản thu của đợt, do người dùng CHỌN (làn fb, mục A3) — cùng cách trang "Công nợ".
     * Bản trước là một trường `Hidden` "khoản gần nhất theo NGÀY TIỀN VỀ", modal không hiện khoản
     * nào: ghi lùi ngày một khoản nhầm rồi bấm huỷ thì khoản ĐÚNG bị huỷ mà không ai biết.
     *
     * - **Ô chọn** liệt kê mọi khoản CHƯA huỷ của đợt, mỗi khoản kèm ngày tiền về, số tiền, cách
     *   nhận, mã giao dịch và LÚC GHI ({@see self::voidOptionLabel()}), mới ghi trước.
     * - **Mặc định là khoản GHI gần nhất** (`id` lớn nhất) — {@see self::latestRecordedActivePayment()};
     *   đúng ý "sửa nhầm vừa ghi", không phụ thuộc ngày tiền về người dùng đã gõ.
     * - **Khoản đang được chọn luôn nằm trong danh sách, kể cả khi vừa bị huỷ** trong lúc modal mở
     *   ({@see self::voidPaymentOptions()}). Luật "trong danh sách" của `Select` tính lại lúc bấm lưu;
     *   nếu khoản vừa bị huỷ rơi khỏi danh sách thì lần bấm đó dừng ở một lỗi "không hợp lệ" chung
     *   chung thay vì tới {@see VoidPayment} và nhận đúng câu {@see PaymentAlreadyVoided}.
     * - Một id của khoản thuộc đợt KHÁC (form chỉnh tay) không nằm trong danh sách, nên bị `Select`
     *   từ chối (đo: "refuses a payment id of another instalment…" trong
     *   `BillingTabCorrectionsTest`). Closure bên dưới chốt thêm "khoản phải thuộc đúng đợt này" —
     *   phòng thủ chiều sâu, nói thẳng: gỡ nó đi test đó vẫn xanh, vì luật "trong danh sách" của
     *   `Select` đã chặn trước (cùng giới hạn đã ghi ở `Receivables::voidPaymentAction()`).
     */
    private function voidPaymentAction(): Action
    {
        return Action::make('voidPayment')
            ->label(__('billing.tab.actions.void_payment'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(__('billing_corrections.void.heading'))
            ->modalDescription(__('billing_corrections.void.description'))
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
                Select::make('payment_id')
                    ->label(__('billing_corrections.void.field'))
                    ->options(fn (Instalment $record, Get $get): array => static::voidPaymentOptions($record, $get('payment_id')))
                    ->default(fn (Instalment $record): ?int => static::latestRecordedActivePayment($record)?->id)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('billing.tab.fields.reason'))
                    ->helperText(__('billing.tab.fields.reason_help'))
                    ->required(),
            ])
            ->successNotificationTitle(__('billing.tab.actions.void_payment_success'))
            ->action(fn (Action $action, Instalment $record, array $data) => $this->runAction(
                $action,
                function () use ($data, $record): Payment {
                    // Khoản phải thuộc ĐÚNG đợt đang mở modal (phòng thủ chiều sâu sau luật "trong
                    // danh sách" của ô chọn — xem docblock).
                    $payment = Payment::query()
                        ->where('instalment_id', $record->getKey())
                        ->find($data['payment_id'] ?? null);

                    if ($payment === null) {
                        throw ValidationException::withMessages(['payment_id' => [__('billing.tab.no_payment_to_void')]]);
                    }

                    return app(VoidPayment::class)->handle(Auth::user(), $payment, $data['reason'] ?? '');
                },
            ));
    }

    /** Khoản chưa huỷ GHI gần nhất của đợt — theo `id`, không theo `paid_on` (làn fb, mục A3). */
    private static function latestRecordedActivePayment(Instalment $instalment): ?Payment
    {
        return $instalment->payments()->whereNull('voided_at')->latest('id')->first();
    }

    /**
     * Danh sách chọn của "Huỷ một khoản thu": các khoản CHƯA huỷ của đợt, mới ghi trước, cộng khoản
     * đang được chọn dù nó vừa bị huỷ (xem docblock {@see self::voidPaymentAction()}).
     *
     * @return array<int, string>
     */
    public static function voidPaymentOptions(Instalment $instalment, mixed $selectedId): array
    {
        return $instalment->payments()
            ->where(fn (Builder $query) => $query
                ->whereNull('voided_at')
                ->when(filled($selectedId), fn (Builder $selected) => $selected->orWhere('id', (int) $selectedId)))
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Payment $payment): array => [(int) $payment->id => static::voidOptionLabel($payment)])
            ->all();
    }

    /** Một dòng của ô chọn: ngày tiền về, số tiền, cách nhận, mã giao dịch nếu có, lúc ghi. */
    public static function voidOptionLabel(Payment $payment): string
    {
        $params = [
            'date' => $payment->paid_on->format('d/m/Y'),
            'amount' => Money::format($payment->amount),
            'method' => $payment->method->label(),
            'reference' => (string) $payment->reference,
            'recorded_at' => $payment->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—',
        ];

        if ($payment->voided_at !== null) {
            return __('billing_corrections.void.option_voided', $params);
        }

        return __(filled($payment->reference) ? 'billing_corrections.void.option_reference' : 'billing_corrections.void.option', $params);
    }

    // =============================================================================================
    // Các hàm dựng ô nhập dùng chung.
    // =============================================================================================

    /** @return array<int, mixed> */
    private static function contractScheduleFields(?Closure $suggestedTotal = null): array
    {
        return [
            TextInput::make('total_amount')
                ->label(__('billing.tab.fields.total_amount'))
                ->helperText(__('billing.tab.fields.total_amount_help'))
                // Chỉ áp khi modal mở KHÔNG có `fillForm()` (soạn mới); "sửa bản nháp" điền từ hợp đồng.
                ->default($suggestedTotal)
                ->maxLength(15)
                ->required()
                ->live(onBlur: true),
            TextInput::make('vat_rate_percent')
                ->label(__('billing.tab.fields.vat_rate_percent'))
                ->helperText(__('billing.tab.fields.vat_rate_percent_help'))
                ->numeric()
                ->live(onBlur: true),
            Repeater::make('instalments')
                ->label(__('billing.tab.columns.name'))
                ->addActionLabel(__('billing.tab.actions.add_instalment'))
                ->defaultItems(1)
                ->columns(2)
                ->live()
                ->schema([
                    TextInput::make('name')
                        ->label(__('billing.tab.fields.instalment_name'))
                        ->helperText(__('billing.tab.fields.instalment_name_help'))
                        ->maxLength(150)
                        ->required()
                        ->columnSpanFull(),
                    ...static::percentOrAmountFields(null, __('billing.tab.fields.instalment_percent_basis_help')),
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
            TextEntry::make('schedule_preview')
                ->label(__('billing.tab.preview.heading'))
                ->state(fn (Get $get): HtmlString => static::schedulePreview(
                    $get('total_amount'),
                    $get('vat_rate_percent'),
                    (array) ($get('instalments') ?? []),
                ))
                ->html()
                ->columnSpanFull(),
        ];
    }

    /**
     * Cặp ô "phần trăm HOẶC số tiền" của một dòng lịch thu (I5 — xem docblock lớp): điền phần trăm
     * thì ô số tiền bị khoá, không bắt buộc và KHÔNG gửi lên (số tiền tính lại từ phần trăm lúc
     * lưu); để trống phần trăm thì số tiền bắt buộc.
     *
     * **Ô số tiền bị khoá luôn TRỐNG** (lượt sửa thứ hai sau rà soát cuối M9, minor): điền phần
     * trăm thì số đã gõ trước đó bị xoá, và form "Sửa hợp đồng" không điền sẵn số tiền của dòng
     * theo phần trăm ({@see self::updateDraftContractAction()}). Ô khoá chỉ còn dòng gợi ý "tự tính
     * từ phần trăm"; con số thật — tính lại mỗi lần giá trị hay phần trăm đổi — nằm ở khung xem
     * trước, đúng số sẽ lưu. Một con số cũ trong ô khoá sẽ nói ngược với cả hai.
     *
     * @param  (Closure(Get): bool)|null  $visible  điều kiện hiện của CẢ HAI ô (dòng phụ lục chỉ hiện chúng khi thêm/sửa)
     * @return array<int, TextInput>
     */
    private static function percentOrAmountFields(?Closure $visible, string $percentHelp): array
    {
        $isVisible = fn (Get $get): bool => $visible === null || $visible($get);
        $byPercent = fn (Get $get): bool => filled($get('percent_basis'));

        return [
            TextInput::make('percent_basis')
                ->label(__('billing.tab.fields.instalment_percent_basis'))
                ->helperText($percentHelp)
                ->maxLength(6)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    if (filled($state)) {
                        $set('amount', null);
                    }
                })
                ->visible($isVisible),
            TextInput::make('amount')
                ->label(__('billing.tab.fields.instalment_amount'))
                ->maxLength(15)
                ->live(onBlur: true)
                ->required(fn (Get $get): bool => ! $byPercent($get))
                ->disabled($byPercent)
                ->placeholder(fn (Get $get): ?string => $byPercent($get) ? __('billing.tab.fields.instalment_amount_from_percent') : null)
                ->visible($isVisible),
        ];
    }

    /**
     * Khung xem trước của form soạn/sửa bản nháp (I5): ba con số VAT của tổng, số tiền từng đợt
     * (tính từ phần trăm bằng {@see SplitByPercent::amountsForRows()}, hay số đã gõ), và tổng các
     * đợt so với giá trị hợp đồng. Chỉ HIỂN THỊ — không ghi gì, không thay bất biến tổng của Action.
     *
     * @param  array<array-key, mixed>  $rows
     */
    public static function schedulePreview(mixed $totalInput, mixed $vatInput, array $rows): HtmlString
    {
        $total = static::tryParseMoney($totalInput);

        if ($total === null) {
            return new HtmlString('<em>'.e(__('billing.tab.preview.enter_total')).'</em>');
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        $lines = [e(static::vatPreviewLine($total, $vatInput))];

        try {
            $derived = SplitByPercent::amountsForRows($total, array_map(fn (array $row): mixed => $row['percent_basis'] ?? null, $rows));
        } catch (ValidationException) {
            return new HtmlString(implode('<br/>', [...$lines, e(__('billing.tab.preview.percent_invalid'))]));
        }

        $sum = 0;
        $complete = true;

        foreach ($rows as $index => $row) {
            $amount = $derived[$index] ?? static::tryParseMoney($row['amount'] ?? null);
            $complete = $complete && $amount !== null;
            $sum += $amount ?? 0;
            $lines[] = e(static::previewRowLine(
                __('billing.tab.preview.row_label_instalment', ['number' => $index + 1]),
                (string) ($row['name'] ?? ''),
                $row['percent_basis'] ?? null,
                $amount,
            ));
        }

        if ($rows !== [] && $complete) {
            $lines[] = e($sum === $total
                ? __('billing.tab.preview.sum_matches', ['sum' => Money::format($sum)])
                : __('billing.tab.preview.sum_differs', ['sum' => Money::format($sum), 'total' => Money::format($total), 'difference' => Money::format(abs($total - $sum))]));
        }

        return new HtmlString(implode('<br/>', $lines));
    }

    /**
     * Khung xem trước của form phụ lục (I5): ba con số VAT của giá trị MỚI (thuế suất của hợp đồng),
     * và số tiền của từng dòng thêm/sửa — tính từ phần trăm của giá trị mới, hay số đã gõ. Không
     * tính trước bất biến tổng sau phụ lục: đó là việc của `AmendContract` (kiểm lại từ CSDL).
     *
     * @param  array<array-key, mixed>  $rows
     */
    public static function amendmentPreview(?Contract $contract, mixed $newTotalInput, array $rows): HtmlString
    {
        $newTotal = static::tryParseMoney($newTotalInput);

        if ($contract === null || $newTotal === null) {
            return new HtmlString('<em>'.e(__('billing.tab.preview.enter_new_total')).'</em>');
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        $lines = [e(static::vatPreviewLine($newTotal, $contract->vat_rate_percent))];

        try {
            $derived = SplitByPercent::amountsForRows($newTotal, static::amendmentPercents($rows), 'instalment_changes');
        } catch (ValidationException) {
            return new HtmlString(implode('<br/>', [...$lines, e(__('billing.tab.preview.percent_invalid'))]));
        }

        $names = $contract->instalments()->pluck('name', 'id');

        foreach ($rows as $index => $row) {
            $action = $row['action'] ?? null;

            if (! in_array($action, [AmendContract::ADD, AmendContract::UPDATE], true)) {
                continue;
            }

            $name = $action === AmendContract::ADD
                ? (string) ($row['name'] ?? '')
                : (string) ($names[$row['instalment_id'] ?? null] ?? '');

            $lines[] = e(static::previewRowLine(
                __('billing.tab.preview.row_label_change', ['number' => $index + 1]),
                $name,
                $row['percent_basis'] ?? null,
                $derived[$index] ?? static::tryParseMoney($row['amount'] ?? null),
            ));
        }

        return new HtmlString(implode('<br/>', $lines));
    }

    /** Ba con số VAT của một tổng (qua {@see Vat}), hoặc câu "không có dòng thuế". */
    private static function vatPreviewLine(int $total, mixed $vatInput): string
    {
        $rate = is_numeric($vatInput) && (int) $vatInput >= 0 && (int) $vatInput <= 100 ? (int) $vatInput : null;

        if ($rate === null) {
            return __('billing.tab.preview.vat_none_line', ['total' => Money::format($total)]);
        }

        return __('billing.tab.preview.vat_line', [
            'total' => Money::format($total),
            'rate' => $rate,
            'tax' => Money::format(Vat::tax($total, $rate)),
            'net' => Money::format(Vat::net($total, $rate)),
        ]);
    }

    private static function previewRowLine(string $label, string $name, mixed $percent, ?int $amount): string
    {
        $amountText = $amount === null ? __('billing.tab.preview.amount_missing') : Money::format($amount);

        return filled($percent)
            ? __('billing.tab.preview.row_by_percent', ['label' => $label, 'name' => $name, 'percent' => trim((string) $percent), 'amount' => $amountText])
            : __('billing.tab.preview.row_by_amount', ['label' => $label, 'name' => $name, 'amount' => $amountText]);
    }

    /**
     * Phần trăm của các dòng THÊM/SỬA của một phụ lục, khoá = chỉ số dòng trong form (dòng huỷ không
     * mang số tiền nên không có mặt).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    private static function amendmentPercents(array $rows): array
    {
        $percents = [];

        foreach ($rows as $index => $row) {
            if (in_array($row['action'] ?? null, [AmendContract::ADD, AmendContract::UPDATE], true)) {
                $percents[$index] = $row['percent_basis'] ?? null;
            }
        }

        return $percents;
    }

    /** `null` khi ô trống hoặc chưa đọc được — khung xem trước không báo lỗi, chỉ chưa có gì để hiện. */
    private static function tryParseMoney(mixed $input): ?int
    {
        if (blank($input)) {
            return null;
        }

        try {
            return Money::parse((string) $input);
        } catch (ValidationException) {
            return null;
        }
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

    /**
     * M10 Task 4 (R3): phí đã báo lúc tiếp nhận của bản ghi đã chuyển thành `$matter`, ở đúng dạng ô nhập
     * nhận (`Money::formatForInput()`), hoặc null khi vụ không đến từ tiếp nhận nào / chưa báo phí.
     */
    private static function quotedAmountSuggestion(Matter $matter): ?string
    {
        $amount = IntakeRequest::quotedAmountFor($matter);

        return $amount === null ? null : Money::formatForInput($amount);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    /** Chuỗi phần trăm đã cắt hai đầu, hoặc `null` khi ô trống — "dòng này gõ số tiền". */
    private static function percentOrNull(mixed $value): ?string
    {
        return blank($value) ? null : trim((string) $value);
    }

    /**
     * Một danh sách dòng lịch thu từ form (`DraftContract`/`UpdateDraftContract`). Dòng có phần trăm
     * nhận số tiền TÍNH bằng {@see SplitByPercent::amountsForRows()} trên `$total` — cùng hàm của
     * khung xem trước, nên số được lưu là số người dùng đã thấy; ô số tiền của dòng đó bị khoá và
     * không được gửi lên, nếu có gửi cũng bị bỏ qua. Dòng không có phần trăm đi qua `Money::parse()`
     * (lỗi gắn `"{$prefix}.{i}.amount"`) và `percent_basis` để `null`. Phần còn lại (tên, loại kích
     * hoạt, ngày…) ĐỂ NGUYÊN cho chính Action kiểm qua
     * `ValidatesBillingInput::instalmentAttributes()`, không kiểm hai lần.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function parsedInstalmentRows(array $rows, int $total, string $prefix): array
    {
        $rows = array_values($rows);
        $percents = array_map(fn (array $row): ?string => static::percentOrNull($row['percent_basis'] ?? null), $rows);
        $derived = SplitByPercent::amountsForRows($total, $percents, $prefix);

        return collect($rows)->map(fn (array $row, int $index): array => [
            ...$row,
            'amount' => $derived[$index] ?? Money::parse((string) ($row['amount'] ?? ''), "{$prefix}.{$index}.amount"),
            'percent_basis' => $percents[$index],
            'due_days_after_trigger' => static::intOrNull($row['due_days_after_trigger'] ?? null) ?? 0,
        ])->all();
    }

    /**
     * `AmendContract::$instalmentChanges` — cùng phép biến đổi, tiền tố `instalment_changes`
     * (đúng tiền tố `AmendContract::plan()` đã dùng cho lỗi của chính nó). Phần trăm của một dòng
     * thêm/sửa là phần trăm của GIÁ TRỊ MỚI (`$newTotal`), tính bằng cùng
     * {@see SplitByPercent::amountsForRows()} như khung xem trước của phụ lục.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function parsedAmendmentChanges(array $rows, int $newTotal): array
    {
        $rows = array_values($rows);
        $percents = array_map(fn (mixed $percent): ?string => static::percentOrNull($percent), static::amendmentPercents($rows));
        $derived = SplitByPercent::amountsForRows($newTotal, $percents, 'instalment_changes');

        return collect($rows)->map(function (array $row, int $index) use ($percents, $derived): array {
            $prefix = "instalment_changes.{$index}";
            $change = ['action' => $row['action'] ?? null];
            $amount = fn (): int => $derived[$index] ?? Money::parse((string) ($row['amount'] ?? ''), "{$prefix}.amount");

            if ($change['action'] === AmendContract::ADD) {
                $change = [
                    ...$change,
                    'name' => $row['name'] ?? null,
                    'amount' => $amount(),
                    'percent_basis' => $percents[$index] ?? null,
                    'trigger_type' => $row['trigger_type'] ?? null,
                    'trigger_stage_key' => $row['trigger_stage_key'] ?? null,
                    'due_date' => $row['due_date'] ?? null,
                    'due_days_after_trigger' => static::intOrNull($row['due_days_after_trigger'] ?? null) ?? 0,
                ];
            } elseif ($change['action'] === AmendContract::UPDATE) {
                $change = [
                    ...$change,
                    'instalment_id' => static::intOrNull($row['instalment_id'] ?? null),
                    'amount' => $amount(),
                    'percent_basis' => $percents[$index] ?? null,
                ];
            } else {
                $change['instalment_id'] = static::intOrNull($row['instalment_id'] ?? null);
            }

            return $change;
        })->all();
    }
}
