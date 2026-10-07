<?php

namespace App\Actions\Storage;

use App\Actions\Deployment\RunPreflight;
use App\Enums\PreflightLevel;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Models\SystemHealth;
use App\Support\Files\FreeSpace;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\DocumentStore;
use App\Support\Storage\GoogleDrive\DriveAdapter;
use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\DriveDiagnosticClient;
use App\Support\Storage\GoogleDrive\DriveObjectIndex;
use App\Support\Storage\HttpTransportAvailability;
use App\Support\Storage\TransferDossier;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use Throwable;

/**
 * MỘT định nghĩa của "kho tài liệu dùng được" (kế hoạch M14, R7), chạy ở MỌI `APP_ENV`.
 *
 * `RunPreflight` chỉ chạy điều kiện ra mắt khi `APP_ENV=production`, nên nó không làm được cổng ở làn
 * hay ở máy thử. Vì thế kiểm tra sẵn sàng nằm ở đây, và:
 *  - `vkcrm:storage:check` in cả hai nhóm dòng ở mọi môi trường (Phụ lục A bước 11, nghiệm thu Task 8);
 *  - preflight production chỉ GÓI lớp này lại ({@see self::preflightRows()});
 *  - `vkcrm:storage:enable`/`migrate` (Task 6) hỏi {@see self::isReady()}.
 *
 * Mỗi dòng có hình dạng của `RunPreflight` (`{key, level: PreflightLevel, message}`), và câu bắt đầu
 * bằng chính khoá (`drive_sharing: …`): hướng dẫn chủ văn phòng (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`,
 * Phụ lục A) gọi từng bước bằng tên dòng kiểm nó, và `vkcrm:preflight` chỉ in câu, không in khoá.
 *
 * # Dòng sẵn sàng — {@see self::rows()}
 *
 * | Khoá | ĐỎ khi |
 * |---|---|
 * | `document_storage_driver` | công tắc là giá trị lạ (gõ sai: tệp ở lại máy chủ trong khi người vận hành tin chúng ở trên kho) |
 * | `drive_credentials` | luật R6, xem {@see self::credentialsRow()}; VÀNG khi nhóm đọc được mà không chứng minh được nhóm là riêng |
 * | `drive_http_client` | thiếu cả cURL lẫn `allow_url_fopen` |
 * | `drive_reachable` | `drives.get` hỏng: khoá, đồng hồ máy chủ, tường lửa, mã Shared Drive |
 * | `drive_sharing` | luật R5 ({@see InspectDriveSharing}); VÀNG cho hai cài đặt mềm |
 * | `drive_root_folder` | thư mục gốc thiếu, không có, không phải thư mục, khác Shared Drive, ở thùng rác |
 * | `drive_roundtrip` | ghi tệp thăm dò 1 KiB `preflight/<ngẫu nhiên>.txt`, kiểm md5 do Google tính, đọc lại, cho vào thùng rác — một bước hỏng |
 *
 * Bốn dòng mạng phụ thuộc nhau: không HTTP client, hay thiếu cấu hình, hay `drives.get` hỏng thì các
 * dòng sau ĐỎ "chưa kiểm được" mà KHÔNG gửi request nào nữa; thư mục gốc ĐỎ thì không thăm dò.
 *
 * # Dòng trạng thái — {@see self::stateRows()} (không gọi mạng)
 *
 * | Khoá | Mức |
 * |---|---|
 * | `document_storage_enabled` | ĐỎ khi công tắc `google_drive` mà chưa có mốc bật kho (`enable` chưa chạy) |
 * | `drive_item_count` | VÀNG từ `item_warn` (300.000) mục, trên giới hạn `item_limit` (400.000) |
 * | `document_push_backlog` | VÀNG khi có tệp MỚI (tạo từ mốc) chờ đẩy quá `push_alert_minutes`; tệp cũ được đếm riêng |
 * | `document_office_copy` | VÀNG khi chưa cấu hình máy văn phòng, biên nhận gần nhất quá `office.max_age_hours` hay chưa từng có, hoặc có lỗi biên nhận; kèm số media trên kho chưa có biên nhận |
 * | `data_transfer_dossier` | chỉ production ({@see TransferDossier}): ĐỎ khi `google_drive` mà chưa có ngày hồ sơ lẫn ý kiến cho chuyển trước, hoặc quá ngày 60 của đồng hồ; VÀNG từ ngày 45 |
 * | `media_on_remote_while_local` | VÀNG khi công tắc không phải `google_drive` mà còn media trên kho |
 * | `disk_free_space_available` | VÀNG khi `FreeSpace` không đo được (gói bàn giao bỏ kiểm chỗ trống) |
 *
 * # Không đụng tới trạng thái dùng chung
 *
 * Client của các dòng mạng là {@see DriveDiagnosticClient}: ngắt mạch riêng trong bộ nhớ, nên lần kiểm
 * không bị ngắt mạch đang mở chặn, và lỗi của nó không mở ngắt mạch của web/job. Tệp thăm dò đi qua một
 * {@see DriveAdapter} dựng riêng (không qua `Storage::disk()`), nên không một đĩa giả nào của test hay
 * một đĩa đã cache nào thay được đường thật.
 *
 * Giao diện công khai {@see self::rows()}, {@see self::stateRows()}, {@see self::isReady()} giữ đúng mục
 * "Interfaces" của kế hoạch (làn m14, Task 6 dựa vào nó). {@see self::preflightRows()} là phần thêm
 * cho `RunPreflight`.
 */
