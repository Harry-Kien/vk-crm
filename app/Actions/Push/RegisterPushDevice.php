<?php

namespace App\Actions\Push;

use App\Exceptions\PushDeviceConflict;
use App\Http\Controllers\Pwa\PushSubscriptionController;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\Push\DeviceLabel;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use NotificationChannels\WebPush\PushSubscription;

/**
 * M12 R8 — gắn đăng ký push của TRÌNH DUYỆT đang dùng với người đang đăng nhập. Hai lối vào, gọi từ
 * {@see PushSubscriptionController}:
 *
 *  - {@see self::handle()} — cú bấm "Bật trên máy này" (nút trên trang thiết bị, hoặc dải mời). Lối
 *    DUY NHẤT được chuyển chủ một endpoint: nếu endpoint đang thuộc một người khác (điện thoại dùng
 *    chung), `HasPushSubscriptions::updatePushSubscription()` của gói XOÁ dòng của người cũ rồi tạo
 *    dòng mới cho người bấm (R6). Người cũ thôi nhận push trên máy này — đúng: máy đó giờ là của
 *    người vừa bấm.
 *  - {@see self::check()} — lượt kiểm `sync=1` mỗi phiên. CHỈ HỎI: endpoint đã thuộc chính người
 *    này thì làm mới `last_seen_at`; chưa thuộc ai, hay thuộc người khác, thì KHÔNG gắn gì và trả
 *    "không phải của bạn" như nhau — câu trả lời không cho biết endpoint có chủ hay không. Luật
 *    "kiểm thì không chuyển chủ" là thứ duy nhất ngăn người đăng nhập sau trên cùng máy nhận tin của
 *    người trước, hay ngược lại (kế hoạch, "Những chỗ đã biết trước là sẽ cắn").
 *
 * # Kiểm endpoint: không mở đường SSRF
 *
 * Máy chủ POST tới endpoint theo lịch, nên endpoint là đầu vào nguy hiểm nhất của tính năng. Luật
 * ({@see self::endpointRule()}): `https://<host>[:443]/<đường dẫn>`, host trong
 * `config('vkcrm.pwa.push_hosts')`, chỉ ký tự URL in được, KHÔNG `@`, `#`, `\`, khoảng trắng hay ký
 * tự ngoài ASCII. Phần host là MỌI thứ giữa `https://` và dấu `/` đầu tiên (trừ `:443`), chỉ gồm chữ, số,
 * `.`, `-` — nên không có chỗ cho hai bộ phân tích URL (của PHP và của cURL) đọc ra hai host khác nhau.
 * Đòi ASCII còn vì cột `endpoint` là `ascii` (rà soát Task 4, Minor 1): MariaDB strict từ chối ký tự
 * ngoài ASCII bằng lỗi 1366, và câu SQL kèm endpoint của lỗi đó vào `laravel.log`. Độ dài tối đa là
 * độ dài cột (`PushSubscription::ENDPOINT_MAX_LENGTH`, 1024).
 *
 * `keys.p256dh` là điểm P-256 không nén (65 byte, mở đầu `0x04`) và `keys.auth` là 16 byte, cả hai
 * base64url (có hay không `=`) — đúng thứ `PushSubscription.toJSON()` của trình duyệt gửi.
 *
 * # Không bao giờ ghi endpoint
 *
 * Endpoint là một URL mang quyền gửi. Audit `push_device_added`/`push_device_removed` chỉ mang
 * `device_label` ({@see DeviceLabel}). Hai lượt Bật đồng thời cùng một endpoint (rà soát Task 4,
 * Minor 3): cả hai không thấy dòng nào, lượt sau vấp chỉ mục UNIQUE — ngoại lệ của CSDL mang câu
 * SQL kèm endpoint. Bắt nó, thử lại MỘT lần (lượt thử lại thấy dòng của lượt trước và đi nhánh "đã
 * có"), thua lần nữa thì {@see PushDeviceConflict} — không mang ngoại lệ gốc.
 *
 * Một trong các Action được chạm thẳng bảng đăng ký (`PushSubscriptionAccessTest`): tìm endpoint
 * BẤT KỂ chủ là nghiệp vụ của cú bấm Bật (biết dòng sắp bị xoá của ai để ghi audit).
 */
class RegisterPushDevice
{
    /**
     * `https://` + host (chữ, số, `.`, `-` — không `@`, nên không có phần thông tin người dùng) +
     * cổng 443 tuỳ chọn + `/` + đường dẫn gồm ký tự URL in được trừ `@`, `#`, `\`, `"`, `<`, `>`,
     * `{`, `}`, `|`, `^`, `` ` `` và khoảng trắng. Cờ `D`: `$` không khớp trước một ký tự xuống dòng
     * ở cuối.
     */
    private const ENDPOINT_PATTERN = '/^https:\/\/([A-Za-z0-9.-]+)(?::443)?\/[A-Za-z0-9\-._~:\/?\[\]!$&\'()*+,;=%]*$/D';

