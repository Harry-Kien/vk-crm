<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Storage\MeasureDocumentStore;
use App\Actions\Storage\RecordDataTransferDossier;
use App\Enums\DocumentStoreStatus;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Models\SystemHealth;
use App\Models\User;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\TransferDossier;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Trang "Kho tài liệu" (kế hoạch M14, Task 5; R13, R14) — chỉ admin. Hai phần:
 *
 *  - **Con số và trạng thái** ({@see self::figures()}, view `document-store-figures`): chế độ, mốc bật
 *    kho, trạng thái lần kiểm sức khoẻ gần nhất, tệp mới chờ đẩy và tệp chờ lâu nhất, tệp cũ chờ
 *    chuyển, bản trên máy chủ còn giữ, media trên kho chưa có biên nhận văn phòng, biên nhận gần nhất,
 *    số mục so với 400.000, lần chuyển dữ liệu đầu tiên và đồng hồ 60 ngày. CHỈ số đếm: trang không
 *    liệt kê tài liệu, hồ sơ hay khách nào (vụ `restricted` không bao giờ lộ, R14), không mã tệp Drive.
 *    Không lệnh gọi Google nào: mọi con số đọc từ CSDL ({@see MeasureDocumentStore}, `system_health`,
 *    `settings`).
 *  - **Form hồ sơ chuyển dữ liệu ra nước ngoài** (R13), lưu qua {@see RecordDataTransferDossier}.
 *    `maxLength()` của hai ô chữ là {@see TransferDossier::REFERENCE_MAX}/{@see TransferDossier::BASIS_MAX},
 *    đúng luật `max:` của Action; ba ô ngày có `maxDate` = hôm nay, đúng luật `before_or_equal:today`
 *    của Action ({@see self::pastDatePicker()}).
 *
 * # Làn tl (2026-10-15): cùng con số, cùng hành vi — nói tiếng thường
 *
 * Chủ văn phòng xem trang và không hiểu nó nói gì. Không con số nào, không hành vi nào đổi; chỉ đổi cách
 * trình bày:
 *
 *  - **Dải trạng thái** ({@see self::overview()}): MỘT câu nói tài liệu đang ở đâu, màu xanh/vàng/đỏ, và
 *    một câu "cần làm gì tiếp". Đi theo thứ tự nghiêm trọng: lỗi trước, việc cần làm sau, ổn cuối.
 *  - **Một câu đầu trang** nói đây là trang kỹ thuật, nhân sự tìm tài liệu ở trang `DocumentLibrary`.
 *  - **Các bước bật Google Drive** dạng danh sách kiểm, mỗi bước đọc từ đúng dữ liệu đã có (cấu hình,
 *    `settings`, `system_health`, số tệp cũ) — không lệnh gọi Google nào, nên bước "kiểm tra đạt" đọc
 *    lần kiểm tra tự động mỗi giờ cộng mốc bật kho. Lần kiểm tra đó (`storage.health`,
 *    `CheckDocumentStoreHealth`) chạy mỗi giờ ở MỌI chế độ; nó chỉ hỏi Google khi kho có liên quan
 *    (công tắc `google_drive`, hoặc còn media trên Drive), luôn xem đồng hồ 60 ngày (chỉ có tác dụng ở
 *    production), và ghi trạng thái `null` khi kho không liên quan mà đồng hồ không có gì để báo.
 *  - **Bốn ô số** có sắc độ tốt/xấu ({@see self::tiles()}); tên biến môi trường và lệnh artisan chỉ còn
 *    trong mục thu gọn "Chi tiết kỹ thuật".
 *  - **Form hồ sơ** nằm trong một mục thu gọn, mở sẵn đúng khi cần: công tắc đã là `google_drive` mà chưa
 *    có ngày hồ sơ lẫn ý kiến luật sư ({@see TransferDossier::allowsTransfer()}). Ô ngày dd/mm/yyyy.
 *
 * # Cổng: `settings.manage` (R14: không quyền mới), hỏi ở MỌI request — kể cả request cập nhật Livewire
 *
 * Cùng khuôn `OfficeProfilePage`: {@see self::canAccess()} hỏi `Gate::forUser()`; {@see self::boot()}
 * (chạy ở mount VÀ mọi request cập nhật Livewire, trước hook của Filament vốn trả 403) biến lần từ
 * chối thành 404; {@see self::save()} hỏi lại; Action hỏi lần nữa với actor tường minh.
 */
