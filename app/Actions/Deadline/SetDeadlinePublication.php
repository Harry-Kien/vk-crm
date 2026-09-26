<?php

namespace App\Actions\Deadline;

use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\TransitionMatterStage;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Models\Deadline;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Công tắc "công bố cho khách" của một mốc thời hạn — cột `deadlines.is_published`.
 *
 * Cột này đã có từ M1 và cổng khách đã đọc nó từ M5 (SPEC §8.3 khối 6, "Mốc thời hạn sắp tới —
 * chỉ mốc `is_published`") — nhưng cho tới task này không một màn hình nào bật được nó. Thứ duy
 * nhất từng bật là `MatterSeeder`, thứ dựng sẵn một mốc `critical` đã công bố cho dữ liệu mẫu,
 * nên khối ấy trên cổng khách **có nội dung trên máy lập trình viên và rỗng ở văn phòng** — đúng
 * hình dạng khiến một lỗ hổng như vậy sống qua ba milestone mà không ai nhìn thấy.
 *
 * `DeadlinePolicy::view` dựng thẳng trên cột này, nên Action này là chỗ duy nhất quyết định một
 * mốc có ra tới mắt khách hàng hay không.
 *
 * # Cổng BẤT ĐỐI XỨNG, và cả hai vế là một quyết định
 *
 * **Bật** đòi vụ việc đã ở trên cổng khách — cùng luật {@see TransitionMatterStage}
 * giữ cho `stage_logs` và cùng lý do: một mốc `is_published = true` trên một vụ việc chưa bật
 * portal nằm chờ im lặng rồi lộ ra NGUYÊN loạt vào khoảnh khắc ai đó bật công tắc của vụ việc.
 *
 * **Gỡ** thì không hỏi gì cả. Một mốc đã trót công bố trên một vụ việc sau đó bị rút khỏi cổng
 * vẫn phải gỡ xuống được; một cổng đối xứng ở đây sẽ khoá đúng con đường sửa sai, và khoá nó vào
 * đúng lúc văn phòng đang cố thu hẹp thứ khách nhìn thấy.
 *
 * Đó không phải một ca giả định: `MatterSeeder::deadlines()` đặt `is_published = true` cho MỌI
 * hồ sơ mẫu, kể cả những hồ sơ chưa bật công tắc portal — tức trong cơ sở dữ liệu đã có sẵn
 * những hàng mà Action này sẽ không bao giờ TẠO ra. Chúng vẫn gỡ xuống được, đúng như phải thế.
 *
 * # Gọi trùng không sinh thêm một dòng nhật ký
 *
 * Cùng lý lẽ {@see SetDeadlineCompletion}: đã ở đúng trạng thái thì không ghi gì.
 */
class SetDeadlinePublication
{
    use OpensDeadline;

    public function handle(Deadline $deadline, bool $publish, User $actor): Deadline
    {
        return DB::transaction(function () use ($deadline, $publish, $actor): Deadline {
            [$fresh, $matter] = $this->openDeadline($deadline, $actor);

            // R5 (roles-05, M6.5 Task 10): `openDeadline()` chỉ hỏi `update` (thao tác thường
            // ngày trên mốc) — công bố/gỡ cho khách đòi thêm `stageLog.publish`, xem docblock
            // DeadlinePolicy::publish(). Hỏi trên $fresh (đọc lại dưới khoá), không trên $deadline
            // caller đưa vào, cùng lý do với mọi lần hỏi Gate khác trong trait này.
            Gate::forUser($actor)->authorize('publish', $fresh);

            if ($publish && ! $matter->is_published_to_portal) {
                throw MatterNotPublishedToPortal::forDeadline($matter);
            }

            if ((bool) $fresh->is_published === $publish) {
                return $fresh;
            }

            $fresh->blameOn($actor)->update(['is_published' => $publish]);

            Audit::record('deadline_publication_set', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'publish' => $publish,
                'due_date' => $fresh->due_date->toDateString(),
            ], causer: $actor);

            return $fresh;
        });
    }
}
