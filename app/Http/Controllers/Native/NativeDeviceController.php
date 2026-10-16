<?php

namespace App\Http\Controllers\Native;

use App\Actions\Push\ForgetPushDevice;
use App\Actions\Push\RegisterNativeDevice;
use App\Exceptions\PushDeviceConflict;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * M15 Task 2 — `POST` và `DELETE /admin/native/devices`: ứng dụng iPhone (WebView đăng nhập bằng
 * phiên của panel admin) đăng ký / gỡ token APNs của chính máy này. Controller mỏng: luật ở
 * {@see RegisterNativeDevice} và {@see ForgetPushDevice}.
 *
 * Route đăng ký trong `->authenticatedRoutes()` của panel admin, cùng chỗ và cùng chồng middleware
 * với `/admin/push/subscriptions` (danh sách IP, đăng nhập, cổng 2FA, huỷ phiên, CSRF) — một tài khoản
 * khách (guard `client`) không có phiên `web` nên không qua được cổng đăng nhập của panel.
 *
 *  - `POST`: 204; thân sai luật 422; thua chỉ mục UNIQUE hai lần 409.
 *  - `DELETE`: 204, gỡ token đang ghi trong phiên (không nhận token từ thân request: không ai gỡ được
 *    máy của người khác bằng cách đoán token). Không có token trong phiên: vẫn 204.
 *
 * Không câu trả lời nào mang token.
 */
final class NativeDeviceController
{
    /** `throttle:` — 30 request/phút (ứng dụng đăng ký lại mỗi lần mở). */
    public const THROTTLE = 'throttle:30,1';

    /**
     * @param  list<class-string>  $middleware  middleware riêng của panel (admin: cổng 2FA)
     */
    public static function routes(array $middleware = []): Closure
    {
        return function () use ($middleware): void {
            Route::middleware([...$middleware, self::THROTTLE])->group(function (): void {
                Route::post('native/devices', [self::class, 'store'])->name('native.devices.store');
                Route::delete('native/devices', [self::class, 'destroy'])->name('native.devices.destroy');
            });
        };
    }

    public function store(Request $request, RegisterNativeDevice $register): Response|JsonResponse
    {
        try {
            $register->handle($this->owner(), $request->all(), $request->userAgent(), $request->session());
        } catch (PushDeviceConflict) {
            return new JsonResponse(['status' => 'conflict'], 409);
        }

        return new Response(status: 204);
    }

    public function destroy(Request $request, ForgetPushDevice $forget): Response
    {
        $forget->nativeFromSession($this->owner(), $request->session());

        return new Response(status: 204);
    }

    private function owner(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