class DocumentStorePage extends Page
{
    protected string $view = 'filament.admin.pages.document-store';

    protected static ?string $slug = 'document-store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 30;

    /** `id` của thẻ `<form>` hồ sơ trong view — nút "Lưu" ở chân mục nộp đúng form này. */
    public const FORM_ID = 'document-store-dossier';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('document_store.page.navigation_label');
    }

    public function getTitle(): string
    {
        return __('document_store.page.title');
    }

    public static function canAccess(): bool
    {
        return Gate::forUser(Auth::user())->allows(Permission::SettingsManage->value);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp, mục "Cổng" — 404 ở mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function mount(): void
    {
        $this->fillWithStoredValues();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('document_store.page.dossier_form_heading'))
                    ->description(__('document_store.page.dossier_intro'))
                    // `collapsed()` tự bật `collapsible()`. Mở sẵn đúng khi việc này đang chặn đường:
                    // công tắc là `google_drive` mà chưa có ngày hồ sơ lẫn ý kiến luật sư.
                    ->collapsed(fn (): bool => ! (DocumentStore::usesRemote() && ! TransferDossier::current()->allowsTransfer()))
                    ->schema([
                        $this->pastDatePicker('transfer_dossier_on'),
                        TextInput::make('transfer_dossier_reference')
                            ->label(__('document_store.page.fields.transfer_dossier_reference'))
                            ->maxLength(TransferDossier::REFERENCE_MAX),
                        $this->pastDatePicker('dpa_accepted_on'),
                        $this->pastDatePicker('transfer_before_dossier_on'),
                        Textarea::make('transfer_before_dossier_basis')
                            ->label(__('document_store.page.fields.transfer_before_dossier_basis'))
                            ->rows(2)
                            ->maxLength(TransferDossier::BASIS_MAX),
                    ])
                    // Nút lưu ở CHÂN mục, trong phần thân thu gọn: mục đóng thì nút đóng theo — không còn
                    // một nút "Lưu" đứng dưới một mục đã đóng, bấm vào chỉ lưu lại đúng giá trị cũ. Nút
                    // nộp form của view (`id` = {@see self::FORM_ID}, `wire:submit="save"`), nên phím
                    // Enter trong một ô và cú bấm đi cùng một đường: {@see self::save()}.
                    ->footerActions([
                        Action::make('save')
                            ->label(__('document_store.page.submit'))
                            ->submit(self::FORM_ID),
                    ]),
            ]);
    }

    /**
     * Ô ngày của hồ sơ: ghi việc ĐÃ xảy ra, nên lịch không cho chọn sau hôm nay (`maxDate`, "hôm nay"
     * tính lại mỗi lần form hiện hay kiểm, theo múi giờ ứng dụng). `maxDate` cũng thêm luật
     * `before_or_equal` vào form; luật đó mang cùng câu lỗi với luật của {@see RecordDataTransferDossier}.
     * Action vẫn tự chặn cho mọi đường gọi khác.
     */
    private function pastDatePicker(string $field): DatePicker
    {
        return DatePicker::make($field)
            ->label(__("document_store.page.fields.{$field}"))
            ->native(false)
            ->displayFormat('d/m/Y')
            ->maxDate(fn (): string => today()->toDateString())
            ->validationMessages(['before_or_equal' => __('document_store.page.validation.not_in_future')]);
    }

    /**
     * Hành động THẬT: hỏi lại cổng, gọi {@see RecordDataTransferDossier}, đổi khoá lỗi của Action sang
     * đường dẫn trạng thái của form (`data.<ô>`) để lỗi hiện đúng dưới ô của nó, rồi điền lại form.
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 404);

        $data = $this->form->getState();

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $changed = app(RecordDataTransferDossier::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            $statePath = $this->getSchema('form')?->getStatePath();

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    (filled($statePath) ? "{$statePath}.{$field}" : $field) => $messages,
                ])
                ->all());
        }

        $notification = Notification::make()
            ->title(__($changed === [] ? 'document_store.page.notifications.unchanged' : 'document_store.page.notifications.saved'));

        $changed === [] ? $notification->info() : $notification->success();

        $notification->send();

        $this->fillWithStoredValues();
    }

    /**
     * Mọi con số của trang, đã định dạng để hiện (view `filament.admin.pages.document-store-figures`).
     * Chỉ số đếm, thời điểm và câu trạng thái — không dòng dữ liệu nào.
     *
     * Làn tl: cùng những con số như trước, xếp lại thành dải trạng thái (`overview`), các bước bật Drive
     * (`steps`), bốn ô số có sắc độ (`tiles`), các mục chữ (`sections`) và mục thu gọn "Chi tiết kỹ
     * thuật" (`technical`) — chỉ ở đó mới có tên biến môi trường.
     *
     * @return array{overview: array{state: string, situation: string, headline: string, next: string}, steps: array<string, bool>, tiles: array<string, array{value: string, tone: string}>, sections: array<string, array<string, string>>, technical: array<string, string>, detail: ?string}
     */
    public function figures(): array
    {
        $measure = app(MeasureDocumentStore::class);
        $health = SystemHealth::query()->where('singleton', 1)->first();
        $dossier = TransferDossier::current();
        $never = __('document_store.page.values.never');
        $driver = config('vkcrm.storage.driver');
        $status = $health?->document_store_status;

        $counts = [
            'pending_new' => $measure->pendingNewFiles(),
            'backlog' => $measure->pendingNewFiles((int) config('vkcrm.storage.push_alert_minutes')),
            'legacy' => $measure->legacyFiles(),
            'local_copies' => $measure->localCopiesKept(),
            'remote' => $measure->remoteMedia(),
            'unreceipted' => $measure->remoteWithoutOfficeReceipt(),
        ];

        return [
            'overview' => $this->overview($status, $dossier, $counts),
            'steps' => $this->steps($status, $dossier, $counts['legacy']),
            'tiles' => $this->tiles($counts),
            'sections' => [
                'status' => [
                    'enabled_at' => $this->time(DocumentStore::remoteEnabledAt()) ?? __('document_store.page.values.not_enabled'),
                    'status' => $status?->label() ?? __('document_store.page.values.not_checked'),
                    'checked_at' => $this->time($health?->document_store_checked_at) ?? $never,
                ],
                'files' => [
                    'oldest_pending' => $this->time($measure->oldestPendingAt()) ?? $never,
                    'items' => __('document_store.page.values.items', [
                        'count' => $this->number($measure->driveItems()),
                        'limit' => $this->number((int) config('vkcrm.storage.google_drive.item_limit')),
                    ]),
                ],
                'office' => [
                    'last_receipt' => $this->time($health?->last_office_receipt_at) ?? $never,
                    'receipt_error' => filled($health?->last_office_receipt_error) ? $health->last_office_receipt_error : $never,
                ],
                'dossier' => [
                    'first_transfer' => $this->time($dossier->firstTransferAt()) ?? $never,
                    'dossier_clock' => match (true) {
                        $dossier->dossierOn() !== null => __('document_store.page.values.clock_stopped', ['date' => $dossier->dossierOn()->format('d/m/Y')]),
                        $dossier->isOverdue() => __('document_store.page.values.clock_overdue', ['days' => $dossier->day() - TransferDossier::DUE_DAYS]),
                        $dossier->clockRunning() => __('document_store.page.values.clock_days_left', ['days' => $dossier->daysLeft()]),
                        default => __('document_store.page.values.clock_idle'),
                    },
                ],
            ],
            'technical' => [
                'mode' => DocumentStore::driverIsValid()
                    ? __('document_store.page.mode.'.$driver)
                    : __('document_store.page.mode.invalid', ['value' => var_export($driver, true)]),
            ],
            'detail' => $health?->document_store_detail,
        ];
    }

    /**
     * Dải trạng thái đầu trang: MỘT tình huống — câu chính, câu "cần làm gì tiếp" và một trong ba sắc
     * độ (`ok` xanh, `warning` vàng, `error` đỏ). Xét theo thứ tự nghiêm trọng, dừng ở điều đầu tiên
     * đúng:
     *
     *  1. công tắc lạ → đỏ (tệp mới ở lại máy chủ trong khi người vận hành có thể tin là đã lên Drive);
     *  2. lần kiểm tra tự động gần nhất `misconfigured`/`unavailable` → đỏ;
     *  3. production ({@see TransferDossier::appliesHere()}), tệp mới đang tự lên Drive
     *     ({@see DocumentStore::pushesNewFiles()}) mà không còn ngày hồ sơ lẫn ý kiến luật sư
     *     ({@see TransferDossier::allowsTransfer()} sai) → đỏ. Đọc thẳng `settings`, không chờ lần kiểm tra
     *     mỗi giờ: ngay khi quản trị viên xoá hai ngày đó trên chính trang này, lượt đẩy
     *     (`PushDocumentFileToRemote`) và `vkcrm:storage:migrate` đã từ chối, trong khi `system_health`
     *     còn giữ `ok` tới lượt kiểm tra kế tiếp (tối đa 60 phút);
     *  4. quá hạn 60 ngày nộp hồ sơ (chỉ production) → đỏ;
     *  5. sắp tới hạn nộp hồ sơ (production, từ ngày 45) → vàng — ở MỌI chế độ, trước nhánh máy chủ: đã
     *     quay lui về máy chủ mà đồng hồ 60 ngày còn chạy thì hạn pháp lý vẫn còn, lần kiểm tra mỗi giờ
     *     vẫn báo `degraded` và gửi thư nhắc mỗi ngày, nên dải không được nói "Không cần làm gì";
     *  6. công tắc là máy chủ: còn tệp trên Drive → vàng (đang quay lui); không → xanh;
     *  7. công tắc là Drive mà chưa có mốc bật → vàng;
     *  8. kiểm tra báo `degraded` → vàng;
     *  9. có tệp mới chờ đẩy quá `push_alert_minutes` (`$counts['backlog']`) → vàng. Từ đây công tắc là
     *     Drive và đã có mốc bật — đúng điều kiện ô "Tệp chờ đẩy lên Drive" tô đỏ ({@see self::tiles()}),
     *     nên ô đỏ thì dải không bao giờ xanh, kể cả khi lần kiểm tra mỗi giờ gần nhất còn `ok`;
     * 10. chưa có lần kiểm tra nào → vàng;
     * 11. còn tệp cũ chưa chuyển → vàng;
     * 12. còn lại → xanh.
     *
     * @param  array<string, int>  $counts
     * @return array{state: string, situation: string, headline: string, next: string}
     */
    private function overview(?DocumentStoreStatus $status, TransferDossier $dossier, array $counts): array
    {
        $legal = TransferDossier::appliesHere();

        [$state, $situation, $replace] = match (true) {
            ! DocumentStore::driverIsValid() => ['error', 'driver_invalid', []],
            in_array($status, [DocumentStoreStatus::Misconfigured, DocumentStoreStatus::Unavailable], true) => ['error', 'check_failed', []],
            $legal && DocumentStore::pushesNewFiles() && ! $dossier->allowsTransfer() => ['error', 'transfer_blocked', []],
            $legal && $dossier->isOverdue() => ['error', 'dossier_overdue', ['days' => $dossier->day() - TransferDossier::DUE_DAYS]],
            $legal && $dossier->isDueSoon() => ['warning', 'dossier_due', ['days' => $dossier->daysLeft()]],
            ! DocumentStore::usesRemote() => $counts['remote'] > 0
                ? ['warning', 'remote_while_local', ['count' => $this->number($counts['remote'])]]
                : ['ok', 'local', []],
            DocumentStore::remoteEnabledAt() === null => ['warning', 'not_enabled', []],
            $status === DocumentStoreStatus::Degraded => ['warning', 'degraded', []],
            $counts['backlog'] > 0 => ['warning', 'push_backlog', [
                'count' => $this->number($counts['backlog']),
                'minutes' => (int) config('vkcrm.storage.push_alert_minutes'),
            ]],
            $status === null => ['warning', 'not_checked', []],
            $counts['legacy'] > 0 => ['warning', 'legacy_pending', ['count' => $this->number($counts['legacy'])]],
            default => ['ok', 'remote_ok', []],
        };

        return [
            'state' => $state,
            'situation' => $situation,
            'headline' => __('document_store.page.overview.headline.'.$situation, $replace),
            'next' => __('document_store.page.overview.next.'.$situation),
        ];
    }

    /**
     * Năm bước bật Google Drive, `true` = đã xong. Mỗi bước đọc đúng dữ liệu đã có, không gọi Google:
     *
     *  - `server`: đã cấu hình đường dẫn khoá tài khoản dịch vụ;
     *  - `shared_drive`: đã cấu hình mã Shared Drive VÀ thư mục gốc (`vkcrm:storage:init` in ra mã thứ hai);
     *  - `dossier`: có ngày hồ sơ hoặc ý kiến luật sư — đúng điều kiện mở cổng production (R13);
     *  - `enabled_checked`: tệp mới đang tự lên Drive (công tắc + mốc bật, {@see DocumentStore::pushesNewFiles()})
     *    VÀ lần kiểm tra tự động gần nhất `ok`. Lần kiểm tra bằng lệnh trước khi bật không để lại dấu vết
     *    nào trong CSDL; lần kiểm tra mỗi giờ chạy ở mọi chế độ nhưng chỉ ghi `ok` khi kho có liên quan
     *    (công tắc `google_drive` hoặc còn media trên Drive) — kho không liên quan thì trạng thái là `null`
     *    hoặc `degraded` của đồng hồ 60 ngày, không bao giờ `ok`;
     *  - `migrated`: tệp mới đang tự lên Drive VÀ không còn tệp cũ ở máy chủ.
     *
     * @return array<string, bool>
     */
    private function steps(?DocumentStoreStatus $status, TransferDossier $dossier, int $legacy): array
    {
        $pushes = DocumentStore::pushesNewFiles();

        return [
            'server' => filled(config('vkcrm.storage.google_drive.credentials_path')),
            'shared_drive' => filled(config('vkcrm.storage.google_drive.shared_drive_id'))
                && filled(config('vkcrm.storage.google_drive.root_folder_id')),
            'dossier' => $dossier->allowsTransfer(),
            'enabled_checked' => $pushes && $status === DocumentStoreStatus::Ok,
            'migrated' => $pushes && $legacy === 0,
        ];
    }

    /**
     * Bốn ô số và sắc độ của chúng: `good` (xanh), `warning` (vàng), `danger` (đỏ), `neutral` (xám).
     * Khi Drive chưa bật, tệp cũ trên máy chủ là trạng thái bình thường — không tô vàng.
     *
     * @param  array<string, int>  $counts
     * @return array<string, array{value: string, tone: string}>
     */
    private function tiles(array $counts): array
    {
        $pushes = DocumentStore::pushesNewFiles();

        return [
            'pending_new' => [
                'value' => $this->number($counts['pending_new']),
                'tone' => match (true) {
                    ! $pushes => 'neutral',
                    $counts['backlog'] > 0 => 'danger',
                    $counts['pending_new'] === 0 => 'good',
                    default => 'neutral',
                },
            ],
            'legacy' => [
                'value' => $this->number($counts['legacy']),
                'tone' => ! $pushes ? 'neutral' : ($counts['legacy'] === 0 ? 'good' : 'warning'),
            ],
            'unreceipted' => [
                'value' => $this->number($counts['unreceipted']),
                'tone' => match (true) {
                    $counts['remote'] === 0 => 'neutral',
                    $counts['unreceipted'] > 0 => 'warning',
                    default => 'good',
                },
            ],
            'local_copies' => [
                'value' => $this->number($counts['local_copies']),
                'tone' => 'neutral',
            ],
        ];
    }

    protected function getViewData(): array
    {
        return ['figures' => $this->figures()];
    }

    private function fillWithStoredValues(): void
    {
        $dossier = TransferDossier::current();

        $this->form->fill(collect(TransferDossier::KEYS)
            ->mapWithKeys(fn (string $key, string $field): array => [$field => $dossier->stored($field)])
            ->all());
    }

    private function time(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('H:i d/m/Y');
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
