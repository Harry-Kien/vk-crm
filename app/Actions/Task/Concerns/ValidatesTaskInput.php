<?php

namespace App\Actions\Task\Concerns;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kiểm tra đầu vào chung của các Action việc nội bộ — nửa gác cổng thật của các ô `required()`/
 * `maxLength()` trên màn hình (form có thể bị bỏ qua bằng một payload Livewire dàn dựng). Mọi giới hạn
 * độ dài là độ dài cột (`Task::*_MAX_LENGTH`, MariaDB strict). Khoá lỗi là tên ô của form, để
 * `ReportsActionFailures` gắn câu vào đúng ô.
 */
trait ValidatesTaskInput
{
    protected function cleanTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages(['title' => [__('tasks.validation.title_required')]]);
        }

        if (mb_strlen($title) > Task::TITLE_MAX_LENGTH) {
            throw ValidationException::withMessages(['title' => [__('tasks.validation.too_long', ['max' => Task::TITLE_MAX_LENGTH])]]);
        }

        return $title;
    }

    protected function cleanText(?string $text, string $field, int $max, bool $required = false): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => [__('tasks.validation.'.$field.'_required')]]);
            }

            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => [__('tasks.validation.too_long', ['max' => $max])]]);
        }

        return $text;
    }

    /**
     * Hạn `Y-m-d` hoặc rỗng. Hạn đã qua bị từ chối — trừ khi đó đúng là hạn việc đang mang
     * (`$current`): sửa tiêu đề một việc đã quá hạn không bắt người sửa dời hạn.
     */
    protected function cleanDueDate(?string $dueDate, ?string $current = null): ?string
    {
        $dueDate = trim((string) $dueDate);

        if ($dueDate === '') {
            return null;
        }

        // Ô chọn ngày gửi `Y-m-d`; một giá trị đã qua cast `date` (sửa việc) có thể mang thêm nửa đêm.
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) 00:00:00$/', $dueDate, $match) === 1) {
            $dueDate = $match[1];
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $dueDate);
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $dueDate) {
            throw ValidationException::withMessages(['due_date' => [__('tasks.validation.due_date_invalid')]]);
        }

        if ($dueDate < today()->toDateString() && $dueDate !== $current) {
            throw ValidationException::withMessages(['due_date' => [__('tasks.validation.due_date_past')]]);
        }

        return $dueDate;
    }
}
