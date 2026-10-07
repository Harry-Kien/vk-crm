<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Support\Pwa\PwaPanels;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// Tên miền gốc dẫn thẳng tới portal khách hàng; panel nội bộ nằm ở /admin.
Route::redirect('/', '/portal');

/*
 * Đường duy nhất tới một tệp hồ sơ (SPEC §10.4). `signed` kiểm chữ ký TRƯỚC controller và trả
 * 403 khi chữ ký sai hoặc hết hạn — 403 ở đây nói về ĐƯỜNG DẪN, không về một bản ghi, nên nó
 * không mâu thuẫn với luật 404 của SPEC §10.10 (xem `AnswerDeniedPanelRequestsWithNotFound`).
 * Mọi từ chối còn lại nằm trong controller và đều là 404.
 *
 * Nằm ngoài cả hai panel một cách có chủ ý: cùng một tài liệu được cả nhân sự (guard `web`) và
 * khách (guard `client`) tải về, và hai panel dùng chung cookie phiên nên một route trung lập
 * phục vụ được cả hai mà không phải nhân đôi.
 *
 * `throttle:document-download` đếm theo TÀI KHOẢN — số lượt và lý do chọn nó nằm ở
 * `DocumentDownloadController::DOWNLOADS_PER_MINUTE`, bộ đếm đăng ký ở `AppServiceProvider`.
 * Đứng SAU `signed` là có chủ đích: một đường dẫn không chữ ký phải chết ở cửa rẻ nhất, và một
 * người bắn id bừa không được phép tiêu hết hạn mức của một tài khoản thật.
 */
Route::get('documents/{document}/download', DocumentDownloadController::class)
    ->middleware(['signed', 'throttle:document-download'])
    ->name('documents.download');

/*
 * M12 Task 3 — hai BÍ DANH của route trên, nằm TRONG scope của app trên điện thoại:
 * `/portal/documents/{document}/download` (`documents.download.portal`) và
 * `/admin/documents/{document}/download` (`documents.download.admin`). Phán quyết tạm 1 của Task 1
 * (`docs/research/2026-10-01-pwa-khao-sat.md` mục 3): trên iPhone, app đã cài mở một URL NGOÀI scope
 * trong trình duyệt trong app, và tài liệu không đủ chắc nó mang cookie phiên theo — thiếu cookie
 * thì mọi lượt tải là 404. Trong scope thì lượt tải ở lại cửa sổ app (cùng cookie).
 *
 * CÙNG controller, CÙNG middleware, cùng nhóm `web` — mọi luật ở trên (chữ ký gắn người nhận, 403
 * cho chữ ký, 404 cho mọi từ chối khác, nhật ký tải, giới hạn theo tài khoản dùng chung bộ đếm có
 * tên) đứng nguyên. KHÔNG thuộc middleware của panel: không `Authenticate` (không đăng nhập → 404,
 * không chuyển hướng cất URL đã ký vào phiên), không `AnswerDeniedPanelRequestsWithNotFound` (403
 * của chữ ký giữ nguyên).
 *
 * **Giới hạn IP của admin (M8 R7) phủ bí danh của panel nào mang nó** — việc sau gộp M12 (làn fu4,
 * mục 4), cùng luật `$ipGate` của `routes/pwa.php`: nhóm bí danh mang `RestrictAdminIpAllowlist` khi
 * và chỉ khi panel đó mang nó trong `getMiddleware()` (hôm nay `admin`, không phải `portal`), ĐỨNG
 * TRƯỚC `signed`. Bản M12 để bí danh nội bộ ngoài giới hạn (theo quyết định M8 cho route gốc), và
 * nó là path DUY NHẤT dưới `/admin` trả lời một IP ngoài danh sách — bằng trang 403 riêng của chữ
 * ký, tức "có một app nội bộ ở đây", trái lời hứa "máy lạ không biết `/admin` tồn tại". Không mất
 * đường tải nào của nhân sự: URL ký cho nhân sự chỉ được dựng trên trang của panel admin (nút tải
 * tệp, hộp duyệt giấy tờ), mà trang đó đã đứng sau cùng giới hạn này; không thư hay thông báo nào
 * mang URL tải. Cái giá đã chấp nhận: một đường dẫn mở trong văn phòng rồi bấm lại từ ngoài dải IP
 * trong 5 phút còn lại của nó nhận 404, như chính trang đã sinh ra nó. Route gốc `/documents/…` ở
 * trên GIỮ quyết định M8 (không giới hạn IP): không mã nào ký URL cho nó nữa, nhưng URL đã phát lúc
 * triển khai vẫn tải được.
 *
 * Nơi ký URL DUY NHẤT là `Document::downloadUrlFor()`, chọn bí danh theo KIỂU người nhận. Tiền tố và
 * tên miền theo panel, cùng khuôn vòng lặp của `routes/pwa.php` (một tên miền mỗi panel). Route gốc
 * ở trên được giữ: không mã nào còn ký URL cho nó, nhưng một URL đã phát (sống 5 phút) lúc triển
 * khai vẫn tải được, và các test của SPEC §10.4 đứng trên nó.
 */
foreach (PwaPanels::IDS as $panelId) {
    $panel = Filament::getPanel($panelId);

    $ipGate = in_array(RestrictAdminIpAllowlist::class, $panel->getMiddleware(), true)
        ? [RestrictAdminIpAllowlist::class]
        : [];

    foreach ((empty($panel->getDomains()) ? [null] : $panel->getDomains()) as $domain) {
        Route::domain($domain)
            ->prefix($panel->getPath())
            ->middleware($ipGate)
            ->group(function () use ($panelId): void {
                Route::get('documents/{document}/download', DocumentDownloadController::class)
                    ->middleware(['signed', 'throttle:document-download'])
                    ->name("documents.download.{$panelId}");
            });
    }
}
