<?php

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Làn fc — sửa sau đợt kiểm tra nghiệp vụ toàn hệ thống (2026-10-09), phần tài liệu vận hành
|--------------------------------------------------------------------------
|
| Đợt kiểm tra đi theo đúng chữ của `docs/CAI-DAT.md`, `docs/SAO-LUU-KHOI-PHUC.md` và `README.md` trên
| một máy Ubuntu 24.04 theo mô hình hai người dùng (người quản trị có `sudo`, PHP-FPM chạy bằng
| `www-data`). Mỗi chỗ vấp đã được một agent khác kiểm chứng là thật; mỗi test dưới đây đọc chính tài
| liệu người vận hành cầm trên tay và giữ cho chỗ vấp đó không quay lại. Mục A (bắt buộc) là A1–A6,
| mục B là các việc nhỏ đã làm.
|
| Hàm toàn cục mang tiền tố `oga…`.
*/

function ogaFile(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)));
}

/** Đoạn của `$text` từ `$start` tới (không gồm) lần xuất hiện kế tiếp của `$end`. */
function ogaSection(string $text, string $start, string $end): string
{
    $from = strpos($text, $start);
    expect($from)->not->toBeFalse("Không thấy {$start}");
    $to = strpos($text, $end, (int) $from + strlen($start));
    expect($to)->not->toBeFalse("Không thấy {$end} sau {$start}");

    return substr($text, (int) $from, (int) $to - (int) $from);
}

function ogaFlat(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
}

/**
 * Các dòng lệnh (bỏ dòng trống và dòng chú thích) của khối ``` đầu tiên trong `$section` có chứa
 * `$needle`. @return list<string>
 */
function ogaBlockLines(string $section, string $needle): array
{
    preg_match_all('/^\s*```[a-z]*\n(.*?)^\s*```/ms', $section, $blocks);

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

/** Mọi dòng lệnh trong mọi khối ``` của `$text`. @return list<string> */
function ogaAllBlockLines(string $text): array
{
    preg_match_all('/^\s*```[a-z]*\n(.*?)^\s*```/ms', $text, $blocks);
    $lines = [];

    foreach ($blocks[1] as $block) {
        foreach (array_map('trim', explode("\n", $block)) as $line) {
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }
    }

    return $lines;
}

function ogaIndexOf(array $lines, string $prefix): int
{
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, $prefix)) {
            return $i;
        }
    }

    expect(false)->toBeTrue("Không thấy dòng bắt đầu bằng {$prefix}");

    return -1;
}

/*
 * A1. Chuỗi nâng cấp không sao lưu ngay trước khi cập nhật, không ghi lại bản đang chạy, và không có
 * đường quay về: một migration hỏng giữa chừng lúc 16:00 chỉ còn bản sao lưu 02:00 (mất mọi tài liệu,
 * khoản thu, cập nhật trong ngày), và người nạp bản đó lên mã mới thì bị SAO-LUU bước 8 bảo "dừng lại".
 */
it('A1: the upgrade chain backs up the database and records the running commit before git pull', function () {
    // Tiền đề: hai lệnh mà chuỗi dùng là lệnh thật, nhận đúng tuỳ chọn tài liệu viết.
    expect(Artisan::all()['backup:run']->getDefinition()->hasOption('only-db'))->toBeTrue()
        ->and(Artisan::all())->toHaveKey('db:wipe')
        ->and(Artisan::all()['db:wipe']->getDefinition()->hasOption('force'))->toBeTrue();

    $upgrade = ogaSection(ogaFile('docs/CAI-DAT.md'), '## Nâng cấp lên bản mới', '### Bản cập nhật M12');

    // Hai khối: khối thứ nhất đóng cổng, sao lưu, ghi commit; người vận hành chỉ chép khối thứ hai (có
    // `git pull`) sau khi thấy `Backup completed!` — dán một khối thì mọi dòng chạy dù dòng trước hỏng.
    $before = ogaBlockLines($upgrade, 'php artisan down');
    $after = ogaBlockLines($upgrade, 'git pull');

    $down = array_search('sudo -u www-data php artisan down', $before, true);
    $backup = array_search('sudo -u www-data php artisan backup:run --only-db', $before, true);
    $record = ogaIndexOf($before, 'echo "$(date');

    expect($down)->toBe(1)
        ->and($backup)->toBe($down + 1)
        ->and($record)->toBe($backup + 1)
        ->and($before)->not->toContain('git pull')
        ->and($before[$record])->toContain('$(git rev-parse HEAD)')
        ->and($before[$record])->toEndWith('>> ~/vk-crm-nang-cap.log')
        ->and($after)->toContain('git pull')
        ->and($after)->not->toContain('sudo -u www-data php artisan down');

    $flat = ogaFlat($upgrade);
    expect(strpos($flat, 'Backup completed!'))->toBeGreaterThan(strpos($flat, 'backup:run --only-db'))
        ->and(strpos($flat, 'Backup completed!'))->toBeLessThan(strpos($flat, ' git pull '));
    expect($flat)->toContain('Backup completed!')
        ->toContain('Nâng cấp hỏng: quay lại bản trước')
        ->not->toContain('bản sao lưu đêm trước là điểm quay lại');
});

