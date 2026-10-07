<?php

use App\Models\Client;
use App\Models\User;
use App\Support\Audit;

/**
 * M9 Task 6 (phán quyết controller 1): một dòng nhật ký của HỆ THỐNG không có causer, kể cả khi
 * nó được ghi trong request của một người đang đăng nhập. Không có cờ đó, `Audit::record()` rơi về
 * phiên đang mở — và spatie/laravel-activitylog tự gán người dùng của guard mặc định ngay khi dựng
 * dòng, nên "không truyền causer" không đủ: phải tuyên bố "không ai".
 */
it('records a system row with no causer even while someone is logged in', function () {
    $this->actingAs(User::factory()->create(), 'web');

    $activity = Audit::record('instalment_triggered', Client::factory()->create(), ['stage_log_id' => 1], bySystem: true);

    expect($activity->causer_id)->toBeNull()
        ->and($activity->causer_type)->toBeNull()
        ->and($activity->fresh()->causer_id)->toBeNull();
});

/** Cặp dương: không có cờ, dòng vẫn mang người đang đăng nhập như trước. */
it('still falls back to the logged-in user without the system flag', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $activity = Audit::record('instalment_triggered', Client::factory()->create(), ['stage_log_id' => 1]);

    expect($activity->causer_id)->toBe($user->id);
});
