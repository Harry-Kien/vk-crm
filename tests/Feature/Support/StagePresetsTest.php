<?php

use App\Support\StagePresets;
use Database\Seeders\MatterTypeSeeder;
use Illuminate\Support\Facades\DB;

/**
 * `stage/stage-08` (M6.5 Task 10): form "Chuyển giai đoạn"/"Thêm cập nhật" điền sẵn `public_content`
 * bằng `matter_type_stages.client_description` của giai đoạn đích (afterStateUpdated,
 * BuildsStageUpdateSchema::stageTemplate()), và công tắc "Công bố cho khách ngay" mặc định BẬT khi
 * vụ việc đã bật portal — kéo theo luật `required()+minLength(30)` (SPEC §4.8, §6.2 bước 4c). Một
 * mẫu ngắn hơn 30 ký tự khiến CHÍNH mẫu do hệ thống gợi ý bị luật của hệ thống chặn ngay khi gửi.
 * `StagePresets` là nguồn duy nhất `MatterTypeSeeder` seed từ (xem `MatterTypeSeeder::run()`), nên
 * sửa ở đây sửa luôn dữ liệu seed — test lặp qua CẢ HAI: bộ preset tĩnh, và dữ liệu đã seed thật.
 */
it('gives every stage of every matter type a client_description of at least 30 characters', function () {
    foreach (['DD', 'DS', 'HS', 'DN', 'LD', 'HN'] as $code) {
        foreach (StagePresets::for($code) as $stage) {
            expect(mb_strlen($stage['client_description'] ?? ''))
                ->toBeGreaterThanOrEqual(30, "Loại {$code}, giai đoạn {$stage['key']} có client_description dưới 30 ký tự.");
        }
    }
});

/** Đối chứng qua đúng đường ghi dữ liệu thật (seeder), không chỉ đọc thẳng StagePresets. */
it('seeds every matter_type_stages row with a client_description of at least 30 characters', function () {
    (new MatterTypeSeeder)->run();

    $rows = DB::table('matter_type_stages')->select('matter_type_id', 'key', 'client_description')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(mb_strlen($row->client_description ?? ''))
            ->toBeGreaterThanOrEqual(30, "matter_type_id {$row->matter_type_id}, giai đoạn {$row->key} có client_description dưới 30 ký tự.");
    }
});
