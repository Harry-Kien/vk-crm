<?php

use App\Actions\Notification\ResendTargets;
use App\Mail\BrandedMailable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Việc sau gộp M6 (làn fu, mục 2 và 3): hai họ thư của main (`staff.instalment_overdue` của M9,
 * `staff.backup_alert.*` của M8a) vào hệ thống mà không ai khai với nhật ký thư — không nhãn tiếng
 * Việt ở `outbound.templates`, không mục nào ở sổ gửi lại `ResendTargets`, nên chúng rơi vào nhánh
 * `default` một cách TÌNH CỜ. Test này là lưới cho milestone sau: MỌI mẫu thư mà một
 * `BrandedMailable` dưới `app/Mail` ghi vào nhật ký phải có nhãn, và phải HOẶC gửi lại được qua
 * `ResendTargets::for()`, HOẶC nằm tường minh trong `ResendTargets::NOT_RESENDABLE` kèm một câu từ
 * chối RIÊNG (không phải câu `default`).
 *
 * Tên mẫu lấy từ CHÍNH hàm `template()` của từng lớp (dựng không qua constructor rồi gọi hàm
 * protected), không gõ tay một danh sách thứ hai. Lớp có hằng `KIND_*` (`BackupAlert`: tên mẫu =
 * họ + loại sự cố) được mở ra thành từng loại.
 *
 * Mutation probe: bỏ `staff.instalment_overdue` khỏi `ResendTargets::NOT_RESENDABLE`, hoặc bỏ nhãn
 * của nó khỏi `lang/vi/outbound.php` → ĐỎ.
 *
 * @return list<string>
 */
function mailTemplatesDeclaredByMailables(): array
{
    $templates = [];

    foreach (File::allFiles(app_path('Mail')) as $file) {
        $class = 'App\\Mail\\'.Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(BrandedMailable::class)) {
            continue;
        }

        $kinds = array_values(array_filter(
            $reflection->getConstants(),
            fn (string $name): bool => str_starts_with($name, 'KIND_'),
            ARRAY_FILTER_USE_KEY,
        ));

        foreach ($kinds === [] ? [null] : $kinds as $kind) {
            $mailable = $reflection->newInstanceWithoutConstructor();

            if ($kind !== null) {
                $mailable->kind = $kind;
            }

            $templates[] = (fn (): string => $this->template())->call($mailable);
        }
    }

    sort($templates);

    return $templates;
}

it('finds every mail template a mailable writes to the outbound log, including the two families added on main', function () {
    expect(mailTemplatesDeclaredByMailables())
        ->toContain('client.stage_update', 'staff.deadline_reminder', 'staff.instalment_overdue')
        ->toContain('staff.backup_alert.backup_failed', 'staff.backup_alert.cleanup_failed', 'staff.backup_alert.unhealthy');
});

it('gives every mail template a Vietnamese label in the outbound log', function () {
    $labels = (array) __('outbound.templates');

    foreach (mailTemplatesDeclaredByMailables() as $template) {
        expect($labels)->toHaveKey($template, message: "Mẫu thư `{$template}` chưa có nhãn ở lang/vi/outbound.php (templates).");
    }
});

it('declares every mail template either resendable or explicitly not resendable with its own reason', function () {
    $reasons = (array) __('outbound.resend.refused.template_reasons');

    foreach (mailTemplatesDeclaredByMailables() as $template) {
        $resendable = ResendTargets::for($template) !== null;
        $exclusion = ResendTargets::exclusionOf($template);

        expect($resendable xor $exclusion !== null)
            ->toBeTrue("Mẫu thư `{$template}` phải HOẶC gửi lại được qua ResendTargets::for(), HOẶC nằm trong ResendTargets::NOT_RESENDABLE — không cả hai, không bỏ trống.");

        if ($exclusion !== null) {
            expect($reasons)->toHaveKey($exclusion, message: "Mẫu `{$template}` bị loại khỏi nút gửi lại nhưng chưa có câu từ chối riêng (outbound.resend.refused.template_reasons.{$exclusion}).")
                ->and($reasons[$exclusion])->not->toBe($reasons['default']);
        }
    }
});

/** Chiều ngược lại: không mục loại trừ nào thiếu câu riêng, kể cả mẫu không do một Mailable gửi (`client.otp`). */
it('gives every entry of the not-resendable list its own refusal reason', function () {
    $reasons = (array) __('outbound.resend.refused.template_reasons');

    foreach (ResendTargets::NOT_RESENDABLE as $entry) {
        expect($reasons)->toHaveKey($entry)
            ->and($reasons[$entry])->not->toBe($reasons['default']);
    }
});
