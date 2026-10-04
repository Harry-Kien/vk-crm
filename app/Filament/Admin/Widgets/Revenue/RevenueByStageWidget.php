<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\InstalmentTrigger;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Filament\Admin\Widgets\Revenue\Concerns\RequiresBillingView;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Doanh thu ĐÃ THU theo từng đợt/giai đoạn — cột ngang, xếp theo THỨ TỰ GIAI ĐOẠN của loại vụ
 * việc (thứ tự thời gian chính là thông tin, cùng thành ngữ `MattersByStageWidget`). Trả lời: văn
 * phòng đang kẹt tiền ở khúc nào của quy trình.
 *
 * **Chỉ đợt `trigger_type = stage` gộp được theo giai đoạn.** Task 6 (đợt theo giai đoạn tự kích
 * hoạt) bị hoãn ở làn này, nên nhiều đợt `stage` vẫn có thể ở trạng thái `scheduled` (chưa có
 * `due_date`) — KHÔNG ảnh hưởng widget này, vì nó gộp theo KHOẢN THU đã về (`payments`), không
 * theo trạng thái đợt. Đợt `on_signing`/`due_date` không gắn với một giai đoạn nào — CHÚNG ĐI VÀO
 * hai "bó" riêng, có nhãn rõ ràng ("Tạm ứng khi ký hợp đồng", "Đến hạn theo ngày cụ thể"), KHÔNG bị
 * bỏ sót và KHÔNG bị gộp lẫn vào một giai đoạn nào chúng không thuộc về.
 *
 * **Gộp theo (matter_type, stage) BẰNG ID, không theo nhãn** (Fix round 1, I3) — cùng lý do
 * `MattersByStageWidget`: nhãn giai đoạn không duy nhất toàn hệ thống, chỉ duy nhất trong một loại
 * vụ việc. Bản trước dùng NHÃN đã dịch làm khoá của mảng kết quả — hai giai đoạn (của hai loại vụ
 * việc khác nhau, hoặc một giai đoạn đã đổi tên rồi một giai đoạn MỚI trùng tên cũ) đè lên nhau nếu
 * nhãn hiển thị trùng. Sửa: `buckets()` trả về một DANH SÁCH có thứ tự (khoá số, không phải mảng
 * kết hợp theo nhãn), mỗi phần tử tự mang `key` (chuỗi ổn định: `stage:{id giai đoạn}` hoặc
 * `on_signing`/`due_date`) và `label` hiển thị riêng — hai bó trùng nhãn vẫn là hai PHẦN TỬ khác
 * nhau trong mảng dữ liệu Chart.js, không mất bó nào.
 *
 * **JOIN cả giai đoạn đã xoá mềm** (Fix round 1, I3): câu hỏi của widget này là LỊCH SỬ ("tiền đã
 * về ở khúc nào") — một giai đoạn bị quản trị viên xoá mềm SAU KHI tiền đã về đó không được phép
 * làm khoản tiền đó biến mất khỏi báo cáo.
 *
 * # Fix round 2 (Important) — ĐÚNG MỘT DÒNG mỗi (matter_type_id, key), không phải mọi dòng khớp
 *
 * Round 1 join thẳng `matter_type_stages` theo `(matter_type_id, key)` KHÔNG lọc `deleted_at` —
 * đúng cho trường hợp MỘT giai đoạn đã xoá mềm, nhưng SAI khi có NHIỀU dòng cùng `(matter_type_id,
 * key)`: `MatterTypeStage::booted()` chỉ chặn trùng trong số dòng CÒN SỐNG (`static::query()` đã
 * tự loại xoá mềm), nên "xoá mềm giai đoạn K rồi tạo giai đoạn K mới" hay "xoá — tạo lại — xoá lần
 * nữa" đều để lại NHIỀU dòng trùng `key` trong DB. JOIN không lọc sẽ khớp CẢ HAI (hay CẢ BA), và
 * một khoản thu bị đếm vào NHIỀU bucket cùng lúc — tổng theo bucket vượt quá tổng thật.
 *
 * Sửa: JOIN qua một BẢNG DẪN XUẤT (derived table) chọn ĐÚNG MỘT `id` đại diện cho mỗi
 * `(matter_type_id, key)` — `coalesce(min(id) trong số dòng CÒN SỐNG, max(id) trong số MỌI dòng)`:
 * còn ít nhất một dòng sống thì lấy dòng sống (id nhỏ nhất, dù ràng buộc ứng dụng đảm bảo chỉ có
 * đúng một); không còn dòng sống nào (đã xoá — tạo lại — xoá nữa, để lại toàn dòng xoá mềm) thì lấy
 * dòng MỚI NHẤT (`max(id)`) trong số các dòng xoá mềm đó — luôn đúng MỘT hàng, không bao giờ hai.
 *
 * `leftJoinSub` (KHÔNG join thường): một đợt `stage` mà `trigger_stage_key` không khớp ĐƯỢC dòng
 * nào trong bảng dẫn xuất (giai đoạn đã bị XOÁ CỨNG — `forceDelete()` — hoặc vụ việc đã đổi sang
 * loại khác không còn key đó) vẫn phải được ĐẾM, chỉ là không biết thuộc giai đoạn nào — nó rơi vào
 * bó riêng "Giai đoạn không còn trong cấu hình" (`unknown_stage_bucket`), KHÔNG bị bỏ sót.
 *
 * **Thời gian lọc `payments.paid_on`** ("tiền về trong kỳ"); **luật sư lọc
 * `payments.attributed_lawyer_id`** ("luật sư phụ trách lúc thu") — cùng nghĩa với
 * `RevenueOverTimeWidget`, vì đây cũng là một biểu đồ tiền ĐÃ THU.
 *
 * Cột một chuỗi, một màu `#4a73bd`, không chú giải.
 */
class RevenueByStageWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;
    use RequiresBillingView;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** Tránh tính hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc (Fix round 1). */
    private ?array $bucketsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.by_stage.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.by_stage.description', [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function numberTableRows(): array
    {
        return collect($this->buckets())
            ->map(fn (array $row): array => ['label' => $row['label'], 'value' => Money::format($row['amount'])])
            ->values()
            ->all();
    }

    protected function getData(): array
    {
        $buckets = $this->buckets();

        return [
            'labels' => array_column($buckets, 'label'),
            'datasets' => [
                [
                    'label' => __('widgets.revenue_dashboard.by_stage.series'),
                    'data' => array_column($buckets, 'amount'),
                    'backgroundColor' => '#4a73bd',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        ];
    }

    /**
     * Theo thứ tự giai đoạn, rồi ba bó "khác": giai đoạn không còn trong cấu hình, tạm ứng khi ký,
     * đến hạn theo ngày (M9 Task 13 sửa chữ "hai bó" cũ — bó thứ ba có từ Fix round 2).
     *
     * @return list<array{key: string, label: string, amount: int}>
     */
    private function buckets(): array
    {
        return $this->bucketsCache ??= $this->computeBuckets();
    }

    /** @return list<array{key: string, label: string, amount: int}> */
    private function computeBuckets(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        [$from, $to] = $filters->bounds();

        // Subquery, KHÔNG `->pluck('id')` (Fix round 1, minor): `Matter::scopeListableBy()` vẫn
        // là MỘT định nghĩa duy nhất (P3), nhưng giữ nó ở dạng SQL con thay vì kéo danh sách id về
        // PHP rồi nhồi vào `whereIn` — tránh một danh sách id dài tràn ra một câu SQL khổng lồ khi
        // văn phòng có nhiều vụ việc.
        $matterIdsQuery = Matter::query()
            ->listableBy($user)
            ->when($filters->practiceAreaId, fn ($q, int $v) => $q->where('matter_type_id', $v))
            ->select('id');

        $base = fn (): Builder => DB::table('payments')
            ->join('instalments', 'instalments.id', '=', 'payments.instalment_id')
            ->join('contracts', 'contracts.id', '=', 'instalments.contract_id')
            ->whereIn('contracts.matter_id', $matterIdsQuery)
            ->whereNull('payments.voided_at')
            ->whereBetween('payments.paid_on', [$from, $to])
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('payments.attributed_lawyer_id', $v));

        // Bảng dẫn xuất: ĐÚNG MỘT `id` cho mỗi (matter_type_id, key) — xem docblock lớp, "Fix round
        // 2". Hàm tạo lại builder mỗi lần gọi (không dùng chung một instance), cùng thành ngữ
        // `$base`/`$matterIdsQuery` bên trên — `leftJoinSub()` chỉ ĐỌC `toSql()`/`getBindings()` của
        // đối số nên dùng lại instance vốn an toàn, nhưng tạo mới cho rõ ràng và nhất quán.
        $stagePick = fn () => DB::table('matter_type_stages')
            ->select('matter_type_id', 'key')
            ->selectRaw('coalesce(min(case when deleted_at is null then id end), max(id)) as stage_id')
            ->groupBy('matter_type_id', 'key');

        // `leftJoinSub`, KHÔNG join thường (Fix round 2): một đợt `stage` không khớp được dòng nào
        // trong bảng dẫn xuất (giai đoạn đã xoá CỨNG, hoặc vụ việc đổi loại) vẫn phải ĐẾM — nó rơi
        // vào bó "không còn trong cấu hình" bên dưới, không bị bỏ sót.
        $stageJoinBase = fn (): Builder => $base()
            ->join('matters', 'matters.id', '=', 'contracts.matter_id')
            ->leftJoinSub($stagePick(), 'stage_pick', function ($join): void {
                $join->on('stage_pick.matter_type_id', '=', 'matters.matter_type_id')
                    ->on('stage_pick.key', '=', 'instalments.trigger_stage_key');
            })
            ->where('instalments.trigger_type', InstalmentTrigger::Stage->value);

        $stageRows = $stageJoinBase()
            ->whereNotNull('stage_pick.stage_id')
            ->join('matter_type_stages', 'matter_type_stages.id', '=', 'stage_pick.stage_id')
            ->join('matter_types', 'matter_types.id', '=', 'matter_type_stages.matter_type_id')
            // matter_types.id trong GROUP BY (Fix round 2, minor): orderBy đọc nó ngay bên dưới, và
            // MariaDB (ONLY_FULL_GROUP_BY) đòi mọi cột ORDER BY không phải hàm tổng hợp phải có mặt
            // ở GROUP BY hoặc phụ thuộc hàm số vào một cột đã có — matter_type_stages.id (khoá
            // chính) đã đủ để suy ra matter_types.id theo lý thuyết, nhưng MariaDB không tự suy luận
            // qua một JOIN, nên liệt kê tường minh.
            ->groupBy(
                'matter_type_stages.id', 'matter_types.id', 'matter_types.name', 'matter_types.sort_order',
                'matter_type_stages.label', 'matter_type_stages.sort_order',
            )
            ->orderBy('matter_types.sort_order')
            ->orderBy('matter_types.id')
            ->orderBy('matter_type_stages.sort_order')
            ->selectRaw('matter_type_stages.id as stage_id, matter_types.name as type_name, matter_type_stages.label as stage_label, sum(payments.amount) as total')
            ->get();

        // Bó "không còn trong cấu hình" — MỘT bó gộp, không chia theo loại vụ việc (không còn dòng
        // matter_type_stages nào để đọc tên/nhãn từ đó).
        $unknownStageTotal = (int) ($stageJoinBase()
            ->whereNull('stage_pick.stage_id')
            ->sum('payments.amount') ?? 0);

        $catchAllRows = $base()
            ->whereIn('instalments.trigger_type', [InstalmentTrigger::OnSigning->value, InstalmentTrigger::DueDate->value])
            ->groupBy('instalments.trigger_type')
            ->selectRaw('instalments.trigger_type as trigger_type, sum(payments.amount) as total')
            ->get()
            ->keyBy('trigger_type');

        $buckets = [];

        foreach ($stageRows as $row) {
            $buckets[] = [
                'key' => 'stage:'.$row->stage_id,
                'label' => __('widgets.revenue_dashboard.by_stage.bucket_label', [
                    'type' => $row->type_name,
                    'stage' => $row->stage_label,
                ]),
                'amount' => (int) $row->total,
            ];
        }

        if ($unknownStageTotal > 0) {
            $buckets[] = [
                'key' => 'unknown_stage',
                'label' => __('widgets.revenue_dashboard.by_stage.unknown_stage_bucket'),
                'amount' => $unknownStageTotal,
            ];
        }

        if ($onSigning = $catchAllRows->get(InstalmentTrigger::OnSigning->value)) {
            $buckets[] = [
                'key' => 'on_signing',
                'label' => __('widgets.revenue_dashboard.by_stage.on_signing_bucket'),
                'amount' => (int) $onSigning->total,
            ];
        }

        if ($dueDate = $catchAllRows->get(InstalmentTrigger::DueDate->value)) {
            $buckets[] = [
                'key' => 'due_date',
                'label' => __('widgets.revenue_dashboard.by_stage.due_date_bucket'),
                'amount' => (int) $dueDate->total,
            ];
        }

        return $buckets;
    }
}
