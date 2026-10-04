<?php

namespace App\Actions\Matter;

use App\Enums\MatterAiAccess;
use App\Exceptions\MatterAiAccessChanged;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bật/tắt cờ "truy cập qua AI" của một vụ việc (M11 R9, tab Tổng quan) — đường DUY NHẤT đổi
 * `matters.ai_access` sau khi vụ đã mở (cột không nằm trong `Matter::$fillable`).
 *
 * Luật Luật sư Điều 25 cấm tiết lộ thông tin vụ việc trừ khi khách đồng ý BẰNG VĂN BẢN; Luật 91
 * Điều 9: im lặng không phải đồng ý. Nên:
 *
 *  - **Bật** (`Allowed`) đòi `$clientConsented = true` — lời xác nhận của người bấm rằng khách đã
 *    đồng ý bằng văn bản cho đúng việc này (ô tích trên màn hình, không đánh dấu sẵn). Thiếu thì
 *    `ValidationException` gắn vào ô `client_consented`, trước khi chạm tới CSDL. Màn hình cũng
 *    đòi ô tích (`accepted`); đây là lớp phòng thủ cho mọi nơi gọi khác.
 *  - **Tắt** (`Denied`) không đòi gì thêm: rút một vụ khỏi AI chỉ thu hẹp, không phải một lần tiết
 *    lộ mới, và không ai phải chờ một văn bản của khách để ngừng chia sẻ.
 *
 * Cả hai chiều đòi `matter.update` trên CHÍNH vụ việc này (`MatterPolicy::update`: chưa xoá mềm, có
 * quyền, thấy được vụ) — hỏi `Gate::forUser($actor)`, không tin màn hình đã ẩn nút.
 *
 * Câu ĐẦU TIÊN trong transaction là khoá dòng vụ việc (cùng kỷ luật với
 * `SetMatterPortalPublication`); quyền và trạng thái hiện tại đọc trên bản đã khoá. `$access` là
 * trạng thái ĐÍCH người dùng đã đọc và xác nhận, không phải "đảo chiều": nếu dưới khoá vụ đã ở đúng
 * trạng thái đó (người khác vừa đổi), Action ném {@see MatterAiAccessChanged} và không ghi gì.
 *
 * Mỗi lần đổi ghi đúng một dòng `matter_ai_access_changed` (`from`, `to`,
 * `client_consent_confirmed`), causer là `$actor` tường minh — không đoán từ phiên `web`, thứ
 * không có trong một request MCP hay một lệnh console. Người và thời điểm của dòng nhật ký là
 * bằng chứng "ai đã xác nhận khách đồng ý, lúc nào". `LogsActivity` của `Matter` ghi thêm dòng
 * `updated` chung (cột nằm trong `logOnly`), như mọi lần sửa vụ.
 *
 * Không gửi thư, không thông báo: cờ này là cấu hình nội bộ, không phải điều khách thấy.
 */
class SetMatterAiAccess
{
    public function handle(Matter $matter, MatterAiAccess $access, User $actor, bool $clientConsented = false): Matter
    {
        if ($access === MatterAiAccess::Allowed && ! $clientConsented) {
            throw ValidationException::withMessages([
                'client_consented' => __('matters.ai_access.consent_required'),
            ]);
        }

        return DB::transaction(function () use ($matter, $access, $actor, $clientConsented): Matter {
            $locked = Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->whereKey($matter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            $from = $locked->ai_access;

            if ($from === $access) {
                throw MatterAiAccessChanged::make();
            }

            // `blameOn()` trước khi lưu: `HasBlameable::updating` ghi `updated_by` từ phiên `web`
            // ambient nếu không ai tuyên bố actor. `forceFill` vì cột cố ý nằm ngoài `$fillable`.
            $locked->blameOn($actor)->forceFill(['ai_access' => $access])->save();

            Audit::record('matter_ai_access_changed', $locked, [
                'from' => $from?->value,
                'to' => $access->value,
                'client_consent_confirmed' => $clientConsented,
            ], $actor);

            return $locked;
        });
    }
}
