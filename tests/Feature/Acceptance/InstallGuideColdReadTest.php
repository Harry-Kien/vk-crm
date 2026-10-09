<?php

use App\Enums\Confidentiality;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Support\Str;

/*
 * Nghiệm thu bản 1.0 (v1 Task 2) — SPEC §14 mục 8: `README.md` + `docs/CAI-DAT.md` đã nghiệm thu.
 * Hai lượt đi tìm ra các chỗ tài liệu bắt người đọc phải đoán; mỗi chỗ một test, đọc chính tài liệu
 * người cài cầm trên tay (hay chạy chính seeder mà tài liệu hứa).
 *
 *  - Gap 1–3: lượt đi theo kịch bản của người làm Task 8 ở làn v1b (2026-10-08; container
 *    `webdevops/php:8.3-alpine`, không đi Bước 1 phần chuẩn bị máy, 4, 9, 11 và phần nâng cấp).
 *  - Gap 4–12: lượt đọc lạnh MÔ PHỎNG của làn v1 (2026-10-08; máy Ubuntu 24.04 trống, Bước 1 tới
 *    "Nâng cấp lên bản mới", cùng lối cài máy dev của README) — xem khối chú thích ngay trên gap 4.
 *
 * Test cuối giữ cho hồ sơ nghiệm thu nói đúng lượt đọc nào đã nghiệm thu §14 mục 8.
 *
 * Hàm toàn cục mang tiền tố `igc…`.
 */
function igcFile(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)));
}

/** Đoạn của `$text` từ dòng tiêu đề `$start` tới (không gồm) tiêu đề `$end`. */
function igcSection(string $text, string $start, string $end): string
{
    $from = strpos($text, $start);
    expect($from)->not->toBeFalse("Không thấy tiêu đề {$start}");
    $to = strpos($text, $end, (int) $from + strlen($start));
    expect($to)->not->toBeFalse("Không thấy tiêu đề {$end}");

    return substr($text, (int) $from, (int) $to - (int) $from);
}

/**
 * Gap 1. Bốn thông tin pháp lý là chuỗi tiếng Việt CÓ DẤU CÁCH ("Đoàn Luật sư tỉnh Đồng Nai"). Viết
 * `BRAND_BAR_ASSOCIATION=Đoàn Luật sư tỉnh Đồng Nai` không dấu ngoặc kép thì Dotenv từ chối cả tệp:
 * MỌI lệnh `php artisan` và mọi trang chết với "The environment file is invalid!" — quan sát được
 * trong lượt đi theo kịch bản (lần chạy đầu dừng ở đây). Bước 3 phải nói luật ngoặc kép, và ví dụ của nó phải là một dòng Dotenv đọc được.
 */
it('§14.8 tells the installer to quote a value with spaces, and its own example parses', function () {
    expect(fn () => Dotenv::parse('BRAND_BAR_ASSOCIATION=Đoàn Luật sư tỉnh Đồng Nai'))
        ->toThrow(InvalidFileException::class);

    $step3 = igcSection(igcFile('docs/CAI-DAT.md'), '### Bước 3 — Tệp `.env`', '### Bước 4 — Máy chủ web');

    expect($step3)->toContain('ngoặc kép')
        ->and($step3)->toContain('The environment file is invalid!');

    preg_match('/^BRAND_BAR_ASSOCIATION=.+$/m', $step3, $example);

    expect($example)->not->toBeEmpty('Bước 3 cần một dòng ví dụ BRAND_BAR_ASSOCIATION=… đúng cú pháp');
    expect(Dotenv::parse($example[0]))->toBe(['BRAND_BAR_ASSOCIATION' => 'Đoàn Luật sư tỉnh Đồng Nai']);

    // Dòng mẫu trong .env.example nói cùng một luật, trong khối chú thích ngay trên biến có dấu cách
    // đầu tiên mà người cài phải tự điền.
    preg_match('/((?:^#(?! BRAND_BAR_ASSOCIATION=).*\n)+)^# BRAND_BAR_ASSOCIATION=$/m', igcFile('.env.example'), $comment);

    expect($comment)->not->toBeEmpty()
        ->and($comment[1])->toContain('ngoặc kép')
        ->and($comment[1])->toContain('The environment file is invalid!');
});

/**
 * Gap 2. `git clone https://github.com/Harry-Kien/vk-crm.git` là một kho RIÊNG TƯ: người ngoài dự án
 * chạy đúng dòng đó sẽ nhận "Repository not found" hoặc bị hỏi mật khẩu GitHub, mà Bước 2 không nói phải
 * xin quyền ở đâu, bằng gì. Tìm ra khi đọc, không quan sát được: lượt đi theo kịch bản clone từ git
 * bundle, container không có quyền GitHub.
 */
