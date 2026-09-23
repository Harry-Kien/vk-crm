<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một dòng duy nhất, nói hệ thống định kỳ còn sống hay không (SPEC §2 "Giám sát cron").
 *
 * KHÔNG dùng `HasBlameable`: không người nào ghi bảng này. Nó do lịch chạy tự động ghi, nên một
 * cột "ai sửa" ở đây sẽ luôn rỗng và sẽ làm người đọc sau tưởng là dữ liệu bị mất.
 *
 * KHÔNG nằm trong ranh giới cổng khách: đây là dữ liệu vận hành của văn phòng, không phải dữ liệu
 * hồ sơ. `PortalCoverageTest` đòi mọi model khai báo lập trường của mình về cổng khách, nên lập
 * trường được khai ở đó chứ không im lặng.
 */
class SystemHealth extends Model
{
    /** Bảng tên số ít có chủ đích: nó mô tả một trạng thái, không phải một tập bản ghi. */
    protected $table = 'system_health';

    protected $fillable = [
        'singleton',
        'last_schedule_run_at',
        'last_heartbeat_at',
        'last_heartbeat_error',
    ];

    protected function casts(): array
    {
        return [
            'last_schedule_run_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * SPEC §2: trang chủ cảnh báo đỏ nếu `last_schedule_run_at` cũ hơn 30 phút. Lịch chạm cột này
     * mỗi phút, nên 30 phút là ba mươi lần lỡ liên tiếp — đủ xa để không báo động vì một lần máy
     * chủ bận, đủ gần để phát hiện trong cùng buổi làm việc.
     */
    public const STALE_AFTER_MINUTES = 30;

    /**
     * Dòng duy nhất, tạo nếu chưa có. Dùng `firstOrCreate` trên cột `singleton` có ràng buộc duy
     * nhất, nên hai tiến trình chạy song song không thể sinh ra hai dòng: tiến trình thua sẽ vấp
     * khoá trùng và Laravel đọc lại (`Builder::createOrFirst`).
     */
    public static function current(): self
    {
        return static::firstOrCreate(['singleton' => 1]);
    }

    /**
     * Ba trạng thái, không phải hai. "Chưa bao giờ chạy" là trạng thái mà một lần triển khai mới
     * thật sự gặp, và là trạng thái dễ quên nhất — một hệ thống vừa dựng xong, cron chưa cắm, và
     * màn hình im lặng vì `null` không lớn hơn ngưỡng nào cả.
     */
    public function scheduleHasNeverRun(): bool
    {
        return $this->last_schedule_run_at === null;
    }

    public function scheduleIsStale(): bool
    {
        return $this->last_schedule_run_at !== null
            && $this->last_schedule_run_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
