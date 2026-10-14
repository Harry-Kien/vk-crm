<?php

use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Models\SystemHealth;
use App\Models\User;
use App\Notifications\Backup\BackupHasFailedNotification;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Backup\Events\BackupHasFailed;

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
    // Vòng sửa 1 (I2): dòng ghi sổ chỉ chạy khi `backup:run` thoát 0 (`&&` cuối dòng — bash đọc tiếp
    // dòng sau), để một lần sao lưu hỏng không để lại dòng trỏ vào tệp sao lưu CŨ.
    $backup = array_search('sudo -u www-data php artisan backup:run --only-db &&', $before, true);
    $record = ogaIndexOf($before, 'echo "$(TZ=Asia/Ho_Chi_Minh date');

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

    expect($lines[$reset])->toBe('git reset --hard "$(cut -d\' \' -f3 ~/quay-lai/diem)"')
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

/*
 * Vòng sửa 1 (I2). Khối thứ hai hỏng giữa migration, người vận hành chạy lại khối thứ nhất để thử lại:
 * bản `--only-db` của lần chạy lại chụp cơ sở dữ liệu ĐÃ migrate dở, và dòng sổ của nó mang commit MỚI.
 * Mục quay lại cũ luôn lấy tệp zip MỚI NHẤT (`ls -t … | head -1`) — chính bản dump hỏng đó — rồi nạp lên
 * mã cũ; `migrate:status` vẫn toàn `Ran` (dòng thừa của bảng `migrations` không hiện `Pending`), không
 * gì bắt được. Và câu "tên tệp phải trùng giờ ở dòng cuối" sai 7 tiếng khi máy chủ để giờ UTC (`date`
 * của hệ thống) còn tên archive theo APP_TIMEZONE (Asia/Ho_Chi_Minh).
 */
it('A1: block 1 logs Vietnam time, the commit and the archive it just made', function () {
    $upgrade = ogaSection(ogaFile('docs/CAI-DAT.md'), '## Nâng cấp lên bản mới', '### Bản cập nhật M12');
    $before = ogaBlockLines($upgrade, 'php artisan down');
    $record = $before[ogaIndexOf($before, 'echo "')];

    // Bốn trường cách nhau một dấu cách: ngày, giờ (giờ Việt Nam), commit, tệp sao lưu vừa tạo.
    expect($record)->toBe('echo "$(TZ=Asia/Ho_Chi_Minh date \'+%F %T\') $(git rev-parse HEAD) $(sudo sh -c \'ls -t storage/app/backups/*/*.zip | head -1\')" >> ~/vk-crm-nang-cap.log');

    // Tiền đề của trường giờ: tên archive của gói theo múi giờ của app, và múi đó mặc định là giờ Việt Nam.
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh')
        ->and(ogaFile('vendor/spatie/laravel-backup/src/Tasks/Backup/BackupJob.php'))
        ->toContain('$this->filename = Carbon::now()->format(static::FILENAME_FORMAT);');

    expect(ogaFlat($upgrade))->toContain('UTC');
});

it('A1: the rollback takes the archive of the chosen log line, the one before the FIRST failed block 2', function () {
    $rollback = ogaSection(ogaFile('docs/CAI-DAT.md'), '### Nâng cấp hỏng: quay lại bản trước', '## Thao tác tiền');
    $flat = ogaFlat($rollback);

    // Không còn đường nào lấy tệp mới nhất.
    expect($rollback)->not->toContain('ls -t')
        ->not->toContain('head -1')
        ->not->toContain('tail -1');

    // Chọn MỘT dòng sổ, cất vào ~/quay-lai/diem; tệp zip và commit đều đọc từ đúng dòng đó.
    // Hai khối: in sổ để chọn, rồi cất dòng đã chọn (dán chung một khối thì `sed` chạy với `<SỐ DÒNG>`).
    expect(ogaBlockLines($rollback, 'cat -n ~/vk-crm-nang-cap.log'))->toBe(['cat -n ~/vk-crm-nang-cap.log']);

    $choose = ogaBlockLines($rollback, '~/quay-lai/diem');
    $copy = ogaBlockLines($rollback, 'truoc-nang-cap.zip');

    expect($choose)->toBe([
        'install -d -m 700 ~/quay-lai',
        "sed -n '<SỐ DÒNG>p' ~/vk-crm-nang-cap.log > ~/quay-lai/diem",
        'cat ~/quay-lai/diem',
    ])
        ->and($copy)->toContain('sudo cp "$(cut -d\' \' -f4- ~/quay-lai/diem)" ~/quay-lai/truoc-nang-cap.zip');

    // Nói thẳng: dòng của lần chạy khối thứ nhất trước lần khối thứ hai hỏng ĐẦU TIÊN, không phải dòng cuối
    // khi đã chạy lại; vì sao migrate:status không bắt được; và hai giờ cùng là giờ Việt Nam.
    expect($flat)->toContain('TRƯỚC lần khối thứ hai hỏng ĐẦU TIÊN')
        ->toContain('KHÔNG phải dòng cuối')
        ->toContain('migrate dở')
        ->toContain('không hiện thành `Pending`')
        ->toContain('giờ Việt Nam')
        ->not->toContain('lấy dòng cuối cùng mà commit khác');
});