it('§14.8 says at step 2 that the repository is private and how the installer gets read access', function () {
    $step2 = igcSection(igcFile('docs/CAI-DAT.md'), '### Bước 2 — Lấy mã nguồn, cài phụ thuộc', '### Bước 3 — Tệp `.env`');

    expect($step2)->toContain('kho riêng tư')
        ->and($step2)->toContain('deploy key')
        ->and($step2)->toContain('Repository not found');
});

/**
 * Gap 3. Gạch đầu dòng "Nâng cấp" của README kể quyền mới của M9 và M10 ("bảy quyền đó") mà quên quyền
 * `performance.viewAny` của M13 — người nâng cấp đọc README, bỏ `db:seed --force`, và trang "Theo dõi
 * đội ngũ" không hiện với ai. CAI-DAT đã kể đủ; README phải khớp.
 */
it('§14.8 names the M13 permission in the README upgrade summary, as the install guide does', function () {
    $readme = igcFile('README.md');
    $from = strpos($readme, '- **Nâng cấp:**');
    expect($from)->not->toBeFalse('Không thấy gạch "- **Nâng cấp:**" trong README');
    $upgrade = substr($readme, (int) $from);

    expect($upgrade)->toContain('performance.viewAny')
        ->and($upgrade)->not->toContain('bảy quyền đó');
});

/** Các dòng lệnh của khối ```bash đầu tiên trong `$section` có chứa `$needle`. @return list<string> */
function igcBashLines(string $section, string $needle): array
{
    preg_match_all('/```bash\n(.*?)```/s', $section, $blocks);

    foreach ($blocks[1] as $block) {
        if (str_contains($block, $needle)) {
            return array_values(array_filter(
                array_map('trim', explode("\n", $block)),
                fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
            ));
        }
    }

    expect(false)->toBeTrue("Không thấy khối lệnh nào chứa {$needle}");

    return [];
}

/*
 * Lượt đọc lạnh mô phỏng của bản 1.0 (làn v1, Task 2, ngày 2026-10-08): làm theo chữ của README và
 * CAI-DAT trên một máy Ubuntu 24.04 trống (container bỏ đi, bản sao của kho từ git bundle), hai người
 * dùng như tài liệu nói (người quản trị có sudo, PHP-FPM chạy bằng `www-data`), nginx 1.24 và PHP-FPM
 * 8.3 của Ubuntu, từ Bước 1 tới "Nâng cấp lên bản mới", cùng phần dữ liệu mẫu demo và lối cài máy dev
 * của README trong Git Bash. Mỗi chỗ vấp là một test dưới đây; "quan sát được" nghĩa là lệnh thật đã
 * dừng ở đúng câu lỗi mà tài liệu nay trích.
 */

/**
 * Gap 4 (quan sát được). `/var/www` thuộc `root`: người quản trị chạy `git clone … /var/www/vk-crm`
 * nhận "could not create work tree dir … Permission denied". Và deploy key phải sinh bằng người quản
 * trị — người chạy `git pull` — không phải `www-data` (rà soát v1b, M1): `www-data` làm chủ mã nguồn thì
 * PHP-FPM ghi được mã, và `~` trong `core.sshCommand` đổi theo người gõ lệnh.
 */
it('§14.8 prepares the checkout directory for the admin user, who also owns the deploy key', function () {
    $step2 = igcSection(igcFile('docs/CAI-DAT.md'), '### Bước 2 — Lấy mã nguồn, cài phụ thuộc', '### Bước 3 — Tệp `.env`');

    expect($step2)->toContain('sudo mkdir -p /var/www/vk-crm')
        ->and($step2)->toContain('sudo chown "$USER": /var/www/vk-crm')
        ->and($step2)->toContain("could not create work tree dir '/var/www/vk-crm': Permission denied")
        ->and($step2)->toContain('Permission denied (publickey)')
        ->and($step2)->not->toContain('ví dụ www-data hoặc người quản trị')
        ->and(strpos($step2, 'sudo mkdir -p /var/www/vk-crm'))->toBeLessThan(strpos($step2, 'git clone -c core.sshCommand'));

    preg_match('/^git clone -c core\.sshCommand=.*$/m', $step2, $clone);
    expect($clone)->not->toBeEmpty()
        ->and($clone[0])->not->toContain('   ');
});

