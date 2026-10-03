<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Exceptions\HandoverPackageFailed;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\StageLog;
use App\Support\Billing\ClientBillingStatement;
use App\Support\BrandFooter;
use App\Support\Handover\HandoverEntry;
use App\Support\OfficeProfile;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * M7 Task 4, R3 — dựng `MUC-LUC.pdf` của gói bàn giao bằng dompdf (thuần PHP, không Chromium/Node:
 * ràng buộc shared hosting). Trả về NỘI DUNG PDF dạng chuỗi nhị phân; ghi ra tệp là việc của
 * {@see BuildHandoverPackage}.
 *
 * # Nội dung — đúng năm khối, không hơn
 *
 *  1. Thông tin vụ việc: mã, tên, khách hàng, loại, luật sư phụ trách, ngày mở, ngày kết thúc,
 *     giai đoạn cuối. KHÔNG mức độ bảo mật, phí, ghi chú nội bộ hay đội ngũ (tiền của vụ chỉ đi
 *     qua khối 5, đúng những gì khách thấy trên cổng).
 *  2. Danh sách tài liệu đánh số — CÙNG danh sách và số thứ tự với tên entry trong zip
 *     ({@see HandoverEntry}), vì cả hai nhận đúng một danh sách do {@see CollectHandoverEntries}.
 *  3. TOÀN BỘ dòng tiến độ ĐÃ CÔNG BỐ (`is_published`), cũ trước mới sau, với đúng những trường mà
 *     cổng khách hàng hiện (giai đoạn, ngày, nội dung công khai, bước tiếp theo, việc khách cần
 *     làm). Dòng chưa công bố — kể cả dòng bàn giao nội bộ của `ReassignMatter` (`is_published`
 *     = false) — không vào đây, và **cột `internal_note` không bao giờ được nạp**: truy vấn chọn
 *     cột tường minh, nên chuỗi trong `internal_note` không có trong bộ nhớ khi Blade chạy chứ
 *     không chỉ "không được in".
 *  4. Chân trang: tên văn phòng và bốn thông tin pháp lý khi đã có ({@see BrandFooter}, bỏ hẳn
 *     dòng trống). Cả hai đọc qua {@see OfficeProfile} (M7 Task 10: trang "Thông tin văn phòng" →
 *     bảng `settings` → cấu hình) LÚC DỰNG, từ cùng một đối tượng — gói sinh sau một lần sửa mang
 *     giá trị mới.
 *  5. "Bảng kê thanh toán" (M9 Task 10, P1) — hợp đồng đã ký, các đợt chưa huỷ, các khoản đã nhận
 *     chưa huỷ, với ĐÚNG những trường khối tiền của cổng khách vẽ; vắng hẳn khi vụ không có hợp
 *     đồng khách được thấy. Xem {@see self::billingStatement()}.
 *
 * Mỗi khối là một partial trong `resources/views/handover/partials/`; thêm một khối là một partial +
 * một `@include` trong `handover/index.blade.php` + một khoá dữ liệu mới trong mảng `loadView()`
 * dưới đây — xem mục "Mở rộng" của view đó. Khối 5 được thêm đúng theo cách ấy.
 *
 * # Tiếng Việt trong PDF (R3)
 *
 * dompdf mặc định dùng font Helvetica/Times không có dấu tiếng Việt. View ép `DejaVu Sans` (đi
 * kèm dompdf, đủ dấu) và `defaultFont` đặt cùng font làm dự phòng. Thư mục cache font là
 * `storage/app/dompdf-fonts`, tạo nếu chưa có (dompdf ghi số đo glyph vào đó) — `storage/app` đã
 * nằm ngoài git. `enable_remote` = false: mục lục không cần tải gì từ mạng, và bật nó cho một
 * tài liệu chứa văn bản do người dùng nhập là mở cửa cho SSRF.
 */
class RenderHandoverIndex
{
    use ReadsWithoutPortalScope;

