<?php

namespace App\Support\Billing;

use App\Actions\Intake\ConvertIntakeToMatter;
use App\Models\IntakeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * "Hồ sơ do nhân viên kinh doanh này đem về" — MỘT định nghĩa cho bộ lọc "Nhân viên kinh doanh" của trang
 * Doanh thu và cho biểu đồ "Doanh thu theo người" ở chế độ kinh doanh (chủ văn phòng 2026-10-21; phán quyết
 * controller 2026-10-21, chờ chủ văn phòng xác nhận).
 *
 * **Luật.** Một hồ sơ thuộc về nhân viên kinh doanh X khi nó được mở từ một bản ghi tiếp nhận
 * (`intake_requests.matter_id` = hồ sơ) mà X là người được giao xử lý (`intake_requests.assigned_to`). Hồ sơ
 * không đến từ tiếp nhận (mở thẳng) hay bản ghi không giao cho ai thì không thuộc nhân viên kinh doanh nào.
 * MỌI số tiền của hồ sơ đó tính cho X — đã thu, còn phải thu, quá hạn, hợp đồng ký mới, theo giai đoạn, theo
 * lĩnh vực, hồ sơ đã đóng còn nợ: khác luật sư (đã thu theo người phụ trách LÚC THU), vì nhân viên kinh doanh
 * không cầm hồ sơ sau khi đem về — công của họ là đem hồ sơ về.
 *
 * **Vì sao `assigned_to` hiện tại, không một cột "người xử lý lúc chuyển đổi".** Không cần chụp lại: kể từ
 * lúc chuyển thành hồ sơ ({@see ConvertIntakeToMatter}, đặt `matter_id` và `won` trong cùng transaction với
 * hồ sơ mới) bản ghi KHÔNG SỬA ĐƯỢC NỮA với bất kỳ ai — {@see IntakeRequest::isClosedToChanges()} là cổng của
 * mọi Action ghi (`UpdateIntakeIdentity` từ chối với "record_final"), không bàn giao hay nghỉ việc nào chuyển
 * bản ghi tiếp nhận, và không câu UPDATE thẳng nào đụng `assigned_to`. Nên `assigned_to` của một bản ghi đã
 * có `matter_id` CHÍNH LÀ người xử lý lúc chuyển đổi. Nhật ký `intake_converted` chỉ mang `matter_id`,
 * `client_id`, `client_created` (causer là người BẤM chuyển đổi — thường là luật sư, không phải người đem về),
 * nên không phải mốc tốt hơn. Không migration.
 *
 * **Một hồ sơ, nhiều nhất một bản ghi**: `intake_requests.matter_id` là unique (bản đã gộp vào bản chuyển đổi
 * mang `merge_chain_matter_id`, không `matter_id`), nên phép nối không nhân đôi khoản thu nào.
 *
 * **Đọc thẳng bảng** (`DB::table`), không qua model: `IntakeRequest` mang `ClientPortalScope` (đóng kín với
 * cổng khách) và `SoftDeletes`; con số tiền không được phụ thuộc phiên nào đang mở, và một bản ghi (không bao
 * giờ bị xoá theo policy) có xoá mềm cũng không làm tiền đã về đổi chủ. Tầm nhìn KHÔNG nằm ở đây: mọi truy vấn
 * gắn điều kiện này lên một tập hồ sơ ĐÃ qua `Matter::scopeListableBy()` (P3), nên vụ hạn chế không lọt qua.
 */
final class SalesAttribution
{
    /** Id các hồ sơ (kể cả đã huỷ) mà `$salesId` đem về — câu SQL con cho `whereIn`. */
    public static function matterIdsBroughtInBy(int $salesId): QueryBuilder
    {
        return DB::table('intake_requests')
            ->whereNotNull('intake_requests.matter_id')
            ->where('intake_requests.assigned_to', $salesId)
            ->select('intake_requests.matter_id');
    }

    /**
     * Thu hẹp một truy vấn HỒ SƠ (`Matter`) về các hồ sơ do `$salesId` đem về; `null` thì để nguyên.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $matters
     * @return TBuilder
     */
    public static function constrainMatters(Builder $matters, ?int $salesId): Builder
    {
        if ($salesId === null) {
            return $matters;
        }

        return $matters->whereIn($matters->getModel()->qualifyColumn('id'), self::matterIdsBroughtInBy($salesId));
    }

    /**
     * Câu SQL con, tương quan với `payments.instalment_id`: nhân viên kinh doanh đã đem về hồ sơ của khoản thu
     * này (null nếu hồ sơ không đến từ tiếp nhận hay bản ghi không giao cho ai). Cho "Doanh thu theo người".
     */
    public static function salesIdOfPayment(): QueryBuilder
    {
        return DB::table('intake_requests')
            ->join('contracts', 'contracts.matter_id', '=', 'intake_requests.matter_id')
            ->join('instalments', 'instalments.contract_id', '=', 'contracts.id')
            ->whereColumn('instalments.id', 'payments.instalment_id')
            ->select('intake_requests.assigned_to')
            ->limit(1);
    }
}
