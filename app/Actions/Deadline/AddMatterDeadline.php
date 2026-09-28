<?php

namespace App\Actions\Deadline;

use App\Actions\Deadline\Concerns\ChecksDeadlineHolder;
use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\Portal\TriageClientRequest;
use App\Actions\TransitionMatterStage;
use App\Enums\DeadlineSeverity;
use App\Exceptions\MatterNotPublishedToPortal;
use App\Models\Concerns\HasBlameable;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * "Thêm nhanh" một mốc thời hạn vào một vụ việc (SPEC §4.13, §7.2 tab "Mốc thời hạn").
 *
 * # Vì sao Action này tồn tại muộn hơn cái bảng nó ghi vào ba milestone
 *
 * `deadlines` có từ M1, `DeadlinePolicy` từ M2, cổng khách đọc nó từ M5 — và cho tới task này
 * đường DUY NHẤT tạo ra một hàng là `MatterSeeder`, tức dữ liệu mẫu. Không một màn hình nào, nên
 * trên một bản cài thật cái bảng này không bao giờ có gì. Phát hiện khi rà soát toàn hệ thống
 * ngày 22/09/2026.
 *
 * Hệ quả nặng nhất không phải một màn hình thiếu, và nó đúng là hình dạng lỗi mà dữ liệu mẫu che
 * đi: `CheckDeadlines` (M6 Task 6) sẽ chạy mỗi sáng trên một cái bảng mà **chỉ máy của lập trình
 * viên mới có dữ liệu**, xanh trong mọi bài kiểm tra, và không nhắc ai điều gì ở văn phòng — một
 * tính năng đúng, chạy đều, và vô nghĩa.
 *
 * # Chữ "nhanh" là một ràng buộc thật, và nó nằm ở ĐÂY chứ không chỉ ở màn hình
 *
 * SPEC §7.2 nói "danh sách, thêm nhanh". Một luật sư thêm một mốc trong lúc đang đọc một quyết
 * định của toà, không trong lúc điền một biểu mẫu. Nên chữ ký này chỉ đòi HAI thứ — tên và ngày
 * — và tự điền ba thứ còn lại: mức độ `normal`, người phụ trách là luật sư phụ trách vụ việc, và
 * chưa công bố cho khách.
 *
 * **Mặc định "chưa công bố" là một quyết định về an toàn, không phải về tiện tay.** Cột
 * `is_published` là thứ duy nhất đứng giữa một mốc nội bộ và mắt khách hàng (SPEC §8.3 khối 6),
 * và một mặc định BẬT sẽ đẩy ra trước mặt khách mọi mốc mà văn phòng ghi cho chính mình.
 *
 * # Người phụ trách phải MỞ ĐƯỢC hồ sơ, và phải CÒN ĐI LÀM
 *
 * Cùng cổng {@see TriageClientRequest::canHoldTheThread()} dựng cho ô "giao
 * việc", và cùng lý do: giao một mốc tố tụng cho người không mở được hồ sơ là đẩy nó vào một hàng
 * đợi không ai nhìn thấy — nó biến mất khỏi tầm mắt mà vẫn đếm là đã có người chịu trách nhiệm.
 * Với một vụ `restricted` (SPEC §4.6) còn thêm một cách rò rỉ tên hồ sơ qua một ô chọn. Câu hỏi
 * được hỏi **trên người được giao**, không chỉ trên người đang giao.
 *
 * Hệ quả cho trường hợp mặc định: nếu chính luật sư phụ trách đã nghỉ việc (`is_active = false`)
 * thì lần thêm nhanh bị từ chối kèm một câu chỉ vào ô "người phụ trách", thay vì lặng lẽ ghi một
 * mốc cho một người không còn đọc thư. Màn hình bày sẵn danh sách đội ngũ còn đi làm để người
 * dùng chọn lại.
 *
 * # `is_published = true` đòi vụ việc đã ở trên cổng
 *
 * Cùng luật {@see TransitionMatterStage} giữ cho `stage_logs`, và cùng lý do:
 * một mốc đã công bố trên một vụ việc chưa bật portal nằm chờ im lặng rồi lộ ra NGUYÊN loạt vào
 * khoảnh khắc ai đó bật công tắc của vụ việc. Chặn từ gốc thay vì chỉ chặn tác dụng phụ.
 *
 * # Không đọc `auth()`, không tin tham số
 *
 * `$actor` tường minh, cổng hỏi lại tất cả, và `blameOn()` để `created_by` là chính người vừa qua
 * cổng chứ không phải người tình cờ đang có phiên `web` — xem {@see HasBlameable}.
 */
class AddMatterDeadline
{
    use ChecksDeadlineHolder;
    use OpensDeadline;

    /** `deadlines.name` là `string(200)` (SPEC §4.13). */
    public const NAME_MAX_LENGTH = 200;

