<?php

use Illuminate\Support\Facades\Blade;

/*
|--------------------------------------------------------------------------
| M12 Task 7 — test cấu trúc của thông báo đẩy (R10, R11)
|--------------------------------------------------------------------------
|
| Ba luật của kế hoạch, canh bằng token PHP trên `app/`, `routes/` và `resources/views/` (Blade biên
| dịch trước, cùng cách lưới R8 `PushSubscriptionAccessTest`):
|  - chỉ `SendPushAlert` dựng `PushAlert` — và `PushAlert` là lớp DUY NHẤT gửi qua `WebPushChannel`;
|  - chỉ `PushTopic` dựng nội dung (`new VkWebPushMessage`, khoá dịch `push.alerts.*`), và không ai
|    dựng `WebPushMessage` của gói — mọi payload đi qua luật nội dung tối thiểu của R11;
|  - không nơi nào ngoài danh sách R10 gọi `SendPushAlert` — quét cả `app/`, không riêng
|    `app/Actions/`: thư mốc hạn gửi trong JOB `SendDeadlineReminderMail`, nên nơi nối của Task 9 là
|    một job. Task 8/9 thêm từng nơi gọi CÙNG commit nối nó, kèm lý do.
|
| "Tham chiếu" = một tên lớp trong mã (tên trần, có namespace một phần hay đầy đủ), trừ câu `use` nhập
| tên ở đầu tệp (nhập để `{@see}` không gọi gì) và chính khai báo lớp; chú thích không tính.
|
| Duyệt thư mục bằng `scandir()`, không `RecursiveDirectoryIterator` — xem đầu tệp
| `PushSubscriptionAccessTest` (ổ 9p của Docker trên Windows bỏ sót tệp im lặng).
*/

/**
 * Nơi được gọi `SendPushAlert` (R10) — mỗi dòng một lý do. Task 8/9 thêm nơi nối của từng chủ đề.
 *
 * @return array<string, string>
 */
function pushAlertCallersAllowed(): array
{
    return [
        // Chính lớp đó (khai báo không tính, nhưng giữ để danh sách đọc trọn).
        'app/Actions/Push/SendPushAlert.php' => 'chính nó',
        // Task 7 — nút "Gửi thử" của trang "Thông báo trên điện thoại" (chủ đề `push.test`).
        'app/Actions/Push/SendTestPush.php' => 'gửi thử tới máy của chính người bấm',
        // Task 8 — bốn thư của khách (bảng R10): mỗi Action đẩy cho đúng những tài khoản lượt đó vừa
        // gửi thư thành công (phán quyết (d)); test đồng nhất người nhận: `ClientEventPushTest`.
        'app/Actions/Notification/NotifyClientOfStageUpdate.php' => 'client.stage_update',
        'app/Actions/Notification/NotifyClientOfDocumentPublished.php' => 'client.document_published',
        'app/Actions/Notification/NotifyClientOfChecklistItemRejected.php' => 'client.document_rejected',
        'app/Actions/Notification/NotifyClientOfRequestAnswered.php' => 'client.request_answered',
        // Task 9 — sự kiện của nhân sự (bảng R10 + phán quyết (e)): nơi THƯ thật sự đi, đẩy cho đúng
        // những người lượt đó vừa gửi thư được; mốc hạn và đợt thu là JOB (người nhận tính lại lúc
        // gửi), không phải tác vụ xếp job. Test đồng nhất người nhận: `StaffEventPushTest`.
        'app/Jobs/SendDeadlineReminderMail.php' => 'staff.deadline_reminder',
        'app/Actions/Notification/NotifyStaffOfNewClientRequest.php' => 'staff.new_client_request',
        'app/Actions/Notification/NotifyStaffOfNewClientDocument.php' => 'staff.new_client_document',
        'app/Jobs/SendInstalmentOverdueMail.php' => 'staff.instalment_overdue',
        // Vòng sửa cuối I5 (M7 Task 4, nối lúc gộp `main` vào nhánh): job thư báo gói bàn giao đã sinh.
        'app/Jobs/SendHandoverPackageReady.php' => 'staff.handover_ready',
        // Câu hỏi tiếp của khách (REQ-2) không có thư: đẩy cùng chủ đề yêu cầu mới, cho đúng người
        // nhận thông báo trong hệ thống `ClientRequestFollowUpAlert`.
        'app/Actions/Portal/ReplyToClientRequest.php' => 'staff.new_client_request (khách hỏi tiếp)',
    ];
}

