<?php

use App\Enums\OutboundStatus;
use App\Models\MatterParty;
use App\Support\Normalizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SensitiveDataFlows;
use Tests\Support\SensitiveTraceScanner;

/*
|--------------------------------------------------------------------------
| SPEC §10.5 — số định danh cá nhân mã hoá khi lưu, và không nằm trong bất kỳ log nào
|--------------------------------------------------------------------------
|
| Kế hoạch M8 Task 4: "quét dữ liệu thật sau khi chạy luồng tạo khách hàng và luồng gửi thư,
| không đọc mã bằng mắt". Test ở đây không khẳng định một cột cụ thể nào được mã hoá — nó cho các
| giá trị lính canh đi qua các MÀN HÌNH thật (`Tests\Support\SensitiveDataFlows`), rồi quét MỌI cột
| của MỌI bảng và tệp log của lượt chạy tìm MỌI dạng suy ra được của chúng
| (`Tests\Support\SensitiveTraceScanner`). Một đường ghi mới, một cột mới, một dòng log mới mà
| làm lộ số CCCD sẽ làm test này đỏ mà không ai phải nhớ thêm nó vào một danh sách.
|
| Phần bản sao lưu thật (dump + tệp, giải nén bằng mật khẩu) ở
| `tests/Feature/Backup/BackupPersonalDataScanTest.php` — cần MariaDB + `mariadb-dump`.
*/

/**
 * Máy quét phải THẤY từng dạng trước khi một lần quét sạch có nghĩa gì. Test này cài từng dạng vào
 * một bảng khác nhau (kể cả bọc base64 trong payload phiên, và quoted-printable bị ngắt dòng như
 * thân thư MIME), đòi máy quét báo đủ — và đòi nó KHÔNG báo một băm CÓ KHOÁ (HMAC với `APP_KEY`),
 * dạng mà §10.5 chấp nhận cho cột so trùng.
 */
it('§10.5 máy quét thấy mọi dạng của một số CCCD, một mật khẩu và một bí mật — và bỏ qua băm có khoá', function () {
    $digits = '079188123456';
    $password = 'mat khau cai thu p0q1';
    $secret = 'JBSWY3DPEHPK3PXPABCD';

    $scanner = SensitiveTraceScanner::make()
        ->digits('CCCD cài thử', '079 188 123 456')
        ->typed('mật khẩu cài thử', $password)
        ->secret('bí mật cài thử', $secret);

    expect($scanner->scanDatabase())->toBe([]);

    $now = now()->getTimestamp();
    $keyed = hash_hmac('sha256', $digits, (string) config('app.key'));
    DB::table('cache')->insert([
        ['key' => 'cai-tran', 'value' => 'so '.$digits.' o giua', 'expiration' => $now],
        ['key' => 'cai-cham', 'value' => '079.188.123.456', 'expiration' => $now],
        ['key' => 'cai-gach', 'value' => '0791-8812-3456', 'expiration' => $now],
        ['key' => 'cai-url', 'value' => 'q=079%20188%20123%20456', 'expiration' => $now],
        ['key' => 'khoa-sha256', 'value' => hash('sha256', $digits), 'expiration' => $now],
        ['key' => 'khoa-hmac', 'value' => $keyed, 'expiration' => $now],
    ]);
    DB::table('cache_locks')->insert(['key' => 'khoa-sha1', 'owner' => hash('sha1', $digits), 'expiration' => $now]);
    $jobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => json_encode(['md5' => md5('079 188 123 456'), 'u' => rawurlencode($password)]),
        'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now,
    ]);
    DB::table('sessions')->insert([
        'id' => 'phien-cai-thu', 'user_id' => null, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
        'payload' => base64_encode(serialize(['_flash' => ['ghi' => "ma {$secret} va so {$digits}"]])),
        'last_activity' => $now,
    ]);

    $findings = $scanner->scanDatabase();

    foreach ([
        'cache.value#cai-tran: CCCD cài thử — chữ số (mọi cách viết)',
        'cache.value#cai-cham: CCCD cài thử — chữ số (mọi cách viết)',
        'cache.value#cai-gach: CCCD cài thử — chữ số (mọi cách viết)',
        'cache.value#cai-url: CCCD cài thử — chữ số (mọi cách viết)',
        "cache.value#khoa-sha256: CCCD cài thử — sha256 trần của '{$digits}'",
        "cache_locks.owner#khoa-sha1: CCCD cài thử — sha1 trần của '{$digits}'",
        "jobs.payload#{$jobId}: CCCD cài thử — md5 trần của '079 188 123 456'",
        "jobs.payload#{$jobId}: mật khẩu cài thử — mã hoá URL",
        'sessions.payload#phien-cai-thu [base64]: CCCD cài thử — chữ số (mọi cách viết)',
        'sessions.payload#phien-cai-thu [base64]: bí mật cài thử — nguyên văn',
    ] as $expected) {
        expect($findings)->toContain($expected);
    }

    // Băm CÓ KHOÁ không phải một dạng lộ: dòng `khoa-hmac` không sinh phát hiện nào.
    expect(collect($findings)->filter(fn (string $finding) => str_contains($finding, '#khoa-hmac'))->all())->toBe([]);

    // Thân thư MIME quoted-printable ngắt dòng mềm GIỮA con số, và một phần base64 ngắt 76 ký tự.
    $mime = "Content-Transfer-Encoding: quoted-printable\r\n\r\nS=E1=BB=91 CCCD: 0791881=\r\n23456\r\n"
        .chunk_split(base64_encode(str_repeat('đệm ', 30).'mã '.$secret), 76, "\r\n");

    expect($scanner->scanText('thư', $mime))
        ->toContain('thư [quoted-printable]: CCCD cài thử — chữ số (mọi cách viết)')
        ->toContain('thư [base64 nối dòng]: bí mật cài thử — nguyên văn');
});

