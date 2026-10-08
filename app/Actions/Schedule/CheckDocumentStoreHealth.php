<?php

namespace App\Actions\Schedule;

use App\Actions\Backup\ResolveBackupNotificationRecipients;
use App\Actions\Storage\InspectDriveSharing;
use App\Actions\Storage\MeasureDocumentStore;
use App\Enums\DocumentStoreStatus;
use App\Enums\OutboundStatus;
use App\Enums\PreflightLevel;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Mail\Staff\DocumentStoreAlert;
use App\Models\OutboundMessage;
use App\Models\SystemHealth;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveDiagnosticClient;
use App\Support\Storage\TransferDossier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Kiểm tra sức khoẻ kho tài liệu, mỗi giờ (mục lịch `storage.health`; kế hoạch M14, Task 5, R5, R9,
 * R10, R13). Ghi kết quả vào dòng duy nhất của `system_health` (`document_store_status`,
 * `document_store_checked_at`, `document_store_detail`) — trang chủ admin (`SystemHealthWidget`) và
 * trang "Kho tài liệu" đọc lại — và gửi thư `staff.document_store_alert.<loại>` cho người vận hành.
 *
 * # Khi nào hỏi Google
 *
 * Kho "đang dùng" = công tắc `google_drive`, HOẶC còn media trên kho (sau quay lui, chúng vẫn được tải từ
 * kho). Chỉ khi đó mới chạy {@see InspectDriveSharing} (chia sẻ + thư mục gốc) và xét tồn đọng, mốc
 * bật, bản ở văn phòng. Kho không dùng (mọi máy chủ trước M14): trạng thái để `NULL`, không request nào. Đồng hồ hồ
 * sơ 60 ngày thì xét ở MỌI chế độ: dữ liệu đã rời máy chủ một lần thì hạn nộp hồ sơ vẫn chạy, kể cả
 * sau khi quay lui về `local`.
 *
 * # Loại sự cố → trạng thái
 *
 * | Loại | Khi nào | Trạng thái |
 * |---|---|---|
 * | `misconfigured` | Drive báo lỗi cấu hình ({@see DocumentStorageMisconfigured}: khoá, quyền, Shared Drive không có) | `misconfigured` |
 * | `unavailable` | Drive không trả lời ({@see DocumentStorageUnavailable}: mạng, 429, 5xx) | `unavailable` |
 * | `sharing_drift` | chia sẻ hay thư mục gốc ĐỎ (thành viên lạ, vai sai, `anyone`/`domain`, …) | `misconfigured` |
 * | `not_enabled` | công tắc `google_drive` mà chưa có mốc bật kho | `degraded` |
 * | `push_backlog` | có tệp mới chờ đẩy quá `push_alert_minutes` | `degraded` |
 * | `office_copy_stale` | đã cấu hình máy văn phòng mà biên nhận gần nhất quá `office.max_age_hours` hay chưa từng có | `degraded` |
 * | `office_copy_error` | `system_health.last_office_receipt_error` khác rỗng | `degraded` |
 * | `transfer_dossier_due` | production, đồng hồ hồ sơ chạy và đã tới ngày 45 | `degraded` |
 * | `transfer_blocked` | production, kho đang BẬT (`DocumentStore::pushesNewFiles()`) mà không còn ngày hồ sơ lẫn ý kiến luật sư — cổng R13 đóng sau lúc bật (rà soát cuối vòng sửa 1, I6); lượt đẩy và lệnh chuyển tệp cũ đã tự dừng, người vận hành phải biết | `misconfigured` |
 *
 * Trạng thái là loại nặng nhất (`misconfigured` > `unavailable` > `degraded` > `ok`). Hai điều không là
 * sự cố: chia sẻ chỉ VÀNG (`domainUsersOnly` tắt là cấu hình có chủ đích, R5) và chưa cấu hình máy văn
 * phòng (đó là trạng thái trước khi có máy, vùng đệm giữ mọi tệp) — cả hai vẫn hiện ở
 * `vkcrm:storage:check`.
 *
 * # Thư
 *
 * Mỗi loại sự cố đang có: một thư MỖI NGÀY (ngày lịch, múi giờ ứng dụng), tới người nhận của
 * {@see ResolveBackupNotificationRecipients} (người vận hành, không phải luật sư phụ trách: đây là sự cố
 * hạ tầng, không gắn một vụ việc nào). Chống trùng qua `outbound_messages`: mẫu
 * `staff.document_store_alert.<loại>`, `status = sent`, `sent_at` từ đầu ngày — thư `failed` KHÔNG
 * tính, nên lượt sau thử gửi lại. Thư chỉ có số đếm và loại sự cố (docblock {@see DocumentStoreAlert}).
 *
 * Thư được XẾP HÀNG (`Mail::queue`, `afterCommit`), không gửi trong lượt này và không trong transaction
 * nào (Action này không mở transaction). Xếp hàng hỏng — hay, với hàng đợi `sync`, máy chủ thư hỏng —
 * chỉ để lại dòng `failed` trong nhật ký thư và một `report()`: không lỗi nào ném ra scheduler, và các
 * loại sự cố khác vẫn được gửi.
 *
 * `document_store_detail` là câu tiếng Việt: không mã tệp, không bí mật. Có thể nêu email của một thành
 * viên lạ trên Shared Drive (người vận hành cần biết ai để gỡ); câu đó chỉ hiện sau đăng nhập admin.
 */
