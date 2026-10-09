<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Storage\MaterialiseStoredFile;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\HandoverPackageFailed;
use App\Exceptions\StoredFileChanged;
use App\Exceptions\StoredFileMissing;
use App\Jobs\GenerateHandoverPackage;
use App\Jobs\SendHandoverPackageReady;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use App\Support\Files\FileGuard;
use App\Support\Files\FreeSpace;
use App\Support\Handover\HandoverEntry;
use App\Support\Storage\DocumentStore;
use ErrorException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use ZipArchive;

/**
 * M7 Task 4 (SPEC §6.12, R1, R3, R8) — DỰNG gói bàn giao của một vụ việc đã kết thúc: một tệp zip
 * gồm các tài liệu được chọn ({@see CollectHandoverEntries}) và `MUC-LUC.pdf`
 * ({@see RenderHandoverIndex}), lưu thành một `Document` nhóm B ở `signed_filed`. Chỉ được gọi từ
 * {@see GenerateHandoverPackage}; người bấm nút đi qua {@see RequestHandoverPackage}.
 *
 * # Gói là một `Document` (R1)
 *
 * Nhóm B, `signed_filed`, `client_can_view/download` = false (chưa công bố), tệp gắn qua
 * medialibrary vào collection `file` trên đĩa `private` — nên đường tải duy nhất
 * (`documents.download`) và sổ `document_downloads` áp nguyên cho nó. Luật sư xem lại rồi công bố
 * qua ĐÚNG `PublishDocument`; action này không bao giờ tự công bố.
 *
 * Sinh lại = version MỚI của CÙNG tài liệu (`parent_document_id` = version trước, `version + 1`),
 * `matter_archives.handover_document_id` trỏ version mới nhất. Dòng `documents` và
 * `document_downloads` của version cũ luôn GIỮ NGUYÊN (lịch sử ai đã tải gì không mất).
 *
 * # Sinh lại và rút lại (rà soát cuối M7, I2)
 *
 * Hai luật của plan từng cãi nhau ở đây: "chỉ giữ version mới nhất của gói" (Task 4, vì hạn mức đĩa)
 * và "một đường rút duy nhất" cùng "bằng chứng khách đã nhận không được biến mất" (Task 7). Bản Task
 * 4 xoá tệp của version cũ kể cả khi nó đã bị rút — trong khi hộp thoại "Rút lại" hứa giữ tệp — và
 * lặng lẽ hạ version cũ đang công bố về `signed_filed`, một đường rút thứ ba không lý do, không dòng
 * giải thích cho khách. Nay:
 *
 *  - **Version cũ đang ra tới khách** (`Document::isReleasedToPortal()`, đọc có khoá trong
 *    transaction lưu): không sinh lại — {@see HandoverPackageFailed::previousReleased()}, không
 *    version mới, version cũ còn nguyên công bố và tệp. Lúc bấm nút, `RequestHandoverPackage` đã từ
 *    chối cùng câu hỏi; ở đây là lần hỏi lại cho gói được công bố trong lúc job chờ hàng. Muốn thay
 *    gói đang công bố, luật sư rút nó bằng "Rút lại" (lý do khách đọc được) rồi sinh lại. Không có
 *    lần kiểm sớm trước khi dựng zip: lời từ chối lúc bấm nút đã chặn ca thường, và một lần kiểm
 *    thứ ba chỉ tiết kiệm công dựng zip cho ca chạy đua hiếm (zip dựng xong bị bỏ, thư mục tạm vẫn
 *    được dọn trong `finally`).
 *  - **Tệp của version cũ chỉ bị xoá khi version đó chưa từng tới tay khách**
 *    ({@see self::keepsFileOf()}): không ở trạng thái `retracted`, và không có lượt tải nào của
 *    khách. Version đã rút hay khách đã tải giữ tệp — đó là bằng chứng văn phòng đã giao gì. Phần
 *    "chỉ giữ version mới nhất" vẫn đúng cho mọi version khách chưa nhận (bản luật sư xem lại rồi
 *    sinh lại trước khi công bố), tức gần như mọi version cũ; tệp giữ lại chỉ phát sinh khi một gói
 *    đã giao bị rút để thay.
 *
 * # Nguyên tử (không tệp dở dang, không version rác)
 *
 *  1. Mọi thứ nặng (dựng PDF, nén zip, kiểm zip đọc lại được với đúng số entry) diễn ra trong
 *     thư mục tạm của lần yêu cầu ({@see self::workDirectory()}, dưới `vkcrm.handover.work_dir`,
 *     mặc định `storage/app/handover-tmp/`) và chưa chạm DB hay medialibrary.
 *  2. Chỉ khi zip xong và kiểm xong, MỘT transaction (khoá `matters` rồi `matter_archives`) tạo
 *     `Document`, gắn tệp vào medialibrary, cập nhật dòng lưu trữ, ghi nhật ký. Transaction này
 *     KHÔNG ngắn với gói lớn: medialibrary không đổi tên tệp mà CHÉP nó (`fopen()` rồi `put()`
 *     cả luồng, rồi xoá nguồn — `Spatie\MediaLibrary\MediaCollections\Filesystem::
 *     copyToMediaLibrary()`), kể cả khi thư mục tạm và đĩa `private` cùng một ổ. Trong lúc chép,
 *     dòng `matters` của ĐÚNG vụ này bị khoá: một thao tác khác khoá vụ đó (chuyển giai đoạn, ghi
 *     tiền sau M9) đứng chờ, lâu nhất bằng thời gian chép gói lớn nhất (trần cỡ gói
 *     `media-library.max_file_size` chia tốc độ ghi đĩa). Chờ quá `innodb_lock_wait_timeout` (mặc
 *     định 50 giây) thì thao tác ĐANG CHỜ hỏng với lỗi hết chờ khoá; gói vẫn xong. Đưa việc chép ra
 *     ngoài khoá thì mất bảo đảm "không tệp mồ côi" của bước 3 — giới hạn này được ghi lại, không
 *     được gỡ.
 *  3. Thư mục tạm luôn bị xoá (`finally`). Nếu transaction hỏng SAU khi tệp đã được medialibrary
 *     copy vào đĩa `private` (dòng `media` bị rollback), thư mục `{media.id}/` mồ côi đó bị xoá
 *     trước khi ném lỗi tiếp — không để lại tệp dở dang. Một tiến trình bị GIẾT (hết `$timeout`
 *     của job, hết bộ nhớ) không tới được `finally`; vì thế thư mục tạm có tên cố định theo lần
 *     yêu cầu: lần chạy lại của cùng job dùng lại rồi xoá nó (kể cả khi lần chạy lại thoát sớm vì
 *     yêu cầu đã bị thay), và {@see RecordHandoverPackageFailure} xoá nó khi job thất bại hẳn.
 *  4. Xoá tệp version cũ (chỉ khi version đó chưa từng tới tay khách — mục "Sinh lại và rút lại")
 *     chỉ sau khi transaction commit (nếu commit hỏng, tệp cũ còn nguyên); lỗi xoá không làm hỏng
 *     gói mới (ghi log).
 *
 * # Lỗi đĩa và cỡ gói là lỗi CÓ TÊN (vòng sửa 1)
 *
 * Thử lại những lỗi này chỉ nén lại toàn bộ gói rồi hỏng y hệt, và lỗi lạ thì chỉ để lại câu chung
 * "lỗi hệ thống" — nên chúng thành {@see HandoverPackageFailed}, job ghi ngay, câu nói người vận
 * hành phải làm gì:
 *  - cảnh báo hệ thống tệp ở thư mục tạm (`ErrorException`: đĩa đầy, mất quyền ghi) →
 *    `workDirectoryFailed()`;
 *  - zip lớn hơn trần MỘT tệp của kho (`FileIsTooBig`; trần là `MEDIA_MAX_FILE_SIZE_MB`, mặc định
 *    2048 MB — `config/media-library.php`) → `tooLarge()`;
 *  - medialibrary không lưu được zip (mọi `FileCannotBeAdded` khác, thường là
 *    `DiskCannotBeAccessed` khi đĩa từ chối ghi) → `storeFailed()`;
 *  - M14 (kế hoạch R12): máy chủ không đủ chỗ trống, khi đo được → `insufficientWorkSpace()`; kho tài
 *    liệu sập (kể cả bản tải về thiếu byte) → `storageUnavailable()` (tệp vẫn ở kho, luật sư bấm sinh
 *    lại); cấu hình kho hỏng → `storageMisconfigured()` và bản trên kho đã bị đổi →
 *    `storageChanged()` (rà soát cuối vòng sửa 1, I7: sinh lại không giúp, câu bảo báo quản trị). Xem
 *    {@see self::buildZip()}.
 *
 * # Dấu của lần yêu cầu
 *
 * `$requestedAt` là `handover_requested_at` của lần yêu cầu đã xếp job này. Không còn khớp (dòng
 * lưu trữ đã sang lần yêu cầu mới, hoặc không còn `generating`) thì action THOÁT LẶNG LẼ ở cả hai
 * chỗ kiểm (đầu và trong transaction): job cũ không đè kết quả của lần mới hơn.
 *
 * # Zip
 *
 * Mọi entry được ghi với cờ `FL_ENC_UTF_8` (bit 11 của general purpose flag), nên tên tiếng Việt
 * có dấu đọc đúng trên trình giải nén hiểu cờ này; test đọc lại bytes thô của central directory.
 *
 * # Báo kết quả
 *
 * Xong thì xếp hàng {@see SendHandoverPackageReady} SAU khi transaction commit (thư đi qua hàng đợi,
 * người nhận qua `ResolveStaffRecipients`). Thất bại hẳn do {@see RecordHandoverPackageFailure}.
 */
