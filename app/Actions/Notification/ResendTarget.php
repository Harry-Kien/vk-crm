<?php

namespace App\Actions\Notification;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Bốn lời gọi vào MỘT mẫu thư gửi lại được (ba bắt buộc, `instanceKey` tuỳ chọn) — xem
 * {@see ResendTargets}. Không chứa luật nào: mỗi closure chỉ chuyển sang hàm CÓ SẴN của
 * Action/Job gốc của mẫu đó.
 *
 *  - `eligible`: những người đủ điều kiện nhận NGAY BÂY GIỜ (mọi cổng lúc-gửi của mẫu);
 *  - `delivered`: người đó đã có một dòng `sent` cho đúng thư này chưa (chống trùng của mẫu);
 *  - `send`: chạy đúng đường gửi thật của mẫu (Action `handle()` / Job `handle()`), tự tính lại
 *    mọi thứ và tự bỏ qua người đã nhận;
 *  - `instanceKey` (tuỳ chọn): khoá chống trùng "lần nào" CỦA CHÍNH mailable gốc, để biết dòng hỏng
 *    còn nói về đúng sự việc hiện tại không.
 */
final class ResendTarget
{
    /**
     * @param  string  $relatedType  Bí danh morph của bản ghi mà dòng nhật ký trỏ tới (`related_type`).
     * @param  Closure(Model): Collection<int, Model>  $eligible
     * @param  Closure(Model, Model): bool  $delivered
     * @param  Closure(Model): mixed  $send
     * @param  (Closure(Model): string)|null  $instanceKey  Khoá "lần nào" của bản ghi HIỆN TẠI, so với
     *                                                      `payload.tier` của dòng hỏng — chỉ cho mẫu mà
     *                                                      CÙNG một bản ghi sinh nhiều thư khác nhau theo
     *                                                      thời gian (`client.document_rejected`: mỗi lần
     *                                                      từ chối một thư). `null`: bản ghi chỉ có một thư.
     */
    public function __construct(
        public readonly string $relatedType,
        public readonly Closure $eligible,
        public readonly Closure $delivered,
        public readonly Closure $send,
        public readonly ?Closure $instanceKey = null,
    ) {}
}
