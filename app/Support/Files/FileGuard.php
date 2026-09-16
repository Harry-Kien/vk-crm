<?php

namespace App\Support\Files;

use App\Exceptions\FileRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Cổng an ninh duy nhất cho mọi tệp đi vào hệ thống qua `UploadStaffDocument` và
 * `SubmitClientDocument` (SPEC §6.6 bước 2-4). Kiểm bốn thứ, đúng thứ tự — tên tệp, đuôi tệp,
 * kích thước, rồi NỘI DUNG (MIME thật bằng `finfo`, và với `docx`/`xlsx` là cả cấu trúc bên trong
 * gói), cộng một điều kiện tiên quyết là đường dẫn phải đọc được (`guardReadablePath()`, chạy
 * trước khi đo kích thước vì mọi bước sau đều phải mở tệp ra) — và không kiểm gì khác (quét virus là một collaborator riêng, `VirusScanner`, do Action
 * gọi sau khi `FileGuard::check()` đã qua; xem docblock interface đó).
 *
 * **Danh sách trắng là ranh giới an ninh, không phải một gợi ý.** SPEC §6.6 bước 2 liệt kê đúng
 * tám đuôi: `pdf jpg jpeg png doc docx xls xlsx`. Mọi đuôi khác bị từ chối. SPEC cũng nêu tên
 * một số đuôi phải từ chối (`zip`, `rar`, `html`, `svg`, `exe`) — đó là LÝ LẼ đằng sau hình dạng
 * của danh sách trắng, không phải một cổng thứ hai: một danh sách đen song song chỉ thêm một chỗ
 * nữa để quên cập nhật, và cái quên đó luôn nghiêng về phía cho qua. `svg` bị từ chối vì nó không
 * có trong tám đuôi trên, và nó không có trong tám đuôi trên vì một SVG là XML, mà XML mang được
 * thẻ `<script>` chạy ngay khi trình duyệt mở tệp — một "ảnh vô hại" biến thành XSS lưu trữ chỉ
 * bằng cách mở nó ra.
 *
 * **Không tin `Content-Type` từ client.** Header đó do trình duyệt/HTTP client gửi lên và kẻ tấn
 * công kiểm soát hoàn toàn — đổi tên `virus.exe` thành `giay-to.pdf` và set `Content-Type:
 * application/pdf` chỉ tốn một dòng cURL. MIME thật được đọc bằng `finfo` ngay trên byte đầu tệp
 * (`realMimeType()`), một nguồn không client nào ghi đè được vì nó không đi qua request — nó đọc
 * lại chính tệp đã lưu xuống đĩa tạm.
 *
 * **Đuôi và MIME được kiểm như một CẶP, không phải hai danh sách rời.** `ALLOWED` ánh xạ từng đuôi
 * tới đúng những MIME hợp lệ cho riêng đuôi đó, nên một thân OLE2 (`.doc`) đội tên `.jpg` vẫn bị
 * chặn dù cả hai đều nằm trong danh sách trắng khi xét riêng lẻ.
 *
 * **`docx`/`xlsx` phải chứng minh chúng là gói Office thật, không chỉ là một ZIP.** Office Open XML
 * là một gói ZIP, nên `finfo` có lúc chỉ đọc ra `application/zip` (libmagic chỉ nhận ra MIME OOXML
 * cụ thể khi thứ tự các mục trong gói hợp với heuristic của nó, và heuristic đó khác nhau giữa các
 * bản libmagic). Bản đầu của lớp này vì thế nhận `application/zip` cho hai đuôi đó, kèm lập luận
 * "whitelist đuôi đã loại `.zip` trần rồi" — lập luận đó loại được cái TÊN, không loại được cái
 * RUỘT: một ZIP tuỳ ý chứa `payload.exe`, đặt tên `bang-ke.docx`, đi thẳng qua cổng, và scanner
 * mặc định là `NullScanner` nên không còn lớp nào phía sau. Nay `verifyOfficePackage()` mở gói ra
 * và đòi đúng các mục bắt buộc của định dạng.
 *
 * **Mọi nhánh lỗi ném đúng MỘT loại exception: `FileRejected`.** Kể cả những trường hợp không có
 * trong SPEC §11 nhưng vẫn phải quyết định một hành vi rõ ràng: tệp rỗng (`empty()`), tên không
 * có đuôi (`extensionNotAllowed('')`), tên chứa ký tự điều khiển (`invalidName()`), đường dẫn
 * không tồn tại (`unreadable()`), gói Office không mở được (`packageUnreadable()`). Một Action
 * gọi `FileGuard::check()` chỉ cần bắt một loại exception; không có nhánh nào để lọt ra một
 * `TypeError`/`ValueError` của PHP (xem `FileGuardTest` — các trường hợp này được test riêng,
 * "pin" hành vi thay vì để ngẫu nhiên).
 *
 * **`check()` không sửa tên tệp, `safeName()` mới sửa.** `check()` chỉ từ chối những cái tên không
 * bao giờ hợp lệ (ký tự điều khiển, xuống dòng — mưu đồ chèn header HTTP). Việc chuẩn hoá phần
 * còn lại (bỏ đường dẫn, cắt độ dài cho vừa `media.file_name` varchar(255)) là việc của
 * `safeName()`, và **mọi nơi ghi tên tệp do client gửi xuống cơ sở dữ liệu hay vào một header
 * `Content-Disposition` phải đi qua `safeName()` trước** — xem docblock của nó.
 *
 * Nói rõ để không ai đọc nhầm: tới lúc này `safeName()` CHƯA có nơi gọi nào trong mã sản phẩm, vì
 * `UploadStaffDocument`/`SubmitClientDocument` (Task 3/4) và `DocumentDownloadController`
 * (Task 5) chưa tồn tại. Nó là cái móc dựng sẵn cho ba chỗ đó, không phải một ràng buộc đang được
 * thi hành; thứ duy nhất đang thi hành điều gì là `guardName()`, và nó chỉ biết TỪ CHỐI.
 */
