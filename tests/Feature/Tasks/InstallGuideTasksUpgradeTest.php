<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| `docs/CAI-DAT.md` — nâng một máy chủ đã có dữ liệu lên bản có "Giao – nhận – xử lý nội bộ" (làn tk)
|--------------------------------------------------------------------------
|
| Vòng sửa cuối 1, F7: các lần gộp làn kd và nt đã phải sửa đúng loại ghi chú nâng cấp này ("không tác vụ lịch
| mới" trong khi có). Theo khuôn `InstallGuideM13UpgradeTest`: đọc CHÍNH đoạn tài liệu rồi so với mã — tên tệp
| migration, tên và nhịp của tác vụ lịch, họ mẫu thư. Hàm toàn cục mang tiền tố `tkFfGuide`.
*/

/** Đoạn "**Bản cập nhật "Giao – nhận – xử lý nội bộ" …" của CAI-DAT, tới trước đoạn in đậm kế tiếp. */
function tkFfGuideParagraph(): string
{
    $guide = (string) file_get_contents(base_path('docs/CAI-DAT.md'));
    $start = strpos($guide, '**Bản cập nhật "Giao – nhận – xử lý nội bộ"');

    expect($start)->not->toBeFalse('CAI-DAT phải có đoạn nâng cấp "Giao – nhận – xử lý nội bộ"');

    $end = strpos($guide, "\n\n**", $start);

    return substr($guide, $start, ($end === false ? strlen($guide) : $end) - $start);
}

it('names exactly the two migrations of the lane, both of which exist', function () {
    preg_match_all('/`(\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+)`/', tkFfGuideParagraph(), $named);

    $lane = collect(File::files(database_path('migrations')))
        ->map(fn ($file): string => $file->getFilenameWithoutExtension())
        ->filter(fn (string $name): bool => str_starts_with($name, '2026_10_14_1000'))
        ->sort()->values()->all();

    expect($lane)->toHaveCount(2)
        ->and(collect($named[1])->unique()->sort()->values()->all())->toBe($lane);
});

it('says the seat backfill marks every existing team member as having accepted', function () {
    $paragraph = tkFfGuideParagraph();
    $migration = (string) file_get_contents(database_path('migrations/2026_10_14_100002_add_assignment_columns_to_matter_user_table.php'));

    expect($paragraph)->toContain('coi như **đã nhận hồ sơ**')
        ->and($migration)->toContain("'accepted_at' => DB::raw(\"COALESCE(created_at, {\$now})\")");
});

it('names the new schedule task with its real cadence, under the existing cron line', function () {
    $paragraph = tkFfGuideParagraph();
    $event = collect(Schedule::events())->first(fn (Event $event): bool => $event->description === 'tasks.remind');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/30 * * * *')
        ->and($paragraph)->toContain('`tasks.remind`')
        ->and($paragraph)->toContain('**mỗi 30 phút**')
        ->and($paragraph)->toContain('**không cần dòng')
        ->and($paragraph)->toContain('**trong giờ làm việc**');
});

it('names every staff.task mail template family the lane added', function () {
    $paragraph = tkFfGuideParagraph();

    $templates = collect(array_keys((array) __('outbound.templates')))
        ->filter(fn (string $key): bool => str_starts_with($key, 'staff.task.'));

    expect($templates)->not->toBeEmpty();

    foreach ($templates as $template) {
        $suffix = substr($template, strrpos($template, '.'));
        $family = substr($template, 0, strrpos($template, '.')).'.*';

        expect(str_contains($paragraph, '`'.$template.'`')
            || str_contains($paragraph, '`'.$family.'`')
            || str_contains($paragraph, '`'.$suffix.'`'))->toBeTrue("CAI-DAT không nêu mẫu thư {$template}");
    }
});
