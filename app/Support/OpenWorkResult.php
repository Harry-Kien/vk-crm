<?php

namespace App\Support;

use App\Actions\Matter\RemoveTeamMember;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use Illuminate\Support\Collection;

/**
 * Kết quả một lần hỏi {@see OpenWork::forUser()}: những việc còn MỞ mà một nhân sự còn đứng tên,
 * tuỳ chọn giới hạn vào một vụ việc. Ba loại việc, đúng ba loại {@see OpenWork} gom lại:
 *
 * - `leadMatters` — vụ việc ĐANG MỞ (`closed_at` null — R8) mà người này là luật sư phụ trách.
 * - `deadlines` — mốc thời hạn CHƯA HOÀN THÀNH mà người này đứng tên phụ trách.
 * - `clientRequests` — yêu cầu khách CHƯA ĐÓNG (`status` khác `closed`) đang giao cho người này.
 *
 * Hình dạng object (không phải một `bool` đơn) để MỘT lần hỏi vừa TỪ CHỐI được (`isEmpty()`) vừa
 * LIỆT KÊ được lý do (`describe()`) — {@see RemoveTeamMember} (Task 3) và
 * việc nghỉ việc (Task 4, R7) đều cần cả hai, và hỏi lại hai lần riêng (một lần đếm, một lần liệt
 * kê) là hai câu trả lời có thể lệch nhau nếu dữ liệu đổi giữa hai lần hỏi — collection được giữ
 * nguyên trong object này, không truy vấn lại.
 */
final readonly class OpenWorkResult
{
    /**
     * @param  Collection<int, Matter>  $leadMatters
     * @param  Collection<int, Deadline>  $deadlines
     * @param  Collection<int, ClientRequest>  $clientRequests
     */
    public function __construct(
        public Collection $leadMatters,
        public Collection $deadlines,
        public Collection $clientRequests,
    ) {}

    public function isEmpty(): bool
    {
        return $this->leadMatters->isEmpty() && $this->deadlines->isEmpty() && $this->clientRequests->isEmpty();
    }

    public function count(): int
    {
        return $this->leadMatters->count() + $this->deadlines->count() + $this->clientRequests->count();
    }

    /**
     * Mô tả tiếng Việt, một dòng một việc — dùng để dựng câu từ chối liệt kê "cần chuyển trước"
     * (R6, R7). Không đưa số CCCD hay nội dung nội bộ dài vào câu, chỉ mã vụ việc, tên mốc và
     * chủ đề yêu cầu — đúng những gì đã hiện trên các tab tương ứng cho người đang thao tác.
     *
     * @return array<int, string>
     */
    public function describe(): array
    {
        return [
            ...$this->leadMatters->map(fn (Matter $matter): string => __('team.open_work.lead_matter', [
                'code' => $matter->code,
            ]))->all(),
            ...$this->deadlines->map(fn (Deadline $deadline): string => __('team.open_work.deadline', [
                'name' => $deadline->name,
                'code' => $deadline->matter?->code ?? (string) $deadline->matter_id,
            ]))->all(),
            ...$this->clientRequests->map(fn (ClientRequest $request): string => __('team.open_work.client_request', [
                'subject' => $request->subject,
                'code' => $request->matter?->code ?? (string) $request->matter_id,
            ]))->all(),
        ];
    }
}
