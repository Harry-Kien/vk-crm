<?php

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Instalment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M9 Task 13 — `vkcrm:preflight` có một dòng "bất biến tiền"
|--------------------------------------------------------------------------
|
| Tầng 4 của bất biến tổng M9 (`billing:check-invariants`, `ScheduleTotal::mismatchedActiveContracts()`)
| nay cũng là một dòng của `vkcrm:preflight`: ĐỎ khi có hợp đồng `active` mà tổng các đợt chưa huỷ
| khác `total_amount`, XANH khi sạch. Dữ liệu chứ không phải cấu hình máy, nên dòng này chạy ở MỌI
| `APP_ENV` (như ba biến số sao lưu), không chỉ dưới `production`.
|
| Tệp riêng (không nối vào `PreflightCommandTest.php`): nhánh `main` đang thêm test vào cuối tệp đó,
| tách ra để lần gộp không xung đột văn bản. Đi qua lệnh Artisan thật như tệp kia.
| Hợp đồng lệch dựng bằng `DB::table()` — đúng đường duy nhất lọt qua ba tầng kia.
*/

/** Hợp đồng `active` có lịch thu cân đúng tổng, dựng ở `draft` rồi mới kích hoạt (hook tầng 2 đứng gác). */
function pfbContract(string $code, array $amounts): Contract
{
    $contract = Contract::factory()->create(['code' => $code, 'status' => ContractStatus::Draft, 'total_amount' => array_sum($amounts)]);

    foreach (array_values($amounts) as $index => $amount) {
        Instalment::factory()->for($contract)->create(['sequence' => $index + 1, 'amount' => $amount]);
    }

    $contract->update(['status' => ContractStatus::Active, 'signed_at' => today()->toDateString()]);

    return $contract;
}