it('A1: the restore guide says when to migrate a restored dump forward and when to check out the old code', function () {
    $restore = ogaFlat(ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '### Quy trình cho máy chủ thật', '### Bảng số đo thật'));

    expect($restore)->toContain('`sudo -u www-data php artisan migrate --force`')
        ->toContain('`~/vk-crm-nang-cap.log`')
        ->toContain('"Nâng cấp hỏng: quay lại bản trước"')
        // Vòng sửa 1 (I2): dòng cuối có thể là lần chạy lại khối thứ nhất trên cơ sở dữ liệu đã migrate dở.
        ->not->toContain('dòng cuối của `~/vk-crm-nang-cap.log`')
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

    // Vòng sửa cuối 1 (I1): khối đứng NGAY sau một đoạn mở đầu bằng "**Shared hosting** —" và kết thúc
    // bằng dấu hai chấm là dạng KHÔNG sudo, viết riêng vì dòng ghi sổ của VPS gọi `sudo sh -c`; nó phải
    // không có `sudo`/`chown` nào, và không tính vào phép quét này.
    $sharedBlock = '/^\*\*Shared hosting\*\* —(?:(?!\n\n).)*:\n\n```[a-z]*\n(.*?)```/ms';
    preg_match_all($sharedBlock, $production, $shared);
    expect($shared[1])->toHaveCount(2);
    foreach ($shared[1] as $block) {
        expect($block)->not->toContain('sudo')->not->toContain('chown');
    }
    $production = (string) preg_replace($sharedBlock, '', $production);

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

/* B (ops). Hai cổng mở hệ thống nay hỏi hai điều kiện cấu hình của sao lưu; Bước 7 nói đúng điều đó. */
it('B: step 7 names the two backup configuration rows that preflight and backup-check now raise', function () {
    $step7 = ogaFlat(ogaSection(ogaFile('docs/CAI-DAT.md'), '### Bước 7', '### Bước 8'));

    expect($step7)->toContain('dòng `backup_encryption`')
        ->toContain('dòng `backup_off_server`')
        ->toContain('`vkcrm:backup-check` (không đối số) hỏi cùng hai điều kiện đó');
});

/*
 * C (việc còn mở từ lần gộp bản 1.0). SPEC §6.9 hứa thư nhắc liệt kê "đúng những gì còn thiếu", mà thư
 * đọc `ChecklistProgress::itemsToRemindClientOf()` — bỏ đầu mục hợp đồng đã ký khi hợp đồng còn nháp.
 * SPEC (nguồn sự thật) mang một đính chính có ngày; hai docblock nói đúng hàm mà mã gọi.
 */
it('C: SPEC §6.9 and the two docblocks name the narrower set the reminder mail really lists', function () {
    $section = ogaFlat(ogaSection(ogaFile('docs/SPEC.md'), '### 6.9 Nhắc khách bổ sung giấy tờ', '### 6.10'));

    expect($section)->toContain('**Đính chính 2026-10-09')
        ->toContain('ChecklistProgress::itemsToRemindClientOf()')
        ->toContain('hợp đồng');

    expect(ogaFile('app/Jobs/SendMissingDocumentsMail.php'))->toContain('ChecklistProgress::itemsToRemindClientOf(');

    $mail = ogaFile('app/Mail/Client/MissingDocuments.php');
    expect($mail)->toContain('{@see ChecklistProgress::itemsToRemindClientOf()}')
        ->not->toContain('bằng {@see ChecklistProgress::outstandingRequiredItems()} và truyền vào');

    // Docblock: bỏ dấu `*` đầu dòng trước khi so.
    $action = ogaFlat(str_replace("\n * ", ' ', ogaFile('app/Actions/Schedule/RemindMissingDocuments.php')));
    expect($action)->toContain('còn đầu mục mà thư được đòi khách ({@see ChecklistProgress::itemsToRemindClientOf()}');
});

/* B (ops). Cảnh báo sao lưu có kênh thứ hai; tài liệu của chủ văn phòng nói đúng điều mã làm. */
it('B: the backup guide says alerts retry and that the admin home page shows a red backup line', function () {
    $guide = ogaFlat(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'));

    expect($guide)->toContain('thử gửi tới 5 lần')
        ->toContain('**"'.__('ops_checks.widget.heading').'"**')
        ->toContain('cũ hơn 36 giờ')
        ->toContain('trong 7 ngày qua');

    expect(SystemHealthWidget::BACKUP_MAIL_FAILED_WINDOW_DAYS)->toBe(7)
        ->and(SystemHealth::offsiteBackupMaxAgeHours())->toBe(36)
        ->and((new BackupHasFailedNotification(new BackupHasFailed(new Exception('x'))))->tries)->toBe(5);
});

/*
 * Vòng sửa cuối 1 (C2). Gạch "Một dòng của khối thứ hai hỏng" liệt kê "trang lỗi sau `up`", và mục quay lại
 * nói "Không mất dữ liệu nào" rồi chỉ bảo `down` trước khi `db:wipe` + nạp bản dump của khối thứ nhất. Sau
 * `up` đó là xoá im lặng mọi khoản thu, tài liệu, cập nhật vụ việc ghi từ lúc mở cổng — trong khi thư và
 * thông báo về chúng đã tới khách, và tệp tải lên còn trên đĩa mà mất dòng `media`. Tài liệu phải nói thẳng
 * là mất, cho một phép đếm chạy được, và cấm xoá khi chưa có quyết định của chủ văn phòng.
 */
function ogaAfterUpQuery(string $rollback): string
{
    expect(preg_match('/-e "(SELECT.*?)"\n/s', $rollback, $m))->toBe(1);

    return $m[1];
}

it('C2: rolling back after up says data written since the log line is lost, counts it, and needs the owner', function () {
    $rollback = ogaSection(ogaFile('docs/CAI-DAT.md'), '### Nâng cấp hỏng: quay lại bản trước', '## Thao tác tiền');
    $flat = ogaFlat($rollback);

    expect($flat)->not->toContain('Không mất dữ liệu nào: cổng đã đóng từ trước bản sao lưu đó.')
        ->toContain('**Đã `up` rồi mới thấy hỏng**')
        ->toContain('MẤT mọi dữ liệu ghi từ giờ của dòng sổ')
        ->toContain('không lấy lại được')
        ->toContain('chủ văn phòng hoặc người phụ trách kỹ thuật')
        ->toContain('giữ cổng đóng và sửa tiếp');

    // Phép đếm đứng SAU khi chọn dòng sổ (nó đọc giờ từ ~/quay-lai/diem) và TRƯỚC `db:wipe`.
    $check = ogaBlockLines($rollback, 'causer_id IS NOT NULL');
    expect($check[0])->toBe('T="$(cut -d\' \' -f1-2 ~/quay-lai/diem)"; echo "$T"')
        ->and($check[1])->toStartWith('mariadb -u vk_crm -p vk_crm -e "SELECT')
        ->and(strpos($rollback, 'causer_id IS NOT NULL'))->toBeGreaterThan(strpos($rollback, '> ~/quay-lai/diem'))
        ->and(strpos($rollback, 'causer_id IS NOT NULL'))->toBeLessThan(strpos($rollback, 'db:wipe --force'));

    // Gạch "Một dòng của khối thứ hai hỏng" và SAO-LUU bước 8 trường hợp 2 không hứa điều mục quay lại không làm.
    $upgrade = ogaFlat(ogaSection(ogaFile('docs/CAI-DAT.md'), '## Nâng cấp lên bản mới', '### Bản cập nhật M12'));
    expect($upgrade)->toContain('đã `up` thì quay lại MẤT dữ liệu');
    $restore = ogaFlat(ogaSection(ogaFile('docs/SAO-LUU-KHOI-PHUC.md'), '### Quy trình cho máy chủ thật', '### Bảng số đo thật'));
    expect($restore)->toContain('đã `up` thì quay lại MẤT dữ liệu');
});

it('C2: the after-up count runs on the real schema and counts what people wrote, not the upgrade itself', function () {
    $rollback = ogaSection(ogaFile('docs/CAI-DAT.md'), '### Nâng cấp hỏng: quay lại bản trước', '## Thao tác tiền');
    $query = ogaAfterUpQuery($rollback);

    // Đúng sáu bảng, mỗi bảng tồn tại và có cột được đọc.
    foreach (['activity_log', 'payments', 'documents', 'media', 'stage_logs', 'outbound_messages'] as $table) {
        expect($query)->toContain("FROM {$table} ")
            ->and(Schema::hasColumns($table, ['created_at', 'updated_at']))->toBeTrue();
    }
    expect(Schema::hasColumn('activity_log', 'causer_id'))->toBeTrue();

    $t = now()->subMinutes(5)->format('Y-m-d H:i:s');
    $run = fn (): array => array_values((array) DB::selectOne(str_replace('$T', $t, $query)));

    expect(array_sum($run()))->toBe(0);

    // Dòng nhật ký không người gây ra (db:seed của chính lần nâng cấp) không tính; dòng của một người thì tính.
    activity()->log('seed');
    expect(array_sum($run()))->toBe(0);

    activity()->causedBy(User::factory()->create())->log('sửa vụ việc');
    expect(array_sum($run()))->toBeGreaterThan(0);
});

/*
 * Vòng sửa cuối 1 (I1). Shared hosting không có `sudo`: `$(sudo sh -c 'ls -t …')` in `sudo: command not found`
 * ra stderr, `echo` vẫn thoát 0 và dòng sổ thiếu trường tệp zip; rồi bước chép của mục quay lại
 * (`sudo cp "$(cut -d' ' -f4- …)"`) hỏng đúng lúc cần quay lại. Cả hai chỗ có dạng shared hosting chép được.
 */
it('I1: shared hosting has its own block-1 log line and rollback copy, without sudo or chown', function () {
    $guide = ogaFile('docs/CAI-DAT.md');
    $upgrade = ogaSection($guide, '## Nâng cấp lên bản mới', '### Bản cập nhật M12');
    $rollback = ogaSection($guide, '### Nâng cấp hỏng: quay lại bản trước', '## Thao tác tiền');

    $shared = ogaBlockLines($upgrade, '$(ls -t storage/app/backups');
    expect($shared)->toBe([
        'php artisan down',
        'php artisan backup:run --only-db &&',
        'echo "$(TZ=Asia/Ho_Chi_Minh date \'+%F %T\') $(git rev-parse HEAD) $(ls -t storage/app/backups/*/*.zip | head -1)" >> ~/vk-crm-nang-cap.log',
    ]);

    // Mọi máy: đọc dòng sổ vừa ghi, phải kết thúc bằng `.zip` — bắt được trường tệp bị thiếu.
    expect(ogaFlat($upgrade))->toContain('`tail -n 1 ~/vk-crm-nang-cap.log`')
        ->toContain('kết thúc bằng `.zip`');

    // Mục quay lại: một khối chép tệp không sudo, không chown, và câu nói bỏ sudo ở khối quay mã.
    preg_match_all('/^\s*```[a-z]*\n(.*?)^\s*```/ms', $rollback, $blocks);
    $copyWithoutSudo = array_filter($blocks[1], fn (string $b): bool => str_contains($b, 'cp "$(cut -d\' \' -f4- ~/quay-lai/diem)" ~/quay-lai/truoc-nang-cap.zip')
        && ! str_contains($b, 'sudo') && ! str_contains($b, 'chown'));
    expect($copyWithoutSudo)->toHaveCount(1);

    expect(ogaFlat($rollback))->toContain('**Shared hosting**')
        ->toContain('bỏ tiền tố `sudo -u www-data`')
        ->toContain('bỏ hẳn dòng `sudo chown -R www-data:www-data storage bootstrap/cache`');

    // Vòng sửa cuối 2 (m-f1): shared hosting không có thư mục /var/www/vk-crm và thường không có client
    // `mariadb` — câu đếm của bước 2 và lệnh nạp bản dump của bước 4 đi qua phpMyAdmin.
    expect(ogaFlat($rollback))->toContain('thay `cd /var/www/vk-crm` bằng `cd` vào thư mục mã nguồn của bạn')
        ->toContain('không có lệnh `mariadb`')
        ->toContain('phpMyAdmin')
        ->toContain('thay `$T` bằng giờ mà dòng `echo "$T"` in ra');
});

/*
 * Vòng sửa cuối 1 (I2). Brief "Xong khi": PROGRESS có mục của làn, nêu đã sửa gì, ba quyết định đang chờ chủ
 * văn phòng và lý do các việc B hoãn — không có mục đó thì ba quyết định treo không ai thấy.
 */
it('I2: PROGRESS has the lane section with the three owner decisions and the deferred items', function () {
    $progress = ogaFile('docs/PROGRESS.md');
    $section = ogaFlat(ogaSection($progress, '### Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fc)', '## Ghi chú M14'));

    expect(strpos($progress, '### Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fc)'))
        ->toBeGreaterThan(strpos($progress, '## Nghiệm thu bản 1.0'));

    expect($section)->toContain('**Cần chủ văn phòng quyết:**')
        ->toContain('`backup:run --only-db` lúc nâng cấp cũng được đẩy lên Google Drive và tính vào 30 bản giữ lại')
        ->toContain('Gắn tag phiên bản')
        ->toContain('`backup_off_server` ĐỎ chặn mở cổng')
        ->toContain('**Hoãn, và lý do:**')
        ->toContain('Gửi lại thư `staff.backup_alert.*`')
        ->toContain('Git tag mỗi bản phát hành')
        ->toContain('`LOG_DAILY_DAYS`');
});
