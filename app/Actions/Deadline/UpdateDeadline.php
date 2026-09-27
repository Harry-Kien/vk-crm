<?php

namespace App\Actions\Deadline;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Deadline\Concerns\ChecksDeadlineHolder;
use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\DeadlineSeverity;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Sửa một mốc thời hạn đã có — tên, ngày đến hạn, mức độ, người phụ trách (M6.5 Task 14,
 * `deadlines/F7`; R14).
 *
 * # Vì sao Action này tồn tại: phiên toà hoãn không có đường sửa
 *
 * Trước bản sửa này, `app/Actions/Deadline` chỉ có `AddMatterDeadline` (tạo), `SetDeadlineCompletion`
 * (đánh dấu xong/mở lại), `SetDeadlinePublication` (công bố/gỡ) và `ChangeDeadlineResponsible`
 * (đổi người phụ trách) — không Action nào sửa được TÊN hay NGÀY. Phiên toà bị hoãn (rất thường
 * gặp) hay gõ nhầm ngày lúc tiếp nhận đều không sửa được; cách lách duy nhất là đánh dấu "hoàn
 * thành" sai sự thật rồi thêm một mốc mới — đúng loại bằng chứng mà một hồ sơ trách nhiệm nghề
 * nghiệp sẽ đọc (`deadlines/F7`). Action này đóng đúng lỗ hổng đó.
 *
 * **Không sửa `is_published` ở đây.** Công bố có Action riêng (`SetDeadlinePublication`) với cổng
 * riêng (`DeadlinePolicy::publish()`, đòi `stageLog.publish` ở chiều bật — R5); gộp vào đây sẽ bắt
 * cổng `update` của Action này trả lời thay một câu hỏi phân quyền khác.
 *
 * # Người phụ trách: sửa được, nhưng chỉ sang người còn GIỮ được mốc
 *
 * `deadlines/F7` nêu cả người phụ trách trong những thứ không sửa được, nên form "Sửa" có ô đó và
 * Action nhận `$responsible` (tuỳ chọn — `null` nghĩa là giữ nguyên). Người mới phải qua
 * {@see ChecksDeadlineHolder::canHoldDeadline()} — CÙNG luật mà `ChangeDeadlineResponsible` và
 * lần mở lại của `SetDeadlineCompletion` hỏi (còn đi làm, còn trong đội ngũ, xem được hồ sơ), đọc
 * lại DƯỚI KHOÁ dòng `users` như `ChangeDeadlineResponsible` làm, để một người bị vô hiệu hoá
 * giữa lúc form mở và lúc bấm lưu vẫn bị chặn. Một mốc ĐÃ XONG không đổi người được (cùng câu của
 * `ChangeDeadlineResponsible`); gửi lại đúng người đang giữ thì không phải một lần "đổi" và không
 * bị chặn. Màn hình chỉ BÀY RA `DeadlinesRelationManager::responsibleOptions()`; cổng thật ở đây.
 *
 * # Đổi `due_date` xoá đúng những bậc `reminders_sent` mà ngày MỚI chưa tới (brief Task 14)
 *
 * `reminders_sent` là trí nhớ chống gửi trùng của `CheckDeadlines` (SPEC §6.8), theo đúng bậc
 * (`d14`/`d7`/`d3`/`d1`/`overdue`) chứ không theo ngày. Dời hạn ra XA hơn (hoãn phiên toà) mà
 * không dọn cột này sẽ để các bậc đã gửi cho ngày CŨ tiếp tục chặn bậc tương ứng của ngày MỚI —
 * một mốc dời từ "còn 2 ngày" (đã gửi d7+d3) sang "còn 20 ngày" sẽ KHÔNG BAO GIỜ nhận lại d7 khi
 * nó thật sự còn 7 ngày, vì khoá chống trùng vẫn còn nguyên từ lần trước.
 *
 * **Chỉ xoá bậc mà ngày MỚI CHƯA TỚI — giữ nguyên bậc ngày mới ĐÃ tới.** "Đã tới" dùng ĐÚNG phép
 * so `daysLeft <= tier` mà {@see CheckDeadlines::tierFor()} dùng để quyết định một bậc có còn ý
 * nghĩa hay không — một điều kiện SỐNG Ở HAI CHỖ theo hai luật khác nhau là điều kiện sẽ lệch nhau
 * lần đầu một trong hai chỗ được sửa, nên phép so ở đây PHẢI đọc y hệt. Dời hạn từ "còn 2 ngày"
 * (đã gửi d7, d3) sang "còn 5 ngày": d7 vẫn "đã tới" (5 ≤ 7) nên GIỮ (không cần gửi lại ngay); d3
 * "chưa tới" (5 > 3) nên XOÁ (sẽ gửi lại khi thật sự còn ≤ 3 ngày). `overdue` giữ khi ngày mới
 * VẪN còn âm (còn quá hạn), xoá khi ngày mới đã về tương lai.
 *
 * **Chỉ chạy khi `due_date` THẬT SỰ đổi**, so theo ngày (`isSameDay()`) — một lượt sửa chỉ đổi tên
 * hay mức độ, gửi lại nguyên `due_date` cũ, không được dọn `reminders_sent` một cách vô cớ.
 *
 * # Khoá vụ việc TRƯỚC, mốc thời hạn SAU — và khoá đó là câu ĐẦU TIÊN
 *
 * Cùng thứ tự khoá nhà {@see ChangeDeadlineResponsible} và {@see DeleteDeadline} dùng (khác
 * `Concerns\OpensDeadline`, nơi khoá MỐC trước): không dùng lại trait đó ở đây vì cùng lý do
 * `ChangeDeadlineResponsible` đã ghi — viết lại tại chỗ thay vì đổi thứ tự khoá của các Action cũ
 * (ngoài phạm vi task này).
 *
 * # Không audit khi không có gì đổi
 *
 * Cùng kỷ luật {@see SetDeadlineCompletion}: một lượt gửi lại y hệt dữ liệu cũ (double-submit,
 * hai tab) không ghi thêm một dòng lịch sử trống nghĩa.
 */