final class FileGuard
{
    /**
     * Đuôi tệp được phép → danh sách MIME thật được chấp nhận cho đuôi đó (SPEC §6.6 bước 2-3).
     * Đọc docblock lớp để biết vì sao `doc`/`xls` chấp nhận nhiều hơn một MIME, và vì sao
     * `application/zip` cho `docx`/`xlsx` chỉ an toàn khi đi kèm `verifyOfficePackage()`.
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
     * Mục bắt buộc phải có bên trong gói Office Open XML, theo đuôi tệp. `[Content_Types].xml` là
     * mục gốc của MỌI gói OPC; mục thứ hai là phần thân riêng của từng loại (ECMA-376 phần 1).
     * Một gói thiếu chúng không phải một tài liệu Word/Excel, bất kể tên tệp nói gì.
     */
    private const OFFICE_PACKAGE_ENTRIES = [
        'docx' => ['[Content_Types].xml', 'word/document.xml'],
        'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
    ];

    /**
     * Giới hạn dự phòng khi `config('vkcrm.upload_max_mb')` thiếu hoặc không phải số dương.
     * Không dùng `(int) null === 0`: nó fail-closed nhưng vô nghĩa — mọi tệp đều "vượt quá 0 MB",
     * và người dùng không có cách nào hiểu hay xử lý một câu như vậy.
     */
    private const DEFAULT_MAX_MEGABYTES = 20;

    /** Đủ dài cho tên tệp thật, đủ ngắn để chắc chắn vừa `media.file_name` varchar(255). */
    private const MAX_NAME_LENGTH = 200;

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

        $maxMegabytes = self::maxMegabytes();

        if ($size > $maxMegabytes * 1024 * 1024) {
            throw FileRejected::tooLarge($maxMegabytes);
        }

        $realMimeType = self::realMimeType($path);

        if (! in_array($realMimeType, self::ALLOWED[$extension], true)) {
            // MIME thật chỉ đi vào log, không đi ra thông điệp — xem docblock lang/vi/documents.php.
            Log::warning('file_guard.content_mismatch', [
                'extension' => $extension,
                'detected_mime' => $realMimeType,
                'size' => $size,
                'name' => self::safeName($name),
            ]);

            throw FileRejected::contentMismatch($extension);
        }