final class CheckDocumentStoreHealth
{
    private const SEVERITY = [
        DocumentStoreStatus::Ok->value => 0,
        DocumentStoreStatus::Degraded->value => 1,
        DocumentStoreStatus::Unavailable->value => 2,
        DocumentStoreStatus::Misconfigured->value => 3,
    ];

    /** @var array<string, array{count?: int, days_left?: int}> loại sự cố → số đếm mang theo thư của lượt này */
    private array $figuresByKind = [];

    public function __construct(
        private readonly InspectDriveSharing $sharing,
        private readonly MeasureDocumentStore $figures,
        private readonly ResolveBackupNotificationRecipients $recipients,
    ) {}

    public function __invoke(): void
    {
        $this->handle();
    }

    /**
     * @return array{status: ?DocumentStoreStatus, incidents: list<string>, mailed: list<string>}
     */
    public function handle(): array
    {
        $status = null;
        $incidents = [];
        $details = [];
        $this->figuresByKind = [];

        if ($this->storeInUse()) {
            $status = DocumentStoreStatus::Ok;
            $this->inspectDrive($status, $incidents, $details);
            $this->inspectLocalState($status, $incidents, $details);
        }

        $this->inspectDossierClock($status, $incidents, $details);

        SystemHealth::current()->forceFill([
            'document_store_status' => $status,
            'document_store_checked_at' => now(),
            'document_store_detail' => $details === [] ? null : implode(' ', $details),
        ])->save();

        return ['status' => $status, 'incidents' => $incidents, 'mailed' => $this->mail($incidents)];
    }

    /** Cùng điều kiện "kho có liên quan" với phần của kho trong preflight (`StorageReadiness::preflightRows()`). */
    private function storeInUse(): bool
    {
        return DocumentStore::usesRemote() || $this->figures->remoteMedia() > 0;
    }

    /** @param  list<string>  $incidents  @param  list<string>  $details */
    private function inspectDrive(?DocumentStoreStatus &$status, array &$incidents, array &$details): void
    {
        try {
            $report = $this->sharing->handle(DriveDiagnosticClient::make());
        } catch (DocumentStorageMisconfigured $e) {
            $this->raise($status, DocumentStoreStatus::Misconfigured, $incidents, DocumentStoreAlert::KIND_MISCONFIGURED);
            $details[] = __('document_store.health.detail.misconfigured', ['error' => $e->getMessage()]);

            return;
        } catch (DocumentStorageUnavailable $e) {
            $this->raise($status, DocumentStoreStatus::Unavailable, $incidents, DocumentStoreAlert::KIND_UNAVAILABLE);
            $details[] = __('document_store.health.detail.unavailable', ['error' => $e->getMessage()]);

            return;
        } catch (Throwable $e) {
            // Không phân loại được: coi như kho không trả lời (thử lại có thể hết), không ném ra lịch.
            report($e);
            Log::warning(__('document_store.health.log.drive_failed'), ['exception' => $e::class]);
            $this->raise($status, DocumentStoreStatus::Unavailable, $incidents, DocumentStoreAlert::KIND_UNAVAILABLE);
            $details[] = __('document_store.health.detail.unavailable', ['error' => class_basename($e)]);

            return;
        }

        $problems = [
            ...($report['sharing']['level'] === PreflightLevel::Red ? $report['sharing']['problems'] : []),
            ...($report['root']['level'] === PreflightLevel::Red ? $report['root']['problems'] : []),
        ];

        if ($problems !== []) {
            $this->raise($status, DocumentStoreStatus::Misconfigured, $incidents, DocumentStoreAlert::KIND_SHARING_DRIFT, count($problems));
            $details[] = __('document_store.health.detail.sharing_drift', ['problems' => implode(' ', $problems)]);
        }
    }