class UpdateDeadline
{
    use ChecksAccountActive;
    use ChecksDeadlineHolder;
    use ReadsWithoutPortalScope;

    /** `deadlines.name` là `string(200)` (SPEC §4.13) — cùng giới hạn với {@see AddMatterDeadline}. */
    public const NAME_MAX_LENGTH = AddMatterDeadline::NAME_MAX_LENGTH;

    /**
     * @param  string|CarbonInterface  $dueDate  chuỗi `Y-m-d` (ô chọn ngày) hoặc một mốc Carbon
     * @param  User|null  $responsible  người giữ mốc mới; `null` = giữ nguyên người đang giữ
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        Deadline $deadline,
        User $actor,
        string $name,
        string|CarbonInterface $dueDate,
        DeadlineSeverity $severity,
        ?User $responsible = null,
    ): Deadline {
        return DB::transaction(function () use ($deadline, $actor, $name, $dueDate, $severity, $responsible): Deadline {
            // Câu ĐẦU TIÊN: khoá vụ việc, đọc lại từ CSDL — không tin `$deadline->matter` do
            // caller đưa vào.
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($deadline->matter_id);

            if ($matter === null || ! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            $fresh = $this->scopelessly(Deadline::query())
                ->lockForUpdate()
                ->where('matter_id', $matter->getKey())
                ->find($deadline->getKey());

            if ($fresh === null) {
                $this->refuse();
            }

            $fresh->setRelation('matter', $matter);

            // `DeadlinePolicy::update` — cùng cổng bốn nút còn lại của tab này.
            if (Gate::forUser($actor)->inspect('update', $fresh)->denied()) {
                $this->refuse();
            }

            $cleanName = $this->cleanName($name);
            $newDueDate = $this->readDueDate($dueDate);
            $dueDateChanged = ! $fresh->due_date->isSameDay($newDueDate);

            $before = $this->snapshot($fresh);

            $fresh->fill([
                'name' => $cleanName,
                'due_date' => $newDueDate->toDateString(),
                'severity' => $severity,
            ]);

            if ($responsible !== null && (int) $responsible->getKey() !== (int) $fresh->responsible_user_id) {
                $fresh->responsible_user_id = $this->lockedHolder($responsible, $fresh, $matter)->getKey();
            }

            if ($dueDateChanged) {
                $fresh->reminders_sent = $this->clearedReminders($fresh->reminders_sent ?? [], $newDueDate);
            }

            // NGAY TRƯỚC `save()`: `getDirty()` vẫn còn nguyên các thay đổi trong bộ nhớ.
            $changedFields = array_keys($fresh->getDirty());

            // Gửi lại y hệt dữ liệu cũ (double-submit): không ghi cột, không ghi nhật ký — cùng
            // kỷ luật `SetDeadlineCompletion`.
            if ($changedFields === []) {
                return $fresh;
            }

            $fresh->blameOn($actor)->save();

            Audit::record('deadline_updated', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'changed_fields' => $changedFields,
                'before' => $before,
                'after' => $this->snapshot($fresh),
            ], causer: $actor);

            return $fresh;
        });
    }

    /**
     * Những cột người dùng sửa được qua Action này, như chúng đứng lúc được hỏi — cho hai vế
     * `before`/`after` của dòng nhật ký.
     *
     * @return array{name: string, due_date: string, severity: string, responsible_user_id: int|null}
     */
    private function snapshot(Deadline $deadline): array
    {
        return [
            'name' => $deadline->name,
            'due_date' => $deadline->due_date->toDateString(),
            'severity' => $deadline->severity->value,
            'responsible_user_id' => $deadline->responsible_user_id,
        ];
    }