class BuildHandoverPackage
{
    use ReadsWithoutPortalScope;

    /** Tên mục lục ở gốc zip — cố định, khách và test đều tìm theo tên này. */
    public const INDEX_ENTRY = 'MUC-LUC.pdf';

    /** Biên chỗ trống cộng thêm của lần kiểm R12 (M14): 50 MB — {@see self::ensureWorkSpace()}. */
    public const FREE_SPACE_MARGIN_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private CollectHandoverEntries $collect,
        private RenderHandoverIndex $render,
        private FreeSpace $freeSpace,
        private MaterialiseStoredFile $materialiseStoredFile,
    ) {}

    /**
     * @return Document|null tài liệu gói vừa tạo; `null` nếu lần yêu cầu này đã bị thay thế.
     */
    public function handle(int $matterId, int $requestedAt): ?Document
    {
        $workDirectory = self::workDirectory($matterId, $requestedAt);

        // `finally` bao CẢ những lối ra sớm: một job cũ bị giết giữa chừng rồi được nhặt lại khi
        // lần yêu cầu của nó đã bị thay vẫn dọn thư mục mà lần chạy trước của nó để lại.
        try {
            $archive = $this->scopelessly(MatterArchive::query())->where('matter_id', $matterId)->first();

            if (! $this->isCurrentRequest($archive, $requestedAt)) {
                return null;
            }

            $matter = $this->scopelessly(Matter::query())->withTrashed()->find($matterId);

            if ($matter === null || $matter->trashed()) {
                throw HandoverPackageFailed::matterGone();
            }

            if (! $matter->isClosed()) {
                throw HandoverPackageFailed::notClosed();
            }

            try {
                $entries = $this->collect->handle($matter, $archive);
            } catch (DocumentStorageMisconfigured $exception) {
                // M14: `exists()` của kho đọc chỉ mục, không gọi mạng — nhưng adapter dựng lười, nên
                // một cấu hình kho hỏng hiện ra ở đây. Cùng câu với cấu hình hỏng lúc tải về.
                throw HandoverPackageFailed::storageMisconfigured($exception);
            } catch (DocumentStorageUnavailable $exception) {
                throw HandoverPackageFailed::storageUnavailable($exception);
            }

            try {
                $zipPath = $this->buildZip($workDirectory, $matter, $entries);
            } catch (ErrorException $exception) {
                // PHP báo lỗi hệ thống tệp (đĩa đầy, không có quyền ghi, đường dẫn hỏng) bằng cảnh
                // báo, Laravel đổi thành `ErrorException`. Thử lại sau 120 giây chỉ nén lại rồi
                // hỏng y hệt: ghi thành lỗi có tên, chỉ đúng thư mục cần kiểm.
                throw HandoverPackageFailed::workDirectoryFailed($exception);
            }

            $document = $this->store($matterId, $requestedAt, $zipPath, $entries->count());
        } finally {
            File::deleteDirectory($workDirectory);
        }

        if ($document === null) {
            return null;
        }

        SendHandoverPackageReady::dispatch($matterId, $document->getKey())->afterCommit();

        return $document;
    }

    /**
     * Thư mục tạm của MỘT lần yêu cầu: `<vkcrm.handover.work_dir>/<id vụ>-<dấu yêu cầu>`. Tên cố
     * định (không ngẫu nhiên) để một tiến trình bị giết giữa chừng — không bao giờ tới `finally` —
     * không để lại một zip dở mà không ai tìm lại được: lần chạy lại của cùng job dọn nó
     * ({@see self::handle()}), và {@see RecordHandoverPackageFailure} dọn nó khi job thất bại hẳn
     * (kể cả hết giờ). Tên mang đúng dấu yêu cầu mà job mang theo — cùng một khoá nhận diện lần
     * yêu cầu với mọi bước khác của gói (mục "Dấu của lần yêu cầu" ở docblock lớp).
     */
    public static function workDirectory(int $matterId, int $requestedAt): string
    {
        return rtrim((string) config('vkcrm.handover.work_dir'), '/\\')
            .DIRECTORY_SEPARATOR.$matterId.'-'.$requestedAt;
    }

    private function isCurrentRequest(?MatterArchive $archive, int $requestedAt): bool
    {
        return $archive !== null
            && $archive->handover_status === HandoverPackageStatus::Generating
            && $archive->handover_requested_at?->getTimestamp() === $requestedAt;
    }

    /**
     * Dựng zip trong thư mục tạm và kiểm nó đọc lại được với đúng số entry. Trả đường dẫn zip.
     *
     * M14 (kế hoạch R12), theo thứ tự:
     *  1. kiểm chỗ trống khi đo được ({@see self::ensureWorkSpace()});
     *  2. cho mỗi entry một đường dẫn cục bộ đã kiểm ({@see MaterialiseStoredFile}): tệp ở vùng đệm
     *     dùng thẳng, tệp trên kho được tải về `src/<NN>` và kiểm cỡ + md5 — TẤT CẢ trước khi mở zip,
     *     để một lỗi kho không bao giờ gặp một zip đang mở dở;
     *  3. nén, đóng, kiểm zip đọc lại được với đúng số entry, như trước M14;
     *  4. xoá `src/` NGAY, trước `store()`: medialibrary CHÉP zip vào `private/<id>/` rồi mới xoá nguồn,
     *     nên còn `src/` thì đỉnh chỗ dùng là `src` + zip + bản chép ≈ 3T; xoá trước thì đỉnh là
     *     max(`src` + zip, zip + bản chép) ≈ 2T — đúng con số của bước 1.
     *
     * @param  Collection<int, HandoverEntry>  $entries
     */
    private function buildZip(string $workDirectory, Matter $matter, Collection $entries): string
    {
        // Lần chạy trước của CÙNG yêu cầu (bị giết, không tới được `finally`) có thể đã để lại
        // tệp dở ở đây: `MUC-LUC.pdf` bị `File::put()` ghi đè, zip được mở với `OVERWRITE`, `src/<NN>`
        // bị ghi đè, và `finally` của `handle()` xoá cả thư mục.
        File::ensureDirectoryExists($workDirectory);

        $this->ensureWorkSpace($workDirectory, $entries);

        $indexPath = $workDirectory.DIRECTORY_SEPARATOR.self::INDEX_ENTRY;

        if (File::put($indexPath, $this->render->handle($matter, $entries)) === false) {
            throw HandoverPackageFailed::indexFailed();
        }

        $sourceDirectory = $workDirectory.DIRECTORY_SEPARATOR.'src';
        $sources = [];

        foreach ($entries as $entry) {
            $sources[$entry->number] = $this->materialise($entry, $sourceDirectory);
        }

        $zipPath = $workDirectory.DIRECTORY_SEPARATOR.'package.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw HandoverPackageFailed::zipFailed();
        }

        // `FL_ENC_UTF_8`: đánh dấu tên entry là UTF-8 (bit 11) — tên tiếng Việt có dấu.
        $flags = ZipArchive::FL_OVERWRITE | ZipArchive::FL_ENC_UTF_8;

        if (! $zip->addFile($indexPath, self::INDEX_ENTRY, 0, 0, $flags)) {
            $zip->close();

            throw HandoverPackageFailed::zipFailed();
        }

        foreach ($entries as $entry) {
            if (! $zip->addFile($sources[$entry->number], $entry->zipPath, 0, 0, $flags)) {
                $zip->close();

                throw HandoverPackageFailed::zipFailed();
            }
        }

        // `close()` mới thật sự đọc các tệp nguồn và ghi zip — lỗi đĩa đầy/tệp biến mất hiện ra ở đây.
        if (! $zip->close()) {
            throw HandoverPackageFailed::zipFailed();
        }

        $verify = new ZipArchive;

        if ($verify->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw HandoverPackageFailed::zipFailed();
        }

        $count = $verify->numFiles;
        $verify->close();

        if ($count !== $entries->count() + 1) {
            throw HandoverPackageFailed::zipFailed();
        }

        // Bước 4 của docblock: `src/` đi TRƯỚC `store()`.
        File::deleteDirectory($sourceDirectory);

        return $zipPath;
    }

    /**
     * M14 (kế hoạch R12), bước 1 của {@see self::buildZip()}: với T = tổng cỡ tệp nguồn (theo dòng
     * `media`), cần `free(thư mục làm việc) >= 2T + 50 MB` (bản tải về + zip) và `free(gốc đĩa private)
     * >= T + 50 MB` (bản chép của medialibrary). Hai đường cùng một ổ thì điều đầu bao điều sau. Thiếu
     * → {@see HandoverPackageFailed::insufficientWorkSpace()}, trước khi tải hay nén gì.
     *
     * Đo qua {@see FreeSpace}: `null` khi `disk_free_space` bị tắt (nhiều shared hosting tắt nó, và gọi
     * một hàm bị tắt là `Error` — gói sẽ hỏng cả ở chế độ `local`). Không đo được thì BỎ kiểm chỗ đó,
     * log `warning` một lần; preflight `disk_free_space_available` báo VÀNG.
     *
     * @param  Collection<int, HandoverEntry>  $entries
     */
    private function ensureWorkSpace(string $workDirectory, Collection $entries): void
    {
        $total = (int) $entries->sum(fn (HandoverEntry $entry): int => $entry->size);
        $margin = self::FREE_SPACE_MARGIN_BYTES;
        $unmeasured = false;

        foreach ([
            [$workDirectory, 2 * $total + $margin],
            [Storage::disk(DocumentStore::STAGING_DISK)->path(''), $total + $margin],
        ] as [$path, $needed]) {
            $free = $this->freeSpace->bytes($path);

            if ($free === null) {
                $unmeasured = true;

                continue;
            }

            if ($free < $needed) {
                throw HandoverPackageFailed::insufficientWorkSpace($needed, $free);
            }
        }

        if ($unmeasured) {
            Log::warning(__('storage.read.log.free_space_unknown'), ['total_bytes' => $total]);
        }
    }

    /**
     * Bước 2 của {@see self::buildZip()}: đường dẫn cục bộ đã kiểm của một entry. Đọc lại dòng `media`
     * (một lượt đẩy có thể vừa đổi nó sang kho; bản vùng đệm vẫn còn qua ân hạn, R2 — đọc lại thì dùng
     * đúng nơi dòng đang trỏ). Phân loại lỗi kho cho luật sư:
     *  - tệp không còn (dòng đã mất, kho 404, vùng đệm trống) → `missingFile()` nêu tiêu đề, như trước;
     *  - kho sập, hay bản tải về THIẾU byte → `storageUnavailable()`: bấm sinh lại;
     *  - cấu hình kho hỏng → `storageMisconfigured()`; bản tải về đủ byte mà lệch
     *    ({@see StoredFileChanged}) → `storageChanged()` nêu tiêu đề: báo quản trị (I7).
     */
    private function materialise(HandoverEntry $entry, string $sourceDirectory): string
    {
        $media = Media::query()->find($entry->mediaId);

        if ($media === null) {
            throw HandoverPackageFailed::missingFile($entry->title);
        }

        try {
            return $this->materialiseStoredFile->handle(
                $media,
                $sourceDirectory.DIRECTORY_SEPARATOR.str_pad((string) $entry->number, 2, '0', STR_PAD_LEFT),
            );
        } catch (StoredFileMissing) {
            throw HandoverPackageFailed::missingFile($entry->title);
        } catch (StoredFileChanged $exception) {
            throw HandoverPackageFailed::storageChanged($entry->title, $exception);
        } catch (DocumentStorageMisconfigured $exception) {
            throw HandoverPackageFailed::storageMisconfigured($exception);
        } catch (DocumentStorageUnavailable $exception) {
            throw HandoverPackageFailed::storageUnavailable($exception);
        }
    }

    /**
     * Tạo `Document`, gắn tệp, cập nhật dòng lưu trữ — trong một transaction. Xem docblock lớp.
     */
    private function store(int $matterId, int $requestedAt, string $zipPath, int $entryCount): ?Document
    {
        $storedMedia = null;
        $previousMedia = null;

        try {
            $document = DB::transaction(function () use (
                $matterId, $requestedAt, $zipPath, $entryCount, &$storedMedia, &$previousMedia,
            ): ?Document {
                // Khoá `matters` TRƯỚC, rồi `matter_archives`, rồi tài liệu gói cũ.
                $matter = $this->scopelessly(Matter::query())
                    ->withTrashed()
                    ->whereKey($matterId)
                    ->lockForUpdate()
                    ->first();

                if ($matter === null || $matter->trashed()) {
                    throw HandoverPackageFailed::matterGone();
                }

                $archive = $this->scopelessly(MatterArchive::query())
                    ->where('matter_id', $matterId)
                    ->lockForUpdate()
                    ->first();

                if (! $this->isCurrentRequest($archive, $requestedAt)) {
                    return null;
                }

                if (! $matter->isClosed()) {
                    throw HandoverPackageFailed::notClosed();
                }

                $previous = $archive->handover_document_id === null ? null : $this->scopelessly(Document::query())
                    ->withTrashed()
                    ->lockForUpdate()
                    ->find($archive->handover_document_id);

                // Rà soát cuối M7, I2 — xem docblock lớp, mục "Sinh lại và rút lại". Trước khi tạo
                // gì: chưa có dòng nào, chưa có tệp nào để dọn.
                if ($previous !== null && $previous->isReleasedToPortal()) {
                    throw HandoverPackageFailed::previousReleased();
                }

                $requester = $archive->handoverRequester;
                $uploader = $requester
                    ?? $matter->leadLawyer
                    ?? ($archive->archived_by === null ? null : User::query()->find($archive->archived_by));

                if ($uploader === null) {
                    throw HandoverPackageFailed::noUploader();
                }

                $document = Document::query()->create([
                    'matter_id' => $matter->getKey(),
                    'matter_checklist_item_id' => null,
                    'group' => DocumentGroup::Issued,
                    'title' => __('handover.package.document_title', ['code' => $matter->code]),
                    'status' => DocumentStatus::SignedFiled,
                    'version' => $previous === null ? 1 : $previous->version + 1,
                    'parent_document_id' => $previous?->getKey(),
                    'uploader_type' => $uploader->getMorphClass(),
                    'uploader_id' => $uploader->getKey(),
                    'client_can_view' => false,
                    'client_can_download' => false,
                    'published_at' => null,
                    'published_by' => null,
                    'issued_at' => now()->toDateString(),
                ]);

                $fileName = __('handover.package.file_name', ['code' => $matter->code]);

                try {
                    $storedMedia = $document->addMedia($zipPath)
                        ->usingName(FileGuard::safeName($fileName))
                        ->usingFileName(Str::lower((string) Str::ulid()).'.zip')
                        ->toMediaCollection('file');
                } catch (FileIsTooBig $exception) {
                    // Medialibrary kiểm cỡ TRƯỚC khi chép gì: chưa có tệp nào để dọn.
                    throw HandoverPackageFailed::tooLarge(
                        (int) filesize($zipPath),
                        (int) config('media-library.max_file_size'),
                        $exception,
                    );
                } catch (FileCannotBeAdded $exception) {
                    // Đĩa từ chối ghi giữa chừng (`DiskCannotBeAccessed`): medialibrary đã tự xoá
                    // dòng `media` và thư mục `{media.id}/` dở của nó trước khi ném.
                    throw HandoverPackageFailed::storeFailed($exception);
                }

                // Version cũ KHÔNG bao giờ bị đổi trạng thái hay cờ khách ở đây (đường rút duy nhất
                // là `RetractDocument`); chỉ tệp của version khách chưa từng nhận mới bị xoá, sau
                // commit — xem docblock lớp.
                if ($previous !== null && ! $this->keepsFileOf($previous)) {
                    $previousMedia = $previous->getMedia('file')->all();
                }

                $archive->update([
                    'handover_document_id' => $document->getKey(),
                    'handover_generated_at' => now(),
                    'handover_status' => HandoverPackageStatus::Ready,
                    'handover_error' => null,
                ]);

                Audit::record('data_exported', $document, [
                    'matter_id' => $matter->getKey(),
                    'client_id' => $matter->client_id,
                    'kind' => 'handover_package',
                    'action' => 'generated',
                    'version' => $document->version,
                    'files' => $entryCount,
                ], $requester);

                return $document;
            });
        } catch (Throwable $exception) {
            // Transaction đã rollback: dòng `media` không còn, nhưng medialibrary đã copy tệp vào
            // đĩa. Xoá thư mục `{media.id}/` mồ côi đó.
            $this->discardStoredFile($storedMedia);

            throw $exception;
        }

        // Transaction chỉ trả `null` ở lần kiểm dấu yêu cầu, TRƯỚC `addMedia()`: chưa có tệp nào để dọn.
        if ($document === null) {
            return null;
        }

        // Sau commit: xoá tệp của version cũ. Lỗi ở đây không làm hỏng gói mới.
        foreach ($previousMedia ?? [] as $media) {
            try {
                $media->delete();
            } catch (Throwable $exception) {
                Log::warning('handover_package.old_file_not_deleted', [
                    'media_id' => $media->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $document;
    }

    /**
     * Version gói cũ này có phải đã tới tay khách không — nếu có, tệp của nó là bằng chứng văn phòng
     * đã giao gì và KHÔNG bị xoá khi sinh lại (rà soát cuối M7, I2; xem docblock lớp).
     *
     *  - `retracted`: hộp thoại "Rút lại" hứa giữ tệp (`retraction.action.modal_description`).
     *  - Có lượt tải của KHÁCH (`downloader_type` là `ClientUser`, cùng cách `RetractDocument` đếm
     *    `client_downloads`). Lượt tải của nhân sự — luật sư xem lại gói trước khi công bố — không
     *    tính. Hôm nay mọi version khách tải được thì hoặc đang công bố (bị từ chối trước khi tới
     *    đây), hoặc đã rút; vế này giữ luật bằng chứng đứng vững nếu một đường khác `RetractDocument`
     *    từng hạ tài liệu khỏi cổng (chính bản Task 4 trước bản sửa là một đường như vậy).
     *  - Gộp M7 vào `main` (M9): tệp đang là biên lai của một khoản thu hay bản scan phụ lục hợp
     *    đồng (`Document::isReferencedByBillingRecord()`). Hook `Document::deleting` chặn mọi đường
     *    xoá DÒNG tài liệu đó, nhưng ở đây chỉ TỆP bị xoá (qua medialibrary), nên hook không đứng
     *    trước — vế này hỏi cùng định nghĩa.
     */
    private function keepsFileOf(Document $version): bool
    {
        return $version->status === DocumentStatus::Retracted
            || $version->isReferencedByBillingRecord()
            || $this->scopelessly(DocumentDownload::query())
                ->where('document_id', $version->getKey())
                ->where('downloader_type', (new ClientUser)->getMorphClass())
                ->exists();
    }

    private function discardStoredFile(?Media $media): void
    {
        if ($media === null) {
            return;
        }

        try {
            Storage::disk($media->disk)->deleteDirectory(dirname($media->getPathRelativeToRoot()));
        } catch (Throwable $exception) {
            Log::warning('handover_package.orphan_file_not_deleted', [
                'path' => $media->getPathRelativeToRoot(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