/**
 * Gap 5 (quan sát được). Tài liệu bảo chạy MỌI `php artisan` bằng `www-data`, mà `.env` do người quản
 * trị `cp` ra thuộc về người quản trị: `sudo -u www-data php artisan key:generate` dừng ở
 * "file_put_contents(…/.env): Failed to open stream: Permission denied". `.env` phải thuộc `www-data`,
 * quyền 600, sửa bằng `sudo -u www-data …`; tài liệu nói hai người dùng làm gì ngay đầu phần production.
 */
it('§14.8 hands .env to the PHP-FPM user before key:generate, and says who runs what', function () {
    $guide = igcFile('docs/CAI-DAT.md');
    $intro = igcSection($guide, '## Cài lên máy chủ thật (production)', '### Bước 0');
    $step2 = igcSection($guide, '### Bước 2 — Lấy mã nguồn, cài phụ thuộc', '### Bước 3 — Tệp `.env`');

    expect($intro)->toContain('**Người quản trị**')
        ->and($intro)->toContain('**`www-data`**')
        ->and($intro)->toContain('sudo -u www-data php artisan');

    expect($step2)->toContain('sudo chown www-data:www-data .env')
        ->and($step2)->toContain('sudo chmod 600 .env')
        ->and($step2)->toContain('sudo -u www-data nano .env')
        ->and(str_replace("\n", ' ', $step2))->toContain('Failed to open stream: Permission denied');

    expect(igcFile('README.md'))->toContain('**Hai người dùng:**');
});

/**
 * Gap 6 (quan sát được bằng chính transport của Laravel). `MAIL_SCHEME=tls`/`ssl` — chữ của bảng điều
 * khiển email và của hướng dẫn cũ — làm mọi thư hỏng. Bước 3 nói ba giá trị đúng; `.env.example` nói
 * cùng điều ngay trên dòng `MAIL_MAILER`; preflight báo ĐỎ (`PreflightCommandTest`).
 */
it('§14.8 tells the installer which MAIL_SCHEME values exist, and that tls or ssl breaks every mail', function () {
    $step3 = igcSection(igcFile('docs/CAI-DAT.md'), '### Bước 3 — Tệp `.env`', '### Bước 4 — Máy chủ web');

    expect($step3)->toContain('**`MAIL_SCHEME` không nhận `tls` hay `ssl`**')
        ->and($step3)->toContain('`smtps` cho cổng 465')
        ->and($step3)->toContain('The "tls" scheme is not supported');

    preg_match('/((?:^#.*\n)+)^MAIL_MAILER=/m', igcFile('.env.example'), $comment);
    expect($comment)->not->toBeEmpty()
        ->and($comment[1])->toContain('MAIL_SCHEME')
        ->and($comment[1])->toContain('smtps')
        ->and($comment[1])->toContain('tls');
});

/**
 * Gap 7 (quan sát được). Mẫu nginx bật HTTP/2 bằng `http2 on;` (nginx từ 1.25.1); nginx của Ubuntu
 * 24.04 là 1.24 và `nginx -t` dừng ở `unknown directive "http2"`. Bước 4 và chính mẫu nói cách viết cho
 * nginx cũ, đã chạy thử trên 1.24.
 */
it('§14.8 tells an Ubuntu 24.04 installer how to enable HTTP/2 on nginx 1.24', function () {
    $step4 = igcSection(igcFile('docs/CAI-DAT.md'), '### Bước 4 — Máy chủ web', '### Bước 5');

    expect($step4)->toContain('unknown directive "http2"')
        ->and($step4)->toContain('1.25.1')
        ->and($step4)->toContain('listen 443 ssl http2;');

    $template = igcFile('tools/deploy/nginx.conf.example');
    expect($template)->toContain('http2 on;')
        ->and($template)->toContain('listen 443 ssl http2;')
        ->and(strpos($template, 'listen 443 ssl http2;'))->toBeLessThan(strpos($template, 'http2 on;'));
});

/**
 * Gap 8 (quan sát được). Chuỗi nâng cấp chạy `composer install` bằng người quản trị, mà sau lần cài đầu
 * `bootstrap/cache/` thuộc `www-data`: composer tự gọi `package:discover` và dừng ở "Script @php artisan
 * package:discover --ansi handling the post-autoload-dump event returned with error code 1". Chuỗi nay
 * tắt script của composer, xoá danh sách gói cũ (gap 8b, quan sát được: thiếu bước này thì một gói vừa
 * gỡ làm `package:discover` chết lúc khởi động) cùng cache cấu hình cũ (test ngay dưới), chạy `package:discover` bằng `www-data` và
 * `filament:assets` bằng người quản trị; mọi dòng `php artisan` khác đều ghi rõ `sudo -u www-data`.
 */
