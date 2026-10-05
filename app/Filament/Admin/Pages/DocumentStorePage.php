<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Storage\MeasureDocumentStore;
use App\Actions\Storage\RecordDataTransferDossier;
use App\Enums\Permission;
use App\Models\SystemHealth;
use App\Models\User;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\TransferDossier;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
 *    đúng luật `max:` của Action.
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
                Section::make(__('document_store.page.sections.dossier'))
                    ->description(__('document_store.page.dossier_intro'))
                    ->schema([
                        DatePicker::make('transfer_dossier_on')
                            ->label(__('document_store.page.fields.transfer_dossier_on')),
                        TextInput::make('transfer_dossier_reference')
                            ->label(__('document_store.page.fields.transfer_dossier_reference'))
                            ->maxLength(TransferDossier::REFERENCE_MAX),
                        DatePicker::make('dpa_accepted_on')
                            ->label(__('document_store.page.fields.dpa_accepted_on')),
                        DatePicker::make('transfer_before_dossier_on')
                            ->label(__('document_store.page.fields.transfer_before_dossier_on')),
                        Textarea::make('transfer_before_dossier_basis')
                            ->label(__('document_store.page.fields.transfer_before_dossier_basis'))
                            ->rows(2)
                            ->maxLength(TransferDossier::BASIS_MAX),
                    ]),
            ]);
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
     * @return array{sections: array<string, array<string, string>>, detail: ?string}
     */
    public function figures(): array
    {
        $measure = app(MeasureDocumentStore::class);
        $health = SystemHealth::query()->where('singleton', 1)->first();
        $dossier = TransferDossier::current();
        $never = __('document_store.page.values.never');
        $driver = config('vkcrm.storage.driver');

        return [
            'sections' => [
                'status' => [
                    'mode' => DocumentStore::driverIsValid()
                        ? __('document_store.page.mode.'.$driver)
                        : __('document_store.page.mode.invalid', ['value' => var_export($driver, true)]),
                    'enabled_at' => $this->time(DocumentStore::remoteEnabledAt()) ?? __('document_store.page.values.not_enabled'),
                    'status' => $health?->document_store_status?->label() ?? __('document_store.page.values.not_checked'),
                    'checked_at' => $this->time($health?->document_store_checked_at) ?? $never,
                ],
                'files' => [
                    'pending_new' => $this->number($measure->pendingNewFiles()),
                    'oldest_pending' => $this->time($measure->oldestPendingAt()) ?? $never,
                    'legacy' => $this->number($measure->legacyFiles()),
                    'local_copies' => $this->number($measure->localCopiesKept()),
                    'items' => __('document_store.page.values.items', [
                        'count' => $this->number($measure->driveItems()),
                        'limit' => $this->number((int) config('vkcrm.storage.google_drive.item_limit')),
                    ]),
                ],
                'office' => [
                    'unreceipted' => $this->number($measure->remoteWithoutOfficeReceipt()),
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
            'detail' => $health?->document_store_detail,
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