/**
 * Phép quét chính. RED trên nền trước M8 Task 4: `matter_parties.id_number_hash` giữ `sha256`
 * TRẦN của số CCCD — của khách hàng (bản sao gần như rõ của `clients.id_number` nằm ngoài cột đã
 * mã hoá) lẫn của bên đối lập (dạng lưu DUY NHẤT của số của họ).
 */
it('§10.5 sau các luồng thật, không dạng nào của số CCCD, mật khẩu hay bí mật 2FA nằm ngoài cột đã mã hoá — ở mọi cột của mọi bảng và trong log', function () {
    $flows = SensitiveDataFlows::for($this)->useProductionStorage()->run();

    // --- Đối chứng: các luồng THẬT SỰ đã ghi thứ mà phép quét đang tìm, ở dạng được phép. ---

    $clientCiphertext = DB::table('clients')->where('id', $flows->client->id)->value('id_number');

    expect($clientCiphertext)->toBeString()
        ->and(Crypt::decryptString($clientCiphertext))->toBe(SensitiveDataFlows::CLIENT_ID_NUMBER_EDITED);

    $parties = MatterParty::query()->withoutGlobalScopes()->where('matter_id', $flows->matter->id)->get();

    expect($parties)->toHaveCount(2)
        ->and($parties->firstWhere('is_our_client', true)->id_number_hash)
        ->toBe(Normalizer::idNumberHash(SensitiveDataFlows::CLIENT_ID_NUMBER_EDITED))
        ->and($parties->firstWhere('is_our_client', false)->id_number_hash)
        ->toBe(Normalizer::idNumberHash(SensitiveDataFlows::OPPOSING_ID_NUMBER_TYPED));

    expect($flows->enrolledStaff->fresh()->two_factor_secret)->toBe($flows->twoFactorSecret)
        ->and($flows->recoveryCodes)->toHaveCount(8);

    expect(DB::table('outbound_messages')->where('status', OutboundStatus::Sent->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('outbound_messages')->where('status', OutboundStatus::Failed->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    $log = (string) file_get_contents($flows->logPath);

    expect(quoted_printable_decode($log))->toContain($flows->matter->code)
        ->and($log)->toContain('TransportException');

    $scanner = SensitiveTraceScanner::make()
        ->digits('CCCD khách hàng lúc tạo', SensitiveDataFlows::CLIENT_ID_NUMBER_TYPED, SensitiveDataFlows::CLIENT_ID_NUMBER_LOOKUP)
        ->digits('CCCD khách hàng sau khi sửa', SensitiveDataFlows::CLIENT_ID_NUMBER_EDITED)
        ->digits('CCCD bên đối lập', SensitiveDataFlows::OPPOSING_ID_NUMBER_TYPED)
        ->typed('mật khẩu nhân sự', SensitiveDataFlows::STAFF_PASSWORD)
        ->typed('mật khẩu sai gõ ở ô mật khẩu', SensitiveDataFlows::WRONG_STAFF_PASSWORD)
        ->typed('chuỗi gõ nhầm vào ô email (admin)', SensitiveDataFlows::TYPED_INTO_STAFF_EMAIL)
        ->typed('mật khẩu tạm của tài khoản cổng', SensitiveDataFlows::PORTAL_INITIAL_PASSWORD)
        ->typed('chuỗi gõ nhầm vào ô email (cổng)', SensitiveDataFlows::TYPED_INTO_PORTAL_EMAIL)
        ->secret('secret 2FA', $flows->twoFactorSecret)
        ->secret('APP_KEY', (string) config('app.key'))
        ->secret('APP_KEY (phần base64)', Str::after((string) config('app.key'), 'base64:'));

    foreach ($flows->recoveryCodes as $index => $code) {
        $scanner->secret('mã khôi phục 2FA #'.($index + 1), $code);
    }

    // Phép quét đi qua dữ liệu thật: mọi bảng mà các luồng trên phải ghi đều có dòng.
    expect(array_keys($scanner->populatedTables()))->toContain(
        'clients', 'client_users', 'matters', 'matter_parties', 'stage_logs', 'activity_log',
        'documents', 'media', 'document_downloads', 'outbound_messages', 'failed_jobs',
        'notifications', 'cache', 'sessions', 'users',
    );

    $findings = [...$scanner->scanDatabase(), ...$scanner->scanText('log', $log)];

    expect($findings)->toBe([]);
});
