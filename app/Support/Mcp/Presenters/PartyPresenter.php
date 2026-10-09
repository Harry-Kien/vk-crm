<?php

namespace App\Support\Mcp\Presenters;

use App\Models\MatterParty;
use App\Support\Mcp\PartyLabel;

/**
 * Các bên của một vụ việc trong kết quả MCP (kế hoạch M11, R10): vai tố tụng, nhãn từ
 * {@see PartyLabel} (tên của khách văn phòng, "Bị đơn 1" cho bên còn lại ở chế độ mặc định), có phải
 * khách của văn phòng không, và nhãn có phải tên giả không — để AI không trình bày "Bị đơn 1" như một
 * cái tên. Không bao giờ số điện thoại, địa chỉ, ghi chú, `id_number_hash` hay `phone_normalized`
 * của một bên (R4, R10), và không có id: không tool nào nhận id một bên.
 */
final class PartyPresenter
{
    public const FIELDS = ['role', 'role_label', 'label', 'is_our_client', 'is_pseudonym'];

    /**
     * Mọi bên của MỘT vụ việc, theo thứ tự thêm vào (xem {@see PartyLabel::assign()}).
     *
     * @param  iterable<MatterParty>  $parties
     * @return list<array{role: string, role_label: string, label: string, is_our_client: bool, is_pseudonym: bool}>
     */
    public static function presentAll(iterable $parties): array
    {
        return array_map(fn (array $row): array => [
            'role' => $row['party']->role->value,
            'role_label' => $row['party']->role->label(),
            'label' => $row['label'],
            'is_our_client' => $row['party']->is_our_client === true,
            'is_pseudonym' => $row['pseudonym'],
        ], PartyLabel::assign($parties));
    }
}
