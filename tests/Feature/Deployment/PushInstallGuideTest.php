<?php

use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| M12 Task 10 — tài liệu cài đặt nói đúng điều máy chủ thật cần cho app trên điện thoại
|--------------------------------------------------------------------------
|
| Kế hoạch M12, Task 10, mục "Tài liệu": `README.md` và `docs/CAI-DAT.md` phải dạy sinh khoá VAPID,
| cất khoá cùng `APP_KEY`, `vkcrm:push-reset`, extension `curl`, HTTPS bắt buộc, dòng lịch hàng đợi
| `push`; `docs/SAO-LUU-KHOI-PHUC.md` Bước 6 cất `VAPID_PRIVATE_KEY` ở đúng chỗ cất `APP_KEY` (R7, M8
| R3). Mỗi test đọc CHÍNH tài liệu rồi so với mã: danh sách extension với
| `vkcrm.deployment.required_extensions`, tên lệnh với `Artisan::all()`, dòng lịch với
| `Schedule::events()`, tên tệp migration với `database/migrations`, máy chủ push với
| `vkcrm.pwa.push_hosts`, khối `location =` với mẫu nginx — đổi mã mà quên tài liệu là test đỏ.
|
| Hai sự thật của gói `laravel-notification-channels/webpush` 13.0.1 mà tài liệu phải nói (đọc ở
| `vendor/…/src/VapidKeysGenerateCommand.php`, Task 4 review Minor 5):
|  - `webpush:vapid` dựng mẫu thay thế từ khoá ĐANG CÓ TRONG CẤU HÌNH (`Config::array('webpush.vapid')`).
|    Cấu hình đã cache với khoá rỗng mà `.env` đang có khoá thì dòng `VAPID_PUBLIC_KEY=cu` thành
|    `VAPID_PUBLIC_KEY=moicu` — hỏng. Nên `config:clear` phải đứng TRƯỚC lệnh sinh khoá;
|  - ngoài `production`, khoá đã có thì lệnh GHI ĐÈ không hỏi (ConfirmableTrait chỉ hỏi ở
|    `production`): tài liệu dặn chỉ chạy khi hai dòng còn trống.
*/

function pushGuideFile(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)));
}

/** Đoạn của `$text` từ dòng bắt đầu bằng `$start` tới trước dòng kế tiếp bắt đầu bằng `$end` (hoặc hết tệp). */
function pushGuideSection(string $text, string $start, ?string $end): string
{
    $from = strpos($text, "\n".$start);
    expect($from)->not->toBeFalse("thiếu đoạn bắt đầu bằng {$start}");

    $to = $end === null ? false : strpos($text, "\n".$end, $from + 1);

    return $to === false ? substr($text, $from) : substr($text, $from, $to - $from);
}

/** Gộp mọi khoảng trắng: tài liệu ngắt dòng ở cột 100, một cụm từ có thể vắt qua hai dòng. */
function pushGuideFlat(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
}

/** Các tên trong dấu `…` của một đoạn văn. */
function pushGuideTicked(string $text): array
{
    preg_match_all('/`([a-z_]+)`/', $text, $matches);

    return $matches[1];
}

function pushGuideRequiredExtensions(): array
{
    $extensions = config('vkcrm.deployment.required_extensions');
    sort($extensions);

    return $extensions;
}

it('Bước 1 của CAI-DAT và tóm tắt README nêu ĐÚNG danh sách extension mà preflight kiểm — curl là bắt buộc', function (): void {
    $stepOne = pushGuideSection(pushGuideFile('docs/CAI-DAT.md'), '### Bước 1', '### Bước 2');
    $listed = pushGuideTicked(pushGuideSection($stepOne, '  `ctype`', '  Đây là kết quả'));
    sort($listed);

    expect($listed)->toBe(pushGuideRequiredExtensions())
        ->and(pushGuideRequiredExtensions())->toContain('curl');

    $readme = pushGuideFlat(pushGuideSection(pushGuideFile('README.md'), '## Triển khai lên máy chủ thật', '## '));
    expect(preg_match('/\*\*PHP 8\.3 với đủ extension\*\*: (.+?)\(nên có thêm (.+?)\)/u', $readme, $match))->toBe(1);

    $required = pushGuideTicked($match[1]);
    sort($required);

    expect($required)->toBe(pushGuideRequiredExtensions())
        ->and(pushGuideTicked($match[2]))->not->toContain('curl');
});

