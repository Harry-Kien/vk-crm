<?php

namespace App\Actions\Push;

use App\Enums\ApnsEnvironment;
use App\Exceptions\PushDeviceConflict;
use App\Http\Controllers\Native\NativeDeviceController;
use App\Models\NativePushDevice;
use App\Models\User;
use App\Support\Audit;
use App\Support\Push\DeviceLabel;
use App\Support\Push\PushSession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * M15 Task 2 — gắn một chiếc iPhone (token APNs) với nhân sự đang đăng nhập; gọi từ
 * {@see NativeDeviceController}. Cùng tinh thần {@see RegisterPushDevice}:
 *
 *  - token là MỘT dòng (cột `unique`). Nếu nó đang thuộc người khác (điện thoại dùng chung), dòng
 *    ĐỔI CHỦ trong cùng một giao dịch — người cũ thôi nhận thông báo trên máy này, đúng như máy giờ
 *    là của người vừa đăng ký. Không bao giờ có hai dòng cho một token;
 *  - hai request đăng ký cùng token cùng lúc: bên thua chỉ mục UNIQUE khi tạo được thử lại đúng một
 *    lần (lần hai thấy dòng của bên thắng và chỉ phải đổi chủ/làm mới); vẫn thua thì
 *    {@see PushDeviceConflict}. Trên MariaDB (REPEATABLE READ), `SELECT … FOR UPDATE` một token CHƯA CÓ
 *    lấy khoá khoảng: hai request cùng giữ nó rồi cùng INSERT thì một bên bị DEADLOCK (1213/40001, không
 *    phải vi phạm UNIQUE). `DB::transaction(attempts: 3)` chạy lại bên bị chọn làm nạn nhân — lần sau
 *    nó thấy dòng của bên thắng — nên không request nào trả 500. Chỉ đúng khi `handle()` là giao dịch NGOÀI
 *    CÙNG (đường thật: request HTTP). Nếu người gọi đã mở giao dịch bao ngoài thì Laravel KHÔNG chạy lại
 *    mà ném `DeadlockException` (MariaDB đã huỷ cả giao dịch ngoài; chạy lại sẽ INSERT ngoài giao dịch
 *    của người gọi) — cố ý không bắt ở đây. Tổng số lần thử tối đa là 3 (không nhân với vòng UNIQUE:
 *    vòng UNIQUE chỉ thử lại vi phạm UNIQUE, mỗi lần một giao dịch);
 *  - nhật ký `push_device_added`/`push_device_removed` chỉ mang nhãn thiết bị, KHÔNG BAO GIỜ token
 *    (một token là địa chỉ nhận thông báo của máy đó).
 */
class RegisterNativeDevice
{
    /** Token APNs: hex thường, 64 ký tự trở lên (hiện 64; APNs cho phép dài hơn), tối đa 200 (độ dài cột). */
    public const TOKEN_PATTERN = '/^[0-9a-f]{64,200}$/D';

    /** Số phiên bản của ứng dụng: `1.0`, `1.0.3`. */
    public const VERSION_PATTERN = '/^\d+\.\d+(\.\d+)?$/D';

    /**
     * @param  array<string, mixed>  $input  `token`, `environment`, `app_version`
     *
     * @throws PushDeviceConflict khi thua chỉ mục UNIQUE hai lần liên tiếp
     */
    public function handle(User $owner, array $input, ?string $userAgent, ?Session $session = null): NativePushDevice
    {
        $data = Validator::make($input, [
            'token' => ['required', 'string', 'max:200', 'regex:'.self::TOKEN_PATTERN],
            'environment' => ['required', 'string', Rule::enum(ApnsEnvironment::class)],
            'app_version' => ['required', 'string', 'max:20', 'regex:'.self::VERSION_PATTERN],
        ], [
            'token.regex' => __('push.native.token_invalid'),
            'app_version.regex' => __('push.native.version_invalid'),
        ])->validate();

        $label = DeviceLabel::fromUserAgent($userAgent);

        for ($attempt = 1; ; $attempt++) {
            try {
                $device = DB::transaction(fn () => $this->attach($owner, $data, $label), attempts: 3);
                break;
            } catch (UniqueConstraintViolationException) {
                if ($attempt >= 2) {
                    throw new PushDeviceConflict;
                }
            }
        }

        $session?->put(PushSession::nativeTokenKey(), $data['token']);

        return $device;
    }

    /**
     * @param  array{token: string, environment: string, app_version: string}  $data
     */
    private function attach(User $owner, array $data, string $label): NativePushDevice
    {
        $existing = NativePushDevice::query()->where('token', $data['token'])->lockForUpdate()->first();
        $previousOwnerId = $existing !== null && $existing->user_id !== $owner->getKey() ? $existing->user_id : null;
        $previousLabel = $existing?->device_label;

        $device = $existing ?? new NativePushDevice(['token' => $data['token']]);
        $device->forceFill([
            'user_id' => $owner->getKey(),
            'environment' => $data['environment'],
            'device_label' => $label,
            'app_version' => $data['app_version'],
            'last_seen_at' => now(),
        ])->save();

        if ($previousOwnerId !== null) {
            // Máy dùng chung đổi chủ: ghi trên NGƯỜI CŨ, người đăng ký là người gây ra.
            Audit::record('push_device_removed', User::withTrashed()->find($previousOwnerId), ['device_label' => $previousLabel], $owner);
        }

        if ($existing === null || $previousOwnerId !== null) {
            Audit::record('push_device_added', $owner, ['device_label' => $label], $owner);
        }

        return $device;
    }
}
