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

    /**
     * SPEC §8.1 là "lần đầu đăng nhập bắt buộc đổi mật khẩu", không phải "ai cũng đổi được lúc
     * nào cũng được". Không có phương thức này thì trang mở cho mọi khách đã đăng nhập, vì
     * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` trả `true` cho mọi trang tự
     * viết — middleware của panel chỉ đổi HÌNH DẠNG của một lần từ chối (403 thành 404), nó
     * không tự sinh ra lần từ chối nào. Hệ quả nếu để mở, và đây là lý do phải đóng: một khách
     * quay lại sau nhiều tháng gõ đúng địa chỉ này sẽ đọc "Đây là lần đầu anh/chị đăng nhập",
     * một câu sai, rồi đặt lại mật khẩu mà không phải nhắc lại mật khẩu đang dùng.
     *
     * **Đổi mật khẩu tự nguyện là một tính năng khác và chưa có.** Nó cần một ô "mật khẩu hiện
     * tại" (không có ô đó thì một máy bỏ quên đang mở phiên là một tài khoản bị chiếm) và một
     * câu chữ khác hẳn. Ghi ra ở đây để nó là một việc được hoãn có chủ ý, không phải một việc
     * bị quên: khách muốn đổi mật khẩu hôm nay thì gọi văn phòng.
     *
     * Hỏi panel `portal` THEO TÊN, không hỏi `Filament::auth()` và cũng không hỏi panel hiện
     * hành. Ba cách, ba câu trả lời khác nhau ngoài ngữ cảnh panel, và chỉ một câu đúng:
     *
     *  - `Filament::auth()` gọi `getCurrentOrDefaultPanel()->auth()` trên một giá trị có thể là
     *    `null`, và không panel nào của dự án gọi `->default()`, nên nó NÉM
     *    `NoDefaultPanelSetException` thay vì trả lời;
     *  - `Filament::getCurrentPanel()` rồi rơi về `false` khi không có panel thì không ném, mà
     *    NÓI SAI: với một khách đang nợ văn phòng một lần đổi mật khẩu, "không" là cánh cổng
     *    đóng vào mặt đúng người bắt buộc phải đi qua nó. Và vì panel từ chối bằng 404
     *    (`AnswerDeniedPanelRequestsWithNotFound`), câu sai ấy hiện ra thành "không có trang nào
     *    như vậy" — một lời nói dối im lặng, không phải một tiếng động ai đó nghe thấy;
     *  - hỏi panel `portal` theo tên thì câu trả lời nói về NGƯỜI DÙNG, và nó giống nhau dù ai
     *    đang hỏi. `FilamentManager::getPanel()` trả `null` chứ không ném khi không tìm thấy,
     *    nên tính chất "không ném ngoài ngữ cảnh panel" vẫn còn nguyên.
     *
     * Đường request thật không bao giờ tới được nhánh "không có panel":
     * `Filament\Http\Middleware\SetUpPanel` nằm trong danh sách middleware BỀN của Livewire và
     * chạy ở móc `snapshot-verified`, tức trước `hydrateCanAuthorizeAccess()`. Nhưng đó là một
     * tính chất của thứ tự middleware trong một gói khác, không phải của trang này — nên trang
     * này thôi dựa vào nó. Có test đo cả hai vế ở `tests/Feature/Portal/LoginTest.php`: câu trả
     * lời ngoài ngữ cảnh panel, và một request cập nhật Livewire THẬT gửi đi sau khi panel hiện
     * hành đã bị xoá.
     *
     * Tên panel viết cứng ở đây là nhất quán chứ không phải một chỗ rò rỉ mới: cặp đôi của trang
     * này, `App\Http\Middleware\RequirePortalPasswordChange`, đã gọi
     * `ChangePassword::getUrl(panel: 'portal')` và `Filament::getPanel('portal')`.
     */
    public static function canAccess(): bool
    {
        $user = Filament::getPanel('portal')?->auth()->user();

        return $user instanceof ClientUser && $user->must_change_password;
    }

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
            /*
             * Task 7 (R12, phát hiện `intake/intake-04`, `intake/intake-05`): activated_at CHỈ hệ
             * thống ghi, ở đúng chỗ này — bằng chứng DUY NHẤT người vừa đổi mật khẩu làm chủ hộp
             * thư đã gõ, vì lần đổi này đi kèm bước xác thực mã OTP qua đúng email đó (SPEC §8.1).
             * Trước bản sửa này không có đường nào ghi cột này, nên luật người nhận R12 (nay ở
             * ResolveClientRecipients::eligibleQuery()) không có gì để lọc và thư
             * client.stage_update đi tới cả những địa chỉ nhân sự gõ nhầm, chưa từng đăng nhập.
             *
             * `?? now()` — CHỈ ghi khi CHƯA từng có giá trị, không phải luôn now(): nhân sự đặt
             * lại mật khẩu (EditClientUser::mutateFormDataBeforeSave()) bật must_change_password
             * lên lại, đưa khách quay lại đúng trang này lần hai, nhưng đó không phải một lần
             * "kích hoạt" thứ hai — ngày kích hoạt đúng nghĩa vẫn là lần đầu tiên.
             */
            'activated_at' => $user->activated_at ?? now(),
        ])->save();

        /*
         * Băm mật khẩu trong PHIÊN phải đi theo mật khẩu vừa đổi, nếu không request kế tiếp đăng
         * xuất chính người vừa đổi.
         *
         * `Illuminate\Session\Middleware\AuthenticateSession` (Filament kế thừa nguyên) so
         * `password_hash_{guard}` trong phiên với `getAuthPassword()` của người dùng, và khi
         * lệch thì `logoutCurrentDevice()` + `session()->flush()`. Nó cất giá trị ấy ở **lần
         * tải trang đầy đủ**; việc đổi mật khẩu lại xảy ra trong một request CẬP NHẬT Livewire,
         * nơi middleware không chạy — nên phiên vẫn giữ băm CŨ, và cú `redirect(Filament::getUrl())`
         * ngay bên dưới là request đầy đủ kế tiếp, tức chỗ khách bị đá ra.
         *
         * Hệ quả nếu thiếu, và nó không phải chuyện lý thuyết: khách đặt mật khẩu xong, mất luôn
         * câu "Xong rồi", rơi về màn hình đăng nhập không một lời giải thích, rồi phải làm lại
         * email + mật khẩu + một mã OTP mới. Một khách demo gặp nó trong năm phút đầu tiên.
         *
         * Cách chữa lấy nguyên của Filament (`Auth\Pages\EditProfile` dòng 216–220): cất thẳng
         * băm mới vào phiên. Cất băm THÔ là đúng, không phải thiếu sót — `validatePasswordHash()`
         * chấp nhận cả dạng HMAC lẫn dạng thô (`hash_equals($passwordHash, $storedValue)`), và
         * lần tải trang kế tiếp sẽ tự thay nó bằng dạng HMAC.
         *
         * Một chỗ lệch với Filament, cố ý: bản của họ bọc trong `if (request()->hasSession())`.
         * Request mà Livewire dựng KHÔNG phải lúc nào cũng mang phiên (bộ dựng request của trình
         * kiểm thử, và request giả của đường ống middleware bền, đều không), và khi đó điều kiện
         * ấy lặng lẽ bỏ qua đúng dòng giữ khách ở lại — một cách hỏng không ai nhìn thấy. Kho
         * phiên toàn cục là CÙNG một đối tượng `StartSession` đã gắn vào request, nên hỏi thẳng
         * nó là câu trả lời đúng ở cả hai đường — cùng cách rơi về mà
         * `App\Http\Middleware\EnsurePortalAccountIsActive` đã phải dùng, ở đó viết tường minh
         * thành `$request->hasSession() ? $request->session() : session()`.
         */
        session()->put([
            'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
        ]);

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