it('A1: a failed upgrade has a copy-paste way back to the recorded commit and the dump taken just before', function () {
    $guide = ogaFile('docs/CAI-DAT.md');
    $rollback = ogaSection($guide, '### Nâng cấp hỏng: quay lại bản trước', '## Thao tác tiền');
    $lines = ogaBlockLines($rollback, 'git reset --hard');

    $reset = ogaIndexOf($lines, 'git reset --hard');
    $composer = array_search('composer install --no-dev --optimize-autoloader --no-scripts', $lines, true);
    $clear = array_search('sudo -u www-data php artisan optimize:clear', $lines, true);
    $wipe = array_search('sudo -u www-data php artisan db:wipe --force', $lines, true);
    $load = ogaIndexOf($lines, 'mariadb ');
    $status = array_search('sudo -u www-data php artisan migrate:status', $lines, true);
    $preflight = array_search('sudo -u www-data php artisan vkcrm:preflight', $lines, true);
    $optimize = array_search('sudo -u www-data php artisan optimize', $lines, true);

    expect($lines[$reset])->toContain('vk-crm-nang-cap.log')
        ->and($composer)->toBeGreaterThan($reset)
        ->and($clear)->toBeGreaterThan($composer)
        ->and($wipe)->toBeGreaterThan($clear)
        ->and($load)->toBeGreaterThan($wipe)
        ->and($status)->toBeGreaterThan($load)
        ->and($preflight)->toBeGreaterThan($status)
        ->and($optimize)->toBeGreaterThan($preflight)
        ->and($lines[$optimize + 1])->toBe('sudo -u www-data chmod 600 bootstrap/cache/config.php')
        ->and(end($lines))->toBe('sudo -u www-data php artisan up');

    // Giải nén bằng PHP (unzip không mở được AES-256), có open() trước setPassword().
    expect($rollback)->toContain('$z->open(')
        ->and($rollback)->toContain('backup:run --only-db')
        ->and(ogaFlat($rollback))->toContain('mọi dòng phải là `Ran`');

    // Mọi dòng php artisan của khối quay lại cũng ghi rõ người chạy.
    foreach ($lines as $line) {
        if (str_contains($line, 'php artisan') && $line !== 'php artisan filament:assets') {
            expect($line)->toStartWith('sudo -u www-data php artisan ');
        }
    }
});

it('A1: the restore guide says when to migrate a restored dump forward and when to check out the old code', function () {
    $restore = ogaFlat(ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '### Quy trình cho máy chủ thật', '### Bảng số đo thật'));

    expect($restore)->toContain('`sudo -u www-data php artisan migrate --force`')
        ->toContain('`~/vk-crm-nang-cap.log`')
        ->toContain('"Nâng cấp hỏng: quay lại bản trước"')
        ->not->toContain('dừng lại, đối chiếu lại phiên bản mã nguồn với thời điểm bản sao lưu trước khi đi tiếp');
});

/*
 * A2. `https://downloads.rclone.org/SHA256SUMS` trả 404, và `curl -O` không `-f` thoát 0 với trang lỗi
 * HTML làm "bảng mã" — phép kiểm toàn vẹn không làm được mà không ai được báo.
 */