    /**
     * @param  string|CarbonInterface  $dueDate  ngày đến hạn; chuỗi `Y-m-d` là thứ ô chọn ngày gửi lên
     * @param  User|null  $responsible  để trống thì lấy luật sư phụ trách vụ việc
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MatterNotPublishedToPortal
     */
    public function handle(
        Matter $matter,
        User $actor,
        string $name,
        string|CarbonInterface $dueDate,
        DeadlineSeverity $severity = DeadlineSeverity::Normal,
        ?User $responsible = null,
        bool $isPublished = false,
    ): Deadline {
        return DB::transaction(function () use ($matter, $actor, $name, $dueDate, $severity, $responsible, $isPublished): Deadline {
            $fresh = $this->openMatterForDeadline($matter, $actor);

            $name = $this->cleanName($name);
            $due = $this->readDueDate($dueDate);

            // Chưa chọn ai thì mặc định là luật sư phụ trách — và người đó đi qua ĐÚNG cổng mà
            // một người được chọn tay phải đi qua. Một mặc định không được kiểm tra là một cách
            // để cổng đúng với mọi trường hợp trừ trường hợp thường gặp nhất.
            $responsible ??= $fresh->leadLawyer;

            if ($responsible === null || ! $this->canHoldTheDeadline($responsible, $fresh)) {
                throw ValidationException::withMessages([
                    'responsible_user_id' => [__('deadlines.validation.responsible_cannot_open')],
                ]);
            }

            if ($isPublished && ! $fresh->is_published_to_portal) {
                throw MatterNotPublishedToPortal::forDeadline($fresh);
            }

            $deadline = new Deadline([
                'matter_id' => $fresh->getKey(),
                'name' => $name,
                'due_date' => $due->toDateString(),
                'severity' => $severity,
                'responsible_user_id' => $responsible->getKey(),
                'is_completed' => false,
                'is_published' => $isPublished,
            ]);

            $deadline->blameOn($actor)->save();

            Audit::record('deadline_added', $deadline, [
                'matter_id' => $fresh->getKey(),
                'client_id' => $fresh->client_id,
                'due_date' => $due->toDateString(),
                'severity' => $severity->value,
                'responsible_user_id' => $responsible->getKey(),
                'is_published' => $isPublished,
            ], causer: $actor);

            return $deadline;
        });
    }

    /**
     * Final review A-M3: người giữ mốc lúc TẠO hỏi đúng luật của mọi đường ghi
     * `responsible_user_id` sau đó — {@see ChecksDeadlineHolder::canHoldDeadline()} (trong đội ngũ
     * hoặc là lead, còn đi làm, chưa xoá, mở được hồ sơ). Bản trước hỏi riêng "còn đi làm +
     * `MatterPolicy::update`", nên một trưởng phòng ngoài đội ngũ (update được mọi vụ thường) nhận
     * được một mốc mà `ChangeDeadlineResponsible`/`UpdateDeadline` sẽ không bao giờ giao cho họ.
     */
    private function canHoldTheDeadline(User $responsible, Matter $matter): bool
    {
        return $this->canHoldDeadline($responsible, $matter);
    }

    /**
     * Tên mốc, đã cắt khoảng trắng hai đầu.
     *
     * Hai cổng, và cả hai đều có thật: một chuỗi toàn khoảng trắng là một dòng không đọc được
     * trong bảng và trong thư nhắc của Task 6; một chuỗi dài quá `string(200)` thì MariaDB tự cắt
     * cụt trong chế độ không nghiêm ngặt, nghĩa là mốc được lưu với một cái tên KHÁC cái người
     * dùng gõ, không ai báo. `mb_strlen` vì tiếng Việt nhiều byte — đếm ký tự, đúng như MariaDB
     * đếm với `utf8mb4`.
     */
    private function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => [__('deadlines.validation.name_required')],
            ]);
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'name' => [__('deadlines.validation.name_too_long', ['max' => self::NAME_MAX_LENGTH])],
            ]);
        }

        return $name;
    }

    /**
     * Ngày đến hạn.
     *
     * **Một ngày trong QUÁ KHỨ được nhận, có chủ đích.** Trường hợp thật: một luật sư vừa nhận hồ
     * sơ từ đồng nghiệp và ghi lại những mốc đã trôi qua để chúng hiện ra trong danh sách quá hạn
     * — thứ SPEC §6.8 đòi hệ thống nói ra chứ không giấu đi. Chặn quá khứ ở đây sẽ biến một mốc
     * đã lỡ thành một mốc không tồn tại, và đó là hình dạng tệ nhất của cùng một vấn đề.
     */
    private function readDueDate(string|CarbonInterface $dueDate): CarbonImmutable
    {
        if ($dueDate instanceof CarbonInterface) {
            return CarbonImmutable::instance($dueDate)->startOfDay();
        }

        try {
            // `trim()` và câu chặn rỗng đứng trước `parse()` vì `CarbonImmutable::parse('')` KHÔNG
            // ném gì cả — nó trả về THỜI ĐIỂM HIỆN TẠI. Không có câu này, một ô ngày bỏ trống gửi
            // lên bằng một đường không đi qua `required()` của Filament sẽ lặng lẽ thành một mốc
            // đến hạn hôm nay.
            if (trim($dueDate) === '') {
                throw new InvalidArgumentException('empty due date');
            }

            return CarbonImmutable::parse($dueDate)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'due_date' => [__('deadlines.validation.due_date_required')],
            ]);
        }
    }
}
