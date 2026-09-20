<?php

use App\Http\Controllers\DocumentDownloadController;
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