    /**
     * @param  Collection<int, HandoverEntry>  $entries
     */
    public function handle(Matter $matter, Collection $entries): string
    {
        $fontDirectory = storage_path('app/dompdf-fonts');

        try {
            File::ensureDirectoryExists($fontDirectory);

            $matter->loadMissing(['client', 'matterType', 'leadLawyer']);

            $office = OfficeProfile::current();

            // `dompdf.wrapper` được gói đăng ký bằng `bind()` (không `singleton()`): mỗi lần gọi là
            // một wrapper MỚI với một `Dompdf` mới, nên tuỳ chọn đặt dưới đây không lọt sang lần dựng
            // sau trong cùng một worker. (Facade `Pdf` của gói cũng tự resolve mới ở mỗi lời gọi
            // tĩnh; gọi thẳng container chỉ để điều đó đọc được ngay tại đây.) `mergeWithDefaults`
            // = true giữ các tuỳ chọn còn lại của cấu hình `dompdf.options` — gồm `chroot` và
            // `allowed_protocols`; repo không publish `config/dompdf.php`, nên đó là tệp cấu hình
            // của chính gói (nạp qua `mergeConfigFrom`). Bản không merge thay TOÀN BỘ tuỳ chọn.
            /** @var PDF $pdf */
            $pdf = app('dompdf.wrapper');

            return $pdf->setOptions([
                'default_font' => 'DejaVu Sans',
                'font_dir' => $fontDirectory,
                'font_cache' => $fontDirectory,
                // Nhúng NGUYÊN font (mặc định của gói barryvdh, viết ra để không ai bật nhầm): cắt
                // font (`true`) tốn ~2,5 giây CPU mỗi lần dựng vì php-font-lib phải phân tích cả tệp
                // TTF 750 KB, để tiết kiệm ~440 KB trong một gói vài chục MB. Đo trong container.
                'enable_font_subsetting' => false,
                'enable_remote' => false,
                'enable_php' => false,
            ], true)
                ->loadView('handover.index', [
                    'officeName' => (string) $office->legalName(),
                    'legalLines' => BrandFooter::legalLines($office),
                    'generatedAt' => now()->format('d/m/Y'),
                    'matterInfo' => $this->matterInfo($matter),
                    'entries' => $entries,
                    'timeline' => $this->timeline($matter),
                    'billing' => $this->billingStatement($matter),
                ])
                ->setPaper('a4')
                ->output();
        } catch (Throwable $exception) {
            throw HandoverPackageFailed::indexFailed($exception);
        }
    }

    /**
     * @return array<string, string>
     */
    private function matterInfo(Matter $matter): array
    {
        $stage = $this->stageLabel($matter, $matter->stage);

        $info = [
            __('handover.pdf.matter.code') => (string) $matter->code,
            __('handover.pdf.matter.title') => (string) $matter->title,
            __('handover.pdf.matter.client') => (string) ($matter->client?->name ?? ''),
            __('handover.pdf.matter.type') => (string) ($matter->matterType?->name ?? ''),
            __('handover.pdf.matter.lead_lawyer') => (string) ($matter->leadLawyer?->name ?? ''),
            __('handover.pdf.matter.opened_at') => (string) $matter->opened_at?->format('d/m/Y'),
            __('handover.pdf.matter.closed_at') => (string) $matter->closed_at?->format('d/m/Y'),
            __('handover.pdf.matter.stage') => (string) $stage,
        ];

        // Dòng không có giá trị thì bỏ hẳn, không in nhãn cụt (cùng luật với chân trang).
        return array_filter($info, fn (string $value): bool => $value !== '');
    }