final class StorageReadiness
{
    /** Kích thước tệp thăm dò của `drive_roundtrip`. */
    public const PROBE_BYTES = 1024;

    /** Tên thư mục cha mà một máy chủ web thường phục vụ công khai (shared hosting). */
    private const WEB_ROOT_DIRECTORIES = ['public_html', 'www', 'htdocs'];

    public function __construct(
        private readonly CredentialFileInspector $files,
        private readonly HttpTransportAvailability $http,
        private readonly InspectDriveSharing $sharing,
        private readonly MeasureDocumentStore $figures,
        private readonly FreeSpace $freeSpace,
    ) {}

    /** @return list<array{key: string, level: PreflightLevel, message: string}> các dòng sẵn sàng, ở MỌI APP_ENV */
    public function rows(): array
    {
        $rows = [
            $this->driverRow(),
            $this->credentialsRow(),
            $httpRow = $this->httpClientRow(),
        ];

        array_push($rows, ...$this->networkRows($httpRow['level'] !== PreflightLevel::Red));

        return $rows;
    }

    /** @return list<array{key: string, level: PreflightLevel, message: string}> các dòng trạng thái (bật kho, tồn đọng, bản văn phòng, hồ sơ, số mục...) */
    public function stateRows(): array
    {
        $rows = [
            $this->enabledRow(),
            $this->itemCountRow(),
            $this->pushBacklogRow(),
            $this->officeCopyRow(),
        ];

        if (TransferDossier::appliesHere()) {
            $rows[] = $this->dossierRow();
        }

        $rows[] = $this->remoteWhileLocalRow();
        $rows[] = $this->diskFreeSpaceRow();

        return $rows;
    }

    /** Không dòng ĐỎ nào trong {@see self::rows()}. Chạy lại cả các dòng mạng, kể cả tệp thăm dò. */
    public function isReady(): bool
    {
        foreach ($this->rows() as $row) {
            if ($row['level'] === PreflightLevel::Red) {
                return false;
            }
        }

        return true;
    }

    /**
     * Phần của kho trong `vkcrm:preflight` production ({@see RunPreflight}, nối ở cuối điều kiện ra
     * mắt). Khi công tắc là `google_drive` HOẶC đã có media trên kho: mọi dòng sẵn sàng và trạng thái.
     * Nếu không (chế độ `local` của mọi máy chủ trước M14): chỉ `document_storage_driver` (một lỗi gõ ở
     * công tắc vẫn phải ĐỎ) và `disk_free_space_available` (gói bàn giao kiểm chỗ trống ở mọi chế độ),
     * không lệnh gọi Drive nào.
     *
     * @return list<array{key: string, level: PreflightLevel, message: string}>
     */
    public function preflightRows(): array
    {
        if (DocumentStore::usesRemote() || $this->figures->remoteMedia() > 0) {
            return [...$this->rows(), ...$this->stateRows()];
        }

        return [$this->driverRow(), $this->diskFreeSpaceRow()];
    }

