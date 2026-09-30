<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\HandoverPackageFailed;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Support\Files\FileGuard;
use App\Support\Handover\HandoverEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Normalizer;

/**
 * M7 Task 4, R8 — CHỌN tài liệu nào vào gói bàn giao và đặt tên entry cho chúng. Chỉ đọc, không
 * ghi gì; {@see BuildHandoverPackage} gọi nó rồi dùng cùng một danh sách cho zip và mục lục.
 *
 * # Luật chọn (R8 của kế hoạch — đối chiếu SPEC §4.11, khách không bao giờ thấy "một bản đơn mà toà
 * chưa hề nhận được")
 *
 *  - **Nhóm A** (khách cung cấp): mọi tệp của version MỚI NHẤT của một đầu mục danh mục ĐÃ ĐƯỢC
 *    CHẤP NHẬN (`accepted`). Một lần nộp có thể nhiều tệp (M6.5 R10) — chúng cùng một `version`,
 *    nên lấy hết. Bỏ version bị từ chối và version đã bị thay thế bằng bản mới hơn: đầu mục chỉ
 *    `accepted` khi bản MỚI NHẤT đã được duyệt, nên "version mới nhất của một đầu mục đã accepted"
 *    chính là bản đã chấp nhận. Đầu mục chưa duyệt/bị từ chối/không áp dụng/đã xoá → không có gì.
 *    Tài liệu nhóm A không gắn đầu mục nào (nhân sự nộp thay, không qua danh mục) không có luồng
 *    duyệt để mà "chưa chấp nhận" — nó vào gói như một bản của khách (quyết định của task, ghi ở
 *    báo cáo).
 *  - **Nhóm B và C**: chỉ `signed_filed` hoặc `published`. Nhóm B còn `internal_draft`/
 *    `pending_approval` là một bản nháp, không phải thứ đã ra khỏi văn phòng.
 *  - **Cùng danh sách trắng trạng thái ({@see self::RELEASED_STATUSES}) áp cho CẢ nhóm A**, ở cả
 *    hai nhánh (đầu mục đã duyệt, và không gắn đầu mục). Tài liệu nhóm A đi đường thường luôn ở
 *    `published` (`StoresDocumentFile::defaultsFor()`), nên luật này không bớt gì của đường
 *    thường; nó bắt hai trường hợp khác: một tài liệu ĐỔI NHÓM sang A (`RegroupDocument` chỉ đổi
 *    `group`, giữ `status` — một bản từ nhóm D sang A vẫn `internal_draft`, khách chưa từng được
 *    thấy), và trạng thái "đã rút" của Task 7. Danh sách TRẮNG, không phải danh sách đen: một
 *    trạng thái mới thêm sau (Task 7 `retracted`) tự nằm ngoài gói ở MỌI nhóm cho tới khi có người
 *    cố ý thêm nó vào. Với đầu mục đã duyệt, "version mới nhất" tính trên MỌI version rồi mới lọc
 *    trạng thái: version mới nhất chưa phát hành (hay bị rút) thì đầu mục không có gì trong gói,
 *    chứ không lùi về một version đã bị thay.
 *  - **Không bao giờ**: nhóm D; tài liệu đã xoá mềm (`SoftDeletingScope` của `Document` giữ
 *    nguyên — truy vấn dưới đây KHÔNG gọi `withTrashed()`); chính tài liệu gói của lần trước, ở
 *    MỌI version ({@see MatterArchive::handoverDocumentIds()}); tài liệu không có tệp nào.
 *
 * # Tên entry
 *
 * `<nhóm>/<NN>-<tên an toàn>.<đuôi>`. `NN` là số thứ tự trong mục lục (đệm số 0, tối thiểu 2 chữ
 * số): tiêu đề không duy nhất và có thể chứa `/` hay `..`, còn số thứ tự thì duy nhất và không
 * bao giờ chứa dấu phân cách — hai tài liệu cùng tiêu đề không đè nhau, và một tiêu đề `../x` không
 * thoát khỏi thư mục nhóm. Đuôi lấy từ TỆP THẬT trên đĩa (`media.file_name`, đã qua `FileGuard`
 * lúc nhận), không từ tiêu đề.
 *
 * Tiêu đề đi qua {@see FileGuard::safeName()} — nhưng `/` và `\` được đổi thành `-` TRƯỚC đó:
 * `safeName()` lấy `basename()`, mà tiêu đề văn bản pháp lý đầy `/` ("Bản án số 12/2024/DS-ST"),
 * nên bỏ qua bước này thì entry chỉ còn "DS-ST" và mất gần hết nghĩa. Thêm vào đó: ký tự Windows
 * không cho phép trong tên tệp (`< > : | ? *`) đổi thành `-` (một bản zip mà khách giải nén trên
 * Windows không được lỗi vì tên), chuẩn hoá NFC (dấu tiếng Việt dựng sẵn, một số máy Mac ghi NFD),
 * cắt phần tên còn 100 ký tự.
 */
class CollectHandoverEntries
{
    use ReadsWithoutPortalScope;

    private const MAX_TITLE_CHARS = 100;

    /** Trạng thái được vào gói, ở MỌI nhóm — xem docblock lớp. */
    private const RELEASED_STATUSES = [DocumentStatus::SignedFiled->value, DocumentStatus::Published->value];

