<?php

use App\Filament\Portal\Pages\Auth\Login;
use App\Models\ClientUser;
use Filament\Facades\Filament;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn portal — khách QUÊN mật khẩu.
 *
 * Cổng không có đường tự đặt lại mật khẩu (thư kích hoạt nói thẳng điều đó, `portal.email.activation.otp_note`);
 * mật khẩu mới chỉ có khi văn phòng bấm "Cấp lại mật khẩu". Nhưng câu báo khi gõ sai là câu chung
 * của Filament, "Thông tin đăng nhập không chính xác." — không nói khách phải làm gì. Khách lớn tuổi
 * quên mật khẩu gõ thử năm lần, bị khoá 15 phút, rồi mới thấy số điện thoại văn phòng ở câu khoá.
 * SPEC §8.4 (và §8 nói chung): thông báo lỗi phải nói rõ phải làm gì.
 *
 * Câu mới vẫn y hệt cho email không tồn tại và mật khẩu sai — không mở đường dò tài khoản.
 */
beforeEach(function () {
    Filament::setCurrentPanel('portal');
});

it('tells a client who typed a wrong password how to get a new one', function () {
    $user = ClientUser::factory()->activated()->create();

    $message = $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'sai-mat-khau')
        ->call('authenticate')
        ->errors()
        ->first('data.email');

    expect($message)
        ->toBe(Login::failureMessage())
        ->toContain((string) config('vkcrm.brand.hotline'))
        ->toContain('quên mật khẩu');
});

it('says exactly the same sentence for an email that has no account', function () {
    $message = $this->livewire(Login::class)
        ->set('data.email', 'khong-co-ai@example.test')
        ->set('data.password', 'sai-mat-khau')
        ->call('authenticate')
        ->errors()
        ->first('data.email');

    expect($message)->toBe(Login::failureMessage());
});
