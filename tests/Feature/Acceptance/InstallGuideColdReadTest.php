<?php

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

/*
 * Nghiệm thu bản 1.0 (v1 Task 2) — SPEC §14 mục 8. R6 của kế hoạch M8 đòi một agent chưa từng đọc kho
 * này làm theo `README.md` + `docs/CAI-DAT.md` từ một máy chủ trống; lượt đó CHƯA làm (việc của người
 * điều phối, ghi ở PROGRESS "Nghiệm thu bản 1.0"). Cái đã có ngày 2026-10-08 là một lượt đi theo kịch
 * bản do chính người làm Task 8 viết (đã đọc kho): bản sao của kho lấy từ git bundle, trong một
 * container `webdevops/php:8.3-alpine` bỏ đi sau đó, chạy `composer install --no-dev`, `key:generate`,
 * điền `.env`, `webpush:vapid`, `migrate --force`, `db:seed --force`, `vkcrm:create-admin`,
 * `vkcrm:preflight`, `optimize`, `schedule:list`, `schedule:run`, `vkcrm:backup-check`; không đi Bước 1
 * (chuẩn bị máy), 4, 9, 11 và phần nâng cấp. Lượt đó và lượt đọc đối chiếu tìm ra ba chỗ tài liệu bắt
 * người đọc phải đoán; mỗi chỗ một test dưới đây, đọc chính tài liệu người cài cầm trên tay. Test cuối
 * giữ cho hồ sơ nghiệm thu gọi đúng tên lượt đi đó.
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
    $upgrade = substr($readme, (int) strpos($readme, '- **Nâng cấp:**'));

    expect($upgrade)->toContain('performance.viewAny')
        ->and($upgrade)->not->toContain('bảy quyền đó');
});

/**
 * Hồ sơ nghiệm thu không được nói quá điều đã làm. R6 của kế hoạch M8 chỉ nhận MỘT cách nghiệm thu
 * §14 mục 8: một agent chưa từng đọc kho này làm theo README + CAI-DAT. Lượt đi ngày 2026-10-08 là một
 * kịch bản do chính người làm Task 8 viết (đã đọc kho), clone từ một git bundle (nên chỗ vấp "kho
 * riêng tư" là tìm ra khi đọc, không quan sát được) và bỏ qua Bước 1 (chuẩn bị máy), Bước 4 (máy chủ
 * web, HTTPS), Bước 9 (đăng nhập lần đầu, 2FA), Bước 11 (mở cổng) và "Nâng cấp lên bản mới". PROGRESS
 * phải gọi đúng tên nó (dòng M8 và dòng "Bản 1.0" của bảng không ✅, không "cài thật từ máy trống";
 * mục "Nghiệm thu bản 1.0" kể đủ các bước chưa đi, nói chỗ vấp nào tìm ra khi đọc), để §14 mục 8 ở
 * trạng thái CHỜ với lượt đọc của agent chưa từng đọc kho trong danh sách việc chờ, và kế hoạch M8
 * không tick Task 8 khi mục 8 còn chờ.
 */
it('§14.8 records the install walk as a scripted walk by the implementer and keeps the cold read pending', function () {
    $progress = igcFile('docs/PROGRESS.md');

    preg_match('/^\| \*\*Bản 1\.0\*\*.*$/m', $progress, $v1Row);
    preg_match('/^\| M8 .*$/m', $progress, $m8Row);
    expect($v1Row)->not->toBeEmpty('Không thấy dòng "Bản 1.0" trong bảng milestone')
        ->and($m8Row)->not->toBeEmpty('Không thấy dòng M8 trong bảng milestone');

    // toContain() nhận nhiều chuỗi cần có (variadic), không nhận câu báo lỗi: mỗi lời gọi một chuỗi.
    foreach ([$v1Row[0], $m8Row[0]] as $text) {
        expect($text)->not->toContain('cài thật từ máy trống')
            ->and($text)->toContain('kịch bản')
            ->and($text)->toContain('agent chưa từng đọc kho')
            ->and($text)->not->toContain('| ✅');
    }

    $acceptance = substr($progress, (int) strpos($progress, '## Nghiệm thu bản 1.0'));
    $section = igcSection($acceptance, '## Nghiệm thu bản 1.0', '### Cần chủ văn phòng quyết / làm');

    expect($section)->not->toContain('Người đọc chưa từng thấy kho')
        ->and($section)->not->toContain('Một lượt đi thật')
        ->and($section)->not->toContain('Lượt cài thật');

    preg_match('/^8\. .*(?:\n {3}.*)*/m', $section, $criterion8);
    expect($criterion8)->not->toBeEmpty('Không thấy tiêu chí 8')
        ->and($criterion8[0])->toContain('CHỜ')
        ->and($criterion8[0])->toContain('agent chưa từng đọc kho');

    $walk = igcSection($acceptance, '### Lượt đi theo kịch bản của người làm', '### Cần chủ văn phòng quyết / làm');

    foreach (['Bước 1', 'Bước 4', 'Bước 9', 'Bước 11', 'Nâng cấp lên bản mới'] as $step) {
        expect($walk)->toContain($step);
    }
    expect($walk)->toContain('Chưa đi')
        ->and($walk)->toContain('tìm ra khi đọc');

    $pending = igcSection($acceptance, '### Cần chủ văn phòng quyết / làm', '### Kiểm chứng của làn');
    expect($pending)->toContain('agent chưa từng đọc kho');

    expect(igcFile('docs/superpowers/plans/2026-09-21-m8-security-and-launch.md'))
        ->toContain('### - [ ] Task 8 — Nghiệm thu toàn hệ thống (SPEC §14)');
});
