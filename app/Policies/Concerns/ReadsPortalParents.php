<?php

namespace App\Policies\Concerns;

use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Nạp bản ghi CHA của một model con, không để `ClientPortalScope` quyết định nó thấy gì.
 *
 * Mọi policy của model con đều kết thúc ở cùng một câu: "người này có thấy vụ việc cha không"
 * ({@see ChecksMatterAccess}). Để hỏi được câu đó thì trước hết phải NẠP bản ghi cha — và một
 * quan hệ nạp lười chạy dưới guard NÀO ĐANG MỞ, chứ không dưới `$user` mà policy đang trả lời
 * cho. Hệ quả đã đo được ở M4 (`SubmitClientDocument` phải `setRelation()` một quan hệ nạp sẵn
 * để đi vòng qua nó): với một phiên portal của khách hàng khác đang mở, `$reply->request` trả
 * `null`, và policy từ chối một người đáng lẽ được phép.
 *
 * Cái này KHÔNG nới một cái cổng nào. Quyền vẫn do hai thứ quyết định, cả hai đều độc lập với
 * guard: `ChecksPortalVisibility::visibleToPortal()` chạy lại scope thật qua
 * `ClientPortalScope::actingAs($user)`, và `MatterPolicy::view` hỏi lại từ đầu. Trait này chỉ
 * lo phần NẠP, để một câu từ chối không đến từ một quan hệ trả `null`.
 *
 * Quan hệ đã nạp sẵn thì tin: đó là đường mà `SubmitClientDocument` dùng khi nó tự đọc bản ghi
 * cha bằng một truy vấn không scope rồi `setRelation()`.
 */
trait ReadsPortalParents
{
    /**
     * @param  string  $relation  tên một quan hệ `BelongsTo` trên $child
     */
    protected function parentWithoutPortalScope(Model $child, string $relation): ?Model
    {
        if ($child->relationLoaded($relation)) {
            return $child->getRelation($relation);
        }

        /** @var Model|null $parent */
        $parent = $child->{$relation}()->withoutGlobalScope(ClientPortalScope::class)->first();

        return $parent;
    }

    /**
     * Một cột của CHÍNH dòng mà policy đọc trong một điều kiện PHỦ ĐỊNH (`voided_at === null`,
     * `status !== Cancelled`) — lượt quét §10 trước bản 1.0, minor m1 của M9 Task 10.
     *
     * Dự án không bật `preventAccessingMissingAttributes`, nên một dòng nạp bằng select hẹp (không có
     * cột đó) đọc ra `null`: "chưa huỷ" — cổng MỞ cho một khoản thu đã huỷ. Cột đã nạp thì tin (như
     * quan hệ đã nạp ở trên); cột thiếu thì đọc lại đúng cột đó, không qua scope nào (cùng tiền lệ
     * `ChecksBillingAccess::matterForBillingGate()`). Dòng không có khoá chính thì không đọc lại được:
     * phần tử đầu `false`, và nơi gọi phải từ chối.
     *
     * @return array{0: bool, 1: mixed} [đọc được hay không, giá trị đã cast]
     */
    protected function ownColumnForGate(Model $row, string $column): array
    {
        if (array_key_exists($column, $row->getAttributes())) {
            return [true, $row->getAttribute($column)];
        }

        if ($row->getKey() === null) {
            return [false, null];
        }

        $fresh = $row->newQueryWithoutScopes()->whereKey($row->getKey())->first([$row->getKeyName(), $column]);

        return $fresh === null ? [false, null] : [true, $fresh->getAttribute($column)];
    }
}
