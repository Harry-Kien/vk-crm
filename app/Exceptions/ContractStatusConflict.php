<?php

namespace App\Exceptions;

use App\Models\Contract;
use DomainException;

/**
 * Một bước trong vòng đời hợp đồng được gọi ở sai trạng thái: kích hoạt một hợp đồng không còn
 * `draft`, hoàn tất hay huỷ một hợp đồng không `active`, hoàn tất khi còn đợt chưa thu đủ và chưa
 * được miễn, hoặc huỷ một khoản thu trên hợp đồng đã hoàn tất.
 * (Phụ lục có lớp riêng, {@see ContractNotAmendable}, vì kế hoạch M9 đặt tên nó.)
 */
class ContractStatusConflict extends DomainException
{
    public static function notDraft(Contract $contract): self
    {
        return new self(__('billing.errors.contract_not_draft', self::describe($contract)));
    }

    /**
     * `UpdateDraftContract` (M9 Task 7): sửa giá trị/thuế/lịch thu chỉ trên bản nháp. Câu RIÊNG
     * với {@see self::notDraft()} (dùng cho kích hoạt): câu đó nói "kích hoạt", câu này nói "sửa"
     * và chỉ đường đúng (phụ lục) cho một hợp đồng đã ký — hai việc khác nhau không nên dùng
     * chung một câu chỉ vì cùng chung điều kiện `status !== draft`.
     */
    public static function notDraftForUpdate(Contract $contract): self
    {
        return new self(__('billing.errors.contract_not_draft_for_update', self::describe($contract)));
    }

    public static function notActive(Contract $contract): self
    {
        return new self(__('billing.errors.contract_not_active', self::describe($contract)));
    }

    /**
     * `VoidPayment` trên hợp đồng `completed` (lượt rà soát cuối M9, C1): huỷ khoản thu ở đó mở lại
     * một khoản nợ mà mọi màn hình công nợ (chỉ đọc hợp đồng `active`) không thấy và
     * `RecordPayment` không thu được. Câu do controller chốt, không mang mã hợp đồng.
     */
    public static function voidOnCompleted(): self
    {
        return new self(__('billing.errors.payment_void_on_completed_contract'));
    }

    public static function hasUnsettled(Contract $contract, int $instalmentCount): self
    {
        return new self(__('billing.errors.contract_has_unsettled', [
            'code' => $contract->code,
            'count' => $instalmentCount,
        ]));
    }

    /** @return array<string, string> */
    private static function describe(Contract $contract): array
    {
        return ['code' => $contract->code, 'status' => $contract->status->label()];
    }
}
