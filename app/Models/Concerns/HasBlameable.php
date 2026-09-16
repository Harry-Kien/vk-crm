<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Điền created_by / updated_by từ nhân sự đang đăng nhập (guard web).
 * Không đưa hai cột này vào $fillable: chỉ hệ thống mới được ghi.
 *
 * **Phiên đăng nhập chỉ là PHƯƠNG ÁN CUỐI.** Mọi Action trong `app/Actions/` đều nhận `$actor`
 * tường minh (vì chính actor đó được đem đi kiểm tra quyền), và một Action biết actor là ai thì
 * phải được quyền ghi đúng người đó vào hai cột này — kể cả khi phiên `web` đang thuộc về người
 * khác, hoặc không có phiên nào (job, lệnh console, import).
 *
 * **Đường nhường là `blameOn()`, KHÔNG phải `isDirty()` (review fix round 4, Important I-1).**
 * Bản trước cho `updating` nhường khi `isDirty('updated_by')`. `isDirty` so với giá trị GỐC của
 * dòng, nên phép gán `$model->updated_by = $actor->id` KHÔNG "bẩn" khi cột đã sẵn mang đúng id
 * đó — và cửa nhường đóng lại đúng lúc nó trông như đang mở:
 *
 *     $m->updated_by = 7;          // giá trị gốc đã là 7
 *     $m->isDirty('updated_by');   // false → hook ghi đè bằng phiên ambient
 *
 * Với actor A, phiên B và `updated_by` đã là A, hai Action ghi ra B. Khiếm khuyết này vô hình với
 * mọi fixture đặt giá trị lưu sẵn là một người KHÁC actor — tức toàn bộ fixture của vòng trước.
 *
 * Nên ý định giờ được phát biểu TƯỜNG MINH thay vì suy ra từ một hiệu ứng phụ của Eloquent:
 * `$model->blameOn($actor)` đặt một thuộc tính tạm (không phải cột, không vào `$attributes`, không
 * ra `toArray()`), và cả hai hook đều đọc nó TRƯỚC khi nhìn tới phiên. "Cột đã mang sẵn giá trị
 * đúng" không còn là một trạng thái đặc biệt vì không có phép so sánh nào nữa.
 *
 * **Gán thẳng `$model->updated_by = $id` KHÔNG còn tác dụng — dùng `blameOn()`.** Đây là hệ quả
 * trực tiếp của việc bỏ `isDirty`: hook `updating` giờ ghi đè `updated_by` VÔ ĐIỀU KIỆN mỗi khi
 * biết một actor (tường minh qua `blameOn()`, hoặc phiên `web` ambient), nên một phép gán thẳng
 * trước `save()` bị đè lặng lẽ — không lỗi, không cảnh báo, chỉ một cột ghi sai người trong một hồ
 * sơ pháp lý. Cửa duy nhất để tuyên bố actor là `blameOn()`; nó cũng lo luôn `created_by` ở lần
 * tạo. (Trước bản sửa I-1, phép gán thẳng "hầu như" chạy được — chạy khi giá trị gốc khác giá trị
 * gán, im lặng không chạy khi trùng. Một cửa mở một nửa như vậy tệ hơn một cửa đóng hẳn, nên nó
 * đóng hẳn.)
 *
 * **Ý định DÍNH với instance, có chủ đích.** `blameOn()` không tự xoá sau lần lưu đầu: câu nó phát
 * biểu là "bản ghi đang nằm trong tay tôi đây được ghi nhân danh người này", đúng cho cả một Action
 * lưu hai lần (dựng rồi `save()`, sau đó `update()` thêm một cột). Một cờ tự xoá sẽ biến thứ tự các
 * lệnh lưu thành một chi tiết phải nhớ — đúng loại luật ngầm mà I-1 sinh ra từ đó.
 */
trait HasBlameable
{
    /**
     * Actor tường minh của mọi lần lưu trên CHÍNH instance này, `null` khi chưa ai tuyên bố. Thuộc
     * tính PHP thật (đã khai báo), không phải attribute Eloquent — nên nó không bị hiểu là một cột,
     * không lọt vào `save()`, `getDirty()` hay `toArray()`.
     */
    protected ?int $blameableActorId = null;

    public static function bootHasBlameable(): void
    {
        static::creating(function (Model $model): void {
            $id = $model->blameableActorId ?? auth('web')->id();

            if ($id === null) {
                return;
            }

            $model->created_by ??= $id;
            $model->updated_by ??= $id;
        });

        static::updating(function (Model $model): void {
            // Ý định tường minh thắng phiên ambient, không điều kiện — kể cả khi cột đã mang sẵn
            // đúng giá trị đó. Xem docblock trait.
            $id = $model->blameableActorId ?? auth('web')->id();

            if ($id !== null) {
                $model->updated_by = $id;
            }
        });
    }

    /**
     * Tuyên bố ai là người chịu trách nhiệm cho mọi lần lưu bản ghi này — dùng ở `app/Actions/`,
     * nơi actor đã được biết (và đã qua Gate) trước khi có lần lưu nào.
     */
    public function blameOn(User|int $actor): static
    {
        $this->blameableActorId = $actor instanceof User ? $actor->id : $actor;

        return $this;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