it('§14.8 runs the upgrade chain with the right user on every line', function () {
    $upgrade = igcSection(igcFile('docs/CAI-DAT.md'), '## Nâng cấp lên bản mới', '### Bản cập nhật M12');
    $lines = igcBashLines($upgrade, 'git pull');
    $index = array_flip($lines);

    expect($lines)->toContain('git pull')
        ->and($lines)->toContain('composer install --no-dev --optimize-autoloader --no-scripts')
        ->and($lines)->toContain('php artisan filament:assets')
        ->and(str_replace("\n  ", ' ', $upgrade))->toContain('returned with error code 1')
        ->and($upgrade)->toContain('Class "Laravel\Pail\PailServiceProvider" not found');

    expect($index['composer install --no-dev --optimize-autoloader --no-scripts'])
        ->toBeLessThan($index['sudo -u www-data rm -f bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php'])
        ->and($index['sudo -u www-data rm -f bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php'])
        ->toBeLessThan($index['sudo -u www-data php artisan package:discover'])
        ->and($index['sudo -u www-data php artisan package:discover'])->toBeLessThan($index['php artisan filament:assets'])
        ->and($index['sudo -u www-data php artisan down'])->toBe(1)
        ->and($index['sudo -u www-data php artisan up'])->toBe(count($lines) - 1);

    foreach ($lines as $line) {
        if (str_contains($line, 'php artisan') && $line !== 'php artisan filament:assets') {
            expect($line)->toStartWith('sudo -u www-data php artisan ');
        }
    }

    $readme = igcFile('README.md');
    $summary = substr($readme, (int) strpos($readme, '- **Nâng cấp:**'));
    expect($summary)->toContain('--no-scripts')
        ->and($summary)->toContain('package:discover')
        ->and($summary)->toContain('filament:assets');
});

/**
 * Rà soát Task 2, vòng sửa 1 (finding 1; tìm ra khi đọc, không quan sát được — lượt đi nâng cấp một cây
 * lên CHÍNH nó, nên cấu hình cũ và mới trùng nhau). `composer install --no-scripts` bỏ luôn
 * `ComposerScripts::clearCompiled`, mà hàm đó xoá BA tệp: `config.php`, `packages.php`, `services.php`;
 * và `filament:upgrade` từng chạy `config:clear`, `route:clear`, `view:clear`. Chuỗi chỉ xoá hai tệp
 * danh sách gói thì `bootstrap/cache/config.php` của lần `optimize` trước còn hiệu lực qua `migrate`,
 * `db:seed`, `billing:check-invariants`: máy chủ trước M12 nâng cấp lên M12 chạy migration
 * `create_push_subscriptions_table`, nó đọc `config('webpush.table_name')` từ cache cũ (chưa có
 * `config/webpush.php`) ra null, `Schema::create(null)` ném TypeError và site kẹt ở chế độ bảo trì.
 *
 * Test đi từng dòng của khối lệnh với một mô hình nhỏ của `bootstrap/cache/`: lúc bắt đầu, lần
 * `optimize` trước để lại `config.php`, `events.php`, `routes-v7.php`. Sau `git pull`, mọi dòng
 * `php artisan` cho tới `optimize` phải khởi động KHÔNG có `config.php` cũ; `migrate`, `db:seed`,
 * `billing:check-invariants`, `vkcrm:preflight` không có cache cũ nào. Và vì `optimize:clear` (qua
 * `clear-compiled`) xoá luôn `packages.php`/`services.php` mà lệnh `php artisan` KẾ TIẾP tự dựng lại
 * vào `bootstrap/cache/` của `www-data`, không dòng `php artisan` nào của người quản trị được chạy
 * khi hai tệp đó đang thiếu.
 */