        if (isset(self::OFFICE_PACKAGE_ENTRIES[$extension])) {
            self::verifyOfficePackage($path, $extension);
        }
    }

    /**
     * Chuẩn hoá một cái tên do client gửi lên thành một cái tên an toàn để LƯU và để HIỂN THỊ.
     *
     * Ba việc, mỗi việc chống một thứ khác nhau:
     * 1. `basename()` — `../../etc/cron.d/x.pdf` và `/etc/passwd.pdf` trở thành `x.pdf`,
     *    `passwd.pdf`. Medialibrary sinh tên tệp vật lý riêng nên đường dẫn trong tên gốc không
     *    tự nó ghi đè được gì, nhưng một cái tên mang đường dẫn không bao giờ là cái tên thật.
     * 2. Bỏ ký tự điều khiển, `"` và `;` — đây là phần chống chèn header: tên tệp cuối cùng đi
     *    vào `Content-Disposition` ở Task 5, và một tên chứa `\r\n` hay dấu nháy kép tách được
     *    header đó ra. `check()` đã TỪ CHỐI tên có ký tự điều khiển, nhưng `safeName()` phải tự
     *    đứng vững: nó còn được gọi trên các tên đã nằm sẵn trong cơ sở dữ liệu.
     * 3. Cắt còn {@see self::MAX_NAME_LENGTH} byte, GIỮ LẠI phần đuôi: `media.file_name` là
     *    `varchar(255)` nên một cái tên 600 ký tự hoặc bị cắt cụt mất đuôi, hoặc (trên MariaDB ở
     *    chế độ strict) làm cả lần lưu thất bại.
     *
     * Không thay dấu tiếng Việt: tên tệp là thứ khách nhìn lại để nhận ra hồ sơ của mình, và
     * `media.file_name` là cột `utf8mb4`.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        // Cố ý KHÔNG có cờ `/u`: với một tên không phải UTF-8 hợp lệ (điện thoại/máy quét cũ vẫn
        // sinh ra), `preg_replace` ở chế độ Unicode trả `null` và cả cái tên biến mất thành tên
        // dự phòng. Lọc theo BYTE an toàn ở đây vì các byte 0x00-0x1F và 0x7F không bao giờ xuất
        // hiện bên trong một chuỗi UTF-8 nhiều byte, nên chữ có dấu không bị chạm tới.
        $name = (string) preg_replace('/[\x00-\x1F\x7F";]+/', '', $name);
        $name = trim($name, " \t.");

        if ($name === '') {
            return __('documents.fallback_file_name');
        }

        if (strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.'.substr($extension, 0, 20);

        // `mb_strcut()` chứ không phải `substr()`: cắt theo BYTE (đúng thứ `varchar(255)` đếm)
        // nhưng lùi lại để không cắt vào giữa một ký tự nhiều byte — tên tệp tiếng Việt có dấu
        // là 2 byte mỗi ký tự. `mb_convert_encoding($x, 'UTF-8', 'UTF-8')` KHÔNG làm được việc
        // này: nó thay byte thừa bằng `?` chứ không bỏ đi, nên độ dài không đổi và tên vẫn lệch.
        $stem = mb_strcut($name, 0, self::MAX_NAME_LENGTH - strlen($suffix), 'UTF-8');

        return rtrim($stem, " \t.").$suffix;
    }

    /**
     * Mở gói ZIP ra và đòi đúng những mục mà định dạng bắt buộc phải có (`OFFICE_PACKAGE_ENTRIES`),
     * rồi từ chối gói nào mang dự án VBA.
     *
     * **Vì sao kiểm cả khi `finfo` đã nói đúng MIME OOXML.** MIME đó cũng chỉ là một heuristic của
     * libmagic đọc trên central directory của gói; đòi đúng mục bắt buộc là kiểm tra rẻ hơn, chắc
     * hơn và không đổi giữa các bản libmagic. Một tệp `.docx` thật do Word xuất ra luôn có
     * `word/document.xml`; không có nó thì nó không mở được bằng Word, nên không có tệp hợp lệ nào
     * bị chặn oan.
     *
     * **Macro: từ chối.** Một gói mang `vbaProject.bin` là một `.docm`/`.xlsm` đội tên `.docx`.
     * Chuẩn ECMA-376 dùng content type và đuôi RIÊNG cho định dạng có macro, mà danh sách trắng
     * SPEC §6.6 không có đuôi macro nào — nên từ chối ở đây không siết thêm gì so với SPEC, nó chỉ
     * làm cho việc đổi tên không lách được nữa. Macro là đường phát tán mã độc phổ biến nhất của
     * tài liệu Office, và người nhận ở đây là nhân viên văn phòng mở tệp bằng Word thật.
     *
     * **Những thứ CỐ Ý không kiểm ở đây, và vì sao.** Quan hệ ngoài (`TargetMode="External"`,
     * remote template), đối tượng nhúng (`oleObject`), và ZIP bomb đều không được xét:
     * - Cổng này trả lời đúng một câu hỏi — "tệp này có phải đúng cái định dạng nó khai không" —
     *   và trả lời bằng những dấu hiệu cấu trúc không mơ hồ. Một lần rà soát quan hệ/nội dung
     *   nhúng là việc đọc hiểu nội dung, tức việc của trình quét mã độc; làm một nửa ở đây chỉ
     *   tạo cảm giác an toàn sai, vì danh sách kiểu tấn công đó dài hơn bất kỳ danh sách nào lớp
     *   này giữ được cập nhật.
     * - Hệ thống KHÔNG BAO GIỜ tự mở các tệp này: không render, không chuyển đổi, không trích
     *   xuất. Chúng nằm yên trên disk `private` và chỉ được stream ra như tệp đính kèm (Task 5).
     *   Nên quan hệ ngoài và đối tượng nhúng chỉ kích hoạt được trên MÁY người nhận, sau khi họ
     *   mở bằng Office và bấm qua cảnh báo tin cậy của chính Office.
     * - Không chỗ nào giải nén gói, nên đường dẫn traversal trong tên mục và tỷ lệ nén của ZIP
     *   bomb đều không có tác dụng; `ZipArchive::locateName()` chỉ đọc central directory.
     * Lớp phòng thủ cho những thứ đó là `CLAMAV_ENABLED=true` (SPEC §6.6 bước 5) — đó là nút điều
     * khiển đúng chỗ, và đây là lý do nó tồn tại.
     *
     * **Không mở được thì từ chối.** Nếu `ext-zip` không có trên máy chủ, hoặc gói hỏng không mở
     * ra được, `check()` ném `packageUnreadable()` chứ không cho qua. Một cổng âm thầm hạ xuống
     * "chấp nhận" khi phần kiểm tra của nó không chạy được thì tệ hơn một cổng từ chối thẳng: nó
     * vẫn báo cáo là đang bảo vệ.
     *
     * @throws FileRejected
     */
    private static function verifyOfficePackage(string $path, string $extension): void
    {
        if (! class_exists(ZipArchive::class)) {
            Log::error('file_guard.zip_extension_missing', ['extension' => $extension]);

            throw FileRejected::packageUnreadable();
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw FileRejected::packageUnreadable();
        }

        try {
            foreach (self::OFFICE_PACKAGE_ENTRIES[$extension] as $entry) {
                if ($zip->locateName($entry) === false) {
                    throw FileRejected::notAnOfficePackage($extension);
                }
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name !== false && str_ends_with(strtolower($name), 'vbaproject.bin')) {
                    throw FileRejected::macroContent();
                }
            }
        } finally {
            $zip->close();
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
     * Chặn ký tự điều khiển trong tên TRƯỚC khi bất kỳ hàm hệ thống tệp nào chạm vào chuỗi này.
     *
     * Byte rỗng: `finfo_file()` ném `TypeError` (không phải `FileRejected`) khi đường dẫn chứa
     * byte rỗng — xem docblock lớp — nên nhánh này phải chạy sớm nhất, trên chuỗi thuần, không qua
     * filesystem.
     *
     * Các ký tự điều khiển còn lại (`\r`, `\n`, tab, C0, DEL): một cái tên như
     * `a"\r\nX-Injected: 1.pdf` không phải một cái tên, nó là một mưu đồ tách header
     * `Content-Disposition` ở phía dưới đường ống. `safeName()` cũng gỡ chúng, nhưng gỡ im lặng
     * một cái tên như vậy là giả vờ rằng nó hợp lệ; ở cổng vào thì từ chối thẳng đúng hơn.
     */
    private static function guardName(string $name): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
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

    private static function maxMegabytes(): int
    {
        $configured = config('vkcrm.upload_max_mb');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_MAX_MEGABYTES;
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

        // `finfo_open()` trả `false` khi cơ sở dữ liệu magic hỏng hoặc thiếu. Không bắt nhánh này
        // thì `finfo_file(false, ...)` ném `TypeError` — một exception KHÔNG phải `FileRejected`
        // lọt ra khỏi `check()`, tức đúng điều docblock lớp này khẳng định là không xảy ra. Gần
        // như không bao giờ xảy ra, nhưng "gần như không bao giờ" không phải "không".
        if ($finfo === false) {
            Log::error('file_guard.finfo_unavailable');

            throw FileRejected::unreadable();
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return $mime === false ? null : $mime;
    }
}
