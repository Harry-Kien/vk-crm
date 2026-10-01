<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Settings\UpdateOfficeProfile;
use App\Enums\Permission;
use App\Models\User;
use App\Support\OfficeProfile;
use BackedEnum;
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
 * Trang "Thông tin văn phòng" (M7 Task 10) — chỉ admin sửa chín thông tin in trên mọi thư, trên
 * chân `MUC-LUC.pdf` và trên cổng khách hàng: bốn thông tin pháp lý (mã số thuế, Đoàn Luật sư, số
 * Giấy đăng ký hoạt động, địa chỉ trụ sở), tên pháp lý, hotline, Zalo, website, email liên hệ.
 * Trước trang này, chúng chỉ đổi được bằng cách sửa `.env` trên máy chủ.
 *
 * Luật nghiệp vụ ở {@see UpdateOfficeProfile} (quyền, kiểm tra, chuẩn hoá, audit); đọc lại ở
 * {@see OfficeProfile}. Trang chỉ vẽ form và gọi Action.
 *
 * # Cổng: `settings.manage`, hỏi ở MỌI request — kể cả request cập nhật Livewire
 *
 * {@see self::canAccess()} hỏi `Gate::forUser()` với `settings.manage` (chỉ admin, SPEC §5). Hỏi ở
 * ba chỗ:
 *  - {@see self::boot()} — Livewire gọi `boot()` ở ĐẦU cả lần mount lẫn MỌI request cập nhật,
 *    TRƯỚC các hook `mount…`/`hydrate…` của trait. Đây là chỗ biến lần từ chối thành 404: hook
 *    `hydrateCanAuthorizeAccess()` của Filament cũng hỏi `canAccess()` nhưng trả **403**, và
 *    middleware 404 của panel không phủ được request cập nhật (đã đo ở `DenialCodeTest`). Một
 *    admin bị hạ quyền giữa lúc trang đang mở nhận 404 ở lần bấm kế tiếp;
 *  - {@see self::save()} — hành động THẬT hỏi lại, không tin vòng đời đã chạy;
 *  - và chính Action hỏi lần nữa với actor tường minh.
 *
 * # Ô trống = dùng cấu hình
 *
 * Form mở với giá trị ĐÃ LƯU, không phải giá trị đang dùng: một ô chưa lưu gì để trống, và gợi ý
 * (placeholder) nói giá trị cấu hình mà hệ thống đang dùng thay. Điền sẵn giá trị cấu hình vào ô
 * sẽ khiến lần bấm "Lưu" đầu tiên chép `.env` vào bảng `settings`, và từ đó một lần sửa `.env` không
 * còn tác dụng nữa mà không ai biết vì sao.
 *
 * Mọi giới hạn độ dài lấy từ {@see OfficeProfile::FIELDS} — cùng con số với luật `max:` của Action.
 */
class OfficeProfilePage extends Page
{
    protected string $view = 'filament.admin.pages.office-profile';

    protected static ?string $slug = 'office-profile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('office.navigation_label');
    }

    public function getTitle(): string
    {
        return __('office.page_title');
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
                Section::make(__('office.sections.legal'))
                    ->schema([
                        $this->textField('legal_name'),
                        $this->textField('tax_code'),
                        $this->textField('bar_association'),
                        $this->textField('licence_number'),
                        Textarea::make('office_address')
                            ->label(__('office.fields.office_address.label'))
                            ->placeholder(fn (): string => self::placeholderFor('office_address'))
                            ->rows(2)
                            ->maxLength(OfficeProfile::FIELDS['office_address']),
                    ]),
                Section::make(__('office.sections.contact'))
                    ->schema([
                        // `type('tel')` chứ không `tel()`: `tel()` gắn thêm một regex của Filament,
                        // còn luật thật của hotline (chuẩn hoá qua Normalizer::phone()) nằm ở Action.
                        $this->textField('hotline')->type('tel'),
                        $this->textField('zalo')->url(),
                        $this->textField('website')->url(),
                        $this->textField('reply_to')->email(),
                    ]),
            ]);
    }

    /**
     * Hành động THẬT: hỏi lại cổng, gọi {@see UpdateOfficeProfile}, đổi khoá lỗi của Action
     * (`tax_code`) sang đường dẫn trạng thái của form (`data.tax_code`) để lỗi hiện đúng dưới ô của
     * nó, rồi điền lại form bằng giá trị vừa lưu (đã chuẩn hoá: hotline theo cách viết trong nước,
     * mã số thuế 13 chữ số có gạch).
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 404);

        $data = $this->form->getState();

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $changed = app(UpdateOfficeProfile::class)->handle($actor, $data);
        } catch (ValidationException $exception) {
            $statePath = $this->getSchema('form')?->getStatePath();

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    (filled($statePath) ? "{$statePath}.{$field}" : $field) => $messages,
                ])
                ->all());
        }

        $notification = Notification::make()
            ->title(__($changed === [] ? 'office.notifications.unchanged' : 'office.notifications.saved'));

        $changed === [] ? $notification->info() : $notification->success();

        $notification->send();

        $this->fillWithStoredValues();
    }

    private function fillWithStoredValues(): void
    {
        $office = OfficeProfile::current();

        $this->form->fill(collect(OfficeProfile::FIELDS)
            ->mapWithKeys(fn (int $limit, string $field): array => [$field => $office->stored($field)])
            ->all());
    }

    private function textField(string $field): TextInput
    {
        $input = TextInput::make($field)
            ->label(__("office.fields.{$field}.label"))
            ->placeholder(fn (): string => self::placeholderFor($field))
            ->maxLength(OfficeProfile::FIELDS[$field]);

        $hint = "office.fields.{$field}.hint";

        return __($hint) === $hint ? $input : $input->helperText(__($hint));
    }

    /** Gợi ý trong ô trống: giá trị cấu hình mà hệ thống đang dùng thay, hoặc "Chưa có". */
    private static function placeholderFor(string $field): string
    {
        $configured = OfficeProfile::current()->configured($field);

        return $configured === null
            ? __('office.placeholder_none')
            : __('office.placeholder_configured', ['value' => $configured]);
    }
}
