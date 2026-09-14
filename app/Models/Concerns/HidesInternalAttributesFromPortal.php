<?php

namespace App\Models\Concerns;

use App\Support\Scopes\ClientPortalScope;

/**
 * Lớp phòng thủ thứ ba cho SPEC §11 ("Response JSON và HTML của portal không chứa chuỗi trong
 * stage_logs.internal_note"). Global scope không đủ: một dòng tiến độ đã công bố vẫn mang
 * internal_note trong cùng bản ghi, nên chỉ cần một view quên lọc cột là rò rỉ.
 *
 * Chỉ tác động lên tầng serialize; mã nghiệp vụ đọc thẳng thuộc tính vẫn nhận giá trị thật.
 */
trait HidesInternalAttributesFromPortal
{
    /** @return list<string> Tên cột không bao giờ được ra portal. */
    abstract protected function internalAttributes(): array;

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        if (! ClientPortalScope::isActive()) {
            return $attributes;
        }

        foreach ($this->internalAttributes() as $attribute) {
            unset($attributes[$attribute]);
        }

        return $attributes;
    }
}
