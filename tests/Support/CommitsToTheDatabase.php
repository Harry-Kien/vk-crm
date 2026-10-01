<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Tắt transaction bọc test của `RefreshDatabase` cho MỘT tệp test — dữ liệu test ghi ra được
 * COMMIT thật, nên một tiến trình bên ngoài (`mariadb-dump` của `backup:run`) nhìn thấy nó.
 *
 * `RefreshDatabase::connectionsToTransact()` đọc thuộc tính này nếu nó tồn tại: mảng rỗng = không
 * kết nối nào được bọc. Đổi lại, dữ liệu commit còn nằm lại trong CSDL test sau khi test kết thúc;
 * {@see self::forgetMigratedDatabaseAfterCommittedTest()} (gọi trong `afterEach` của tệp dùng
 * trait) đặt lại cờ "đã migrate" của `RefreshDatabase`, nên test KẾ TIẾP trong cùng tiến trình chạy
 * lại `migrate:fresh` và bắt đầu trên một CSDL sạch.
 */
trait CommitsToTheDatabase
{
    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function forgetMigratedDatabaseAfterCommittedTest(): void
    {
        RefreshDatabaseState::$migrated = false;
    }
}
