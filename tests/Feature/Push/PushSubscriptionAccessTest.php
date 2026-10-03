<?php

/*
|--------------------------------------------------------------------------
| M12 R8 — không màn hình nào truy vấn thẳng `PushSubscription`
|--------------------------------------------------------------------------
|
| `NotificationChannels\WebPush\PushSubscription` là model của gói, nằm trong `vendor/` — ngoài lưới
| `app/Models` của `PortalCoverageTest`, cùng hoàn cảnh với `Activity` và `Media`
| (`tests/Feature/Authorization/PortalIsolationSweepTest.php`). Không global scope nào cắt nó theo
| người đang đăng nhập: một `PushSubscription::query()` viết vội ở một trang của cổng khách liệt
| kê thiết bị của MỌI người, và một endpoint là một URL mang quyền gửi thông báo tới máy đó.
|
| Luật: mã trong `app/` chỉ chạm bảng này qua quan hệ của CHÍNH người đang đăng nhập
| (`$user->pushSubscriptions()`, `updatePushSubscription()` của trait), trừ đúng các Action trong
| danh sách dưới — những nơi mà việc "nhìn mọi dòng" CHÍNH LÀ nghiệp vụ. Test này là lưới duy nhất
| (kế hoạch M12, "Những chỗ đã biết trước là sẽ cắn").
|
| Quét bằng token PHP (cùng cách `ActivityLogEventTranslationsTest`), bắt ba dạng đi vòng:
|  - truy cập tĩnh qua tên lớp, kể cả bí danh `use … as X` và tên đầy đủ `\NotificationChannels\…`
|    (`::class` thì không tính — chỉ là một chuỗi tên lớp);
|  - `new PushSubscription`;
|  - chuỗi `'push_subscriptions'` (`DB::table(...)`, quy tắc `exists:`/`unique:`) và
|    `'webpush.model'`/`'webpush.table_name'` (lớp hay bảng lấy động từ cấu hình).
*/

/**
 * Đường dẫn (tính từ gốc dự án) được phép chạm thẳng bảng đăng ký. Mỗi dòng một lý do; tệp chưa
 * tồn tại là của task sau trong kế hoạch M12, ghi trước tên để task đó không phải mở rộng luật.
 *
 * @return array<string, string>
 */
function pushSubscriptionDirectAccessAllowed(): array
{
    return [
        // R7, Task 4 — `vkcrm:push-reset`: xoá MỌI đăng ký sau khi đổi khoá VAPID.
        'app/Actions/Push/ResetPushSubscriptions.php' => 'xoá mọi dòng',
        // R8, Task 5 — nút "Bật trên máy này": chuyển chủ endpoint (máy dùng chung).
        'app/Actions/Push/RegisterPushDevice.php' => 'tìm endpoint bất kể chủ',
        // R9, Task 6 — đăng xuất gỡ đúng thiết bị đang đăng xuất.
        'app/Actions/Push/ForgetPushDevice.php' => 'gỡ theo endpoint trong phiên',
        // R9, Task 6 — dọn đăng ký của tài khoản đã vô hiệu/xoá mềm và đăng ký cũ hơn 180 ngày.
        'app/Actions/Schedule/PrunePushSubscriptions.php' => 'dọn hằng ngày',
    ];
}

/**
 * Các vị trí (`dòng: mô tả`) trong một đoạn mã PHP chạm thẳng bảng đăng ký.
 *
 * @return list<string>
 */