it('§14.8 clears the previous release\'s cached config before the upgrade migrates', function () {
    // Tiền đề: bản M12 có migration đọc cấu hình của một tệp config mới.
    expect(igcFile('database/migrations/2026_10_03_000001_create_push_subscriptions_table.php'))
        ->toContain("config('webpush.table_name')")
        ->and(is_file(config_path('webpush.php')))->toBeTrue();

    $upgrade = igcSection(igcFile('docs/CAI-DAT.md'), '## Nâng cấp lên bản mới', '### Bản cập nhật M12');
    $lines = igcBashLines($upgrade, 'git pull');

    $stale = ['config.php' => true, 'events.php' => true, 'routes-v7.php' => true];
    $manifests = ['packages.php' => true, 'services.php' => true];
    $pulled = false;
    $seen = [];

    foreach ($lines as $line) {
        if ($line === 'git pull') {
            $pulled = true;
        }

        if (preg_match('/^(sudo -u www-data )?php artisan (\S+)/', $line, $artisan)) {
            [, $asWebUser, $command] = $artisan;
            $seen[] = $command;

            if ($command === 'optimize') {
                break;
            }

            if ($pulled) {
                expect($stale['config.php'])->toBeFalse("`{$line}` khởi động với bootstrap/cache/config.php của bản cũ");
            }

            if (in_array($command, ['migrate', 'db:seed', 'billing:check-invariants', 'vkcrm:preflight'], true)) {
                expect(array_keys(array_filter($stale)))->toBe([], "`{$line}` chạy với cache cũ còn lại");
            }

            if ($asWebUser === '') {
                expect(array_filter($manifests))->toHaveCount(2, "`{$line}` (người quản trị) phải tự ghi lại danh sách gói vào bootstrap/cache/ của www-data");
            }

            if ($command === 'optimize:clear') {
                $stale = array_map(fn () => false, $stale);
                $manifests = array_map(fn () => false, $manifests);
            } elseif ($command === 'config:clear') {
                $stale['config.php'] = false;
            } else {
                // Mỗi lần khởi động dựng lại danh sách gói còn thiếu.
                $manifests = array_map(fn () => true, $manifests);
            }
        } elseif (str_starts_with($line, 'sudo -u www-data rm -f ')) {
            preg_match_all('#bootstrap/cache/(\S+)#', $line, $removed);
            foreach ($removed[1] as $file) {
                if (array_key_exists($file, $stale)) {
                    $stale[$file] = false;
                }
                if (array_key_exists($file, $manifests)) {
                    $manifests[$file] = false;
                }
            }
        }
    }

    expect($seen)->toContain('migrate')
        ->and($seen)->toContain('vkcrm:preflight')
        ->and(end($seen))->toBe('optimize');

    expect($upgrade)->toContain("config('webpush.table_name')");

    $readme = igcFile('README.md');
    $summary = str_replace("\n  ", ' ', substr($readme, (int) strpos($readme, '- **Nâng cấp:**')));
    expect($summary)->toContain('config.php')
        ->and(strpos($summary, 'optimize:clear'))->toBeLessThan(strpos($summary, 'migrate --force'));
});

/**
 * Rà soát Task 2, vòng sửa 1 (finding 2; tìm ra khi đọc). Phần production nói nó dành cho "VPS … hoặc
 * một gói shared hosting có SSH", mà mô hình hai người dùng viết `sudo` ở mọi bước: trên shared hosting
 * không có `sudo` và PHP chạy bằng chính tài khoản SSH, người đọc dừng ở dòng đầu của Bước 2 với
 * `sudo: command not found` và tài liệu không nói gì. Đầu phần production và chuỗi nâng cấp phải nói
 * cách đọc lệnh trên shared hosting: một người làm cả hai việc, bỏ `sudo -u www-data`/`sudo`, bỏ các
 * dòng `chown`, GIỮ `chmod 600 .env`. README nói cùng điều ở gạch "Hai người dùng".
 */
it('§14.8 tells a shared-hosting installer how to read the sudo lines', function () {
    $guide = igcFile('docs/CAI-DAT.md');
    $intro = str_replace("\n", ' ', igcSection($guide, '## Cài lên máy chủ thật (production)', '### Bước 0'));
    $upgrade = str_replace("\n", ' ', igcSection($guide, '## Nâng cấp lên bản mới', '### Bản cập nhật M12'));

    expect($intro)->toContain('**Shared hosting (không có `sudo`).**')
        ->and($intro)->toContain('sudo: command not found')
        ->and($intro)->toContain('bỏ tiền tố `sudo -u www-data`')
        ->and($intro)->toContain('`chown`')
        ->and($intro)->toContain('GIỮ `chmod 600 .env`');

    expect($upgrade)->toContain('**Shared hosting:**')
        ->and($upgrade)->toContain('bỏ tiền tố `sudo -u www-data`')
        ->and($upgrade)->toContain('`chown`');

    $readme = igcFile('README.md');
    $twoUsers = str_replace("\n", ' ', substr($readme, (int) strpos($readme, '- **Hai người dùng:**'), 1500));
    expect($twoUsers)->toContain('shared hosting')
        ->and($twoUsers)->toContain('bỏ `sudo`');
});

/**
 * Gap 9 (quan sát được). Dòng `docker run … -w /var/www/html …` của README chạy trong Git Bash (README
 * bảo người dùng Windows dùng Git Bash) thì Docker từ chối "the working directory 'C:/Program
 * Files/Git/var/www/html' is invalid". Cả README và lối cài máy dev của CAI-DAT phải có
 * `MSYS_NO_PATHCONV=1`; và lối của CAI-DAT phải sinh khoá trước khi gieo dữ liệu (gap 10, quan sát được:
 * thiếu `key:generate` thì gieo dừng ở "No application encryption key has been specified.").
 */
