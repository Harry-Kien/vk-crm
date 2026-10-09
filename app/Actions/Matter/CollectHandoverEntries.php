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
 * Tiêu đề đi qua {@see FileGuard::safeName()} (R8), theo thứ tự ({@see self::entryName()}):
 *  1. `mb_scrub()` — UTF-8 hợp lệ trước mọi bước theo ký tự (byte lạc thành `?`); `/` và `\` đổi
 *     thành `-` (`safeName()` lấy `basename()`, mà tiêu đề văn bản pháp lý đầy `/` — "Bản án số
 *     12/2024/DS-ST" sẽ chỉ còn "DS-ST"); chuẩn hoá NFC (dấu tiếng Việt dựng sẵn, một số máy Mac
 *     ghi NFD).
 *  2. `safeName(<tiêu đề>.<đuôi thật>)` — KHÔNG `safeName(<tiêu đề>)`: `safeName()` coi phần sau
 *     dấu chấm CUỐI là đuôi tệp và cắt nó còn 20 byte, mà tiêu đề đầy dấu chấm (ngày "05.3.2026",
 *     "TP.", "v.v."). Đưa tiêu đề trơn vào thì "Biên bản … ngày 05.3.2026 với Toà án nhân dân quận
 *     Hải Châu" thành "… với Toà án", và "Đơn. Yêu cầu bồi thường…" bị cắt giữa chữ "ư" (vòng sửa
 *     1). Nối đuôi thật vào thì phần nó tách ra đúng là đuôi thật; phần tên chỉ mất ký tự điều
 *     khiển, `"`, `;` và dấu chấm/khoảng trắng ở hai đầu, và nếu dài quá 200 byte thì bị cắt ở
 *     ranh giới ký tự (`mb_strcut`).
 *  3. Bỏ lại đuôi vừa nối, và bỏ thêm một lần nếu chính tiêu đề kết thúc bằng đuôi thật.
 *  4. Cắt còn 100 ký tự (`mb_substr` trên chuỗi đã hợp lệ ở bước 1).
 *  5. Ký tự Windows không cho phép trong tên tệp (`< > : " / \ | ? *`) đổi thành `-`, SAU mọi bước
 *     cắt — một bản zip khách giải nén trên Windows không được lỗi vì tên; bỏ dấu chấm và khoảng
 *     trắng cuối (Windows cũng tự bỏ chúng).
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

            // M14 (kế hoạch R3, R12): trên kho, `exists()` trả lời từ CHỈ MỤC, không gọi mạng; và
            // KHÔNG `$disk->path()` — trên đĩa không cục bộ nó trả một chuỗi không tồn tại mà không
            // báo lỗi. Tệp được tải về lúc nén (`MaterialiseStoredFile`).
            if (! Storage::disk($media->disk)->exists($relative)) {
                throw HandoverPackageFailed::missingFile($document->title);
            }

            $number = $index + 1;
            $md5 = $media->getAttribute('checksum_md5');

            return new HandoverEntry(
                number: $number,
                group: $document->group,
                title: $document->title,
                zipPath: $document->group->value.'/'.str_pad((string) $number, $width, '0', STR_PAD_LEFT).'-'
                    .self::entryName($document->title, (string) $media->file_name),
                mediaId: (int) $media->getKey(),
                disk: (string) $media->disk,
                relativePath: $relative,
                size: (int) $media->size,
                md5: is_string($md5) && $md5 !== '' ? $md5 : null,
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
     * Phần `<tên an toàn>.<đuôi>` của entry — luật và THỨ TỰ các bước ở docblock lớp, mục "Tên
     * entry". Hàm thuần (không đọc DB, không đọc đĩa); `public` để test phủ được cả những đầu vào
     * không đi qua cột `title` của MariaDB (UTF-8 hỏng).
     */
    public static function entryName(string $title, string $storedFileName): string
    {
        $extension = strtolower((string) pathinfo($storedFileName, PATHINFO_EXTENSION));
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);
        $suffix = $extension === '' ? '' : '.'.$extension;

        // 1. UTF-8 hợp lệ trước mọi bước theo ký tự: byte lạc thành "?" (bước 5 đổi nó thành "-").
        $clean = mb_scrub($title, 'UTF-8');
        $clean = str_replace(['/', '\\'], '-', $clean);

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($clean, Normalizer::FORM_C);
            $clean = $normalized === false ? $clean : $normalized;
        }

        // 2. `safeName()` với ĐUÔI THẬT nối vào: phần sau dấu chấm cuối mà nó tách ra làm đuôi là
        //    đuôi thật, không phải một mẩu tiêu đề. Không có đuôi thật thì nối một dấu chấm trơn —
        //    `pathinfo("x.y.")` cho đuôi rỗng, nên dấu chấm trong tiêu đề vẫn không bị đọc là đuôi.
        $clean = FileGuard::safeName($clean.'.'.$extension);

        // 3. Bỏ lại đuôi vừa nối; rồi một lần nữa nếu chính tiêu đề kết thúc bằng đuôi thật
        //    ("Đơn khởi kiện.pdf") — không lặp đuôi.
        if ($suffix !== '' && str_ends_with($clean, $suffix)) {
            $clean = substr($clean, 0, -strlen($suffix));
        }

        if ($suffix !== '' && str_ends_with(strtolower($clean), $suffix)) {
            $clean = substr($clean, 0, -strlen($suffix));
        }

        // 4. Cắt theo KÝ TỰ trên chuỗi UTF-8 hợp lệ (bước 1): không bao giờ dừng giữa một ký tự.
        $clean = mb_substr($clean, 0, self::MAX_TITLE_CHARS);

        // 5. Ký tự Windows cấm trong tên tệp → "-", SAU mọi bước cắt; bỏ dấu chấm và khoảng trắng cuối.
        $clean = (string) preg_replace('/[<>:"\/\\\\|?*]/', '-', $clean);
        $clean = rtrim($clean, " .\t");

        if ($clean === '') {
            $clean = __('documents.fallback_file_name');
        }

        return $clean.$suffix;
    }
}