    /**
     * @return Collection<int, array{date: string, stage: string, public_content: ?string, next_step: ?string, client_action: ?string}>
     */
    private function timeline(Matter $matter): Collection
    {
        // Chọn cột TƯỜNG MINH: `internal_note` không có trong danh sách này, nên không bao giờ
        // được nạp — xem docblock lớp, khối 3.
        return $this->scopelessly(StageLog::query())
            ->where('matter_id', $matter->getKey())
            ->where('is_published', true)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'matter_id', 'to_stage', 'occurred_at', 'public_content', 'next_step', 'client_action'])
            ->map(fn (StageLog $log): array => [
                'date' => $log->occurred_at->format('d/m/Y'),
                'stage' => $this->stageLabel($matter, $log->to_stage),
                'public_content' => $log->public_content,
                'next_step' => $log->next_step,
                'client_action' => $log->client_action,
            ]);
    }

    /**
     * Khối 5 — "Bảng kê thanh toán" (M9 Task 10, P1): ĐÚNG hình chiếu khối "Hợp đồng và thanh
     * toán" của cổng khách vẽ ({@see ClientBillingStatement::present()}), cùng chữ, cùng dòng.
     * `null` khi vụ không có hợp đồng khách được thấy — khi đó mục lục không có mục này.
     *
     * **Tự lọc, vì không có phiên cổng.** Gói dựng trong một job, nên `ClientPortalScope` không chạy
     * và mọi truy vấn ở đây gỡ nó tường minh (`scopelessly()`). Luật "bản ghi nào khách được thấy"
     * đến từ đúng các scope mà tầng truy vấn của cổng dùng — `Contract::scopeShownToClient()` (đã
     * ký), `Instalment::scopeShownToClient()` (chưa huỷ), `Payment::scopeShownToClient()` (chưa huỷ)
     * — nên cổng và gói không lệch nhau về TẬP DÒNG (có test so `toBe` hai bên trên cùng dữ liệu).
     * Ranh giới VỤ VIỆC của cổng (đã công bố, chưa hết hạn tra cứu…) cố ý KHÔNG áp ở đây: gói bàn
     * giao là thứ văn phòng giao cho khách của vụ đã kết thúc, độc lập với cổng — cùng luật với
     * mọi khối khác của mục lục.
     *
     * **Chọn cột tường minh** (tiền lệ {@see self::timeline()}): `note`, `ended_reason`,
     * `waived_reason`, `void_reason`, `reference`, `receipt_document_id`, `attributed_lawyer_id`…
     * không bao giờ được nạp, nên chúng không có trong bộ nhớ khi Blade chạy. Bản scan biên lai và
     * phụ lục là nhóm D, không vào zip ({@see CollectHandoverEntries}).
     *
     * `public` để test so được hình chiếu của gói với hình chiếu của cổng trên cùng dữ liệu; nó chỉ
     * đọc.
     *
     * @return array<string, mixed>|null
     */
    public function billingStatement(Matter $matter): ?array
    {
        $contract = $this->scopelessly(Contract::query())
            ->shownToClient()
            ->where('matter_id', $matter->getKey())
            ->first(['id', 'matter_id', 'code', 'status', 'total_amount', 'vat_rate_percent', 'signed_at', 'ended_at']);

        if ($contract === null) {
            return null;
        }

        $instalments = $this->scopelessly(Instalment::query())
            ->shownToClient()
            ->where('contract_id', $contract->getKey())
            ->orderBy('sequence')
            ->get(['id', 'contract_id', 'sequence', 'name', 'amount', 'trigger_type', 'trigger_stage_key', 'due_days_after_trigger', 'due_date', 'status']);

        $payments = $this->scopelessly(Payment::query())
            ->shownToClient()
            ->whereIn('instalment_id', $instalments->modelKeys())
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get(['id', 'instalment_id', 'amount', 'paid_on', 'method']);

        return ClientBillingStatement::present($matter, $contract, $instalments, $payments);
    }

    /**
     * Nhãn giai đoạn in trong mục lục — mục lục là thứ GIAO CHO KHÁCH, nên đó là `client_label`
     * (SPEC §4.5 "Nhãn hiển thị cho khách"), cùng nhãn cổng khách hàng hiện
     * (`MatterProgress::stageLabel()`), không phải `label` nội bộ. Giai đoạn đã xoá mềm vẫn có nhãn
     * (`stageIncludingTrashed()`); khoá không còn khai báo ở loại vụ việc thì in nguyên khoá, như
     * trước.
     *
     * M7 Task 11: `stage_logs.to_stage` cho phép NULL (SPEC §4.8 — dòng cập nhật không ghi giai
     * đoạn đích; dữ liệu mẫu có những dòng như vậy), và `stageIncludingTrashed(string $key)` nhận
     * NULL thì ném TypeError: mục lục hỏng và gói của vụ thất bại. Khoá rỗng nghĩa là không có nhãn
     * (chuỗi rỗng — view chỉ in ngày, khối thông tin bỏ hẳn dòng "giai đoạn").
     */
    private function stageLabel(Matter $matter, ?string $key): string
    {
        if (blank($key)) {
            return '';
        }

        return (string) ($matter->matterType?->stageIncludingTrashed($key)?->client_label ?? $key);
    }
}