it('§14.8 gives a dev install that runs in Git Bash and generates the key before seeding', function () {
    preg_match('/^.*docker run --rm -v "\$PWD:\/var\/www\/html".*$/m', igcFile('README.md'), $readmeLine);
    expect($readmeLine)->not->toBeEmpty()
        ->and($readmeLine[0])->toStartWith('MSYS_NO_PATHCONV=1 docker run');

    $dev = igcSection(igcFile('docs/CAI-DAT.md'), '## Bốn bước', '## Bốn thứ cố ý KHÔNG nằm trong kho');
    $lines = igcBashLines($dev, 'migrate:fresh --seed');

    expect($lines[0])->toStartWith('MSYS_NO_PATHCONV=1 docker run')
        ->and(array_search('bin/dev artisan key:generate', $lines, true))
        ->toBeLessThan(array_search('bin/dev artisan migrate:fresh --seed', $lines, true))
        ->and($dev)->toContain('No application encryption key has been specified.');
});

/**
 * Gap 11 (quan sát được). Hai tài liệu hứa một con số cho dữ liệu mẫu (README "20 vụ việc", CAI-DAT "21
 * vụ việc, 12 khách hàng, 8 nhân sự") mà `migrate:fresh --seed` nay dựng 27 vụ, 12 khách, 9 nhân sự (8
 * đang hoạt động) và 16 tài khoản cổng; vụ chuyển từ tiếp nhận là vụ thứ 27, không phải 23. Test gieo
 * đúng `DatabaseSeeder` và so với chữ của hai tài liệu.
 */
it('§14.8 promises the demo data the seeder really builds', function () {
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    $this->seed(DatabaseSeeder::class);

    expect(Matter::query()->withoutGlobalScopes()->count())->toBe(27)
        ->and(Client::query()->withoutGlobalScopes()->count())->toBe(12)
        ->and(ClientUser::query()->withoutGlobalScopes()->count())->toBe(16)
        ->and(User::query()->count())->toBe(9)
        ->and(User::query()->where('is_active', true)->count())->toBe(8)
        ->and(Matter::query()->withoutGlobalScopes()->where('id', '>', 20)->where('confidentiality', Confidentiality::Restricted)->exists())->toBeTrue()
        ->and(IntakeRequest::query()->withoutGlobalScopes()->whereNotNull('matter_id')->max('matter_id'))
        ->toBe(Matter::query()->withoutGlobalScopes()->max('id'));

    $readme = igcFile('README.md');
    expect(str_replace("\n", ' ', $readme))->toContain('27 vụ việc, 12 khách hàng, 16 tài khoản cổng khách và 9')
        ->and($readme)->toContain('vụ thứ 27')
        ->and($readme)->not->toContain('vụ thứ 23')
        ->and($readme)->not->toContain('Dữ liệu mẫu có 20 vụ việc');

    expect(str_replace("\n", ' ', igcSection(igcFile('docs/CAI-DAT.md'), '# Cài lại VK-CRM trên một máy trống', '## Cần có sẵn trên máy')))
        ->toContain('27 vụ việc, 12 khách hàng, 9 nhân')
        ->toContain('16 tài khoản cổng khách')
        ->not->toContain('21 vụ việc');

    // SPEC (nguồn sự thật, CLAUDE.md) nói cùng con số với README, qua một đính chính có ngày; không
    // còn số thứ tự cũ ở đâu trong §12 (rà soát cuối làn v1, vòng sửa 1, I3).
    $section12 = str_replace("\n", ' ', igcSection(igcFile('docs/SPEC.md'), '## 12. Dữ liệu mẫu', '## 13. Milestone'));
    expect($section12)->toContain('**Đính chính 2026-10-09')
        ->toContain('**vụ thứ 27**')
        ->toContain('**27 vụ**')
        ->not->toMatch('/vụ (việc )?thứ\s+(\*\*)?23/u');
});

/**
 * Rà soát cuối làn v1, vòng sửa 1 (I2; tìm ra khi đọc mã của Laravel). Tài liệu hứa `.env` chỉ
 * `www-data` đọc được vì nó chứa `APP_KEY` và mọi mật khẩu, mà `php artisan optimize` (Bước 7, chuỗi
 * nâng cấp) chép đúng những giá trị đó ra `bootstrap/cache/config.php` bằng `Filesystem::put()` — một
 * tệp mới mang quyền theo `umask` (thường `644`), ai trên máy cũng đọc được: đúng mối nguy mà đoạn
 * "Shared hosting" nêu tên. Mọi dòng `php artisan optimize` trong các khối lệnh của CAI-DAT phải có
 * ngay sau nó `sudo -u www-data chmod 600 bootstrap/cache/config.php`; ba chỗ hứa giữ kín (đầu phần
 * production, Bước 2, Bước 7) nói về tệp cache; README nói cùng điều.
 */
