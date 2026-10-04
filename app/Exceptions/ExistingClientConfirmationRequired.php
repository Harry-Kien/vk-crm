<?php

namespace App\Exceptions;

use App\Models\Client;
use DomainException;

/**
 * Chuyển một lần tiếp nhận thành vụ việc sẽ GẮN người liên hệ vào một hồ sơ `Client` đã có (M10 Task 4,
 * fix vòng 1 — rà soát Task 4, I1), nhưng người bấm chưa xác nhận đúng hồ sơ đó. Một số máy có thể
 * dùng chung (con gọi bằng máy của mẹ): khớp đúng số không nói được hai người có phải một không, chỉ
 * người bấm nói được — sau khi thấy mã và tên của hồ sơ, như ô tra khách của form mở vụ
 * (`CreateMatter::lookupClient()`, M6.5 R4a).
 *
 * Mang hồ sơ tìm thấy ra ngoài để màn hình hiện mã + tên rồi gửi lại id đó làm xác nhận. Chỉ ném cho
 * một hồ sơ actor được thấy: `FindClientByIdentifier` và `CreateClient::resolve()` đã từ chối (câu
 * trung lập, `DuplicateClientNotVisible`) mọi hồ sơ actor không được tra ra. Cùng khuôn với
 * `ConflictAcknowledgementRequired`: không phải lệnh cấm, chỉ là "xác nhận rồi gửi lại".
 */
class ExistingClientConfirmationRequired extends DomainException
{
    public function __construct(string $message, public readonly Client $client)
    {
        parent::__construct($message);
    }

    public static function make(Client $client): self
    {
        return new self(__('intake.errors.convert_client_confirmation_required', [
            'code' => $client->code,
            'name' => $client->name,
        ]), $client);
    }
}