it('A2: the rclone download checks the versioned SHA256SUMS and fails loudly on HTTP errors', function () {
    $guide = ogaFile('docs/SAO-LUU-KHOI-PHUC.md');
    $step1 = ogaSection($guide, '## Bước 1 — Cài `rclone` trên máy chủ', '## Bước 2');

    expect($step1)->toContain('https://downloads.rclone.org/version.txt')
        ->toContain('curl -fsSLO "https://downloads.rclone.org/$V/rclone-$V-linux-amd64.zip"')
        ->toContain('curl -fsSLO "https://downloads.rclone.org/$V/SHA256SUMS"')
        ->toContain('sha256sum -c --ignore-missing SHA256SUMS')
        ->toContain(': OK')
        ->and($guide)->not->toContain('https://downloads.rclone.org/SHA256SUMS')
        ->and($guide)->not->toContain('rclone-current-linux-amd64.zip');

    // Mọi lệnh curl trong mọi khối lệnh của tài liệu đều có -f: 404 là lỗi thật.
    foreach (ogaAllBlockLines($guide) as $line) {
        if (preg_match('/(^|\s|\()curl\s/', $line) === 1) {
            expect($line)->toMatch('/curl -[a-zA-Z]*f/');
        }
    }
});

/*
 * A3. Trên VPS theo CAI-DAT, `www-data` (người chạy cron và backup-check) không chạy được `~/bin/rclone`
 * của người quản trị và không thấy cấu hình rclone nằm trong thư mục nhà của người khác; tài liệu lại
 * bảo để trống BACKUP_RCLONE_CONFIG. backup-check không bao giờ xanh.
 */
it('A3: on a VPS rclone lives in /usr/local/bin and its config belongs to www-data, named in .env', function () {
    $guide = ogaFile('docs/SAO-LUU-KHOI-PHUC.md');
    $step1 = ogaSection($guide, '## Bước 1 — Cài `rclone` trên máy chủ', '## Bước 2');
    $step3 = ogaSection($guide, '## Bước 3 — Chạy `rclone config`', '## Bước 4');
    $step4 = ogaSection($guide, '## Bước 4 — Điền `.env` trên máy chủ', '## Bước 5');

    expect($step1)->toContain('sudo install -m 755 "rclone-$V-linux-amd64/rclone" /usr/local/bin/rclone')
        ->toContain('sudo install -d -m 0750 -o www-data -g www-data /etc/vkcrm-rclone')
        ->toContain('**Shared hosting**')
        ->toContain('~/bin/rclone');

    expect($step3)->toContain('sudo -u www-data rclone --config /etc/vkcrm-rclone/rclone.conf config')
        ->toContain('sudo -u www-data rclone --config /etc/vkcrm-rclone/rclone.conf lsd gdrive:')
        ->toContain('sudo -u www-data rclone --config /etc/vkcrm-rclone/rclone.conf mkdir gdrive:VK-CRM-backups');

    expect($step4)->toContain('BACKUP_RCLONE_CONFIG=/etc/vkcrm-rclone/rclone.conf')
        ->toContain('BACKUP_RCLONE_BINARY=/usr/local/bin/rclone')
        ->and(ogaFlat($step4))->not->toContain('để trống trong tình huống thông thường');

    // Mã đọc đúng biến đó: có đường dẫn thì truyền --config.
    expect(ogaFile('config/vkcrm.php'))->toContain("env('BACKUP_RCLONE_CONFIG')")
        ->and(ogaFile('app/Support/Backup/RcloneProcess.php'))->toContain("'--config'");
});

/*
 * A4. `#` trong một giá trị không ngoặc cắt im lặng phần còn lại (phpdotenv của chính dự án). Mật khẩu
 * sao lưu bị cắt thì archive được mã hoá bằng nửa đầu, và chuỗi đầy đủ đã cất ở Bước 6 không mở được nó.
 */
