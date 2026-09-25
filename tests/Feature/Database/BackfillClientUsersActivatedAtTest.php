<?php

use App\Models\ClientUser;

/**
 * Task 7 (R12, phát hiện `intake/intake-04`, `intake/intake-05`): migration backfill
 * `2026_09_25_000001_backfill_client_users_activated_at.php`.
 *
 * Migration này đã chạy XONG trước khi bất kỳ test nào ở đây tạo dữ liệu (RefreshDatabase chạy
 * mọi migration một lần, trên một CSDL rỗng, trước khi bộ test bắt đầu) — nên không có gì để
 * "sau khi backfill" quan sát bằng cách seed rồi chờ. Test này vì vậy gọi LẠI đúng migration đó
 * (`require` tệp migration, một anonymous class chuẩn Laravel) sau khi đã dựng dữ liệu, để đo
 * đúng logic `up()` — không gọi thẳng một Action hay lặp lại câu UPDATE bằng tay.
 */
function runBackfillClientUsersActivatedAtMigration(): void
{
    (require database_path('migrations/2026_09_25_000001_backfill_client_users_activated_at.php'))->up();
}

it('backfills activated_at from last_login_at for an account that has signed in before', function () {
    $everSignedIn = ClientUser::factory()->create([
        'last_login_at' => now()->subDays(10),
        'activated_at' => null,
    ]);

    runBackfillClientUsersActivatedAtMigration();

    expect($everSignedIn->fresh()->activated_at?->equalTo($everSignedIn->last_login_at))->toBeTrue();
});

/**
 * Twin âm: chưa từng đăng nhập lần nào (last_login_at NULL) thì KHÔNG suy diễn ra một ngày kích
 * hoạt — "chưa có bằng chứng nào khách làm chủ hộp thư này" phải giữ nguyên là NULL, không phải
 * một ngày bịa ra.
 */
it('leaves activated_at null for an account that has never signed in', function () {
    $neverSignedIn = ClientUser::factory()->create([
        'last_login_at' => null,
        'activated_at' => null,
    ]);

    runBackfillClientUsersActivatedAtMigration();

    expect($neverSignedIn->fresh()->activated_at)->toBeNull();
});

/**
 * Fix round 1 (I3): ĐẢO NGƯỢC khẳng định của vòng đầu. Bản đầu GIỮ một `activated_at` đã có, với
 * lý do sai — cột đó có thể mang một giá trị NHÂN SỰ GÕ TAY qua `DateTimePicker` đã gỡ (đúng phát
 * hiện `intake/intake-05`: "activated_at là một ô ngày giờ nhân sự gõ tay"), không phải bằng
 * chứng khách tự đổi mật khẩu lần đầu. Phán quyết fix round 1: BỎ QUA giá trị cũ vô điều kiện,
 * luôn đặt lại bằng `last_login_at` — kể cả khi hai ngày đó khác nhau và kể cả khi giá trị cũ
 * "trông hợp lý hơn" (mới hơn, gần hơn).
 *
 * Mutation probe: thêm lại `->whereNull('activated_at')` vào migration thì test này đỏ —
 * activated_at giữ nguyên giá trị gõ tay thay vì bị ghi đè (xem báo cáo).
 */
it('overwrites any existing activated_at with last_login_at, ignoring what staff had typed in before', function () {
    $staffTypedDate = now()->subDays(30);

    $account = ClientUser::factory()->create([
        'last_login_at' => now()->subDay(),
        'activated_at' => $staffTypedDate,
    ]);

    runBackfillClientUsersActivatedAtMigration();

    $account->refresh();

    expect($account->activated_at->equalTo($account->last_login_at))->toBeTrue()
        ->and($account->activated_at->equalTo($staffTypedDate))->toBeFalse();
});

/**
 * I3: "Ghi nhớ đăng nhập" đã gỡ hẳn khỏi cổng (phát hiện `portal/portal-1`) và chưa có môi trường
 * production nào đang chạy — remember_token còn sót lại chỉ là dữ liệu chết của một tính năng
 * không còn tồn tại, nên xoá luôn trong cùng lượt backfill này.
 *
 * Mutation probe: bỏ `'remember_token' => null` khỏi migration thì test này đỏ.
 */
it('clears remember_token for every account, active or not', function () {
    $account = ClientUser::factory()->create(['remember_token' => 'con-token-cu-cua-ghi-nho-dang-nhap']);

    expect($account->remember_token)->not->toBeNull();

    runBackfillClientUsersActivatedAtMigration();

    expect($account->fresh()->remember_token)->toBeNull();
});