    /**
     * Người giữ mốc mới, đã đọc lại DƯỚI KHOÁ và đã qua {@see ChecksDeadlineHolder::canHoldDeadline()}
     * — cùng thứ tự khoá "vụ việc, mốc, rồi người thứ ba" và cùng hai câu từ chối của
     * {@see ChangeDeadlineResponsible}. Xem docblock lớp.
     *
     * @throws ValidationException
     */
    private function lockedHolder(User $responsible, Deadline $deadline, Matter $matter): User
    {
        if ($deadline->is_completed) {
            throw ValidationException::withMessages([
                'responsible_user_id' => [__('deadlines.validation.already_completed')],
            ]);
        }

        $locked = User::query()->withTrashed()->whereKey($responsible->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $this->canHoldDeadline($locked, $matter)) {
            throw ValidationException::withMessages([
                'responsible_user_id' => [__('deadlines.validation.responsible_cannot_open')],
            ]);
        }

        return $locked;
    }

    /**
     * Chỉ giữ những bậc mà ngày MỚI đã "tới" — xem docblock lớp cho lý do và ví dụ.
     *
     * @param  array<int, string>  $sent
     * @return array<int, string>
     */
    private function clearedReminders(array $sent, CarbonImmutable $newDueDate): array
    {
        $daysLeft = (int) today()->diffInDays($newDueDate, false);

        return array_values(array_filter($sent, function (string $tierKey) use ($daysLeft): bool {
            if ($tierKey === CheckDeadlines::OVERDUE_KEY) {
                return $daysLeft < 0;
            }

            // 'd7' -> 7, 'd14' -> 14, ...
            $tierDays = (int) mb_substr($tierKey, 1);

            return $daysLeft <= $tierDays;
        }));
    }

    /** Cùng hai cổng, cùng thông điệp với {@see AddMatterDeadline::cleanName()}. */
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

    /** Cùng hai cổng, cùng thông điệp với {@see AddMatterDeadline::readDueDate()} — quá khứ được nhận, có chủ đích. */
    private function readDueDate(string|CarbonInterface $dueDate): CarbonImmutable
    {
        if ($dueDate instanceof CarbonInterface) {
            return CarbonImmutable::instance($dueDate)->startOfDay();
        }

        try {
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

    /** Cùng câu, cùng lớp exception với {@see OpensDeadline} (SPEC §10.10). */
    private function refuse(): never
    {
        throw new AuthorizationException(__('deadlines.unavailable'));
    }
}