    /**
     * @return Collection<int, HandoverEntry>
     */
    public function handle(Matter $matter, MatterArchive $archive): Collection
    {
        $excluded = $archive->handoverDocumentIds();

        $documents = $this->clientProvided($matter, $excluded)
            ->concat($this->issuedOrAuthority($matter, $excluded));

        $pairs = [];

        foreach ($documents as $document) {
            $media = $document->getFirstMedia('file');

            // Một `Document` không có tệp (bản ghi mồ côi) không có gì để đưa vào gói.
            if ($media === null) {
                continue;
            }

            $pairs[] = [$document, $media];
        }

        $width = max(2, strlen((string) count($pairs)));

        return collect($pairs)->map(function (array $pair, int $index) use ($width): HandoverEntry {
            [$document, $media] = $pair;

            $relative = $media->getPathRelativeToRoot();
            $disk = Storage::disk($media->disk);

            if (! $disk->exists($relative)) {
                throw HandoverPackageFailed::missingFile($document->title);
            }

            $number = $index + 1;

            return new HandoverEntry(
                number: $number,
                group: $document->group,
                title: $document->title,
                zipPath: $document->group->value.'/'.str_pad((string) $number, $width, '0', STR_PAD_LEFT).'-'
                    .$this->entryName($document->title, (string) $media->file_name),
                sourcePath: $disk->path($relative),
                documentId: $document->getKey(),
                date: $document->issued_at ?? $document->published_at ?? $document->created_at,
            );
        })->values();
    }

    /**
     * @param  Collection<int, int>  $excluded
     * @return Collection<int, Document>
     */
    private function clientProvided(Matter $matter, Collection $excluded): Collection
    {
        // Đầu mục còn sống và đã được chấp nhận. `SoftDeletingScope` của `MatterChecklistItem` loại
        // đầu mục đã xoá.
        $acceptedItems = $this->scopelessly(MatterChecklistItem::query())
            ->where('matter_id', $matter->getKey())
            ->where('status', ChecklistItemStatus::Accepted->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $documents = collect();

        foreach ($acceptedItems as $item) {
            $latestVersion = $this->scopelessly(Document::query())
                ->where('matter_id', $matter->getKey())
                ->where('matter_checklist_item_id', $item->getKey())
                ->where('group', DocumentGroup::ClientProvided->value)
                ->whereNotIn('id', $excluded->all())
                ->max('version');

            if ($latestVersion === null) {
                continue;
            }

            $documents = $documents->concat(
                $this->scopelessly(Document::query())
                    ->where('matter_id', $matter->getKey())
                    ->where('matter_checklist_item_id', $item->getKey())
                    ->where('group', DocumentGroup::ClientProvided->value)
                    ->where('version', $latestVersion)
                    ->whereIn('status', self::RELEASED_STATUSES)
                    ->whereNotIn('id', $excluded->all())
                    ->orderBy('id')
                    ->get(),
            );
        }

        // Nhóm A do nhân sự nộp thay, không gắn đầu mục nào — xem docblock lớp.
        $unlinked = $this->scopelessly(Document::query())
            ->where('matter_id', $matter->getKey())
            ->whereNull('matter_checklist_item_id')
            ->where('group', DocumentGroup::ClientProvided->value)
            ->whereIn('status', self::RELEASED_STATUSES)
            ->whereNotIn('id', $excluded->all())
            ->orderBy('id')
            ->get();

        return $documents->concat($unlinked)->values();
    }

    /**
     * @param  Collection<int, int>  $excluded
     * @return Collection<int, Document>
     */
    private function issuedOrAuthority(Matter $matter, Collection $excluded): Collection
    {
        return $this->scopelessly(Document::query())
            ->where('matter_id', $matter->getKey())
            ->whereIn('group', [DocumentGroup::Issued->value, DocumentGroup::Authority->value])
            ->whereIn('status', self::RELEASED_STATUSES)
            ->whereNotIn('id', $excluded->all())
            ->orderBy('group')
            ->orderByRaw('issued_at is null')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Phần `<tên an toàn>.<đuôi>` của entry — xem docblock lớp.
     */
    private function entryName(string $title, string $storedFileName): string
    {
        $extension = strtolower((string) pathinfo($storedFileName, PATHINFO_EXTENSION));
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        $clean = str_replace(['/', '\\'], '-', $title);
        $clean = (string) preg_replace('/[<>:|?*]/', '-', $clean);
        $clean = FileGuard::safeName($clean);

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($clean, Normalizer::FORM_C);
            $clean = $normalized === false ? $clean : $normalized;
        }

        // Tiêu đề đã kết thúc bằng chính đuôi thật ("Đơn khởi kiện.pdf") thì không nối thêm lần nữa.
        if ($extension !== '' && str_ends_with(strtolower($clean), '.'.$extension)) {
            $clean = substr($clean, 0, -(strlen($extension) + 1));
        }

        $clean = mb_substr($clean, 0, self::MAX_TITLE_CHARS);
        $clean = rtrim($clean, " .\t");

        if ($clean === '') {
            $clean = __('documents.fallback_file_name');
        }

        return $extension === '' ? $clean : $clean.'.'.$extension;
    }
}
