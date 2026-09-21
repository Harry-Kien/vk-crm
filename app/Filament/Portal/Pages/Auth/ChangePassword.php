<?php

namespace App\Filament\Portal\Pages\Auth;

use App\Models\ClientUser;
use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use SensitiveParameter;

/**
 * Màn hình đổi mật khẩu lần đầu — SPEC §8.1 "Lần đầu đăng nhập bắt buộc đổi mật khẩu".
 *
 * Cặp đôi với `App\Http\Middleware\RequirePortalPasswordChange`: trang này là chỗ DUY NHẤT mà
 * middleware kia cho đi qua khi `must_change_password` còn bật, nên không có cách nào đi vòng
 * bằng cách gõ thẳng một URL khác của cổng.
 *
 * Kế thừa `Filament\Pages\Page` chứ không phải `SimplePage` là một lựa chọn có lý do đo được:
 * `Panel::discoverPages()` đăng ký route cho các lớp con của `Page`, còn `SimplePage` KHÔNG phải
 * lớp con của `Page` (cả hai cùng kế thừa `BasePage`) nên không có route nào được sinh ra. Trang
 * đăng nhập dùng được `SimplePage` vì panel tự đăng ký route cho nó qua `->login()`; trang này
 * thì không có cơ chế tương đương.
 *
 * @property-read Schema $form
 */
class ChangePassword extends Page
{
    protected string $view = 'filament.portal.pages.auth.change-password';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    /**
     * Không vào thanh điều hướng: đây là một cánh cổng bắt buộc đi qua một lần, không phải một
     * mục khách chọn.
     */
    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('portal.change_password.title');
    }

    public function getHeading(): string|Htmlable
    {
        return __('portal.change_password.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('portal.change_password.description');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('password')
                    ->label(__('portal.change_password.fields.password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->rule(PasswordRule::default())
                    // `rule()` ĐÁNH GIÁ một Closure rồi mới dùng kết quả làm luật
                    // (`CanBeValidated::getValidationRules()`), nên luật thật phải nằm trong một
                    // lớp bọc — truyền thẳng closure ba tham số vào đây sẽ bị Filament coi là
                    // closure cần tiêm tham số và vỡ.
                    ->rule(fn (): Closure => $this->rejectTheOldPassword())
                    ->same('passwordConfirmation')
                    ->validationAttribute(__('portal.change_password.fields.password'))
                    // Không có bước dựng CSS trong dự án (CLAUDE.md), nên cỡ chạm tối thiểu 44px
                    // đặt bằng style nội tuyến chứ không bằng một lớp tiện ích Tailwind — một lớp
                    // như vậy sẽ không tô gì cả trên `theme.css` dựng sẵn của Filament.
                    ->extraInputAttributes(['style' => 'min-height: 44px'])
                    ->autofocus(),
                TextInput::make('passwordConfirmation')
                    ->label(__('portal.change_password.fields.password_confirmation'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false)
                    ->extraInputAttributes(['style' => 'min-height: 44px']),
            ])
            ->statePath('data');
    }

    public function changePassword(): void
    {
        $user = Filament::auth()->user();

        if (! ($user instanceof ClientUser)) {
            return;
        }

        /** @var array{password: string} $data */
        $data = $this->form->getState();

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        $this->form->fill();

        Notification::make()
            ->title(__('portal.change_password.saved'))
            ->success()
            ->send();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    /**
     * Mật khẩu mới phải khác mật khẩu văn phòng đã gửi. Không có điều kiện này thì màn hình vẫn
     * "đổi" xong trong khi bí mật đi qua email của khách vẫn là bí mật đang dùng — tức là cánh
     * cổng của SPEC §8.1 đóng mà không chặn gì.
     */
    private function rejectTheOldPassword(): Closure
    {
        return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
            $user = Filament::auth()->user();

            if ($user instanceof ClientUser && is_string($value) && Hash::check($value, $user->getAuthPassword())) {
                $fail(__('portal.change_password.reuse'));
            }
        };
    }
}
