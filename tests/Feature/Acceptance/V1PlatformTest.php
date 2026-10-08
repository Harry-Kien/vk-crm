<?php

use Composer\Semver\Semver;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Nghiệm thu bản 1.0 (v1 Task 2, M8 Task 8) — SPEC §14 mục 2: "Chạy được trên PHP 8.3 với đúng một
 * dòng cron, không cần Redis hay supervisor."
 *
 * Bốn vế, mỗi vế đọc chính tệp mà người cài dùng — không đọc lại một danh sách viết tay trong test:
 *  - `composer.lock` (phần `packages`, thứ `composer install --no-dev` cài lên máy chủ): mọi gói cài
 *    được trên PHP 8.3.0, và không gói nào cần Redis hay một tiến trình thường trực (Horizon, Octane,
 *    Reverb, Pulse, Scout — CLAUDE.md cấm cả năm);
 *  - `.env.example`: hàng đợi, bộ nhớ đệm, phiên đều nằm trong cơ sở dữ liệu;
 *  - lịch chạy thật (`Schedule` sau khi `routes/console.php` nạp): hàng đợi được rút BẰNG CHÍNH lịch,
 *    mỗi lượt tự dừng khi hết việc (`--stop-when-empty`), nên không cần `queue:work` thường trực hay
 *    supervisor giữ nó sống;
 *  - `docs/CAI-DAT.md` và `README.md`: hướng dẫn cài đòi ĐÚNG MỘT dòng crontab, và dòng đó gọi
 *    `schedule:run`.
 *
 * Hàm toàn cục mang tiền tố `v1p…`.
 */
function v1pLock(): array
{
    return json_decode(file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, string> dòng `KEY=value` (không chú thích) của `.env.example` */
function v1pEnvExample(): array
{
    $values = [];

    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $match)) {
            $values[$match[1]] = trim($match[2], '"');
        }
    }

    return $values;
}

/** @return list<string> những dòng của `$file` có dạng một dòng crontab (5 trường thời gian rồi lệnh) */
function v1pCrontabLines(string $file): array
{
    preg_match_all('/^\s*`?((?:\S+\s+){5}cd \S+ && php artisan \S+.*?)`?\s*$/m', file_get_contents(base_path($file)), $matches);

    return $matches[1];
}

it('§14.2 installs every production package of composer.lock on PHP 8.3.0', function () {
    $tooNew = [];

    foreach (v1pLock()['packages'] as $package) {
        $constraint = $package['require']['php'] ?? null;

        if ($constraint !== null && ! Semver::satisfies('8.3.0', $constraint)) {
            $tooNew[] = $package['name'].' ('.$constraint.')';
        }
    }

    expect($tooNew)->toBe([])
        ->and(json_decode(file_get_contents(base_path('composer.json')), true)['require']['php'])->toBe('^8.3');
});

it('§14.2 installs no package that needs Redis or a resident process', function () {
    $installed = array_column(v1pLock()['packages'], 'name');

    expect(array_values(array_intersect($installed, [
        'predis/predis',
        'laravel/horizon',
        'laravel/octane',
        'laravel/reverb',
        'laravel/pulse',
        'laravel/scout',
    ])))->toBe([]);
});

it('§14.2 keeps the queue, the cache and the sessions in the database', function () {
    $env = v1pEnvExample();

    expect($env['QUEUE_CONNECTION'] ?? null)->toBe('database')
        ->and($env['CACHE_STORE'] ?? null)->toBe('database')
        ->and($env['SESSION_DRIVER'] ?? null)->toBe('database');
});

it('§14.2 drains every queue from the schedule itself, each run stopping once the queue is empty', function () {
    $workers = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));

    expect($workers)->not->toBeEmpty();

    foreach ($workers as $worker) {
        expect($worker->command)->toContain('--stop-when-empty')
            ->and($worker->expression)->toBe('* * * * *');
    }

    // Hàng mặc định (thư), hàng `push` (M12) và hàng `handover` (gói bàn giao, M7) đều có người rút;
    // không hàng nào bị bỏ cho một worker thường trực mà máy chủ không có.
    $queues = $workers->map(fn (Event $event): string => preg_match('/--queue=(\S+)/', (string) $event->command, $m) ? trim($m[1], "'\"") : 'default')
        ->flatMap(fn (string $list): array => explode(',', $list))
        ->unique()->values()->all();

    expect($queues)->toContain('default')->toContain('push')->toContain('handover');
});

it('§14.2 asks the installer for exactly one crontab line, and it runs schedule:run', function () {
    $guide = v1pCrontabLines('docs/CAI-DAT.md');

    expect($guide)->toHaveCount(1)
        ->and($guide[0])->toStartWith('* * * * * ')
        ->and($guide[0])->toContain('php artisan schedule:run');

    // README nhắc lại đúng dòng đó, không một dòng thứ hai.
    expect(v1pCrontabLines('README.md'))->toBe($guide);
});
