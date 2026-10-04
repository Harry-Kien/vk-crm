<?php

namespace App\Support\Mcp;

use App\Models\MatterParty;
use LogicException;

/**
 * Tên của các bên trong một vụ việc khi ra khỏi hệ thống qua MCP (kế hoạch M11, R10).
 *
 * Bên thứ ba (bị đơn, người liên quan, luật sư bên kia…) không thể đồng ý cho dữ liệu của mình đi tới
 * một nhà cung cấp AI [PL:294], [PL:373]. Nên ở chế độ mặc định `pseudonym` (`MCP_PARTY_NAMES`):
 *  - bên có `is_our_client = true` ra bằng TÊN;
 *  - mọi bên còn lại ra bằng vai tố tụng + số thứ tự trong vai đó: "Bị đơn 1", "Bị đơn 2". Số thứ tự
 *    theo `id` tăng dần (thứ tự thêm vào vụ việc), nên ổn định giữa các lần gọi và không phụ thuộc
 *    thứ tự nơi gọi đưa vào. `is_our_client` không rõ (`null`, cột không được SELECT) là "không phải
 *    khách" — đóng cửa.
 * Chế độ `full` trả tên thật của mọi bên; chỉ đúng chuỗi `full` bật nó, mọi giá trị khác là
 * `pseudonym` (một lỗi gõ trong `.env` phải đóng cửa, cùng hướng với `MCP_MATTER_DEFAULT`).
 *
 * Nơi gọi phải đưa vào MỌI bên (chưa xoá mềm) của MỘT vụ việc: số thứ tự chỉ có nghĩa trong một vụ,
 * và thiếu một bên làm số của các bên sau lệch đi. Một bên bị xoá mềm sau đó làm số thứ tự của các bên
 * cùng vai sau nó dồn lên ở lần gọi kế tiếp — chấp nhận được: số thứ tự là một nhãn trong một câu trả
 * lời, không phải một định danh.
 *
 * **Giả danh ở đây KHÔNG phải khử nhận dạng** [PL:361]: tiêu đề vụ việc hay chứa tên đương sự, và mã
 * hồ sơ dẫn ngược về người thật. Không bao giờ trả số điện thoại, địa chỉ hay ghi chú của các bên —
 * việc đó là của presenter (`Presenters\PartyPresenter`), lớp này chỉ trả nhãn.
 */
final class PartyLabel
{
    public const PSEUDONYM = 'pseudonym';

    public const FULL = 'full';

    public static function mode(): string
    {
        return config('vkcrm.mcp.party_names') === self::FULL ? self::FULL : self::PSEUDONYM;
    }

    /**
     * Mỗi bên kèm nhãn của nó, theo `id` tăng dần.
     *
     * @param  iterable<MatterParty>  $parties
     * @return list<array{party: MatterParty, label: string, pseudonym: bool}>
     */
    public static function assign(iterable $parties): array
    {
        $sorted = collect($parties)->each(function (MatterParty $party): void {
            if ($party->getKey() === null) {
                throw new LogicException('PartyLabel: mỗi bên phải có id để số thứ tự ổn định.');
            }
        })->sortBy(fn (MatterParty $party) => (int) $party->getKey())->values();

        if ($sorted->pluck('matter_id')->map(fn ($id) => (int) $id)->unique()->count() > 1) {
            throw new LogicException('PartyLabel: các bên phải thuộc cùng một vụ việc.');
        }

        $pseudonymize = self::mode() === self::PSEUDONYM;
        $counters = [];

        return $sorted->map(function (MatterParty $party) use ($pseudonymize, &$counters): array {
            if (! $pseudonymize || $party->is_our_client === true) {
                return ['party' => $party, 'label' => (string) $party->name, 'pseudonym' => false];
            }

            $role = $party->role;
            $number = $counters[$role->value] = ($counters[$role->value] ?? 0) + 1;

            return [
                'party' => $party,
                'label' => __('mcp.party_pseudonym', ['role' => $role->label(), 'number' => $number]),
                'pseudonym' => true,
            ];
        })->all();
    }
}