    /** @param  list<string>  $incidents  @param  list<string>  $details */
    private function inspectLocalState(?DocumentStoreStatus &$status, array &$incidents, array &$details): void
    {
        if (DocumentStore::usesRemote() && DocumentStore::remoteEnabledAt() === null) {
            $this->raise($status, DocumentStoreStatus::Degraded, $incidents, DocumentStoreAlert::KIND_NOT_ENABLED);
            $details[] = __('document_store.health.detail.not_enabled');
        }

        $minutes = (int) config('vkcrm.storage.push_alert_minutes');
        $backlog = $this->figures->pendingNewFiles($minutes);

        if ($backlog > 0) {
            $this->raise($status, DocumentStoreStatus::Degraded, $incidents, DocumentStoreAlert::KIND_PUSH_BACKLOG, $backlog);
            $details[] = __('document_store.health.detail.push_backlog', ['count' => $backlog, 'minutes' => $minutes]);
        }

        $health = SystemHealth::query()->where('singleton', 1)->first();
        $lastReceipt = $health?->last_office_receipt_at;

        if (filled(config('vkcrm.storage.office.receipts_path'))
            && ($lastReceipt === null || $lastReceipt->lt(now()->subHours((int) config('vkcrm.storage.office.max_age_hours'))))) {
            $this->raise($status, DocumentStoreStatus::Degraded, $incidents, DocumentStoreAlert::KIND_OFFICE_COPY_STALE, $this->figures->remoteWithoutOfficeReceipt());
            $details[] = __('document_store.health.detail.office_copy_stale', [
                'at' => $lastReceipt === null ? '—' : CarbonImmutable::instance($lastReceipt)->setTimezone((string) config('app.timezone'))->format('H:i d/m/Y'),
            ]);
        }

        if (filled($health?->last_office_receipt_error)) {
            $this->raise($status, DocumentStoreStatus::Degraded, $incidents, DocumentStoreAlert::KIND_OFFICE_COPY_ERROR, $this->figures->remoteWithoutOfficeReceipt());
            $details[] = __('document_store.health.detail.office_copy_error', ['error' => $health->last_office_receipt_error]);
        }
    }

    /** @param  list<string>  $incidents  @param  list<string>  $details */
    private function inspectDossierClock(?DocumentStoreStatus &$status, array &$incidents, array &$details): void
    {
        if (! TransferDossier::appliesHere()) {
            return;
        }

        $dossier = TransferDossier::current();

        if (DocumentStore::pushesNewFiles() && ! $dossier->allowsTransfer()) {
            $this->raise($status, DocumentStoreStatus::Misconfigured, $incidents, DocumentStoreAlert::KIND_TRANSFER_BLOCKED);
            $details[] = __('document_store.health.detail.transfer_blocked');
        }

        if (! $dossier->isDueSoon()) {
            return;
        }

        $this->raise($status, DocumentStoreStatus::Degraded, $incidents, DocumentStoreAlert::KIND_TRANSFER_DOSSIER_DUE, null, $dossier->daysLeft());

        $details[] = $dossier->isOverdue()
            ? __('document_store.health.detail.transfer_dossier_overdue', ['days' => $dossier->day() - TransferDossier::DUE_DAYS])
            : __('document_store.health.detail.transfer_dossier_due', ['days_left' => $dossier->daysLeft()]);
    }

    /** @param  list<string>  $incidents */
    private function raise(?DocumentStoreStatus &$status, DocumentStoreStatus $to, array &$incidents, string $kind, ?int $count = null, ?int $daysLeft = null): void
    {
        if ($status === null || self::SEVERITY[$to->value] > self::SEVERITY[$status->value]) {
            $status = $to;
        }

        $incidents[] = $kind;
        $this->figuresByKind[$kind] = array_filter(['count' => $count, 'days_left' => $daysLeft], fn (?int $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $incidents
     * @return list<string> loại sự cố đã xếp thư trong lượt này
     */
    private function mail(array $incidents): array
    {
        $kinds = array_values(array_filter(array_unique($incidents), fn (string $kind): bool => ! $this->mailedToday($kind)));

        if ($kinds === []) {
            return [];
        }

        $recipients = $this->recipients->handle();

        if ($recipients === []) {
            Log::warning(__('document_store.health.log.no_recipients'), ['incidents' => $kinds]);

            return [];
        }

        $mailed = [];

        foreach ($kinds as $kind) {
            try {
                Mail::queue((new DocumentStoreAlert($kind, $this->figuresByKind[$kind] ?? [], $recipients))->afterCommit());
                $mailed[] = $kind;
            } catch (Throwable $e) {
                // Máy chủ thư hỏng (hàng đợi sync) hay không xếp được job: dòng `failed` đã nằm trong nhật
                // ký thư nếu thư đi tới transport; lượt sau thử lại vì chỉ dòng `sent` chặn.
                report($e);
                Log::warning(__('document_store.health.log.mail_failed'), ['kind' => $kind, 'exception' => $e::class]);
            }
        }

        return $mailed;
    }

    private function mailedToday(string $kind): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', DocumentStoreAlert::TEMPLATE_PREFIX.$kind)
            ->where('status', OutboundStatus::Sent)
            ->where('sent_at', '>=', now()->startOfDay())
            ->exists();
    }
}
