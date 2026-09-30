<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Exceptions\HandoverPackageFailed;
use App\Jobs\GenerateHandoverPackage;
use App\Jobs\SendHandoverPackageReady;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use App\Support\Files\FileGuard;
use App\Support\Handover\HandoverEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * `matter_archives.handover_document_id` trỏ version mới nhất. Chỉ tệp của version mới nhất được
 * giữ: tệp của version cũ bị xoá (dòng `documents` và `document_downloads` của nó GIỮ NGUYÊN — lịch
 * sử ai đã tải gì không mất). Nếu version cũ đang mở cho khách, nó bị gỡ khỏi cổng khách cùng lúc
 * (`signed_filed`, hai cờ tắt): một liên kết tải trỏ vào tệp đã xoá là 404 đối với khách. Gói mới
 * phải được công bố lại.
 *
 * # Nguyên tử (không tệp dở dang, không version rác)
 *
 *  1. Mọi thứ nặng (dựng PDF, nén zip, kiểm zip đọc lại được với đúng số entry) diễn ra trong
 *     thư mục tạm của lần yêu cầu ({@see self::workDirectory()}, dưới `vkcrm.handover.work_dir`,
 *     mặc định `storage/app/handover-tmp/`) và chưa chạm DB hay medialibrary.
 *  2. Chỉ khi zip xong và kiểm xong, một transaction ngắn (khoá `matters` rồi `matter_archives`)
 *     tạo `Document`, gắn tệp vào medialibrary, cập nhật dòng lưu trữ, ghi nhật ký.
 *  3. Thư mục tạm luôn bị xoá (`finally`). Nếu transaction hỏng SAU khi tệp đã được medialibrary
 *     copy vào đĩa `private` (dòng `media` bị rollback), thư mục `{media.id}/` mồ côi đó bị xoá
 *     trước khi ném lỗi tiếp — không để lại tệp dở dang. Một tiến trình bị GIẾT (hết `$timeout`
 *     của job, hết bộ nhớ) không tới được `finally`; vì thế thư mục tạm có tên cố định theo lần
 *     yêu cầu: lần chạy lại của cùng job dùng lại rồi xoá nó (kể cả khi lần chạy lại thoát sớm vì
 *     yêu cầu đã bị thay), và {@see RecordHandoverPackageFailure} xoá nó khi job thất bại hẳn.
 *  4. Xoá tệp version cũ chỉ sau khi transaction commit (nếu commit hỏng, tệp cũ còn nguyên); lỗi
 *     xoá không làm hỏng gói mới (ghi log).
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

    public function __construct(
        private CollectHandoverEntries $collect,
        private RenderHandoverIndex $render,
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

            if ($matter->closed_at === null) {
                throw HandoverPackageFailed::notClosed();
            }

            // Lần chạy trước của CÙNG yêu cầu (bị giết, không tới được `finally`) có thể đã để lại
            // tệp dở ở đây: `MUC-LUC.pdf` bị `File::put()` ghi đè, zip được mở với `OVERWRITE`, và
            // `finally` dưới đây xoá cả thư mục.
            File::ensureDirectoryExists($workDirectory);

            $entries = $this->collect->handle($matter, $archive);

            $zipPath = $this->buildZip($workDirectory, $matter, $entries);

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
     * @param  Collection<int, HandoverEntry>  $entries
     */
    private function buildZip(string $workDirectory, Matter $matter, Collection $entries): string
    {
        $indexPath = $workDirectory.DIRECTORY_SEPARATOR.self::INDEX_ENTRY;

        if (File::put($indexPath, $this->render->handle($matter, $entries)) === false) {
            throw HandoverPackageFailed::indexFailed();
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
            if (! $zip->addFile($entry->sourcePath, $entry->zipPath, 0, 0, $flags)) {
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

        return $zipPath;
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

                if ($matter->closed_at === null) {
                    throw HandoverPackageFailed::notClosed();
                }

                $previous = $archive->handover_document_id === null ? null : $this->scopelessly(Document::query())
                    ->withTrashed()
                    ->lockForUpdate()
                    ->find($archive->handover_document_id);

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

                $storedMedia = $document->addMedia($zipPath)
                    ->usingName(FileGuard::safeName($fileName))
                    ->usingFileName(Str::lower((string) Str::ulid()).'.zip')
                    ->toMediaCollection('file');

                if ($previous !== null) {
                    $previousMedia = $previous->getMedia('file')->all();

                    // Version cũ đang mở cho khách thì gỡ nó cùng lúc tệp bị xoá — xem docblock lớp.
                    if ($previous->wasPublishedToClient()) {
                        $previous->update([
                            'status' => DocumentStatus::SignedFiled,
                            'client_can_view' => false,
                            'client_can_download' => false,
                        ]);
                    }
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
