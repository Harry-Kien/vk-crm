<?php

namespace App\Actions\Matter;

use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gia hạn khách tra cứu một vụ đã kết thúc (làn fm, mục A5 của kiểm tra nghiệp vụ 2026-10-09).
 *
 * `client_access_until` được `SyncMatterArchive` tính bằng `closed_at` + `vkcrm.client_access_days`
 * (SPEC §6.12 bước 5). Khi luật sư công bố gói bàn giao muộn, hay khách gọi xin thêm thời gian, cách
 * duy nhất trước Action này là quản trị viên mở lại vụ bằng đường bỏ qua — làm sai lịch sử giai đoạn.
 * Đây là đường thẳng: đổi đúng một cột của dòng lưu trữ, có lý do và nhật ký.
 *
 * Luật:
 *  - Cổng `MatterPolicy::extendClientAccess` — luật sư phụ trách của chính vụ hoặc quản trị viên.
 *  - Vụ phải đang kết thúc, có dòng lưu trữ, chưa ghi quyết định tiêu huỷ.
 *  - Ngày mới là một ngày thật (`Y-m-d`), SAU hôm nay và SAU hạn hiện có (chỉ gia hạn, không rút
 *    ngắn — rút quyền khách đã có đường "Tắt công bố"), và không quá {@see self::MAX_DAYS} ngày kể từ
 *    hôm nay (gia hạn có chủ đích, không mở vô thời hạn).
 *  - Lý do bắt buộc; ghi `client_access_extended` (chủ thể là vụ, mang ngày cũ, ngày mới, lý do).
 *
 * Thứ tự khoá: `matters` trước (câu lệnh đầu tiên), rồi dòng `matter_archives` — cùng thứ tự
 * `SyncMatterArchive`. `SyncMatterArchive::syncClosed()` giữ ngày muộn hơn giữa ngày tính lại và ngày
 * đang có, nên một lần đồng bộ lại (chuyển giữa hai giai đoạn kết thúc) không xoá mất lần gia hạn;
 * mở lại vụ thì hạn về NULL như trước.
 */
class ExtendClientAccess
{
    /** Trần chủ động: gia hạn tối đa từng ấy ngày kể từ hôm nay mỗi lần. */
    public const MAX_DAYS = 365;

    /** Trần chủ động cho lý do (nằm trong `properties` JSON của nhật ký). */
    public const REASON_MAX = 2000;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Matter $matter, User $actor, string|CarbonInterface $until, string $reason): MatterArchive
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($matter, $actor, $until, $reason): MatterArchive {
            /** @var Matter $locked */
            $locked = Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->whereKey($matter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('extendClientAccess', $locked);

            $archive = MatterArchive::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('matter_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked->isClosed() || $archive === null || $archive->destroyed_at !== null) {
                throw ValidationException::withMessages([
                    'until' => [__('lifecycle.access.not_extendable')],
                ]);
            }

            $date = $this->validatedDate($until, $archive);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [__('lifecycle.access.reason_required')],
                ]);
            }

            if (mb_strlen($reason) > self::REASON_MAX) {
                throw ValidationException::withMessages([
                    'reason' => [__('lifecycle.access.reason_max', ['max' => self::REASON_MAX])],
                ]);
            }

            $from = $archive->client_access_until?->toDateString();

            $archive->update(['client_access_until' => $date->toDateString()]);

            Audit::record('client_access_extended', $locked, [
                'matter_id' => $locked->getKey(),
                'client_id' => $locked->client_id,
                'from' => $from,
                'to' => $date->toDateString(),
                'reason' => $reason,
            ], $actor);

            return $archive;
        });
    }

    private function validatedDate(string|CarbonInterface $until, MatterArchive $archive): CarbonInterface
    {
        $invalid = fn (string $key, array $replace = []): ValidationException => ValidationException::withMessages([
            'until' => [__("lifecycle.access.{$key}", $replace)],
        ]);

        if ($until instanceof CarbonInterface) {
            $date = $until->copy()->startOfDay();
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1) {
            $date = Carbon::createFromFormat('!Y-m-d', $until);

            if ($date === false || $date->toDateString() !== $until) {
                throw $invalid('until_invalid');
            }
        } else {
            throw $invalid('until_invalid');
        }

        $floor = $archive->client_access_until !== null && $archive->client_access_until->toDateString() > today()->toDateString()
            ? $archive->client_access_until->toDateString()
            : today()->toDateString();

        if ($date->toDateString() <= $floor) {
            throw $invalid('until_too_early', ['date' => Carbon::parse($floor)->format('d/m/Y')]);
        }

        if ($date->toDateString() > today()->addDays(self::MAX_DAYS)->toDateString()) {
            throw $invalid('until_too_late', ['days' => self::MAX_DAYS]);
        }

        return $date;
    }
}