it('A4: secrets go in single quotes, because # silently truncates an unquoted value', function () {
    // Tiền đề, đo trên phpdotenv của dự án.
    expect(Dotenv::parse('A=ab#cd'))->toBe(['A' => 'ab'])
        ->and(Dotenv::parse('A=ab #cd'))->toBe(['A' => 'ab'])
        ->and(Dotenv::parse("A='ab#c\${D}'"))->toBe(['A' => 'ab#c${D}']);

    $step4 = ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '## Bước 4 — Điền `.env` trên máy chủ', '## Bước 5');
    preg_match('/^BACKUP_ARCHIVE_PASSWORD=.*$/m', $step4, $template);

    expect($template)->not->toBeEmpty()
        ->and($template[0])->toMatch("/^BACKUP_ARCHIVE_PASSWORD='[^']+'$/")
        ->and(ogaFlat($step4))->toContain('ngoặc ĐƠN')
        ->and($step4)->toContain('openssl rand -hex 32');

    $step3 = ogaFlat(ogaSection(ogaFile('docs/CAI-DAT.md'), '### Bước 3 — Tệp `.env`', '### Bước 4 — Máy chủ web'));
    expect($step3)->toContain('**Mật khẩu và mọi bí mật nằm trong ngoặc ĐƠN.**')
        ->toContain('`#`')
        ->toContain('`${…}`')
        ->toContain("`DB_PASSWORD='…'`")
        ->toContain('openssl rand -hex 32');

    preg_match('/((?:^#.*\n)+)^BACKUP_ARCHIVE_PASSWORD=$/m', ogaFile('.env.example'), $comment);
    expect($comment)->not->toBeEmpty()
        ->and(ogaFlat($comment[1]))->toContain('ngoặc đơn');
});

/*
 * A5. Mẫu nginx trỏ tới /etc/letsencrypt/live/<tên miền>/…, mà không bước nào lấy chứng chỉ: `nginx -t`
 * dừng ở "cannot load certificate", và certbot chế độ nginx/webroot lại cần nginx đang chạy.
 */
it('A5: step 4 obtains the first certificate before enabling the site, and says how it renews', function () {
    $guide = ogaFile('docs/CAI-DAT.md');
    $step4 = ogaSection($guide, '### Bước 4 — Máy chủ web', '### Bước 5');
    $flat = ogaFlat($step4);

    expect($step4)->toContain('sudo certbot certonly --standalone')
        ->toContain('--pre-hook "systemctl stop nginx"')
        ->toContain('--post-hook "systemctl start nginx"')
        ->toContain('sudo certbot renew --dry-run')
        ->toContain('/etc/letsencrypt/live/<tên miền>/fullchain.pem')
        ->and($flat)->toContain('cannot load certificate')
        ->and(strpos($step4, 'sudo certbot certonly'))->toBeLessThan(strpos($step4, '/etc/nginx/sites-enabled/'));

    expect(ogaFile('tools/deploy/nginx.conf.example'))->toContain('/etc/letsencrypt/live/');

    preg_match('/^\s*sudo apt update && sudo apt install .*$/m', ogaSection($guide, '### Bước 1', '### Bước 2'), $apt);
    expect($apt)->not->toBeEmpty();
    foreach (['certbot', 'unzip', 'curl', 'cron'] as $package) {
        expect($apt[0])->toMatch('/\s'.$package.'(\s|$)/');
    }
});

/*
 * A6. SAO-LUU viết `php artisan …` trần; người quản trị gõ đúng chữ thì Laravel chết lúc khởi động vì
 * không đọc được `bootstrap/cache/config.php` (600, của `www-data`). Mọi khối lệnh của SAO-LUU và của
 * phần production trong CAI-DAT ghi rõ người chạy.
 */
