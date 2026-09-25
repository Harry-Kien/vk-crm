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
 * Điều kiện quan trọng nhất của migration: KHÔNG được ghi đè một activated_at THẬT đã có (ví dụ
 * đã đi qua ChangePassword::changePassword() trước khi migration này chạy) bằng một giá trị suy
 * diễn từ last_login_at — hai ngày đó có thể khác nhau (đặt lại mật khẩu sau lần kích hoạt đầu,
 * xem LoginTest.php "keeps the original activation timestamp across a later forced reset").
 *
 * Mutation probe: bỏ `->whereNull('activated_at')` khỏi migration thì test này đỏ — activated_at
 * bị ghi đè thành last_login_at (xem báo cáo).
 */
it('never overwrites an activated_at that is already set, even when last_login_at is a different, later date', function () {
    $alreadyActivated = ClientUser::factory()->create([
        'last_login_at' => now()->subDay(),
        'activated_at' => now()->subDays(30),
    ]);

    // Đọc lại từ CSDL trước khi so sánh: cột datetime bỏ phần micro giây khi lưu, còn biến
    // Carbon vừa gán ở trên vẫn còn nguyên — so hai bên khác độ chính xác sẽ luôn lệch dù
    // migration đúng. Cùng thành ngữ LoginTest.php "keeps the original activation timestamp…".
    $originalActivatedAt = $alreadyActivated->fresh()->activated_at;

    runBackfillClientUsersActivatedAtMigration();

    expect($alreadyActivated->fresh()->activated_at->equalTo($originalActivatedAt))->toBeTrue();
});