/** @return list<string> mọi tệp `.php` dưới `app/`, `routes/`, `resources/views/`, tính từ gốc dự án */
function pushStructureFiles(): array
{
    $files = [];

    $walk = function (string $relative) use (&$walk, &$files): void {
        foreach (scandir(base_path($relative)) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $relative.'/'.$entry;

            if (is_dir(base_path($path))) {
                $walk($path);
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    };

    foreach (['app', 'routes', 'resources/views'] as $root) {
        $walk($root);
    }

    sort($files, SORT_STRING);

    return $files;
}

/** @return list<array{0: int|string, 1: string, 2: int}|string> token PHP của một tệp (Blade đã biên dịch) */
function pushStructureTokens(string $relative): array
{
    $code = (string) file_get_contents(base_path($relative));

    return token_get_all(str_ends_with($relative, '.blade.php') ? Blade::compileString($code) : $code);
}

/**
 * Mọi tham chiếu tới lớp có tên ngắn `$short` (đoạn cuối của tên) trong mã, trừ câu `use` nhập tên ở
 * cấp tệp và khai báo lớp. `new` = tham chiếu đứng ngay sau `new`.
 *
 * @return list<array{file: string, line: int, new: bool}>
 */
function pushStructureReferences(string $short): array
{
    $found = [];

    foreach (pushStructureFiles() as $file) {
        $tokens = array_values(array_filter(
            pushStructureTokens($file),
            fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $depth = 0;
        $inImport = false;

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                } elseif ($token === ';') {
                    $inImport = false;
                }

                continue;
            }

            if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }

            if ($token[0] === T_USE && $depth === 0) {
                $inImport = true;

                continue;
            }

            if ($inImport || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            $segments = explode('\\', $token[1]);

            if (end($segments) !== $short) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $previousType = is_array($previous) ? $previous[0] : null;

            if (in_array($previousType, [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT], true)) {
                continue;
            }

            $found[] = ['file' => $file, 'line' => $token[2], 'new' => $previousType === T_NEW];
        }
    }

    return $found;
}

/** @return list<string> `tệp:dòng` */
function pushStructureWhere(array $references): array
{
    return array_values(array_map(fn (array $ref): string => $ref['file'].':'.$ref['line'], $references));
}

/**
 * Mutation probe: thêm `->notify(new \App\Notifications\PushAlert(…))` vào một Action khác → ĐỎ.
 */
it('lets only SendPushAlert construct a PushAlert', function () {
    $references = pushStructureReferences('PushAlert');
    $outside = array_filter($references, fn (array $ref): bool => $ref['file'] !== 'app/Actions/Push/SendPushAlert.php');

    expect(pushStructureWhere($outside))->toBe([])
        ->and(array_filter($references, fn (array $ref): bool => $ref['new']))->not->toBeEmpty();
});

/**
 * Lưới cho chính lưới: máy quét thấy lời dựng thật ở `SendPushAlert` và ở `PushTopic` — nếu nó mù
 * (duyệt thiếu tệp, đọc sai token), hai test trên xanh vô nghĩa.
 */
it('sees the real constructions, so a blind scanner cannot pass the rules', function () {
    expect(pushStructureWhere(array_filter(pushStructureReferences('PushAlert'), fn (array $ref): bool => $ref['new'])))
        ->toHaveCount(1)
        ->and(pushStructureWhere(array_filter(pushStructureReferences('VkWebPushMessage'), fn (array $ref): bool => $ref['new'])))
        ->toHaveCount(1)
        ->and(pushStructureFiles())->toContain('app/Exceptions/OutboundMessageNotResendable.php', 'resources/views/pwa/push-devices.blade.php');
});

/** Một thông báo khác qua kênh push sẽ mang nội dung không qua R11 — chỉ `PushAlert` được dùng kênh. */
it('lets only PushAlert send through the web push channel', function () {
    $outside = array_filter(
        pushStructureReferences('WebPushChannel'),
        fn (array $ref): bool => $ref['file'] !== 'app/Notifications/PushAlert.php',
    );

    expect(pushStructureWhere($outside))->toBe([]);
});

/**
 * Plan Task 7: "chỉ `PushTopic` dựng nội dung". Nội dung = `new VkWebPushMessage` (payload đã dựng) và
 * khoá dịch `push.alerts.*` (câu chữ); không ai dựng `WebPushMessage`/`DeclarativeWebPushMessage` của
 * gói (một payload tự do, không qua luật R11).
 *
 * Mutation probe: dựng `new VkWebPushMessage(…)` trong `SendTestPush` (hay đọc `__('push.alerts.test')`
 * ở đó) → ĐỎ.
 */
it('lets only PushTopic build push content', function () {
    $constructions = array_filter(
        [
            ...pushStructureReferences('VkWebPushMessage'),
            ...pushStructureReferences('WebPushMessage'),
            ...pushStructureReferences('DeclarativeWebPushMessage'),
        ],
        fn (array $ref): bool => $ref['new'] && $ref['file'] !== 'app/Enums/PushTopic.php',
    );

    $alertKeys = [];

    foreach (pushStructureFiles() as $file) {
        foreach (pushStructureTokens($file) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && str_contains($token[1], 'push.alerts') && $file !== 'app/Enums/PushTopic.php') {
                $alertKeys[] = $file.':'.$token[2];
            }
        }
    }

    expect(pushStructureWhere($constructions))->toBe([])
        ->and($alertKeys)->toBe([]);
});

/**
 * Plan Task 7: "không Action nào ngoài danh sách R10 gọi `SendPushAlert`" — quét cả `app/` (job, listener,
 * controller, trang Filament), `routes/` và view.
 *
 * Mutation probe: thêm `app(\App\Actions\Push\SendPushAlert::class)` vào một Action ngoài danh sách → ĐỎ.
 */
it('lets only the R10 call sites call SendPushAlert', function () {
    $outside = array_filter(
        pushStructureReferences('SendPushAlert'),
        fn (array $ref): bool => ! array_key_exists($ref['file'], pushAlertCallersAllowed()),
    );

    expect(pushStructureWhere($outside))->toBe([]);

    foreach (array_keys(pushAlertCallersAllowed()) as $file) {
        expect(file_exists(base_path($file)))->toBeTrue("{$file} trong danh sách cho phép nhưng không tồn tại");
    }
});
