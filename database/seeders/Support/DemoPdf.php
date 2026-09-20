<?php

namespace Database\Seeders\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Sinh một tệp PDF **thật** cho dữ liệu mẫu (SPEC §12), rồi gói nó thành `UploadedFile` để đưa
 * vào đúng hai Action nộp tệp của mã sản phẩm.
 *
 * **Vì sao phải là PDF thật, không phải một tệp giả.** `FileGuard::check()` đọc MIME bằng `finfo`
 * trên NỘI DUNG tệp (SPEC §6.6 bước 3), nên một tệp rỗng hay một chuỗi "fake pdf" bị chính cổng
 * nộp tệp từ chối — và nếu vòng qua cổng đó bằng cách ghi thẳng dòng `media` thì dữ liệu mẫu
 * không còn đi cùng đường với dữ liệu thật, đúng thứ mà việc gắn tệp vào seeder tồn tại để chứng
 * minh. Mỗi tệp ở đây vài trăm byte và mở được bằng một trình đọc PDF thật.
 *
 * **Vì sao dựng bằng tay thay vì kèm một tệp mẫu trong repo.** Mỗi tài liệu mẫu mang tên hồ sơ và
 * tên đầu mục của chính nó in lên trang, nên người mở dữ liệu demo ra thấy ngay tệp nào thuộc về
 * đâu — một tệp `mau.pdf` chép 30 lần thì không nói được gì. Và một bộ sinh vài chục dòng rẻ hơn
 * một tệp nhị phân trong lịch sử git.
 *
 * **Bảng `xref` được tính bằng byte offset thật.** Một PDF có xref sai vẫn mở được ở nhiều trình
 * đọc dễ tính, nhưng SPEC §12 đòi dữ liệu mẫu "dùng thật được ngay" — một tệp hỏng nằm sau một
 * nút tải về là một nút chưa được kiểm.
 *
 * Chữ in lên trang là ASCII không dấu, có chủ đích: font base-14 (`Helvetica`) chỉ có bảng mã
 * WinAnsi, nên chữ tiếng Việt có dấu sẽ ra ký tự rác. Tên tệp và `media.name` thì GIỮ dấu — đó là
 * thứ đi qua `FileGuard::safeName()` và là thứ người dùng nhìn thấy.
 */
final class DemoPdf
{
    /**
     * Một `UploadedFile` trỏ tới một tệp PDF thật trong thư mục tạm.
     *
     * Cờ `$test = true` của `UploadedFile` là bắt buộc ngoài môi trường HTTP: không có nó,
     * Symfony hỏi `is_uploaded_file()` và mọi thao tác đọc/di chuyển đều ném. Ở đây tệp đúng là
     * không đến từ một request, và đó là sự thật chứ không phải một lần lách kiểm tra.
     *
     * Mỗi lần gọi sinh một tệp MỚI: medialibrary DI CHUYỂN tệp nguồn khi gắn vào bản ghi, nên
     * dùng lại một đường dẫn cho hai tài liệu sẽ làm tài liệu thứ hai không còn tệp nào để gắn.
     */
    public static function upload(string $fileName, string $heading, string $subheading): UploadedFile
    {
        $path = sys_get_temp_dir().'/vkcrm-demo-'.Str::lower((string) Str::ulid()).'.pdf';

        file_put_contents($path, self::bytes($heading, $subheading));

        return new UploadedFile($path, $fileName, 'application/pdf', null, true);
    }

    /** Byte của một PDF một trang, hợp lệ, có bảng `xref` đúng. */
    public static function bytes(string $heading, string $subheading): string
    {
        $content = 'BT /F1 16 Tf 56 760 Td ('.self::escape($heading).") Tj ET\n"
            .'BT /F1 11 Tf 56 736 Td ('.self::escape($subheading).") Tj ET\n"
            ."BT /F1 9 Tf 56 700 Td (Tep mau cua du lieu demo VK-CRM - khong phai giay to that.) Tj ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf
            ."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n"
            ."startxref\n".$xrefOffset."\n%%EOF\n";
    }

    /**
     * Ba ký tự `(`, `)` và `\` kết thúc hoặc mở rộng được một chuỗi PDF, nên chúng phải được
     * thoát — và chữ ngoài ASCII bị bỏ vì font base-14 không vẽ được nó (xem docblock lớp).
     */
    private static function escape(string $value): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '', Str::ascii($value));

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
