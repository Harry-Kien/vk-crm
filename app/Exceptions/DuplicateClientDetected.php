<?php

namespace App\Exceptions;

use App\Models\Client;
use DomainException;

/**
 * Một hồ sơ `Client` đã có mang ĐÚNG số điện thoại đã chuẩn hoá hoặc ĐÚNG hash CCCD với dữ liệu
 * vừa nhập để tạo khách hàng mới (M6.5 Task 6, finding `intake/intake-07`, phán quyết R4).
 *
 * Chỉ ném ra cho actor có `client.manage` (màn hình "Khách hàng" → "Tạo mới") — `App\Actions\
 * Client\CreateClient` không bao giờ ném lớp này cho actor không có quyền đó (luật sư tạo khách
 * ngay trong form mở vụ): người đó tự động DÙNG hồ sơ đã có (R4 b), không có gì để hỏi xác nhận,
 * vì họ không có quyền tạo một hồ sơ thứ hai dù có muốn.
 *
 * Dò trùng so với các BÊN `is_our_client` ĐÃ LƯU (`matter_parties`), không so toàn bảng `clients`
 * — xem docblock `CreateClient` cho lý do đầy đủ (bảng `clients` không có gì để truy vấn: `phone`
 * không được chuẩn hoá khi lưu, `id_number` mã hoá không tất định).
 */
class DuplicateClientDetected extends DomainException
{
    public function __construct(string $message, public readonly Client $client)
    {
        parent::__construct($message);
    }

    public static function make(Client $client): self
    {
        return new self(__('exceptions.duplicate_client_detected', ['code' => $client->code]), $client);
    }
}
