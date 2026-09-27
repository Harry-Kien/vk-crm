<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Giới hạn tần suất tra/dò khách hàng theo định danh — fix round 1, I1, M6.5 Task 6:
 * "lookups are an unthrottled roster oracle". 20 lần / giờ / MỘT nhân sự, cùng thành ngữ
 * `RateLimiter` mà `App\Support\PortalLoginThrottle` dùng cho cổng khách hàng.
 *
 * **MỘT bộ đếm cho CẢ HAI đường**, không phải hai bộ đếm riêng: `App\Actions\Client\
 * FindClientByIdentifier::handle()` (ô "Tra khách hàng" — R4 a) VÀ dò trùng của
 * `App\Actions\Client\CreateClient::handle()` khi actor không có `client.manage` (khối "Tạo
 * khách mới" — R4 b) đều là cùng MỘT oracle: cả hai cho actor biết "định danh này có khớp ai
 * không" bằng cách quan sát kết quả (khớp thì dùng lại hồ sơ cũ, không thì tạo hồ sơ mới). Hai
 * bộ đếm riêng để một luật sư đổi đường khi gần chạm trần là lách được giới hạn mà không đổi gì
 * về bản chất câu hỏi đang bị hỏi.
 *
 * Ai chạm trần bị TỪ CHỐI ngay lúc đó (không tăng thêm) và một dòng audit `client_lookup_throttled`
 * được ghi lại — chỉ hash của định danh (nếu caller cung cấp), không bao giờ số thô, cùng luật
 * SPEC §10.5 mọi audit khác của lớp này tuân theo.
 */
final class ClientLookupThrottle
{
    public const MAX_ATTEMPTS = 20;

    public const DECAY_SECONDS = 3600;

    public static function keyFor(User $user): string
    {
        return 'client-lookup:'.sha1(User::class.'|'.$user->getKey());
    }

    public static function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts(self::keyFor($user), self::MAX_ATTEMPTS);
    }

    public static function hit(User $user): void
    {
        RateLimiter::hit(self::keyFor($user), self::DECAY_SECONDS);
    }

    public static function availableInMinutes(User $user): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn(self::keyFor($user)) / 60));
    }
}