    // ---------------------------------------------------------------------------------------------
    // Dòng sẵn sàng không cần mạng
    // ---------------------------------------------------------------------------------------------

    private function driverRow(): array
    {
        $driver = config('vkcrm.storage.driver');

        return DocumentStore::driverIsValid()
            ? $this->row('document_storage_driver', PreflightLevel::Green, __('document_store.readiness.driver_ok', ['driver' => $driver]))
            : $this->row('document_storage_driver', PreflightLevel::Red, __('document_store.readiness.driver_invalid', [
                'value' => var_export($driver, true),
            ]));
    }

    /**
     * R6: tệp khoá ngoài repo, ngoài gốc web, chỉ người dùng chạy PHP (hoặc một nhóm riêng) đọc được.
     *
     * ĐỎ, theo thứ tự hỏi: đường dẫn trống; tệp không có; PHP không đọc được; tệp (theo đường dẫn đã
     * khai HOẶC đường dẫn đã giải symlink) nằm dưới `base_path()`, dưới `public_path()`, hay dưới một
     * thư mục tên `public_html`/`www`/`htdocs`; người khác đọc hoặc ghi được (`& 0o006`); nhóm ghi được
     * (`& 0o020`); nội dung không phải JSON có `type = service_account`, `client_email` và
     * `private_key` khác rỗng.
     *
     * VÀNG khi nhóm ĐỌC được (`& 0o040`) mà không chứng minh được nhóm là riêng: thiếu `posix`, nhóm
     * của tệp khác nhóm hiệu lực của PHP, không đọc được danh sách thành viên nhóm, hay danh sách đó có
     * người khác ngoài người dùng của PHP. XANH: `0400`/`0600`, hoặc `0440`/`0640` với nhóm riêng.
     */
    private function credentialsRow(): array
    {
        $path = (string) config('vkcrm.storage.google_drive.credentials_path');

        if ($path === '') {
            return $this->row('drive_credentials', PreflightLevel::Red, __('document_store.readiness.credentials_missing'));
        }

        if (! $this->files->isFile($path)) {
            return $this->row('drive_credentials', PreflightLevel::Red, __('document_store.readiness.credentials_not_found', ['path' => $path]));
        }

        if (! $this->files->isReadable($path)) {
            return $this->row('drive_credentials', PreflightLevel::Red, __('document_store.readiness.credentials_unreadable', ['path' => $path]));
        }

        $exposure = $this->exposedLocation($path);

        if ($exposure !== null) {
            return $this->row('drive_credentials', PreflightLevel::Red, $exposure);
        }

        $mode = $this->files->permissions($path) ?? 0o777;
        $octal = sprintf('%04o', $mode);

        if (($mode & 0o006) !== 0) {
            return $this->row('drive_credentials', PreflightLevel::Red, __('document_store.readiness.credentials_world', ['path' => $path, 'mode' => $octal]));
        }

        if (($mode & 0o020) !== 0) {
            return $this->row('drive_credentials', PreflightLevel::Red, __('document_store.readiness.credentials_group_writable', ['path' => $path, 'mode' => $octal]));
        }

        if (! $this->looksLikeServiceAccountKey((string) $this->files->contents($path))) {
            return $this->row('drive_credentials', PreflightLevel::Red, __('document_store.readiness.credentials_not_service_account', ['path' => $path]));
        }

        if (($mode & 0o040) !== 0) {
            $doubt = $this->groupDoubt($path);

            if ($doubt !== null) {
                return $this->row('drive_credentials', PreflightLevel::Yellow, __('document_store.readiness.credentials_group_unproven', [
                    'path' => $path,
                    'mode' => $octal,
                    'reason' => $doubt,
                ]));
            }
        }

        return $this->row('drive_credentials', PreflightLevel::Green, __('document_store.readiness.credentials_ok', ['path' => $path, 'mode' => $octal]));
    }