it('§preflight M9 một hợp đồng đang hiệu lực lệch tổng là ĐỎ, nêu mã hợp đồng, mã thoát khác 0, ở mọi APP_ENV', function () {
    config(['app.env' => 'staging']);

    pfbContract('HD-2026-0101', [60_000_000, 40_000_000]);
    $drifted = pfbContract('HD-2026-0102', [30_000_000, 20_000_000]);
    DB::table('instalments')->where('contract_id', $drifted->id)->where('sequence', 2)->update(['amount' => 20_000_001]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.billing_invariants_mismatch', ['count' => 1, 'codes' => 'HD-2026-0102']))
        ->and($output)->not->toContain('HD-2026-0101');
});

/*
| Rà soát cuối làn m9f, I2 (minor m2 của rà soát Task 13). Dòng ĐỎ "bất biến tiền" là DỮ LIỆU, chỉ sửa
| được trong app (phụ lục do luật sư phụ trách ký), nên nó không chặn `php artisan up` — nhưng trước bản
| sửa CLI in "KHÔNG mở cổng cho tới khi sửa hết" ngay dưới nó, README nói preflight "phải xanh" sau mỗi
| lần nâng cấp, CAI-DAT nói "Dòng ĐỎ chặn mở cổng" và "Preflight ĐỎ thì sửa trước khi up". Người vận
| hành giữa lần nâng cấp hoặc giữ trang bảo trì với một độ lệch chỉ sửa được khi đã `up`, hoặc học cách
| lờ ĐỎ đi. Mã thoát vẫn khác 0 (kế hoạch Task 13 bước 3: "đỏ khi có hợp đồng lệch").
*/

it('§preflight M9 khi dòng ĐỎ duy nhất là bất biến tiền, chính dòng đó và câu tổng kết nói nó không chặn mở cổng, mã thoát vẫn khác 0', function () {
    config(['app.env' => 'staging']);

    $drifted = pfbContract('HD-2026-0401', [30_000_000, 20_000_000]);
    DB::table('instalments')->where('contract_id', $drifted->id)->where('sequence', 2)->update(['amount' => 20_000_001]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('Dòng ĐỎ này không chặn mở cổng (php artisan up)')
        ->and($output)->toContain(__('preflight.summary_red_billing_only'))
        ->and($output)->not->toContain(__('preflight.summary_red'));
});

it('§preflight M9 bất biến tiền ĐỎ cùng một dòng ĐỎ khác thì câu tổng kết vẫn là KHÔNG mở cổng', function () {
    config(['app.env' => '']);

    $drifted = pfbContract('HD-2026-0501', [30_000_000, 20_000_000]);
    DB::table('instalments')->where('contract_id', $drifted->id)->where('sequence', 2)->update(['amount' => 20_000_001]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('preflight.app_env_blank'))
        ->and($output)->toContain(__('preflight.billing_invariants_mismatch', ['count' => 1, 'codes' => 'HD-2026-0501']))
        ->and($output)->toContain(__('preflight.summary_red'))
        ->and($output)->not->toContain(__('preflight.summary_red_billing_only'));
});

/**
 * Các khối (đoạn văn hoặc gạch đầu dòng) của một mục tài liệu, từ dòng tiêu đề `$heading` tới tiêu
 * đề cùng cấp hoặc cao hơn kế tiếp.
 *
 * @return list<string>
 */
function pfbRunbookBlocks(string $path, string $heading): array
{
    $text = (string) file_get_contents(base_path($path));
    $level = strspn($heading, '#');
    $start = strpos($text, "\n{$heading}\n");

    expect($start)->not->toBeFalse();

    $rest = substr($text, $start + 1);
    $end = preg_match('/\n#{1,'.$level.'} /', $rest, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($rest);

    return array_values(array_filter(
        array_map('trim', preg_split('/\n(?=- )|\n\s*\n/', substr($rest, 0, $end)) ?: []),
        fn (string $block): bool => $block !== '',
    ));
}

it('§preflight M9 README và CAI-DAT: mọi câu "preflight phải xanh / ĐỎ chặn mở cổng" đều nêu ngoại lệ bất biến tiền', function () {
    $sections = [
        ['README.md', '## Triển khai lên máy chủ thật'],
        ['docs/CAI-DAT.md', '### Bước 7 — `vkcrm:preflight`, rồi mới cache cấu hình'],
        ['docs/CAI-DAT.md', '## Nâng cấp lên bản mới'],
    ];
    $blocking = ['phải xanh', 'Dòng ĐỎ chặn', 'ĐỎ thì sửa trước'];
    $checked = [];

    foreach ($sections as [$path, $heading]) {
        foreach (pfbRunbookBlocks($path, $heading) as $block) {
            if (! Str::contains($block, $blocking)) {
                continue;
            }

            $checked[] = $path;

            expect($block)->toContain('bất biến tiền');
        }
    }

    // README (bullet "phải xanh"), CAI-DAT Bước 7 (đoạn "phải xanh hết" và đoạn "Dòng ĐỎ chặn"), CAI-DAT
    // nâng cấp (bullet "Preflight ĐỎ thì sửa trước") — một câu chặn bị xoá hay đổi chữ cũng làm đỏ ở đây.
    expect($checked)->toBe(['README.md', 'docs/CAI-DAT.md', 'docs/CAI-DAT.md', 'docs/CAI-DAT.md']);
});

it('§preflight M9 mọi hợp đồng đang hiệu lực khớp tổng là XANH, nêu số hợp đồng đã quét, không đổi mã thoát', function () {
    config(['app.env' => 'staging']);

    pfbContract('HD-2026-0201', [60_000_000, 40_000_000]);
    pfbContract('HD-2026-0202', [25_000_000]);
    // Hợp đồng không `active` không được tính, dù lịch thu của nó lệch.
    Contract::factory()->create(['code' => 'HD-2026-0203', 'status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);

    $exitCode = Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('preflight.billing_invariants_ok', ['count' => 2]))
        ->and($output)->not->toContain('HD-2026-0203')
        ->and($output)->not->toContain(__('preflight.summary_red'));
});

it('§preflight M9 nêu tối đa năm mã hợp đồng lệch, rồi chỉ sang billing:check-invariants', function () {
    config(['app.env' => 'staging']);

    foreach (range(1, 7) as $n) {
        $contract = pfbContract(sprintf('HD-2026-03%02d', $n), [10_000_000]);
        DB::table('contracts')->where('id', $contract->id)->update(['total_amount' => 10_000_001]);
    }

    Artisan::call('vkcrm:preflight');
    $output = Artisan::output();

    expect($output)->toContain(__('preflight.billing_invariants_mismatch', [
        'count' => 7,
        'codes' => 'HD-2026-0301, HD-2026-0302, HD-2026-0303, HD-2026-0304, HD-2026-0305, …',
    ]))
        ->and($output)->not->toContain('HD-2026-0306')
        ->and($output)->toContain('billing:check-invariants');
});
