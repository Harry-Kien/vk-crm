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
}