it('Bước 3 của CAI-DAT sinh khoá VAPID đúng thứ tự: config:clear, webpush:vapid, VAPID_SUBJECT, preflight, rồi mới cache', function (): void {
    $stepThree = pushGuideSection(pushGuideFile('docs/CAI-DAT.md'), '### Bước 3', '### Bước 4');
    $block = pushGuideSection($stepThree, '#### Khoá thông báo đẩy', null);
    $flat = pushGuideFlat($block);

    $order = ['php artisan config:clear', 'php artisan webpush:vapid', 'VAPID_SUBJECT=mailto:', 'php artisan vkcrm:preflight', 'php artisan optimize'];
    $positions = array_map(fn (string $needle): int|false => strpos($block, $needle), $order);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());

    expect(array_keys(Artisan::all()))->toContain('webpush:vapid', 'vkcrm:push-reset', 'vkcrm:preflight');

    expect($flat)
        // Lý do của config:clear — sự thật của gói, xem docblock tệp.
        ->toContain('VAPID_PUBLIC_KEY=moicu')
        // Một lần cho mỗi môi trường, và chỉ khi hai dòng còn trống.
        ->toContain('MỘT lần cho mỗi môi trường')
        ->toContain('còn trống')
        // Cất cùng chỗ với APP_KEY, ở đúng bước của tài liệu sao lưu.
        ->toContain('VAPID_PRIVATE_KEY')
        ->toContain('`docs/SAO-LUU-KHOI-PHUC.md`, Bước 6')
        ->toContain('php artisan vkcrm:push-reset');
});

it('Bảng biến .env ở Bước 3 có dòng VAPID, và câu VÀNG của preflight bảo config:clear trước khi sinh khoá', function (): void {
    $stepThree = pushGuideSection(pushGuideFile('docs/CAI-DAT.md'), '### Bước 3', '### Bước 4');

    expect(preg_match('/^\| `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` \|.+\|$/m', $stepThree))->toBe(1);

    $missing = __('preflight.vapid_missing', ['variables' => 'VAPID_PUBLIC_KEY']);
    expect(strpos($missing, 'php artisan config:clear'))->not->toBeFalse()
        ->and(strpos($missing, 'php artisan config:clear'))->toBeLessThan(strpos($missing, 'php artisan webpush:vapid'));

    // `.env.example`: dòng VÀNG chỉ có ở production (`RunPreflight::launchConditionRows()`).
    $example = pushGuideFlat(pushGuideSection(pushGuideFile('.env.example'), '# --- Thông báo đẩy trên điện thoại', 'VAPID_SUBJECT='));
    expect($example)->toContain('vkcrm:preflight báo VÀNG trên production')
        ->toContain('config:clear');
});

it('Bước 4 nói HTTPS là bắt buộc cho app trên điện thoại và mẫu nginx có khối riêng cho hai sw.js', function (): void {
    $stepFour = pushGuideFlat(pushGuideSection(pushGuideFile('docs/CAI-DAT.md'), '### Bước 4', '### Bước 5'));
    $nginx = pushGuideFile('tools/deploy/nginx.conf.example');

    expect($stepFour)
        ->toContain('service worker')
        ->toContain('HTTPS')
        ->toContain('location = /admin/sw.js')
        ->toContain('location = /portal/sw.js')
        ->toContain('bash tools/deploy/verify-pwa-routes.sh');

    foreach (['admin', 'portal'] as $panel) {
        expect($nginx)->toContain("location = /{$panel}/sw.js {");
    }

    expect(file_exists(base_path('tools/deploy/verify-pwa-routes.sh')))->toBeTrue();
});

it('Bước 7 nói dòng VÀNG của khoá VAPID và dòng ĐỎ của curl, Bước 8 nói hàng đợi push chạy trong CHÍNH dòng cron', function (): void {
    $guide = pushGuideFile('docs/CAI-DAT.md');
    $stepSeven = pushGuideFlat(pushGuideSection($guide, '### Bước 7', '### Bước 8'));
    $stepEight = pushGuideFlat(pushGuideSection($guide, '### Bước 8', '### Bước 9'));

    // Câu đầu của dòng VÀNG, đúng như preflight in (trước dấu ngoặc tên biến).
    $yellowLead = strtok(__('preflight.vapid_missing'), '(');
    expect($stepSeven)->toContain(trim($yellowLead))
        ->toContain('`curl`');

    $push = collect(Schedule::events())->first(fn ($event) => $event->description === 'queue.push');
    expect($push)->not->toBeNull()
        ->and($push->expression)->toBe('* * * * *');

    $command = 'queue:work --queue=push --stop-when-empty --max-time=50';
    expect($push->command)->toEndWith($command)
        ->and($stepEight)->toContain($command)
        ->toContain('không thêm dòng cron nào');
});

