<?php

namespace App\Actions\Settings;

use App\Enums\Permission;
use App\Jobs\SendTestMail;
use App\Models\User;
use App\Support\Audit;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Nút "Gửi thư thử" của trang Cài đặt › Thư điện tử (làn st): xếp MỘT thư thử tới email của CHÍNH
 * người bấm, để kiểm chứng máy chủ gửi thư.
 *
 *  - Quyền: chỉ `settings.manage` (chỉ admin), hỏi `Gate::forUser($actor)` ngay đầu.
 *  - Tần suất: tối đa {@see self::MAX_PER_WINDOW} lần mỗi {@see self::WINDOW_SECONDS} giây theo người
 *    bấm (`RateLimiter`, khoá {@see self::throttleKey()}) — quá thì `DomainException` với câu tiếng Việt
 *    nói còn bao lâu, không xếp gì, không ghi nhật ký.
 *  - Ghi và gửi: một transaction ghi dòng `test_mail_requested` (`recipient` = email của chính người
 *    bấm, causer = người bấm) rồi xếp {@see SendTestMail} bằng `->afterCommit()` — thư không bao giờ đi
 *    từ bên trong transaction (M6.5 R2), và rollback thì không thư nào được xếp. Job tính lại người nhận
 *    lúc gửi (R3); thư được ghi vào sổ thư như mọi thư khác.
 *
 * Trả về địa chỉ thư sẽ nhận, để màn hình nói đúng "đã xếp thư tới …".
 */
final class SendTestMailToSelf
{
    public const MAX_PER_WINDOW = 3;

    public const WINDOW_SECONDS = 600;

    public function handle(User $actor): string
    {
        if (! Gate::forUser($actor)->allows(Permission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        $key = self::throttleKey($actor);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_WINDOW)) {
            throw new DomainException(__('settings.mail.test.throttled', [
                'max' => self::MAX_PER_WINDOW,
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
            ]));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        DB::transaction(function () use ($actor): void {
            Audit::record('test_mail_requested', null, ['recipient' => $actor->email], $actor);

            SendTestMail::dispatch($actor->getKey())->afterCommit();
        });

        return $actor->email;
    }

    public static function throttleKey(User $actor): string
    {
        return 'settings-test-mail:'.$actor->getKey();
    }
}
