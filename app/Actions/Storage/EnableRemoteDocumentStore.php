<?php

namespace App\Actions\Storage;

use App\Enums\PreflightLevel;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\TransferDossier;
use Carbon\CarbonImmutable;

/**
 * BẬT kho tài liệu (kế hoạch M14, R2, R11, R13) — `vkcrm:storage:enable`. Công tắc
 * `DOCUMENT_STORAGE=google_drive` chỉ CHO PHÉP; lớp này ghi mốc `settings.storage.remote_enabled_at`,
 * từ lúc đó tệp MỚI tự lên kho (`DocumentStore::pushesNewFiles()`), còn tệp cũ chỉ đi qua
 * `vkcrm:storage:migrate`.
 *
 * Thứ tự hỏi, dừng ở điều đầu tiên không đạt:
 *  1. Công tắc, đọc qua `config()` (tức là sau `optimize`), phải là đúng chuỗi `google_drive` →
 *     nếu không: `not_google_drive`. Không request nào.
 *  2. Đã có mốc → `already`, trả mốc cũ, KHÔNG dời: dời mốc làm tệp tạo giữa hai mốc lọt khỏi tác vụ
 *     quét `storage.push-pending` (cận dưới của nó là mốc). Không request nào.
 *  3. {@see StorageReadiness::rows()} có dòng ĐỎ → `not_ready`, kèm các dòng đỏ. Đây là lần kiểm thật:
 *     `drives.get`, chia sẻ, thư mục gốc, tệp thăm dò.
 *  4. Trên production ({@see TransferDossier::appliesHere()}), cổng pháp lý R13: chưa có ngày hồ sơ và
 *     cũng chưa có ý kiến luật sư cho chuyển trước → `dossier_missing`.
 *  5. Ghi mốc = giờ hiện tại, MỘT lần: `insertOrIgnore` trên khoá unique rồi `UPDATE … WHERE value IS
 *     NULL` — hai lượt chạy cùng lúc không dời mốc của nhau. Audit `document_store_enabled` (chỉ mốc).
 *     Trả `enabled` với mốc đọc lại từ bảng.
 *
 * Không I/O mạng trong transaction: lần kiểm sẵn sàng chạy trước, và lần ghi chỉ có hai câu SQL.
 */
final class EnableRemoteDocumentStore
{
    public function __construct(private readonly StorageReadiness $readiness) {}

    /**
     * @return array{status: 'not_google_drive'|'already'|'not_ready'|'dossier_missing'|'enabled', enabled_at: ?CarbonImmutable, red_rows: list<array{key: string, level: PreflightLevel, message: string}>}
     */
    public function handle(): array
    {
        if (! DocumentStore::usesRemote()) {
            return $this->result('not_google_drive');
        }

        $enabledAt = DocumentStore::remoteEnabledAt();

        if ($enabledAt !== null) {
            return $this->result('already', $enabledAt);
        }

        $red = array_values(array_filter($this->readiness->rows(), fn (array $row): bool => $row['level'] === PreflightLevel::Red));

        if ($red !== []) {
            return $this->result('not_ready', redRows: $red);
        }

        if (TransferDossier::appliesHere() && ! TransferDossier::current()->allowsTransfer()) {
            return $this->result('dossier_missing');
        }

        $now = CarbonImmutable::now();

        Setting::query()->insertOrIgnore([
            'key' => DocumentStore::REMOTE_ENABLED_AT_KEY,
            'value' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written = Setting::query()
            ->where('key', DocumentStore::REMOTE_ENABLED_AT_KEY)
            ->whereNull('value')
            ->update(['value' => $now->toIso8601String(), 'updated_at' => $now]);

        $enabledAt = DocumentStore::remoteEnabledAt();

        if ($written === 0) {
            return $this->result('already', $enabledAt);
        }

        Audit::record('document_store_enabled', null, [
            'remote_enabled_at' => $enabledAt?->toIso8601String(),
        ]);

        return $this->result('enabled', $enabledAt);
    }

    /** @param  list<array{key: string, level: PreflightLevel, message: string}>  $redRows */
    private function result(string $status, ?CarbonImmutable $enabledAt = null, array $redRows = []): array
    {
        return ['status' => $status, 'enabled_at' => $enabledAt, 'red_rows' => $redRows];
    }
}
