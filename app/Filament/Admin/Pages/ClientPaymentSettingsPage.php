<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Settings\UpdateClientPaymentSettings;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Billing\ClientPaymentSettings;
use App\Support\Billing\VietQr;
use App\Support\Billing\VietQrBanks;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Trang "Nhắc thanh toán cho khách" (làn nt Task 1) — MỘT công tắc toàn hệ thống, mặc định TẮT, cùng
 * thông tin chuyển khoản của văn phòng và số ngày báo trước hạn. Ý nghĩa của công tắc ở docblock
 * {@see ClientPaymentSettings}; luật lưu (quyền, kiểm tra, "bật thì phải đủ thông tin chuyển khoản",
 * audit) ở {@see UpdateClientPaymentSettings}. Trang chỉ vẽ form, gọi Action, và vẽ một mã QR xem thử
 * (không số tiền, không nội dung) để quản trị viên quét bằng ứng dụng ngân hàng trước khi bật.
 *
 * # Cổng: `settings.manage`, hỏi ở MỌI request — cùng ba chỗ với `OfficeProfilePage`
 *
 *  - {@see self::boot()}: Livewire gọi `boot()` ở đầu cả lần mount lẫn mọi request cập nhật — từ chối
 *    thành 404 (hook của Filament trả 403, và middleware 404 của panel không phủ request cập nhật);
 *  - {@see self::save()}: hành động thật hỏi lại;
 *  - và chính Action hỏi lần nữa với actor tường minh.
 *
 * Mọi giới hạn độ dài lấy từ {@see ClientPaymentSettings::TEXT_LIMITS} — cùng con số với luật `max:`
 * của Action.
 */
class ClientPaymentSettingsPage extends Page
{
    protected string $view = 'filament.admin.pages.client-payment-settings';

    protected static ?string $slug = 'client-payment-reminders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('client_payments.settings.navigation_label');
    }

    public function getTitle(): string
    {
        return __('client_payments.settings.page_title');
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
                Section::make(__('client_payments.settings.sections.switch'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('client_payments.settings.fields.enabled.label'))
                            ->helperText(__('client_payments.settings.fields.enabled.hint')),
                        TextInput::make('days_before_due')
                            ->label(__('client_payments.settings.fields.days_before_due.label'))
                            ->helperText(__('client_payments.settings.fields.days_before_due.hint'))
                            ->integer()
                            ->minValue(ClientPaymentSettings::MIN_DAYS_BEFORE_DUE)
                            ->maxValue(ClientPaymentSettings::MAX_DAYS_BEFORE_DUE)
                            ->required(),
                    ]),
                Section::make(__('client_payments.settings.sections.bank'))
                    ->schema([
                        Select::make('bank_bin')
                            ->label(__('client_payments.settings.fields.bank_bin.label'))
                            ->helperText(__('client_payments.settings.fields.bank_bin.hint'))
                            ->options(collect(VietQrBanks::NAMES)->mapWithKeys(fn (string $name, int|string $bin): array => [(string) $bin => $name])->all())
                            ->searchable(),
                        $this->textField('account_number'),
                        $this->textField('account_holder'),
                        $this->textField('bank_branch'),
                    ]),
            ]);
    }

    /**
     * Hành động THẬT: hỏi lại cổng, gọi {@see UpdateClientPaymentSettings}, đổi khoá lỗi của Action
     * (`account_number`) sang đường dẫn trạng thái của form (`data.account_number`), rồi điền lại form
     * bằng giá trị vừa lưu (số tài khoản đã chuẩn hoá).
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 404);

        $data = $this->form->getState();

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $changed = app(UpdateClientPaymentSettings::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            $statePath = $this->getSchema('form')?->getStatePath();

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    (filled($statePath) ? "{$statePath}.{$field}" : $field) => $messages,
                ])
                ->all());
        }

        $notification = Notification::make()
            ->title(__($changed === [] ? 'client_payments.settings.notifications.unchanged' : 'client_payments.settings.notifications.saved'));

        $changed === [] ? $notification->info() : $notification->success();

        $notification->send();

        $this->fillWithStoredValues();
    }

    /**
     * Mã QR xem thử của tài khoản ĐÃ LƯU (không phải ô đang gõ dở): `data:` URI SVG, hoặc `null` khi
     * thông tin chuyển khoản đã lưu chưa đủ. Mã tĩnh — không số tiền, không nội dung chuyển khoản.
     */
    public function previewQr(): ?string
    {
        abort_unless(static::canAccess(), 404);

        $account = ClientPaymentSettings::current()->transferAccount();

        if ($account === null) {
            return null;
        }

        return VietQr::svgDataUri(VietQr::payload($account['bank_bin'], $account['account_number'], null, null));
    }

    private function fillWithStoredValues(): void
    {
        $settings = ClientPaymentSettings::current();

        $this->form->fill([
            'enabled' => $settings->isEnabled(),
            'days_before_due' => $settings->daysBeforeDue(),
            'bank_bin' => $settings->stored('bank_bin'),
            'account_number' => $settings->stored('account_number'),
            'account_holder' => $settings->stored('account_holder'),
            'bank_branch' => $settings->stored('bank_branch'),
        ]);
    }

    private function textField(string $field): TextInput
    {
        $input = TextInput::make($field)
            ->label(__("client_payments.settings.fields.{$field}.label"))
            ->maxLength(ClientPaymentSettings::TEXT_LIMITS[$field]);

        $hint = "client_payments.settings.fields.{$field}.hint";

        return __($hint) === $hint ? $input : $input->helperText(__($hint));
    }
}