it('§14.8 keeps the cached copy of .env as private as .env itself', function () {
    // Tiền đề: Laravel ghi cache cấu hình bằng put() — không đặt quyền riêng, quyền theo umask.
    $command = igcFile('vendor/laravel/framework/src/Illuminate/Foundation/Console/ConfigCacheCommand.php');
    expect($command)->toContain('$this->files->put(')
        ->not->toContain('chmod');

    $guide = igcFile('docs/CAI-DAT.md');
    $chmod = 'sudo -u www-data chmod 600 bootstrap/cache/config.php';

    preg_match_all('/```bash\n(.*?)```/s', $guide, $blocks);
    $optimizeLines = 0;

    foreach ($blocks[1] as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn (string $line): bool => $line !== ''));

        foreach ($lines as $i => $line) {
            if (preg_match('/php artisan optimize$/', $line) === 1) {
                $optimizeLines++;
                expect($lines[$i + 1] ?? null)->toBe($chmod, "`{$line}` không có dòng chmod ngay sau");
            }
        }
    }

    expect($optimizeLines)->toBeGreaterThanOrEqual(2);

    $flat = fn (string $text): string => str_replace("\n", ' ', $text);
    expect($flat(igcSection($guide, '## Cài lên máy chủ thật (production)', '### Bước 0')))
        ->toContain('GIỮ cả dòng `chmod 600 bootstrap/cache/config.php` sau MỖI `php artisan optimize`');
    expect($flat(igcSection($guide, '### Bước 2', '### Bước 3')))
        ->toContain('`bootstrap/cache/config.php` (Bước 7) được tạo với quyền `644`');
    expect($flat(igcSection($guide, '### Bước 7', '### Bước 8')))
        ->toContain('**Dòng `chmod 600 bootstrap/cache/config.php` sau MỖI `optimize`, ở mọi chỗ trong tài liệu này.**');

    $readme = $flat(igcFile('README.md'));
    $upgrade = substr($readme, (int) strpos($readme, '- **Nâng cấp:**'));
    expect(substr($readme, (int) strpos($readme, '- **Hai người dùng:**'), 1500))->toContain('chmod 600 bootstrap/cache/config.php')
        ->and(substr($readme, (int) strpos($readme, '- **Thứ tự cài:**'), 1500))->toContain('`php artisan optimize` → `chmod 600 bootstrap/cache/config.php`')
        ->and(strpos($upgrade, '`php artisan optimize` →'))->toBeLessThan(strpos($upgrade, 'chmod 600 bootstrap/cache/config.php'))
        ->and(strpos($upgrade, 'chmod 600 bootstrap/cache/config.php'))->toBeLessThan(strpos($upgrade, '`php artisan up`'));
});

/**
 * Gap 12 (quan sát được). Lệnh dữ liệu mẫu cho demo trên máy chủ thật (`db:seed --class=DemoDataSeeder`)
 * dừng ở "Call to undefined function Database\Seeders\fake()": Faker là gói dev, và Bước 2 cài
 * `--no-dev`. Tài liệu bảo cài gói dev trước khi gieo, và gỡ chúng trước chuỗi "Hết demo". Test cũng giữ
 * cho tiền đề đúng: Faker còn nằm ở `require-dev`.
 */
it('§14.8 installs the dev packages the demo data needs, and removes them before going live', function () {
    $composer = json_decode(igcFile('composer.json'), true);
    expect($composer['require-dev'])->toHaveKey('fakerphp/faker')
        ->and($composer['require'])->not->toHaveKey('fakerphp/faker');

    $step5 = igcSection(igcFile('docs/CAI-DAT.md'), '### Bước 5', '### Bước 6');

    expect($step5)->toContain('Call to undefined function Database\Seeders\fake()');

    $demo = igcBashLines($step5, '--class=DemoDataSeeder');
    expect($demo[0])->toBe('composer install --optimize-autoloader --no-scripts')
        ->and(end($demo))->toBe('sudo -u www-data php artisan db:seed --class=DemoDataSeeder --force');

    $live = substr($step5, (int) strpos($step5, '**Hết demo, chuyển sang dùng thật'));
    expect(igcBashLines($live, 'composer install')[0])->toBe('composer install --no-dev --optimize-autoloader --no-scripts')
        ->and(strpos($live, 'composer install --no-dev'))->toBeLessThan(strpos($live, 'migrate:fresh --force'));
});

