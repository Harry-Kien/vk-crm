<?php

namespace App\Filament\Portal\Auth;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Ô nhập mã 6 số của cổng khách hàng (SPEC §8.1), dựng trên bộ MFA có sẵn của Filament 5.
 *
 * Phần cơ chế giữ nguyên của Filament và KHÔNG chép lại ở đây: sinh mã 6 số, băm bằng
 * `Hash::make()` rồi cất vào phiên cùng một khoá hạn dùng riêng, xoá khoá khi mã khớp (mã dùng
 * một lần), và kiểm lại mật khẩu một lần nữa sau khi mã đúng. Vì mã nằm trong PHIÊN chứ không
 * trong một bảng, M5 không cần thêm bảng nào — và mã gắn với chính trình duyệt đã nhập mật khẩu,
 * điều mà `portal.login.code.hint` nói ra cho khách.
 *
 * # Thứ lớp này thay: MỘT câu lỗi thành BA
 *
 * `EmailAuthentication::getChallengeFormComponents()` của Filament gắn một rule duy nhất gọi
 * `verifyCode()`, và `verifyCode()` trả `false` cho cả bốn trường hợp — chưa có mã nào, mã đã
 * dùng rồi, mã hết hạn, mã gõ sai. Cả bốn ra cùng một câu
 * (`login_form.code.messages.invalid`: "Mã bạn vừa nhập không hợp lệ"). Người đọc câu đó không
 * biết nên gõ lại hay nên bấm "Gửi lại mã", và SPEC §8 cấm đúng kiểu thông điệp ấy.
 *
 * Nên rule ở đây hỏi hai câu theo thứ tự:
 *
 *  1. Mã trong phiên còn dùng được không (`isCodeUnusable()`)? Không — thì lỗi là "hết hạn hoặc
 *     đã dùng rồi", và việc phải làm là bấm "Gửi lại mã". **Mã đã dùng rồi cũng rơi vào nhánh
 *     này**, vì `verifyCode()` xoá khoá phiên khi khớp: với người đang ngồi trước màn hình, "mã
 *     này không còn dùng được nữa" là câu đúng cho cả hai, và việc phải làm giống hệt nhau.
 *  2. Còn dùng được mà `verifyCode()` vẫn từ chối thì chỉ còn một khả năng: gõ sai. Câu lỗi nói
 *     mã gồm 6 chữ số và bảo xem lại thư mới nhất.
 *
 * Câu thứ ba — bị khoá tạm 15 phút — không nằm ở đây mà ở `Login::isMultiFactorChallengeRateLimited()`,
 * vì nó là chuyện của bộ đếm chứ không phải chuyện của mã.
 *
 * `verifyCode()` của lớp cha vẫn là nơi DUY NHẤT quyết định mã có đúng hay không, kể cả khi
 * `isCodeUnusable()` đã nói là còn dùng được: hai lần hỏi không được phép trở thành hai định
 * nghĩa. `isCodeUnusable()` chỉ chọn CÂU CHỮ, không cấp quyền.
 *
 * # Ranh giới SPEC §10.10
 *
 * Ba câu này chỉ xuất hiện SAU khi mật khẩu đã đúng — `getChallengeFormComponents()` chỉ được
 * dựng cho một `$user` mà `Login::authenticate()` đã xác thực xong. Bước nhập email + mật khẩu
 * giữ nguyên câu chung chung của Filament, nên không câu nào ở đây tiết lộ một tài khoản có tồn
 * tại hay không.
 */
class PortalEmailAuthentication extends EmailAuthentication
{
    /**
     * Mã trong phiên đã không còn dùng được: chưa từng có, đã bị xoá sau một lần dùng, hoặc quá
     * hạn. Đọc đúng hai khoá phiên mà lớp cha ghi vào, không dựng khoá riêng.
     */
    public function isCodeUnusable(HasEmailAuthentication $user): bool
    {
        if (! ($user instanceof Model)) {
            return true;
        }

        $codeHash = session($this->getCodeSessionKey($user));
        $codeExpiresAt = session($this->getCodeExpirySessionKey($user));

        return blank($codeHash)
            || blank($codeExpiresAt)
            || now()->greaterThan($codeExpiresAt);
    }

    /**
     * Lớp cha gửi mã rồi bỏ qua kết quả. `sendCode()` trả `false` khi bộ đếm riêng của nó
     * (2 lần / 60 giây, khoá `filament-email-authentication:{id}`) chặn — và khi đó khách nhìn
     * vào một ô nhập mã mà chẳng có thư nào tới. Nói ra bằng tiếng Việt thay vì để họ ngồi đợi.
     */
    public function beforeChallenge(Authenticatable $user): void
    {
        if (! ($user instanceof HasEmailAuthentication)) {
            parent::beforeChallenge($user);

            return;
        }

        if (! $this->sendCode($user)) {
            Notification::make()
                ->title(__('portal.login.code.resend_throttled'))
                ->danger()
                ->send();
        }
    }

    /**
     * @param  Authenticatable&HasEmailAuthentication  $user
     * @return array<int, Component>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [
            OneTimeCodeInput::make('code')
                ->label(__('portal.login.code.label'))
                ->validationAttribute(__('portal.login.code.validation_attribute'))
                ->belowContent([
                    Text::make(__('portal.login.code.hint')),
                    Action::make('resend')
                        ->label(__('portal.login.code.resend'))
                        ->link()
                        ->action(function () use ($user): void {
                            if (! $this->sendCode($user)) {
                                Notification::make()
                                    ->title(__('portal.login.code.resend_throttled'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('portal.login.code.resent'))
                                ->success()
                                ->send();
                        }),
                ])
                ->required()
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] $value, Closure $fail) use ($user): void {
                    if ($this->isCodeUnusable($user)) {
                        $fail(__('portal.login.code.expired'));

                        return;
                    }

                    if (! (is_string($value) && $this->verifyCode($value, $user))) {
                        $fail(__('portal.login.code.invalid'));
                    }
                }),
        ];
    }
}