    /** Câu ĐỎ khi tệp khoá nằm ở nơi một `git add` hay một lỗi cấu hình web làm lộ; `null` khi không. */
    private function exposedLocation(string $path): ?string
    {
        $candidates = array_values(array_unique(array_filter([$path, $this->files->realPath($path)])));

        foreach ($candidates as $candidate) {
            foreach ($this->files->applicationRoots() as $root) {
                $root = rtrim($this->files->realPath($root) ?? $root, '/\\');

                if (str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $root).'/')) {
                    return __('document_store.readiness.credentials_inside_app', ['path' => $path, 'root' => $root]);
                }
            }

            $directories = explode('/', str_replace('\\', '/', dirname($candidate)));
            $webRoot = array_values(array_intersect($directories, self::WEB_ROOT_DIRECTORIES));

            if ($webRoot !== []) {
                return __('document_store.readiness.credentials_web_root', ['path' => $path, 'directory' => $webRoot[0]]);
            }
        }

        return null;
    }

    private function looksLikeServiceAccountKey(string $contents): bool
    {
        $json = json_decode($contents, true);

        return is_array($json)
            && ($json['type'] ?? null) === 'service_account'
            && is_string($json['client_email'] ?? null) && trim($json['client_email']) !== ''
            && is_string($json['private_key'] ?? null) && trim($json['private_key']) !== '';
    }

    /** Vì sao không chứng minh được nhóm của tệp là riêng, hoặc `null` khi chứng minh được. */
    private function groupDoubt(string $path): ?string
    {
        if (! $this->files->posixAvailable()) {
            return __('document_store.readiness.group_no_posix');
        }

        $group = $this->files->fileGroup($path);

        if ($group === null || $group !== $this->files->processGroup()) {
            return __('document_store.readiness.group_not_process_group');
        }

        $members = $this->files->groupMembers($group);

        if ($members === null) {
            return __('document_store.readiness.group_members_unknown');
        }

        $others = array_values(array_diff($members, array_filter([$this->files->processUser()])));

        return $others === [] ? null : __('document_store.readiness.group_has_others', ['members' => implode(', ', $others)]);
    }

    private function httpClientRow(): array
    {
        $transports = array_keys(array_filter([
            'cURL' => $this->http->curl(),
            'allow_url_fopen' => $this->http->urlFopen(),
        ]));

        return $transports === []
            ? $this->row('drive_http_client', PreflightLevel::Red, __('document_store.readiness.http_missing'))
            : $this->row('drive_http_client', PreflightLevel::Green, __('document_store.readiness.http_ok', ['transports' => implode(', ', $transports)]));
    }

    // ---------------------------------------------------------------------------------------------
    // Dòng sẵn sàng có mạng
    // ---------------------------------------------------------------------------------------------

    /** @return list<array{key: string, level: PreflightLevel, message: string}> */
    private function networkRows(bool $canSend): array
    {
        if (! $canSend) {
            return $this->skipped(['drive_reachable', 'drive_sharing', 'drive_root_folder', 'drive_roundtrip'], __('document_store.readiness.skipped_no_http'));
        }

        $missing = array_keys(array_filter([
            'GOOGLE_DRIVE_CREDENTIALS_PATH' => blank(config('vkcrm.storage.google_drive.credentials_path')),
            'GOOGLE_DRIVE_SHARED_DRIVE_ID' => blank(config('vkcrm.storage.google_drive.shared_drive_id')),
        ]));

        if ($missing !== []) {
            return [
                $this->row('drive_reachable', PreflightLevel::Red, __('document_store.readiness.reachable_not_configured', ['missing' => implode(', ', $missing)])),
                ...$this->skipped(['drive_sharing', 'drive_root_folder', 'drive_roundtrip'], __('document_store.readiness.skipped_unreachable')),
            ];
        }

        $client = DriveDiagnosticClient::make();

        try {
            $drive = $client->drive((string) config('vkcrm.storage.google_drive.shared_drive_id'));
        } catch (Throwable $e) {
            return [
                $this->row('drive_reachable', PreflightLevel::Red, __('document_store.readiness.reachable_failed', ['error' => $this->describe($e)])),
                ...$this->skipped(['drive_sharing', 'drive_root_folder', 'drive_roundtrip'], __('document_store.readiness.skipped_unreachable')),
            ];
        }

        $rows = [$this->row('drive_reachable', PreflightLevel::Green, __('document_store.readiness.reachable_ok', [
            'name' => (string) ($drive['name'] ?? ''),
        ]))];

        $rows[] = $this->sharingRow($client);
        $rows[] = $rootRow = $this->rootFolderRow($client);

        $rows[] = $rootRow['level'] === PreflightLevel::Red
            ? $this->row('drive_roundtrip', PreflightLevel::Red, __('document_store.readiness.skipped_root'))
            : $this->roundtripRow($client);

        return $rows;
    }

    private function sharingRow(DriveClient $client): array
    {
        try {
            $report = $this->sharing->sharing($client);
        } catch (Throwable $e) {
            return $this->row('drive_sharing', PreflightLevel::Red, __('document_store.readiness.sharing_failed', ['error' => $this->describe($e)]));
        }

        return match ($report['level']) {
            PreflightLevel::Green => $this->row('drive_sharing', PreflightLevel::Green, __('document_store.readiness.sharing_ok')),
            PreflightLevel::Yellow => $this->row('drive_sharing', PreflightLevel::Yellow, implode(' ', $report['warnings'])),
            PreflightLevel::Red => $this->row('drive_sharing', PreflightLevel::Red, implode(' ', [...$report['problems'], ...$report['warnings']])),
        };
    }

    private function rootFolderRow(DriveClient $client): array
    {
        try {
            $report = $this->sharing->rootFolder($client);
        } catch (Throwable $e) {
            return $this->row('drive_root_folder', PreflightLevel::Red, __('document_store.readiness.root_failed', ['error' => $this->describe($e)]));
        }

        return $report['level'] === PreflightLevel::Green
            ? $this->row('drive_root_folder', PreflightLevel::Green, __('document_store.readiness.root_ok'))
            : $this->row('drive_root_folder', PreflightLevel::Red, implode(' ', $report['problems']));
    }

    /**
     * Ghi → kiểm md5 do Google tính → đọc lại → cho vào thùng rác, trên một adapter dựng riêng. Bước
     * dọn LUÔN chạy (như `RunPreflight::storagePrivateExposureRow()`), kể cả khi bước trước hỏng; dọn
     * hỏng (tài khoản dịch vụ ở vai Contributor không cho vào thùng rác được) cũng là ĐỎ. Khoá xoá chưa
     * có trong chỉ mục (ghi đã hỏng trước khi ghi chỉ mục) thì adapter không làm gì.
     */
    private function roundtripRow(DriveClient $client): array
    {
        $adapter = new DriveAdapter(
            $client,
            new DriveObjectIndex,
            (string) config('vkcrm.storage.google_drive.shared_drive_id'),
            (string) config('vkcrm.storage.google_drive.root_folder_id'),
            (string) config('vkcrm.storage.lock_store'),
        );

        $key = 'preflight/'.Str::lower(Str::random(26)).'.txt';
        $content = Str::random(self::PROBE_BYTES);

        try {
            $adapter->write($key, $content, new Config);

            if ($adapter->checksum($key, new Config(['checksum_algo' => 'md5'])) !== md5($content)) {
                $row = $this->row('drive_roundtrip', PreflightLevel::Red, __('document_store.readiness.roundtrip_checksum_mismatch'));
            } elseif ($adapter->read($key) !== $content) {
                $row = $this->row('drive_roundtrip', PreflightLevel::Red, __('document_store.readiness.roundtrip_content_mismatch'));
            } else {
                $row = $this->row('drive_roundtrip', PreflightLevel::Green, __('document_store.readiness.roundtrip_ok', ['bytes' => self::PROBE_BYTES]));
            }
        } catch (Throwable $e) {
            $row = $this->row('drive_roundtrip', PreflightLevel::Red, __('document_store.readiness.roundtrip_failed', ['error' => $this->describe($e)]));
        }

        try {
            $adapter->delete($key);
        } catch (Throwable $e) {
            Log::warning(__('document_store.readiness.roundtrip_cleanup_log'), ['exception' => $e::class]);

            if ($row['level'] !== PreflightLevel::Red) {
                $row = $this->row('drive_roundtrip', PreflightLevel::Red, __('document_store.readiness.roundtrip_cleanup_failed', ['error' => $this->describe($e)]));
            }
        }

        return $row;
    }

    // ---------------------------------------------------------------------------------------------
    // Dòng trạng thái
    // ---------------------------------------------------------------------------------------------

    private function enabledRow(): array
    {
        if (! DocumentStore::usesRemote()) {
            return $this->row('document_storage_enabled', PreflightLevel::Green, __('document_store.readiness.enabled_local'));
        }

        $enabledAt = DocumentStore::remoteEnabledAt();

        return $enabledAt === null
            ? $this->row('document_storage_enabled', PreflightLevel::Red, __('document_store.readiness.enabled_missing'))
            : $this->row('document_storage_enabled', PreflightLevel::Green, __('document_store.readiness.enabled_ok', ['at' => $this->time($enabledAt)]));
    }

    private function itemCountRow(): array
    {
        $count = $this->figures->driveItems();
        $warn = (int) config('vkcrm.storage.google_drive.item_warn');
        $parameters = ['count' => $this->number($count), 'limit' => $this->number((int) config('vkcrm.storage.google_drive.item_limit'))];

        return $count >= $warn
            ? $this->row('drive_item_count', PreflightLevel::Yellow, __('document_store.readiness.items_warn', $parameters))
            : $this->row('drive_item_count', PreflightLevel::Green, __('document_store.readiness.items_ok', $parameters));
    }

    private function pushBacklogRow(): array
    {
        $minutes = (int) config('vkcrm.storage.push_alert_minutes');
        $overdue = $this->figures->pendingNewFiles($minutes);
        $legacy = $this->figures->legacyFiles();

        return $overdue > 0
            ? $this->row('document_push_backlog', PreflightLevel::Yellow, __('document_store.readiness.backlog_warn', [
                'count' => $overdue,
                'minutes' => $minutes,
                'oldest' => $this->time($this->figures->oldestPendingAt()),
                'legacy' => $legacy,
            ]))
            : $this->row('document_push_backlog', PreflightLevel::Green, __('document_store.readiness.backlog_ok', ['legacy' => $legacy]));
    }

    private function officeCopyRow(): array
    {
        $unreceipted = __('document_store.readiness.office_unreceipted', ['count' => $this->figures->remoteWithoutOfficeReceipt()]);

        if (blank(config('vkcrm.storage.office.receipts_path'))) {
            return $this->row('document_office_copy', PreflightLevel::Yellow, __('document_store.readiness.office_not_configured', ['unreceipted' => $unreceipted]));
        }

        $health = SystemHealth::query()->where('singleton', 1)->first();
        $error = $health?->last_office_receipt_error;

        if (filled($error)) {
            return $this->row('document_office_copy', PreflightLevel::Yellow, __('document_store.readiness.office_error', ['error' => $error, 'unreceipted' => $unreceipted]));
        }

        $last = $health?->last_office_receipt_at;
        $maxAge = (int) config('vkcrm.storage.office.max_age_hours');

        if ($last === null) {
            return $this->row('document_office_copy', PreflightLevel::Yellow, __('document_store.readiness.office_never', ['unreceipted' => $unreceipted]));
        }

        if ($last->lt(now()->subHours($maxAge))) {
            return $this->row('document_office_copy', PreflightLevel::Yellow, __('document_store.readiness.office_stale', [
                'at' => $this->time($last),
                'hours' => $maxAge,
                'unreceipted' => $unreceipted,
            ]));
        }

        return $this->row('document_office_copy', PreflightLevel::Green, __('document_store.readiness.office_ok', ['at' => $this->time($last), 'unreceipted' => $unreceipted]));
    }

    /**
     * R13, chỉ production (nơi gọi đã hỏi {@see TransferDossier::appliesHere()}). Thứ tự hỏi: có ngày
     * hồ sơ → XANH (đồng hồ dừng); quá ngày 60 → ĐỎ; công tắc `google_drive` mà không có ý kiến cho
     * chuyển trước → ĐỎ (cổng: `enable` cũng từ chối); từ ngày 45 → VÀNG kèm số ngày còn lại.
     */
    private function dossierRow(): array
    {
        $dossier = TransferDossier::current();

        if ($dossier->dossierOn() !== null) {
            return $this->row('data_transfer_dossier', PreflightLevel::Green, __('document_store.readiness.dossier_filed', [
                'date' => $dossier->dossierOn()->format('d/m/Y'),
            ]));
        }

        if ($dossier->isOverdue()) {
            return $this->row('data_transfer_dossier', PreflightLevel::Red, __('document_store.readiness.dossier_overdue', [
                'first' => $this->time($dossier->firstTransferAt()),
                'days' => $dossier->day(),
            ]));
        }

        if (DocumentStore::usesRemote() && ! $dossier->allowsTransfer()) {
            return $this->row('data_transfer_dossier', PreflightLevel::Red, __('document_store.readiness.dossier_blocked'));
        }

        if ($dossier->isDueSoon()) {
            return $this->row('data_transfer_dossier', PreflightLevel::Yellow, __('document_store.readiness.dossier_due', [
                'first' => $this->time($dossier->firstTransferAt()),
                'days_left' => $dossier->daysLeft(),
            ]));
        }

        return $this->row('data_transfer_dossier', PreflightLevel::Green, match (true) {
            $dossier->clockRunning() => __('document_store.readiness.dossier_clock', [
                'first' => $this->time($dossier->firstTransferAt()),
                'days_left' => $dossier->daysLeft(),
            ]),
            $dossier->opinionOn() !== null => __('document_store.readiness.dossier_opinion', ['date' => $dossier->opinionOn()->format('d/m/Y')]),
            default => __('document_store.readiness.dossier_idle'),
        });
    }

    private function remoteWhileLocalRow(): array
    {
        $remote = $this->figures->remoteMedia();

        return ! DocumentStore::usesRemote() && $remote > 0
            ? $this->row('media_on_remote_while_local', PreflightLevel::Yellow, __('document_store.readiness.remote_while_local', ['count' => $remote]))
            : $this->row('media_on_remote_while_local', PreflightLevel::Green, __('document_store.readiness.remote_while_local_ok', ['count' => $remote]));
    }

    /** Đo ở gốc đĩa `private` (luôn có): `FreeSpace` trả `null` cho đường dẫn chưa tồn tại. */
    private function diskFreeSpaceRow(): array
    {
        $bytes = $this->freeSpace->bytes((string) config('filesystems.disks.private.root'));

        return $bytes === null
            ? $this->row('disk_free_space_available', PreflightLevel::Yellow, __('document_store.readiness.free_space_unknown'))
            : $this->row('disk_free_space_available', PreflightLevel::Green, __('document_store.readiness.free_space_ok', ['free' => Number::fileSize($bytes, precision: 1)]));
    }

    // ---------------------------------------------------------------------------------------------
    // Tiện ích
    // ---------------------------------------------------------------------------------------------

    /**
     * Câu tiếng Việt cho một lỗi, không bao giờ mang bí mật: ngoại lệ của kho có câu dựng từ
     * `lang/vi/storage.php` (không token, không thân phản hồi); mọi ngoại lệ khác chỉ để lại tên lớp.
     */
    private function describe(Throwable $e): string
    {
        foreach ([$e, $e->getPrevious()] as $candidate) {
            if ($candidate instanceof DocumentStorageMisconfigured
                || $candidate instanceof DocumentStorageUnavailable
                || $candidate instanceof DriveApiError) {
                return $candidate->getMessage();
            }
        }

        return class_basename($e);
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, level: PreflightLevel, message: string}>
     */
    private function skipped(array $keys, string $reason): array
    {
        return array_map(fn (string $key): array => $this->row($key, PreflightLevel::Red, $reason), $keys);
    }

    private function time(?DateTimeInterface $at): string
    {
        return $at === null ? '—' : CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('H:i d/m/Y');
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /** @return array{key: string, level: PreflightLevel, message: string} */
    private function row(string $key, PreflightLevel $level, string $message): array
    {
        return ['key' => $key, 'level' => $level, 'message' => $key.': '.$message];
    }
}
