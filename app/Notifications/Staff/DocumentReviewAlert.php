<?php

namespace App\Notifications\Staff;

use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Jobs\SendDocumentReviewNotice;
use App\Models\Document;
use App\Support\MatterTab;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

/**
 * Chuông của vòng trình duyệt văn bản nội bộ nhóm B (làn fa2, phán quyết 7) — chỉ {@see SendDocumentReviewNotice}
 * gửi, sau khi đã lọc người nhận (đang hoạt động, còn mở được hồ sơ — và với lời "trình duyệt", còn duyệt được văn
 * bản), nên tên văn bản và mã hồ sơ được phép hiện. Không tên khách. Ba loại:
 *
 *  - {@see self::SUBMITTED}: gửi người duyệt — một văn bản vừa được trình, chờ duyệt;
 *  - {@see self::APPROVED}: gửi người đã trình — văn bản đã được đánh dấu "Đã ký, đã nộp";
 *  - {@see self::RETURNED}: gửi người đã trình — văn bản bị trả về bản nháp, kèm lý do.
 *
 * Kênh `database`, không `ShouldQueue`, `e()` mọi giá trị nội suy (tên văn bản, lý do là chữ người dùng gõ). Nút
 * "Mở" về tab "Tài liệu" của hồ sơ. `viewData.document_review_key` là khoá chống trùng của job.
 */
class DocumentReviewAlert extends Notification
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public function __construct(
        private readonly Document $document,
        private readonly string $kind,
        private readonly string $actorName,
        private readonly string $key,
        private readonly ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $warning = $this->kind === self::RETURNED;

        return [
            'actions' => [
                Action::make('open')
                    ->label(__('internal_flow.document_review.open'))
                    ->url(MatterTab::url((int) $this->document->matter_id, DocumentsRelationManager::class))
                    ->markAsRead()
                    ->toArray(),
            ],
            'body' => __("internal_flow.document_review.{$this->kind}.body", [
                'title' => e($this->document->title),
                'code' => e((string) $this->document->matter?->code),
                'who' => e($this->actorName),
                'reason' => e((string) $this->reason),
            ]),
            'color' => $warning ? 'warning' : 'info',
            'duration' => 'persistent',
            'icon' => null,
            'iconColor' => null,
            'status' => $warning ? 'warning' : 'info',
            'title' => __("internal_flow.document_review.{$this->kind}.title"),
            'view' => null,
            'viewData' => ['document_id' => $this->document->getKey(), 'document_review_key' => $this->key],
            'format' => 'filament',
        ];
    }
}
