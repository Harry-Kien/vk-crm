<?php

namespace App\Actions\Document\Concerns;

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\FileRejected;
use App\Models\Document;
use App\Support\Files\FileGuard;
use App\Support\Files\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Các bước 2-6 của SPEC §6.6 cộng bảng "Quy tắc mặc định khi tạo" ở SPEC §4.11, dùng chung cho
 * `UploadStaffDocument` (Task 3) và `SubmitClientDocument` (Task 4). Hai Action đó khác nhau ở
 * quyền, ở nhóm tài liệu và ở việc đánh version; chúng KHÔNG được phép khác nhau ở cách một tệp
 * đi vào hệ thống, cũng KHÔNG được phép khác nhau ở bộ cờ mặc định của cùng một nhóm — nhóm A
 * là nhóm CẢ HAI Action tạo ra ({@see self::defaultsFor()}), và một bảng SPEC chép ra hai chỗ là
 * một bảng sẽ lệch.
 *
 * Hai phương thức lo phần tệp, tách ra vì chúng chạy ở hai chỗ khác nhau so với transaction:
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

        // Không đọc được đường dẫn thì TỪ CHỐI, không lặng lẽ bỏ qua lần quét. Bản đầu viết
        // `if ($path !== false)` và vì thế bỏ qua bước 5 của SPEC §6.6 trong đúng trường hợp
        // không ai nhìn thấy. Hôm nay nhánh này không với tới được — `FileGuard::check()` đã
        // ném `unreadable()` cho cùng điều kiện vài dòng trước — nhưng một cái cổng hỏng theo
        // hướng CHO QUA là thứ docblock của `FileGuard` nói thẳng là không được có, và "hôm nay
        // không với tới được" là một tính chất của mã xung quanh, không phải của hàm này.
        if ($path === false) {
            throw FileRejected::unreadable();
        }

        app(VirusScanner::class)->scan($path);
    }

    /**
     * SPEC §6.6 bước 6: lưu qua medialibrary trên disk `private`, **tên tệp sinh ngẫu nhiên**.
     *
     * Hai cái tên, hai công việc khác nhau, và đây là chỗ duy nhất quyết định quan hệ giữa chúng:
     *
     * - `media.file_name` — cái tên NẰM TRÊN ĐĨA, và cũng là cái tên medialibrary dùng khi dựng
     *   đường dẫn. Phần THÂN sinh ngẫu nhiên hoàn toàn ({@see self::storedFileName()}); phần
     *   ĐUÔI thì lấy từ tên người nộp đặt, đã chuẩn hoá bằng một lớp ký tự và cắt còn 8 — nói
     *   cho đúng, vì bản đầu của đoạn này viết "không byte nào do người nộp chọn" trong khi
     *   docblock của chính `storedFileName()` 37 dòng bên dưới nói ngược lại. Cái SPEC §6.6 bước
     *   6 đòi và cái thật sự quan trọng là phần THÂN: người nộp có thể là khách hàng, tức một
     *   người ngoài hệ thống, và một cái tên do người ngoài đặt mà đi thẳng vào đường dẫn hệ
     *   thống tệp là một lớp tấn công không cần tồn tại. Phần đuôi đi qua `[^a-z0-9]` nên nó
     *   không mang được dấu chấm, gạch chéo, byte rỗng hay khoảng trắng — nó chọn được tám ký tự
     *   chữ-số, và không hơn.
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

    /**
     * Bảng "Quy tắc mặc định khi tạo" ở SPEC §4.11, chép thẳng thành mã. `match` vét cạn trên
     * enum nên thêm một nhóm mới vào `DocumentGroup` sẽ là một lỗi `UnhandledMatchError` ngay lần
     * chạy đầu, không phải một nhóm âm thầm nhận mặc định của nhóm khác.
     *
     * Nằm ở trait chứ không ở một Action vì cả hai Action đều tạo tài liệu nhóm A: nhân sự nộp
     * thay khách (`UploadStaffDocument`) và khách tự nộp qua portal (`SubmitClientDocument`) là
     * hai DÒNG của cùng một hàng trong bảng SPEC §4.11, và hàng đó phải được đọc ở một chỗ.
     *
     * **Caller không chọn được ba cờ khách hàng.** `client_can_view`, `client_can_download` và
     * `status` được suy ra từ `$group` chứ không có tham số nào ghi đè được. Đây là cách "nhóm D:
     * client_can_download vĩnh viễn false" (SPEC §4.11) trở thành một điều không diễn đạt nổi ở
     * tầng gọi, thay vì một điều mà mọi màn hình phải nhớ tự tay đặt đúng.
     *
     * @return array{status: DocumentStatus, client_can_view: bool, client_can_download: bool}
     */
    protected function defaultsFor(DocumentGroup $group): array
    {
        return match ($group) {
            // A — khách cung cấp (khách tự nộp, hoặc nhân viên nộp thay): khách xem và tải được ngay.
            DocumentGroup::ClientProvided => [
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => true,
            ],
            // B — văn bản văn phòng phát hành: còn phải đi hết vòng đời trước khi khách thấy.
            // C — văn bản từ cơ quan nhà nước: nhân sự đọc trước, công bố sau (SPEC §6.5).
            DocumentGroup::Issued, DocumentGroup::Authority => [
                'status' => DocumentStatus::InternalDraft,
                'client_can_view' => false,
                'client_can_download' => false,
            ],
            // D — hồ sơ công việc nội bộ. `client_can_download` là **vĩnh viễn** false; ở đây nó
            // chỉ là giá trị khởi tạo. Hai thứ giữ cho nó false về sau là `PublishDocument` (chặn
            // tuyệt đối nhóm D) và hook `saving` của `Document`, thứ ép cờ này về false trên mọi
            // dòng nhóm D kể cả khi lệnh ghi đi vòng qua Action.
            DocumentGroup::Internal => [
                'status' => DocumentStatus::InternalDraft,
                'client_can_view' => false,
                'client_can_download' => false,
            ],
        };
    }

    /**
     * "Bộ mặc định này đã đưa tài liệu ra tới khách chưa" — ĐỊNH NGHĨA DUY NHẤT, đọc ra từ bảng
     * SPEC §4.11 chứ không từ một chữ cái nhóm. Các chỗ cần câu trả lời (cổng `document.publish`
     * của `UploadStaffDocument`, việc đóng đầu mục danh mục, dòng nhật ký công bố, và cặp
     * `published_at`/`published_by` ở cả hai Action) đều hỏi ở đây, nên thêm hay sửa một nhóm
     * trong {@see self::defaultsFor()} là đủ.
     *
     * Hai điều kiện chứ không một: `status = published` một mình nói "đã qua vòng đời", còn
     * `client_can_view` mới nói "khách đọc được". Cùng cặp điều kiện mà `PublishDocument` dùng
     * để biết một tài liệu đã tới tay khách hay chưa.
     *
     * @param  array{status: DocumentStatus, client_can_view: bool, client_can_download: bool}  $defaults
     */
    protected function releasesToClientAtCreation(array $defaults): bool
    {
        return $defaults['status'] === DocumentStatus::Published && $defaults['client_can_view'];
    }
}