it('A6: every artisan line of the backup guide and of the production install runs as www-data', function () {
    $backup = ogaFile('docs/SAO-LUU-KHOI-PHUC.md');
    $production = ogaSection(ogaFile('docs/CAI-DAT.md'), '## Cài lên máy chủ thật (production)', '## Thao tác tiền');
    $bare = [];

    foreach (['SAO-LUU' => $backup, 'CAI-DAT' => $production] as $name => $text) {
        foreach (ogaAllBlockLines($text) as $line) {
            if (! str_contains($line, 'php artisan')
                || $line === 'php artisan filament:assets'
                || str_starts_with($line, '* * * * *')
                || str_contains($line, 'bin/dev')) {
                continue;
            }

            if (! str_starts_with($line, 'sudo -u www-data php artisan ')) {
                $bare[] = "{$name}: {$line}";
            }
        }
    }

    expect($bare)->toBe([]);

    $flatBackup = ogaFlat($backup);
    expect($flatBackup)->toContain('bootstrap/cache/config.php): Failed to open stream: Permission denied')
        ->toContain('**Shared hosting**');

    $intro = ogaFlat(ogaSection(ogaFile('docs/CAI-DAT.md'), '## Cài lên máy chủ thật (production)', '### Bước 0'));
    expect($intro)->not->toContain('viết gọn')
        ->toContain('bootstrap/cache/config.php): Failed to open stream: Permission denied');
});

/*
 * B (ops). Lần khôi phục thử bắt buộc trước khi mở cổng không làm được ngay trong ngày: không tài liệu
 * nào bảo tạo bản sao lưu đầu tiên bằng tay.
 */
it('B: the first backup is made by hand before the restore drill', function () {
    $step10 = ogaSection(ogaFile('docs/CAI-DAT.md'), '### Bước 10', '### Bước 11');
    $step5 = ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '## Bước 5 — Chạy lệnh kiểm tra', '## Bước 6');

    expect($step10)->toContain('sudo -u www-data php artisan backup:run')
        ->and($step5)->toContain('sudo -u www-data php artisan backup:run')
        ->and(ogaFlat($step5))->toContain('Backup completed!');
});

/*
 * B (ops + coldread). Quy trình khôi phục THẬT không nói bỏ bước cài đặt nào, thiếu chown/optimize, và
 * kết thúc ở "mở thử một hồ sơ" — hệ thống không trở lại hoạt động.
 */
it('B: the real restore says which install steps to skip and brings the system back up', function () {
    $restore = ogaFlat(ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '### Quy trình cho máy chủ thật', '### Bảng số đo thật'));

    expect($restore)->toContain('Bước 1–4')
        ->toContain('BỎ Bước 5 và Bước 6')
        ->toContain('`key:generate`')
        ->toContain('`webpush:vapid`')
        ->toContain('`sudo chown -R www-data:www-data storage`')
        ->toContain('**Sau khi dữ liệu đã đúng (chỉ khôi phục thật)**')
        ->toContain('`sudo -u www-data php artisan vkcrm:backup-check`')
        ->toContain('`sudo -u www-data php artisan up`');

    $step6 = ogaFlat(ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '## Bước 6', '## Khôi phục thử'));
    expect($step6)->toContain("`sudo -u www-data grep -E '^(APP_KEY|BACKUP_ARCHIVE_PASSWORD|VAPID_)' .env`")
        ->toContain('`sudo -u www-data cat storage/oauth-private.key`')
        ->toContain('tệp `.env`');
});

/* B (coldread). Bước 7 đòi preflight xanh hết, mà HEARTBEAT_URL (ĐỎ) chỉ được dựng ở Bước 8. */
it('B: HEARTBEAT_URL is created at step 3, not discovered red at step 7', function () {
    $guide = ogaFile('docs/CAI-DAT.md');
    $step3 = ogaSection($guide, '### Bước 3 — Tệp `.env`', '### Bước 4 — Máy chủ web');
    preg_match('/^\| `HEARTBEAT_URL` .*$/m', $step3, $row);

    expect($row)->not->toBeEmpty()
        ->and($row[0])->toContain('tạo ngay bây giờ')
        ->and(ogaFlat(ogaSection($guide, '### Bước 7', '### Bước 8')))->toContain('`HEARTBEAT_URL`');
});

/* B (coldread). Giới hạn upload của PHP-FPM: không nói sửa tệp nào, không nói phải khởi động lại. */
it('B: the PHP-FPM upload limits come with the file, the command and the restart', function () {
    $step1 = ogaSection(ogaFile('docs/CAI-DAT.md'), '### Bước 1', '### Bước 2');

    expect($step1)->toContain('/etc/php/8.3/fpm/php.ini')
        ->toContain('sudo systemctl restart php8.3-fpm')
        ->toContain("php-fpm8.3 -i | grep -E 'upload_max|post_max'");
});

