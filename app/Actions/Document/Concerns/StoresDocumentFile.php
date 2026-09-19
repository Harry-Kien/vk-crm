<?php

namespace App\Actions\Document\Concerns;

use App\Exceptions\FileRejected;
use App\Models\Document;
use App\Support\Files\FileGuard;
use App\Support\Files\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Các bước 2-6 của SPEC §6.6, dùng chung cho `UploadStaffDocument` (Task 3) và
 * `SubmitClientDocument` (Task 4). Hai Action đó khác nhau ở quyền, ở nhóm tài liệu và ở việc
 * đánh version; chúng KHÔNG được phép khác nhau ở cách một tệp đi vào hệ thống.
 *
 * Hai phương thức, tách ra vì chúng chạy ở hai chỗ khác nhau so với transaction:
 *
 * - `guardFile()` (bước 2-5) chạy TRƯỚC khi mở transaction. Quét virus có thể nói chuyện với một
 *   daemon qua socket và `config('vkcrm.clamav.timeout')` cho nó tới 30 giây; giữ một transaction
 *   mở suốt thời gian đó là giữ khoá hàng trên `documents` trong khi chờ mạng.
 * - `storeFile()` (bước 6) chạy BÊN TRONG transaction, sau khi `Document` đã có id — medialibrary
 *   cần một model đã lưu.
 *
 * **Tệp có thể sống sót qua một lần rollback.** `toMediaCollection()` ghi ra disk `private` ngay,
 * còn dòng `media` thì biến mất nếu transaction rollback. Chấp nhận có chủ đích: hậu quả là một
 * tệp rác không có dòng nào trỏ tới. Nó không ai tới được CHỪNG NÀO đường tải duy nhất vẫn là một
 * route giải bản ghi `media` rồi mới đọc đĩa — đó là hình dạng bắt buộc của
 * `DocumentDownloadController` ở Task 5 (controller đó chưa tồn tại lúc viết dòng này), chứ không
 * phải một tính chất đang được thi hành ở đâu đó. Đánh đổi ngược lại
 * (ghi tệp sau khi commit) tạo ra một `Document` đã lưu mà không có tệp, tức một dòng hỏng mà
 * giao diện và khách đều nhìn thấy.
 */
trait StoresDocumentFile
{
    /**
     * SPEC §6.6 bước 2-5: đuôi tệp, MIME thật, kích thước, rồi quét virus — đúng thứ tự đó.
     * Quét sau `FileGuard::check()` chứ không trước: không gửi một tệp 20 MB sai định dạng qua
     * socket tới clamd chỉ để biết nó là `.svg`.
     *
     * Cả hai bước đều ném `FileRejected` và không ghi gì, nên gọi nó trước transaction không để
     * lại trạng thái dở dang nào.
     *
     * @throws FileRejected
     */
    protected function guardFile(UploadedFile $file): void
    {
        FileGuard::check($file);

        $path = $file->getRealPath();

        if ($path !== false) {
            app(VirusScanner::class)->scan($path);
        }
    }

    /**
     * SPEC §6.6 bước 6: lưu qua medialibrary trên disk `private`, **tên tệp sinh ngẫu nhiên**.
     *
     * Hai cái tên, hai công việc khác nhau, và đây là chỗ duy nhất quyết định quan hệ giữa chúng:
     *
     * - `media.file_name` — cái tên NẰM TRÊN ĐĨA, và cũng là cái tên medialibrary dùng khi dựng
     *   đường dẫn. Sinh ngẫu nhiên hoàn toàn ({@see self::storedFileName()}), nên không byte nào
     *   của nó do người nộp chọn. Đây là điều SPEC §6.6 bước 6 đòi, và lý do là: người nộp tệp có
     *   thể là khách hàng, tức một người ngoài hệ thống, và một cái tên do người ngoài đặt mà đi
     *   thẳng vào đường dẫn hệ thống tệp là một lớp tấn công không cần tồn tại.
     * - `media.name` — cái tên HIỂN THỊ, đi qua `FileGuard::safeName()`. Khách phải nhận ra được
     *   hồ sơ của chính mình: một danh sách toàn `01k5g…3m.pdf` thì vô dụng với người đã gửi lên
     *   "CCCD mặt trước.jpg". `safeName()` bỏ đường dẫn, ký tự điều khiển, `"` và `;` (những thứ
     *   tách được một header `Content-Disposition`) và cắt cho vừa `varchar(255)`, nhưng GIỮ dấu
     *   tiếng Việt — xem docblock của nó. Đây chính là nơi gọi mà Task 1 ghi lại là còn thiếu.
     *
     * Tên hiển thị giữ cả phần đuôi (`.pdf`), khác thói quen mặc định của medialibrary (tên không
     * đuôi). Lý do là để Task 5 dùng được nó nguyên vẹn làm tên tệp trong `Content-Disposition`:
     * một tệp tải về không có đuôi thì Windows không biết mở bằng gì. Task 5 chưa tồn tại, nên đây
     * là một điều kiện được chuẩn bị sẵn, không phải một điều đang xảy ra.
     */
    protected function storeFile(Document $document, UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();

        $document->addMedia($file)
            ->usingName(FileGuard::safeName($originalName))
            ->usingFileName($this->storedFileName($originalName))
            ->toMediaCollection('file');
    }

    /**
     * ULID viết thường (26 ký tự) cộng phần đuôi đã chuẩn hoá. Không trùng nhau, phần ngẫu nhiên
     * 80 bit không đoán được, và sắp xếp được theo thời gian — tiện cho người vận hành khi phải
     * soi thư mục, mà không nói gì thêm về nội dung.
     *
     * Đuôi được GIỮ LẠI, vì tệp cuối cùng vẫn được stream ra cho người dùng mở bằng Word/Excel.
     * Nó được chuẩn hoá bằng một lớp KÝ TỰ (`[^a-z0-9]` bị bỏ, cắt còn 8) chứ không bằng một danh
     * sách trắng thứ hai: `FileGuard::ALLOWED` đã là danh sách trắng duy nhất, và chép nó ra đây
     * chỉ thêm một chỗ nữa để quên cập nhật. Lớp ký tự thì đúng cho MỌI giá trị, kể cả khi một
     * ngày nào đó có người gọi `storeFile()` mà quên `guardFile()`: dấu chấm, gạch chéo, byte rỗng
     * và khoảng trắng đều không sống sót, nên cái tên sinh ra luôn là một tên tệp phẳng, an toàn.
     */
    private function storedFileName(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = substr((string) preg_replace('/[^a-z0-9]/', '', $extension), 0, 8);

        return Str::lower((string) Str::ulid()).($extension === '' ? '' : '.'.$extension);
    }
}
