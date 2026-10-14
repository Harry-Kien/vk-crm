<?php

use App\Enums\PreflightLevel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;

/*
|--------------------------------------------------------------------------
| Làn fc (việc còn mở từ lần gộp bản 1.0, mục C): dòng MAIL_SCHEME khi MAIL_MAILER là failover/roundrobin
|--------------------------------------------------------------------------
|
| `RunPreflight::mailSchemeRows()` chỉ xét khi transport của mailer mặc định là `smtp`. Một văn phòng đặt
| `MAIL_MAILER=failover` (SMTP của tên miền, dự phòng một SMTP khác) với `MAIL_SCHEME=tls` không có dòng
| nào — mà mọi thư qua nhánh SMTP hỏng ngay lúc dựng kết nối. Nay preflight đi các mailer con có transport
| `smtp` của failover/roundrobin và áp cùng kiểm tra, nêu tên mailer con. Đi qua lệnh Artisan thật.
|
| Hàm toàn cục mang tiền tố `pmf…`.
*/

function pmfPreflight(array $config): string
{
    config(['app.env' => 'production', ...$config]);
    Http::fake(fn () => Http::response('not found', 404));
    Process::fake(['command -v *' => Process::result(exitCode: 0)]);

    Artisan::call('vkcrm:preflight');

    return Artisan::output();
}

function pmfLine(PreflightLevel $level, string $message): string
{
    return '['.$level->label().'] '.$message;
}

it('failover có nhánh smtp mang MAIL_SCHEME=tls: dòng ĐỎ nêu đúng mailer con và mailer cha', function () {
    $output = pmfPreflight([
        'mail.default' => 'failover',
        'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        'mail.mailers.smtp.scheme' => 'tls',
    ]);

    expect($output)->toContain(pmfLine(PreflightLevel::Red, __('ops_checks.preflight.mail_scheme_unsupported_in', [
        'mailer' => 'smtp', 'parent' => 'failover', 'value' => 'tls',
    ])));
});

it('failover có nhánh smtp mang smtps hay để trống: dòng XANH nêu mailer con', function (?string $scheme) {
    $output = pmfPreflight([
        'mail.default' => 'failover',
        'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        'mail.mailers.smtp.scheme' => $scheme,
    ]);

    expect($output)->toContain(pmfLine(PreflightLevel::Green, __('ops_checks.preflight.mail_scheme_ok_in', [
        'mailer' => 'smtp', 'parent' => 'failover',
    ])))
        ->and($output)->not->toContain('MAIL_SCHEME=');
})->with(['smtps', 'để trống' => [null]]);

it('roundrobin hai nhánh smtp: mỗi nhánh một dòng, chỉ nhánh sai là ĐỎ', function () {
    $output = pmfPreflight([
        'mail.default' => 'roundrobin',
        'mail.mailers.roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'smtp_du_phong']],
        'mail.mailers.smtp.scheme' => 'smtps',
        'mail.mailers.smtp_du_phong' => ['transport' => 'smtp', 'scheme' => 'ssl', 'host' => 'smtp2.vidu.test', 'port' => 465],
    ]);

    expect($output)->toContain(pmfLine(PreflightLevel::Green, __('ops_checks.preflight.mail_scheme_ok_in', [
        'mailer' => 'smtp', 'parent' => 'roundrobin',
    ])))
        ->and($output)->toContain(pmfLine(PreflightLevel::Red, __('ops_checks.preflight.mail_scheme_unsupported_in', [
            'mailer' => 'smtp_du_phong', 'parent' => 'roundrobin', 'value' => 'ssl',
        ])));
});

it('failover không có nhánh smtp nào: không dòng MAIL_SCHEME nào', function () {
    $output = pmfPreflight([
        'mail.default' => 'failover',
        'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['log', 'array']],
        'mail.mailers.smtp.scheme' => 'tls',
    ]);

    expect($output)->not->toContain('MAIL_SCHEME');
});

/** Tiền đề: Laravel dựng nhánh smtp của failover bằng đúng cấu hình của mailer con, nên tls làm hỏng cả failover. */
it('tiền đề: failover với nhánh smtp mang tls hỏng ngay lúc dựng kết nối', function () {
    config([
        'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        'mail.mailers.smtp.scheme' => 'tls',
        'mail.mailers.smtp.host' => 'smtp.vidu.test',
        'mail.mailers.smtp.port' => 587,
    ]);
    app('mail.manager')->purge('failover');

    expect(fn () => Mail::mailer('failover')->getSymfonyTransport())->toThrow(UnsupportedSchemeException::class);
});