    /**
     * Cú bấm Bật. Trả endpoint đã kiểm — để controller ghi vào phiên theo guard (R9).
     *
     * @param  array<string, mixed>  $input  thân request: `endpoint`, `keys.p256dh`, `keys.auth`, `contentEncoding`
     */
    public function handle(User|ClientUser $owner, array $input, ?string $userAgent): string
    {
        $data = $this->validated($input);
        $label = DeviceLabel::fromUserAgent($userAgent);

        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(fn () => $this->attach($owner, $data, $label));

                return $data['endpoint'];
            } catch (UniqueConstraintViolationException) {
                if ($attempt >= 2) {
                    throw new PushDeviceConflict;
                }
            }
        }
    }

    /**
     * Lượt kiểm `sync=1`. Trả endpoint khi nó thuộc chính `$owner` (đã làm mới `last_seen_at`),
     * `null` khi không — chưa thuộc ai hay thuộc người khác, như nhau.
     *
     * Cùng luật kiểm với cú bấm Bật (kể cả hai khoá): trình duyệt gửi cùng một thân request cho hai
     * lối, và một thân request hỏng ở đây không đến từ `register.js`.
     *
     * @param  array<string, mixed>  $input
     */
    public function check(User|ClientUser $owner, array $input): ?string
    {
        $data = $this->validated($input);

        $touched = $owner->pushSubscriptions()
            ->where('endpoint', $data['endpoint'])
            ->update(['last_seen_at' => now()]);

        return $touched > 0 ? $data['endpoint'] : null;
    }

    /**
     * Luật endpoint — dùng chung với {@see ForgetPushDevice::byEndpoint()}, ở đó với
     * `$knownHost = false`: gỡ chỉ tìm trong dòng của chính người đang đăng nhập nên host không
     * quan trọng, nhưng giá trị vẫn phải đúng hình dạng (ASCII in được) trước khi vào câu WHERE.
     *
     * @return list<string|Closure>
     */
    public static function endpointRule(bool $knownHost = true): array
    {
        return [
            'required',
            'string',
            'max:'.PushSubscription::ENDPOINT_MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail) use ($knownHost): void {
                if (! is_string($value) || preg_match(self::ENDPOINT_PATTERN, $value, $match) !== 1) {
                    $fail(__('push.validation.endpoint'));

                    return;
                }

                if ($knownHost && ! self::isKnownPushHost($match[1])) {
                    $fail(__('push.validation.endpoint'));
                }
            },
        ];
    }

    /**
     * Một câu cho mọi cách endpoint sai — thiếu, không phải chuỗi, quá dài, sai hình dạng, host lạ —
     * và không nhắc lại giá trị đã gửi.
     *
     * @return array<string, string>
     */
    public static function endpointMessages(): array
    {
        $message = __('push.validation.endpoint');

        return ['endpoint.required' => $message, 'endpoint.string' => $message, 'endpoint.max' => $message];
    }

    /** Host khớp một mục của `config('vkcrm.pwa.push_hosts')`: đúng tên, hay `*.đuôi` với ít nhất một nhãn trước đuôi. */
    private static function isKnownPushHost(string $host): bool
    {
        $host = strtolower($host);

        foreach ((array) config('vkcrm.pwa.push_hosts') as $allowed) {
            $allowed = strtolower((string) $allowed);

            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1);

                if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{endpoint: string, p256dh: string, auth: string, encoding: string}
     */
    private function validated(array $input): array
    {
        $keyRule = fn (int $bytes, ?string $prefix): Closure => function (string $attribute, mixed $value, Closure $fail) use ($bytes, $prefix): void {
            if (! is_string($value) || ! self::isBase64UrlOf($value, $bytes, $prefix)) {
                $fail(__('push.validation.keys'));
            }
        };

        $data = Validator::make($input, [
            'endpoint' => self::endpointRule(),
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['bail', 'required', $keyRule(65, "\x04")],
            'keys.auth' => ['bail', 'required', $keyRule(16, null)],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ], [
            ...self::endpointMessages(),
            'keys.required' => __('push.validation.keys'),
            'keys.array' => __('push.validation.keys'),
            'keys.p256dh.required' => __('push.validation.keys'),
            'keys.auth.required' => __('push.validation.keys'),
        ])->validate();

        return [
            'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'encoding' => $data['contentEncoding'] ?? 'aes128gcm',
        ];
    }

    /** `$value` là base64url (có hay không dấu `=`) của đúng `$bytes` byte, mở đầu bằng `$prefix`. */
    private static function isBase64UrlOf(string $value, int $bytes, ?string $prefix): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value) !== 1) {
            return false;
        }

        $raw = rtrim($value, '=');
        $decoded = base64_decode(strtr($raw, '-_', '+/').str_repeat('=', (4 - strlen($raw) % 4) % 4), true);

        return $decoded !== false
            && strlen($decoded) === $bytes
            && ($prefix === null || str_starts_with($decoded, $prefix));
    }

    /**
     * @param  array{endpoint: string, p256dh: string, auth: string, encoding: string}  $data
     */
    private function attach(User|ClientUser $owner, array $data, string $label): void
    {
        $existing = PushSubscription::query()->where('endpoint', $data['endpoint'])->first();
        $wasMine = $existing !== null && $owner->ownsPushSubscription($existing);
        /** @var Model|null $previousOwner */
        $previousOwner = $existing !== null && ! $wasMine ? $existing->subscribable : null;

        $subscription = $owner->updatePushSubscription($data['endpoint'], $data['p256dh'], $data['auth'], $data['encoding']);

        // `device_label`, `last_seen_at` không nằm trong `$fillable` của model gói.
        $subscription->forceFill(['device_label' => $label, 'last_seen_at' => now()])->save();

        if ($existing !== null && ! $wasMine) {
            // Gói vừa xoá dòng của người cũ (máy dùng chung). Ghi lại trên NGƯỜI CŨ, người bấm là
            // người gây ra; nhãn là nhãn cũ của dòng đã xoá.
            Audit::record('push_device_removed', $previousOwner, ['device_label' => $existing->device_label], $owner);
        }

        if (! $wasMine) {
            Audit::record('push_device_added', $owner, ['device_label' => $label], $owner);
        }
    }
}