/* B (ops). Dòng cron dùng `php` trần, và lỗi bị nuốt vào /dev/null. */
it('B: the cron step says how to name the exact PHP binary on shared hosting and how to read cron errors', function () {
    $step8 = ogaFlat(ogaSection(ogaFile('docs/CAI-DAT.md'), '### Bước 8', '### Bước 9'));

    expect($step8)->toContain('`command -v php`')
        ->toContain('/opt/cpanel/ea-php83/root/usr/bin/php')
        ->toContain('storage/logs/cron.log');
});

/* B (ops). Nhật ký lỗi một tệp không xoay vòng, và không tài liệu nào chỉ nơi xem nó. */
it('B: production logs rotate daily and the guide says where they are', function () {
    $guide = ogaFile('docs/CAI-DAT.md');
    preg_match('/^\| `LOG_STACK` .*$/m', ogaSection($guide, '### Bước 3 — Tệp `.env`', '### Bước 4'), $row);

    expect($row)->not->toBeEmpty()
        ->and($row[0])->toContain('`daily`')
        ->and(ogaFlat(ogaSection($guide, '## Vận hành hằng ngày', '## Nâng cấp lên bản mới')))->toContain('storage/logs/laravel-');

    // Tiền đề: kênh daily của dự án giữ LOG_DAILY_DAYS tệp (mặc định 14), tên laravel-YYYY-MM-DD.log.
    expect(config('logging.channels.daily.max_files'))->toBe(14)
        ->and(config('logging.channels.daily.path'))->toEndWith('logs/laravel.log');
});

/* B (coldread). Tài khoản CSDL @'localhost' trong khi .env đặt DB_HOST=127.0.0.1. */
it('B: the database user and DB_HOST agree on localhost', function () {
    $guide = ogaFile('docs/CAI-DAT.md');
    preg_match('/^\| `DB_HOST`, .*$/m', ogaSection($guide, '### Bước 3 — Tệp `.env`', '### Bước 4'), $row);

    expect($row)->not->toBeEmpty()
        ->and($row[0])->toContain('`localhost`')
        ->and($row[0])->not->toContain('`127.0.0.1`, `3306`')
        ->and(ogaSection($guide, '### Bước 1', '### Bước 2'))->toContain("CREATE USER 'vk_crm'@'localhost'");
});

/* B (coldread). README: crontab của ai, thứ tự cài thiếu passport:keys/VAPID/cron/heartbeat, lệnh vận hành cũ. */
it('B: the README install summary is complete and names whose crontab the line goes in', function () {
    $readme = ogaFlat(ogaFile('README.md'));
    $order = ogaSection($readme, '- **Thứ tự cài:**', '- **`php artisan vkcrm:preflight` phải xanh');

    expect($order)->toContain('passport:keys')
        ->toContain('webpush:vapid')
        ->toContain('HEARTBEAT_URL')
        ->toContain('certbot');

    expect($readme)->toContain('`sudo crontab -u www-data -e`')
        ->toContain('php artisan list vkcrm');
});

/* B (coldread). SAO-LUU trỏ tới một mục không tồn tại, và trỏ chủ văn phòng tới tệp mã nguồn. */
it('B: the restore guide points to sections that exist and not into source files', function () {
    $restore = ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '### Quy trình cho máy chủ thật', '### Bảng số đo thật');

    expect($restore)->not->toContain('Khi đưa lên máy chủ thật')
        ->not->toContain('docblock')
        ->not->toContain('App\Actions\Deployment\RunPreflight');
});

/* B (coldread). Bước 4 nói "sáu việc" mà liệt kê bảy; service worker bị dẫn chiếu là "việc 6". */
it('B: step 4 counts its seven tasks and the service worker is task 7', function () {
    $guide = ogaFile('docs/CAI-DAT.md');

    expect($guide)->toContain('Hai mẫu cùng làm bảy việc')
        ->not->toContain('sáu việc')
        ->not->toContain('Bước 4, việc 6');
});
