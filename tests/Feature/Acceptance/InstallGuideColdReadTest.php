<?php

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

/*
 * Nghiệm thu bản 1.0 (v1 Task 2) — SPEC §14 mục 8, R6 của kế hoạch M8: một người chưa từng đọc kho
 * này làm theo `README.md` + `docs/CAI-DAT.md` từ một máy chủ trống. Lượt đi thật ngày 2026-10-08
 * (bản sao sạch của kho trong một container `webdevops/php:8.3-alpine` bỏ đi sau đó, đúng chuỗi lệnh
 * của phần "Cài lên máy chủ thật": `composer install --no-dev`, `key:generate`, điền `.env`,
 * `webpush:vapid`, `migrate --force`, `db:seed --force`, `vkcrm:create-admin`, `vkcrm:preflight`,
 * `optimize`, `schedule:list`, `schedule:run`, `vkcrm:backup-check`) vấp ba chỗ tài liệu bắt người đọc
 * phải đoán; mỗi chỗ một test dưới đây, đọc chính tài liệu người cài cầm trên tay.
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
 * MỌI lệnh `php artisan` và mọi trang chết với "The environment file is invalid!" — đo trong lượt đi
 * thật. Bước 3 phải nói luật ngoặc kép, và ví dụ của nó phải là một dòng Dotenv đọc được.
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
 * chạy đúng dòng đó nhận "Repository not found" hoặc bị hỏi mật khẩu GitHub, mà Bước 2 không nói phải
 * xin quyền ở đâu, bằng gì.
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
    $upgrade = substr($readme, (int) strpos($readme, '- **Nâng cấp:**'));

    expect($upgrade)->toContain('performance.viewAny')
        ->and($upgrade)->not->toContain('bảy quyền đó');
});