function directPushSubscriptionAccesses(string $code): array
{
    $tokens = token_get_all($code);
    $count = count($tokens);
    $fqcn = 'NotificationChannels\\WebPush\\PushSubscription';
    $names = ['PushSubscription' => true, '\\'.$fqcn => true, $fqcn => true];
    $hits = [];

    // Bí danh: `use NotificationChannels\WebPush\PushSubscription as X;`
    if (preg_match_all('/\buse\s+\\\\?NotificationChannels\\\\WebPush\\\\PushSubscription\s+as\s+(\w+)\s*;/', $code, $aliases)) {
        foreach ($aliases[1] as $alias) {
            $names[$alias] = true;
        }
    }

    $next = function (int $from) use ($tokens, $count): ?int {
        for ($k = $from; $k < $count; $k++) {
            if (! (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return $k;
            }
        }

        return null;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            continue;
        }

        [$kind, $text, $line] = $token;

        if (in_array($kind, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && isset($names[$text])) {
            $after = $next($i + 1);
            $previous = null;

            for ($k = $i - 1; $k >= 0; $k--) {
                if (! (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE)) {
                    $previous = $tokens[$k];
                    break;
                }
            }

            if ($after !== null && is_array($tokens[$after]) && $tokens[$after][0] === T_DOUBLE_COLON) {
                $member = $next($after + 1);

                if (! ($member !== null && is_array($tokens[$member]) && $tokens[$member][0] === T_CLASS)) {
                    $hits[] = $line.': '.$text.'::';
                }
            } elseif (is_array($previous) && $previous[0] === T_NEW) {
                $hits[] = $line.': new '.$text;
            }
        }

        if ($kind === T_CONSTANT_ENCAPSED_STRING) {
            $literal = substr($text, 1, -1);

            if (preg_match('/(^|[:,|])push_subscriptions($|[,|.])|^webpush\.(model|table_name)$/', $literal) === 1) {
                $hits[] = $line.': '.$text;
            }
        }
    }

    return $hits;
}

it('máy quét bắt mọi dạng đi vòng và tha các dạng hợp lệ', function () {
    $violations = <<<'PHP'
    <?php
    use NotificationChannels\WebPush\PushSubscription;
    use NotificationChannels\WebPush\PushSubscription as Device;
    PushSubscription::query()->get();
    Device::where('endpoint', $x)->first();
    \NotificationChannels\WebPush\PushSubscription::all();
    $row = new PushSubscription;
    DB::table('push_subscriptions')->get();
    $rules = ['endpoint' => 'unique:push_subscriptions,endpoint'];
    $model = config('webpush.model');
    PHP;

    $allowed = <<<'PHP'
    <?php
    use NotificationChannels\WebPush\PushSubscription;
    $class = PushSubscription::class;
    $user->pushSubscriptions()->where('endpoint', $endpoint)->delete();
    $user->updatePushSubscription($endpoint, $key, $token);
    function label(PushSubscription $subscription): string { return (string) $subscription->device_label; }
    // PushSubscription::query() trong chú thích không phải lời gọi
    PHP;

    expect(directPushSubscriptionAccesses($violations))->toHaveCount(7)
        ->and(directPushSubscriptionAccesses($allowed))->toBe([]);
});

it('mã trong app/ chỉ chạm thẳng bảng push_subscriptions ở các Action được liệt kê', function () {
    $offenders = [];
    $touching = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        $hits = directPushSubscriptionAccesses((string) file_get_contents($file->getPathname()));

        if ($hits === []) {
            continue;
        }

        $touching[] = $relative;

        if (! array_key_exists($relative, pushSubscriptionDirectAccessAllowed())) {
            $offenders[] = $relative.' — '.implode('; ', $hits);
        }
    }

    expect($offenders)->toBe([], "Chạm thẳng bảng đăng ký ngoài danh sách cho phép:\n".implode("\n", $offenders))
        // Đối chứng dương: lưới bắt được lời gọi THẬT của Action push-reset (nếu nó không còn
        // xuất hiện ở đây thì máy quét đã mù, không phải mã đã sạch).
        ->and($touching)->toContain('app/Actions/Push/ResetPushSubscriptions.php');
});

it('mọi đường dẫn trong danh sách cho phép là một Action', function () {
    foreach (array_keys(pushSubscriptionDirectAccessAllowed()) as $path) {
        expect($path)->toStartWith('app/Actions/');
    }
});
