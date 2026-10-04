<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Concerns;

use Closure;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Chạy một Action tiếp nhận từ nút LƯU của form trang (tạo hoặc sửa) và đưa mọi lời từ chối của nó tới
 * mắt người dùng bằng tiếng Việt, đúng chỗ (M10 Task 3: "mọi thao tác đi qua Action, bắt
 * `DomainException` thành lỗi trên form"). Các nút trong modal dùng `ReportsActionFailures` có sẵn;
 * trait này là bản cho FORM CHÍNH của trang, vì khoá lỗi của Action là khoá TRẦN (`contact_name`)
 * còn ô nằm ở `data.contact_name`.
 *
 *  - `ValidationException`: khoá có ô trên form → `data.<khoá>` (kể cả `summary` — nút "Lưu câu
 *    chuyện" của trang sửa đi qua đây, để lời từ chối của cổng gắn vào đúng ô câu chuyện); khoá của một dòng bên đối lập
 *    (`parties.N.x`, `parties`) → ô danh sách `data.parties` (chỉ số dòng của Action không khớp khoá
 *    UUID của repeater; gắn sai dòng còn tệ hơn gắn vào cả danh sách); khoá không ứng với ô nào
 *    (`intake` — bản ghi đã xong việc) → thông báo.
 *  - `DomainException` (`ConflictCheckBusy` …) → thông báo, dừng lưu, giữ dữ liệu đã nhập.
 *  - `AuthorizationException` → câu chung `actions.unauthorized` (không phân biệt "không quyền" với
 *    "không tồn tại", SPEC §10.10).
 */
trait TranslatesIntakeFailures
{
    /** Các khoá lỗi của Action có ô cùng tên ở form chính. */
    private const FORM_FIELDS = [
        'contact_name', 'contact_phone', 'contact_email', 'contact_id_number', 'contact_role', 'source',
        'referred_by', 'matter_type_id', 'quoted_amount', 'assigned_to', 'received_at', 'summary',
    ];

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function translatingIntakeFailures(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $this->failOnFormFields($exception->errors());
        } catch (DomainException $exception) {
            $this->failWithIntakeNotification($exception->getMessage());
        } catch (AuthorizationException) {
            $this->failWithIntakeNotification(__('actions.unauthorized'));
        }
    }

    /** @param  array<string, array<int, string>>  $errors */
    private function failOnFormFields(array $errors): never
    {
        $bound = [];
        $unbound = [];

        foreach ($errors as $key => $messages) {
            $field = match (true) {
                in_array($key, self::FORM_FIELDS, true) => $key,
                $key === 'parties' || str_starts_with($key, 'parties.') => 'parties',
                default => null,
            };

            if ($field === null) {
                $unbound = [...$unbound, ...$messages];

                continue;
            }

            $bound["data.{$field}"] = [...($bound["data.{$field}"] ?? []), ...$messages];
        }

        if ($bound === []) {
            $this->failWithIntakeNotification(implode("\n", $unbound));
        }

        if ($unbound !== []) {
            $this->sendIntakeFailure(implode("\n", $unbound));
        }

        throw ValidationException::withMessages($bound);
    }

    private function failWithIntakeNotification(string $message): never
    {
        $this->sendIntakeFailure($message);

        throw new Halt;
    }

    private function sendIntakeFailure(string $message): void
    {
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();
    }
}
