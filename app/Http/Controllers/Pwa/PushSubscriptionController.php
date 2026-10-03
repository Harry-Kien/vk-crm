<?php

namespace App\Http\Controllers\Pwa;

use App\Actions\Push\ForgetPushDevice;
use App\Actions\Push\RegisterPushDevice;
use App\Exceptions\PushDeviceConflict;
use App\Http\Middleware\RefuseStaffWithoutTwoFactor;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Push\PushSession;
use App\Support\Push\VapidKeys;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * M12 R8 — `POST` và `DELETE /{admin,portal}/push/subscriptions`, gọi từ `public/pwa/register.js`.
 * Controller mỏng: luật ở {@see RegisterPushDevice} và {@see ForgetPushDevice}; ở đây chỉ có người
 * đang đăng nhập của panel hiện hành, khoá phiên theo guard ({@see PushSession}) và mã trả lời.
 *
 *  - `POST` không `sync` — cú bấm "Bật trên máy này": 201 `{"status":"enabled"}`.
 *  - `POST` với `sync=1` — lượt kiểm mỗi phiên: 200 `{"status":"owned"|"not_owned"}`, không chuyển
 *    chủ endpoint nào. Hai câu trả lời không phân biệt "chưa ai có" với "người khác có".
 *  - `DELETE` — gỡ máy này: 204, hoặc 404 khi endpoint không phải của người đang đăng nhập.
 *  - Endpoint sai luật: 422 với câu tiếng Việt. Lượt Bật thua chỉ mục UNIQUE hai lần: 409.
 *  - Máy chủ chưa có khoá VAPID (R7, push tắt êm): `POST` trả 404; `DELETE` vẫn chạy — gỡ luôn được.
 *
 * Không câu trả lời nào mang endpoint.
 *
 * Route đăng ký bằng {@see self::routes()} trong `->authenticatedRoutes()` của TỪNG panel — vô điều
 * kiện, kể cả khi thiếu khoá VAPID (rà soát Task 4: test dò route model binding chỉ đọc route đã
 * đăng ký) — nên đi sau toàn bộ chồng middleware có phiên và cổng đăng nhập của panel đó: chưa đăng
 * nhập thì về trang đăng nhập (401 với request JSON), khách bị vô hiệu dừng ở
 * `EnsurePortalAccountIsActive`, khách chưa đổi mật khẩu lần đầu được `RequirePortalPasswordChange`
 * chuyển sang trang đổi mật khẩu (302 — tài khoản đó chưa `activated_at` nên chưa bao giờ là người
 * nhận push), nhân sự chưa cài 2FA dừng ở {@see RefuseStaffWithoutTwoFactor}.
 */
final class PushSubscriptionController
{
    /** Bộ đếm `throttle:` — 10 request/phút cho MỖI tài khoản (guard + id), đăng ký ở `WebPushServiceProvider`. */
    public const RATE_LIMITER = 'push-devices';

    public const REQUESTS_PER_MINUTE = 10;

    /**
     * Route của một panel, cho `->authenticatedRoutes()`. Tên route (sau tiền tố `filament.{panel}.`
     * của Filament): `push.subscriptions.store`, `push.subscriptions.destroy` — cùng một URL.
     *
     * @param  list<class-string>  $middleware  middleware riêng của panel (admin: cổng 2FA)
     */
    public static function routes(array $middleware = []): Closure
    {
        return function () use ($middleware): void {
            Route::middleware([...$middleware, 'throttle:'.self::RATE_LIMITER])->group(function (): void {
                Route::post('push/subscriptions', [self::class, 'store'])->name('push.subscriptions.store');
                Route::delete('push/subscriptions', [self::class, 'destroy'])->name('push.subscriptions.destroy');
            });
        };
    }

    /** Khoá bộ đếm của request hiện tại: guard của panel + id tài khoản (nhân sự và khách trùng id vẫn tách). */
    public static function rateLimitKey(Request $request): string
    {
        $id = Filament::auth()->id();

        return $id === null ? 'ip:'.$request->ip() : Filament::getAuthGuard().':'.$id;
    }

    public function store(Request $request, RegisterPushDevice $register): JsonResponse
    {
        abort_unless(VapidKeys::configured(), 404);

        $owner = $this->owner();
        $guard = Filament::getAuthGuard();
        $session = $request->session();

        if ($request->boolean('sync')) {
            $endpoint = $register->check($owner, $request->all());
            $session->put(PushSession::checkedKey($guard), true);

            if ($endpoint !== null) {
                $session->put(PushSession::endpointKey($guard), $endpoint);
            }

            return new JsonResponse(['status' => $endpoint !== null ? 'owned' : 'not_owned']);
        }

        try {
            $endpoint = $register->handle($owner, $request->all(), $request->userAgent());
        } catch (PushDeviceConflict) {
            return new JsonResponse(['status' => 'conflict'], 409);
        }

        $session->put(PushSession::checkedKey($guard), true);
        $session->put(PushSession::endpointKey($guard), $endpoint);

        return new JsonResponse(['status' => 'enabled'], 201);
    }

    public function destroy(Request $request, ForgetPushDevice $forget): Response
    {
        $owner = $this->owner();
        $endpoint = $request->input('endpoint');

        abort_unless($forget->byEndpoint($owner, $endpoint), 404);

        $key = PushSession::endpointKey(Filament::getAuthGuard());

        if ($request->session()->get($key) === $endpoint) {
            $request->session()->forget($key);
        }

        return new Response(status: 204);
    }

    /** Người đang đăng nhập ở guard của panel hiện hành — `User` ở `/admin`, `ClientUser` ở `/portal`. */
    private function owner(): User|ClientUser
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User || $user instanceof ClientUser, 404);

        return $user;
    }
}