it('mục "Nâng cấp lên bản mới" có đoạn cho bản M12: đủ migration, khoá VAPID, khối nginx, push-reset chỉ khi đổi khoá', function (): void {
    $upgrade = pushGuideSection(pushGuideFile('docs/CAI-DAT.md'), '## Nâng cấp lên bản mới', '## ');
    $m12 = pushGuideSection($upgrade, '### Bản cập nhật M12', '### ');
    $flat = pushGuideFlat($m12);

    // Bảng do migration publish từ gói (tên bảng đọc `config('webpush.table_name')`) — dò theo tên tệp.
    $migrations = collect(glob(base_path('database/migrations/*.php')))
        ->map(fn (string $file): string => basename($file, '.php'))
        ->filter(fn (string $name): bool => str_contains($name, 'push_subscriptions'))
        ->values();

    expect($migrations)->toHaveCount(2);

    foreach ($migrations as $migration) {
        expect($flat)->toContain($migration);
    }

    expect($flat)
        ->toContain('mục "Khoá thông báo đẩy (VAPID)" ở Bước 3')
        ->toContain('location = /admin/sw.js')
        ->toContain('location = /portal/sw.js')
        ->toContain('php artisan vkcrm:push-reset')
        ->toContain('chỉ khi đổi khoá')
        ->toContain('`curl`')
        ->toContain('queue.push');
});

it('CAI-DAT có lệnh kiểm máy chủ gọi ra được máy chủ push, và mọi tên máy trong đó nằm trong danh sách push_hosts', function (): void {
    $reachability = pushGuideSection(pushGuideFile('docs/CAI-DAT.md'), '#### Máy chủ có gọi ra được máy chủ push không', '### ');

    preg_match_all('#curl -sS -o /dev/null -w \'%\{http_code\}\\\\n\' https://([a-z0-9.-]+)/#', $reachability, $matches);
    $hosts = $matches[1];

    // Hai tên máy của FCM (Chromium đăng ký trên `jmt17.google.com` — đo ở Task 10), Apple, Mozilla.
    expect($hosts)->toEqualCanonicalizing(['fcm.googleapis.com', 'jmt17.google.com', 'web.push.apple.com', 'updates.push.services.mozilla.com']);

    foreach ($hosts as $host) {
        $allowed = collect(config('vkcrm.pwa.push_hosts'))->contains(fn (string $pattern): bool => fnmatch($pattern, $host));
        expect($allowed)->toBeTrue("{$host} không thuộc vkcrm.pwa.push_hosts");
    }

    // Máy chủ push do TRÌNH DUYỆT gọi, không phải script của trang: CSP không mở connect-src cho chúng.
    expect(ContentSecurityPolicy::policy('n'))->toContain("connect-src 'self';")
        ->not->toContain('push.apple.com')
        ->not->toContain('fcm.googleapis.com');
});

it('SAO-LUU Bước 6 cất cặp khoá VAPID cùng APP_KEY, và quy trình khôi phục nói khoá cũ giữ đăng ký, mất khoá thì push-reset', function (): void {
    $backup = pushGuideFile('docs/SAO-LUU-KHOI-PHUC.md');
    $stepSix = pushGuideFlat(pushGuideSection($backup, '## Bước 6', '## '));
    $restore = pushGuideFlat(pushGuideSection($backup, '### Quy trình cho máy chủ thật', '### '));

    expect($stepSix)
        ->toContain('`VAPID_PRIVATE_KEY`')
        ->toContain('`VAPID_PUBLIC_KEY`')
        ->toContain('`php artisan vkcrm:push-reset`');

    expect($restore)
        ->toContain('`VAPID_PRIVATE_KEY`')
        ->toContain('`php artisan vkcrm:push-reset`');

    $readme = pushGuideFlat(pushGuideSection(pushGuideFile('README.md'), '## Triển khai lên máy chủ thật', '## '));
    expect($readme)
        ->toContain('`php artisan webpush:vapid`')
        ->toContain('`VAPID_PRIVATE_KEY`')
        ->toContain('`php artisan vkcrm:push-reset`')
        ->toContain('HTTPS');

    expect(pushGuideFile('README.md'))->toContain('vkcrm:push-reset');
});