/**
 * Hồ sơ nghiệm thu nói đúng điều đã làm. R6 của kế hoạch M8 đòi một agent chưa từng đọc kho; ngày
 * 2026-10-08 người điều phối quyết định nhận thay vào đó một lượt đọc lạnh MÔ PHỎNG — agent làm Task 2
 * của làn v1 làm theo chữ của README + CAI-DAT trên máy Ubuntu 24.04 trống, từ Bước 1 tới "Nâng cấp
 * lên bản mới". §14 mục 8 vì vậy được tick, và hồ sơ phải nói đúng ba điều: đó là lượt MÔ PHỎNG do
 * người đã đọc kho đi (không phải agent chưa từng đọc kho), theo quyết định của người điều phối; bước
 * nào chưa đi (đăng nhập + 2FA trên trình duyệt, deploy key với GitHub thật, khôi phục thử, cài máy chủ
 * MariaDB); và mỗi chỗ vấp có test, tám chỗ ở tệp này. Test này thay test cũ "keeps the cold read pending"
 * (rà soát v1b, R1), trong cùng commit tick §14 mục 8.
 */
it('§14.8 records the simulated cold read for what it is, and ticks criterion 8 with it', function () {
    $progress = igcFile('docs/PROGRESS.md');

    preg_match('/^\| \*\*Bản 1\.0\*\*.*$/m', $progress, $v1Row);
    preg_match('/^\| M8 .*$/m', $progress, $m8Row);
    expect($v1Row)->not->toBeEmpty('Không thấy dòng "Bản 1.0" trong bảng milestone')
        ->and($m8Row)->not->toBeEmpty('Không thấy dòng M8 trong bảng milestone');

    foreach ([$v1Row[0], $m8Row[0]] as $text) {
        expect($text)->toContain('| ✅')
            ->and($text)->toContain('mô phỏng')
            ->and($text)->toContain('người điều phối')
            ->and($text)->not->toContain('cài thật từ máy trống')
            ->and($text)->not->toContain('CHỜ một lượt đọc');
    }

    $acceptance = substr($progress, (int) strpos($progress, '## Nghiệm thu bản 1.0'));
    $section = igcSection($acceptance, '## Nghiệm thu bản 1.0', '### Cần chủ văn phòng quyết / làm');

    preg_match('/^8\. .*(?:\n {3}.*)*/m', $section, $criterion8);
    expect($criterion8)->not->toBeEmpty('Không thấy tiêu chí 8')
        ->and($criterion8[0])->toContain('✅')
        ->and($criterion8[0])->toContain('mô phỏng')
        ->and($criterion8[0])->toContain('KHÔNG phải agent chưa từng đọc kho')
        ->and($criterion8[0])->not->toContain('CHỜ');

    $walk = igcSection($acceptance, '### Lượt đọc lạnh mô phỏng', '### Cần chủ văn phòng quyết / làm');

    foreach (['Bước 1', 'Bước 2', 'Bước 3', 'Bước 4', 'Bước 5', 'Bước 6', 'Bước 7', 'Bước 8', 'Bước 10', 'Bước 11', 'Nâng cấp lên bản mới'] as $step) {
        expect($walk)->toContain($step);
    }

    $notWalked = igcSection($walk, 'Chưa đi:', 'Chỗ vấp');
    foreach (['Bước 9', 'deploy key', 'khôi phục thử', 'MariaDB'] as $item) {
        expect($notWalked)->toContain($item);
    }

    // Tám trong mười chỗ vấp của hồ sơ trỏ tới một test "§14.8 …" có thật trong tệp này (chỗ 7 dùng chung
    // test với chỗ 6; chỗ 10 ở InstallGuideDemoDataTest).
    $gaps = substr($walk, (int) strpos($walk, 'Chỗ vấp'));
    preg_match_all('/"(§14\.8 [^"]+)"/u', $gaps, $cited);
    $file = igcFile('tests/Feature/Acceptance/InstallGuideColdReadTest.php');

    expect($cited[1])->toHaveCount(8);
    foreach ($cited[1] as $name) {
        // Tên test trong PROGRESS có thể xuống dòng giữa chừng; gộp khoảng trắng trước khi so.
        expect($file)->toContain("it('".preg_replace('/\s+/u', ' ', $name)."'");
    }

    $plan = igcFile('docs/superpowers/plans/2026-09-21-m8-security-and-launch.md');
    expect($plan)->toContain('### - [x] Task 8 — Nghiệm thu toàn hệ thống (SPEC §14)')
        ->and($plan)->not->toContain('### - [ ] Task 8');
});
