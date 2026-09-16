<?php

namespace App\Support\Files;

use App\Exceptions\FileRejected;
use Illuminate\Http\UploadedFile;

/**
 * Cổng an ninh duy nhất cho mọi tệp đi vào hệ thống qua `UploadStaffDocument` và
 * `SubmitClientDocument` (SPEC §6.6 bước 2-4). Kiểm ba thứ, đúng thứ tự — đuôi tệp, kích thước,
 * rồi MIME thật — và không kiểm gì khác (quét virus là một collaborator riêng, `VirusScanner`, do
 * Action gọi sau khi `FileGuard::check()` đã qua; xem docblock interface đó).
 *
 * **Danh sách trắng là ranh giới an ninh, không phải một gợi ý.** SPEC §6.6 bước 2 liệt kê đúng
 * tám đuôi: `pdf jpg jpeg png doc docx xls xlsx`. Mọi đuôi khác bị từ chối, kể cả những đuôi
 * "trông vô hại" — `svg` bị cấm TƯỜNG MINH (không chỉ vì nó không có trong danh sách): một SVG là
 * XML, và XML có thể mang thẻ `<script>` chạy được ngay khi trình duyệt mở tệp — một "ảnh vô hại"
 * biến thành XSS lưu trữ chỉ bằng cách mở nó ra. `zip`/`rar` bị từ chối vì nội dung bên trong
 * không được kiểm tra ở lớp này (một zip chứa .exe qua được whitelist đuôi ngoài, nhưng người
 * nhận vẫn có thể giải nén ra thứ không được quét). `html`/`exe` là hiển nhiên.
 *
 * **Không tin `Content-Type` từ client.** Header đó do trình duyệt/HTTP client gửi lên và kẻ tấn
 * công kiểm soát hoàn toàn — đổi tên `virus.exe` thành `giay-to.pdf` và set `Content-Type:
 * application/pdf` chỉ tốn một dòng cURL. MIME thật được đọc bằng `finfo` ngay trên byte đầu tệp
 * (`realMimeType()`), một nguồn không client nào ghi đè được vì nó không đi qua request — nó đọc
 * lại chính tệp đã lưu xuống đĩa tạm.
 *
 * **Vì sao đuôi Office (`doc/xls/docx/xlsx`) chấp nhận cả MIME "chung chung".** `docx`/`xlsx`
 * thực chất là một gói ZIP (Office Open XML); `finfo` chỉ nhận diện được MIME OOXML cụ thể
 * (`application/vnd.openxmlformats-...`) khi các mục bên trong gói đúng thứ tự/đầy đủ theo một
 * heuristic của libmagic — một tệp `.docx`/`.xlsx` thật do Word/Excel xuất ra thường qua được,
 * nhưng không có gì đảm bảo tuyệt đối trên mọi phiên bản libmagic, nên `application/zip` cũng
 * được chấp nhận cho hai đuôi này. Tương tự, `doc`/`xls` cũ là Compound File Binary (OLE2);
 * `finfo` nhận diện dạng chung `application/x-ole-storage` khi không tìm thấy đủ stream đặc
 * trưng của Word/Excel bên trong. Việc nới này KHÔNG mở lỗ hổng thực thi: whitelist đuôi ở bước
 * trước đã loại `.zip` trần — MIME "chung chung" chỉ được chấp nhận khi đuôi tệp đã tự giới hạn
 * vào đúng bốn định dạng Office, và cả ZIP lẫn OLE2 đều là định dạng lưu trữ dữ liệu, không phải
 * định dạng thực thi được khi mở qua ứng dụng Office/trình duyệt.
 *
 * **Mọi nhánh lỗi ném đúng MỘT loại exception: `FileRejected`.** Kể cả những trường hợp không có
 * trong SPEC §11 nhưng vẫn phải quyết định một hành vi rõ ràng: tệp rỗng (`empty()`), tên không
 * có đuôi (`extensionNotAllowed('')`), tên chứa byte rỗng (`invalidName()`), đường dẫn không tồn
 * tại (`unreadable()`). Một Action gọi `FileGuard::check()` chỉ cần bắt một loại exception; không
 * có nhánh nào để lọt ra một `TypeError`/`ValueError` của PHP (xem `FileGuardTest` — các trường
 * hợp này được test riêng, "pin" hành vi thay vì để ngẫu nhiên).
 */
final class FileGuard
{
    /**
     * Đuôi tệp được phép → danh sách MIME thật được chấp nhận cho đuôi đó (SPEC §6.6 bước 2-3).
     * Đọc docblock lớp để biết vì sao `docx`/`xlsx`/`doc`/`xls` chấp nhận nhiều hơn một MIME.
     */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/x-cfb', 'application/CDFV2'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/x-cfb', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /**
     * @throws FileRejected
     */
    public static function check(UploadedFile|string $file): void
    {
        [$name, $path] = self::resolve($file);

        self::guardName($name);

        $extension = self::extensionOf($name);

        if (! isset(self::ALLOWED[$extension])) {
            throw FileRejected::extensionNotAllowed($extension);
        }

        self::guardReadablePath($path);

        $size = self::sizeOf($file, $path);

        if ($size === 0) {
            throw FileRejected::empty();
        }

        $maxMegabytes = (int) config('vkcrm.upload_max_mb');

        if ($size > $maxMegabytes * 1024 * 1024) {
            throw FileRejected::tooLarge($maxMegabytes);
        }

        $realMimeType = self::realMimeType($path);

        if (! in_array($realMimeType, self::ALLOWED[$extension], true)) {
            throw FileRejected::contentMismatch($extension, $realMimeType);
        }
    }

    /**
     * @return array{0: string, 1: string} [tên gốc, đường dẫn thật trên đĩa]
     */
    private static function resolve(UploadedFile|string $file): array
    {
        if ($file instanceof UploadedFile) {
            if (! $file->isValid()) {
                throw FileRejected::uploadFailed();
            }

            $path = $file->getRealPath();

            if ($path === false) {
                throw FileRejected::unreadable();
            }

            return [$file->getClientOriginalName(), $path];
        }

        return [basename($file), $file];
    }

    /**
     * Chặn byte rỗng trong tên TRƯỚC khi bất kỳ hàm hệ thống tệp nào chạm vào chuỗi này.
     * `finfo_file()` ném `TypeError` (không phải `FileRejected`) khi đường dẫn chứa byte rỗng —
     * xem docblock lớp — nên nhánh này phải chạy sớm nhất, trên chuỗi thuần, không qua filesystem.
     */
    private static function guardName(string $name): void
    {
        if (str_contains($name, "\0")) {
            throw FileRejected::invalidName();
        }
    }

    private static function guardReadablePath(string $path): void
    {
        if (str_contains($path, "\0") || ! is_file($path)) {
            throw FileRejected::unreadable();
        }
    }

    private static function extensionOf(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    /**
     * `UploadedFile::getSize()` đọc kích thước từ chính tệp tạm trên đĩa (Symfony), không phải từ
     * một header do client khai — cùng mức tin cậy như MIME thật, khác `Content-Length`.
     */
    private static function sizeOf(UploadedFile|string $file, string $path): int
    {
        if ($file instanceof UploadedFile) {
            return $file->getSize() ?? 0;
        }

        $size = filesize($path);

        return $size === false ? 0 : $size;
    }

    private static function realMimeType(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return $mime === false ? null : $mime;
    }
}
