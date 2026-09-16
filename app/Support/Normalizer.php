<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Chuẩn hoá dữ liệu để so khớp xung đột lợi ích (SPEC §6.10 bước 1).
 * Không bao giờ lưu số căn cước gốc ở đây; chỉ lưu hash.
 */
final class Normalizer
{
    /** Số thuê bao Việt Nam ngắn nhất còn gặp trong dữ liệu tiếp nhận: 8 chữ số (cố định kế hoạch cũ). */
    private const SUBSCRIBER_MIN_DIGITS = 8;

    /** Dài nhất: 10 chữ số — cố định hiện hành, và di động 11 số của kế hoạch trước 2018. */
    private const SUBSCRIBER_MAX_DIGITS = 10;

    public static function name(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $ascii = Str::ascii(mb_strtolower(trim($value), 'UTF-8'));

        return trim(preg_replace('/\s+/u', ' ', $ascii)) ?: null;
    }

    /**
     * Đưa mọi cách viết của cùng một số điện thoại Việt Nam về `84xxxxxxxxx` (SPEC §6.10 bước 1).
     *
     * Đây là một trong ba tầng khớp của kiểm tra xung đột lợi ích, và là tầng MẠNH DUY NHẤT còn
     * lại khi một bên không có số căn cước — tình trạng bình thường của bên đối lập. Hai cách
     * viết khác nhau của cùng một số ở đây = một xung đột lợi ích bị bỏ sót, im lặng.
     *
     * **Vì sao phải đoán cả số đã mất số 0 đầu.** Bản xuất Excel/CSV coi `0912345678` là số và
     * cắt mất số 0 đứng đầu; dữ liệu nhập vào văn phòng thật sự tồn tại song song cả hai cách
     * viết. Trước đây `912345678` rơi hết mọi nhánh và giữ nguyên, không bao giờ khớp với
     * `84912345678`.
     *
     * **Quy tắc độ dài: 8–10 chữ số.** Phần thuê bao (sau số 0 gọi nội hạt) KHÔNG cùng độ dài cho
     * mọi loại số trong kế hoạch đánh số hiện hành:
     *  - di động: 9 chữ số (`09x xxx xxxx` — 10 chữ số kể cả số 0);
     *  - CỐ ĐỊNH: 10 chữ số — 2 chữ số mã vùng + 8 chữ số thuê bao ở Hà Nội (`024`) và TP.HCM
     *    (`028`), 3 + 7 ở các tỉnh còn lại (`0292` Cần Thơ) — tức 11 chữ số kể cả số 0;
     *  - di động 11 chữ số của kế hoạch TRƯỚC 2018 (`0166 123 4567`), vẫn còn rải rác trong dữ
     *    liệu tiếp nhận cũ: cũng 10 chữ số sau số 0;
     *  - một số cố định cũ/ngắn: 8 chữ số.
     *
     * Bản sửa trước đặt trần ở 9 trên tiền đề "phần thuê bao là 9 chữ số cho cả di động lẫn cố
     * định có mã vùng". Tiền đề đó SAI, và sai đúng ở loại số mà một bên đối lập là DOANH NGHIỆP
     * nhiều khả năng có nhất: `028 3822 1234` bị Excel ăn mất số 0 thành `2838221234` và không
     * bao giờ khớp lại với chính nó viết theo cách khác — đúng lỗi mà quy tắc này sinh ra để vá.
     *
     * **Một dãy TRẦN dài đúng 10 chữ số còn có thể là gì nữa?** Đã cân nhắc trước khi nới trần:
     *  - một số di động CÒN số 0 (`0912345678`): không bao giờ tới được nhánh này, nhánh "cách
     *    viết trong nước" bắt trước;
     *  - mã số thuế doanh nghiệp (10 chữ số) gõ nhầm vào ô điện thoại: phần lớn bắt đầu bằng `0`
     *    nên đã rơi vào nhánh trong nước từ trước bản sửa này — nới trần không tạo hạng lỗi mới;
     *  - số căn cước 12 chữ số nằm ngoài trần; CMND cũ 9 chữ số thì đã nằm trong quy tắc từ trước;
     *  - một số NƯỚC NGOÀI viết không có dấu `+` (`6591234567`): nay bị thêm `84`. Đây là cái giá
     *    thật của việc nới, và nó KHÔNG phải một khớp nhầm: hai giá trị chỉ gặp nhau khi dãy chữ
     *    số giống hệt nhau, mà để một số Việt Nam ra cùng giá trị thì phải tồn tại cách viết
     *    trong nước `0` + đúng 10 chữ số đó — một số 11 chữ số không có trong kế hoạch đánh số.
     *    Cái mất là một giá trị lưu trông "sai quốc tịch", không phải một cảnh báo sai.
     *
     * **Cố ý chọn phía "chuẩn hoá thừa".** Nếu đoán sai, hậu quả xấu nhất là một cảnh báo
     * vàng/đỏ thừa mà luật sư bấm bỏ qua; nếu đoán thiếu, hậu quả là văn phòng nhận việc chống
     * lại chính khách hàng của mình. Hai sai lầm này không cùng hạng, nên nghi ngờ thì chuẩn hoá.
     *
     * **`84` đứng đầu một dãy 9 chữ số là đầu số thuê bao, KHÔNG phải mã quốc gia.** `084` là
     * đầu số VinaPhone có thật: `0843123456` mất số 0 thành `843123456`. Đọc `84` ở đây như mã
     * quốc gia sẽ để lại phần thuê bao 7 chữ số — độ dài không tồn tại trong kế hoạch đánh số —
     * và `843123456` sẽ không bao giờ khớp `0843123456`. Vì vậy nhánh mã quốc gia chỉ nhận khi
     * phần còn lại (sau khi bỏ số 0 gọi nội hạt thừa) đủ dài để là một số thuê bao thật.
     *
     * **Vì sao hai nhánh có tiền tố chỉ có SÀN, còn nhánh trần có cả trần.** Một số 0 gọi nội hạt
     * hay một `+84` do người nhập viết ra là một KHẲNG ĐỊNH "đây là số Việt Nam"; ở đó chỉ cần từ
     * chối những gì ngắn tới mức không thể là số thuê bao. Nhánh trần thì ngược lại: không ai
     * khẳng định gì cả, nó là một PHÉP ĐOÁN, nên phải đoán trong đúng khoảng độ dài của kế hoạch.
     *
     * **Luỹ đẳng — và vì sao tính chất này từng SAI dù được khẳng định ở đây.** Nhánh "cách viết
     * trong nước" trước đây không có sàn độ dài, nên `01234567` cho ra `841234567`: `84` + 7 chữ
     * số, ngắn hơn mọi thứ mà nhánh mã quốc gia chịu nhận lại. Lần chuẩn hoá thứ hai vì vậy đọc
     * `841234567` như một dãy trần 9 chữ số và thêm `84` lần nữa: `84841234567`. Nguyên nhân nằm ở
     * nhánh trong nước, không ở nhánh trần, nên sàn được đặt ở đó (và ở nhánh mã quốc gia, sau khi
     * bỏ số 0 thừa).
     *
     * **Điều thật sự được bảo đảm là ĐIỂM BẤT ĐỘNG, không phải một khoảng độ dài (sửa round 4).**
     * Một bản trước ghi ở đây rằng "MỌI giá trị trả về có tiền tố `84` đều là `84` + 8…10 chữ số".
     * Câu đó SAI ở hai phía, và đã đo lại từng phía:
     *  - TRÊN trần: chỉ nhánh TRẦN mới có trần 10 — hai nhánh có tiền tố cố ý chỉ có sàn (xem đoạn
     *    ngay trên), nên `phone('079012345678')` trả `84` + 11 chữ số và `phone('07901234567890')`
     *    trả `84` + 13. Hành vi này đúng như thiết kế: người nhập đã tự khẳng định đây là số Việt
     *    Nam, hàm không có quyền bác bỏ chỉ vì họ gõ thừa.
     *  - DƯỚI sàn: một dãy chỉ TÌNH CỜ bắt đầu bằng `84` mà quá ngắn cho nhánh mã quốc gia rơi
     *    xuống lệnh trả cuối cùng NGUYÊN VĂN, nên `phone('8412345')` trả `8412345` — một giá trị
     *    mang tiền tố `84` với 5 chữ số theo sau, chưa từng đi qua nhánh gắn tiền tố nào.
     *
     * Tính chất đúng, và là tính chất duy nhất `RunConflictCheck` cần, là: `phone(phone($x))` luôn
     * bằng `phone($x)`. Nó đứng vững vì hai lẽ — (1) mọi giá trị do một nhánh gắn tiền tố sinh ra
     * đều là `84` + ít nhất 8 chữ số không bắt đầu bằng 0, đúng hình dạng mà nhánh mã quốc gia trả
     * lại nguyên vẹn ở lần chạy sau (nhánh đó không có trần nên `84` + 11 cũng được trả lại y
     * nguyên); (2) mọi giá trị còn lại là chính dãy chữ số đã nhận, và một dãy chữ số không đổi thì
     * lần sau vẫn rơi vào đúng nhánh cũ. Cả hai vế được `NormalizerTest` chạy hai lượt trên từng
     * fixture, gồm cả `84` + 11 và `8412345`.
     */
    public static function phone(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        // Tiền tố gọi quốc tế: 0084... -> 84...
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (self::withoutTrunkPrefix($digits) === '') {
            // Chỉ toàn số 0 (`0`, `00`, `0000`, hoặc `00` sau khi đã bỏ tiền tố gọi quốc tế):
            // không còn chữ số nào mang thông tin. Trả null, không trả chuỗi rỗng và cũng không
            // trả lại dãy số 0 — `RunConflictCheck` bỏ qua chuỗi rỗng khi TÌM (`when('')` là
            // falsy) nhưng vẫn đếm nó là "bên đã có định danh", tức một bên vô hình mà không ai
            // được cảnh báo là thiếu định danh; null thì đi đúng nhánh "thiếu định danh".
            return null;
        }

        // Mã quốc gia — chỉ khi phần sau `84` còn đủ dài để là một số thuê bao (xem docblock).
        if (str_starts_with($digits, '84')) {
            $national = self::withoutTrunkPrefix(substr($digits, 2));

            if (strlen($national) >= self::SUBSCRIBER_MIN_DIGITS) {
                return '84'.$national;
            }
        }

        // Cách viết trong nước: 0912345678 -> 84912345678. Ngắn hơn một số thuê bao thì giữ
        // nguyên: thêm `84` vào đó chỉ sinh ra một giá trị giả dạng đã chuẩn hoá (xem docblock).
        if (str_starts_with($digits, '0')) {
            $national = self::withoutTrunkPrefix($digits);

            return strlen($national) >= self::SUBSCRIBER_MIN_DIGITS ? '84'.$national : $digits;
        }

        // Số thuê bao trần đã mất số 0 đứng đầu (xem quy tắc độ dài ở docblock).
        if (strlen($digits) >= self::SUBSCRIBER_MIN_DIGITS && strlen($digits) <= self::SUBSCRIBER_MAX_DIGITS) {
            return '84'.$digits;
        }

        return $digits;
    }

    public static function idNumberHash(?string $value): ?string
    {
        $digits = self::digits($value);

        return $digits === null ? null : hash('sha256', $digits);
    }

    /**
     * Bỏ số 0 gọi nội hạt ở đầu phần thuê bao — cả sau mã quốc gia (`+84 (0)912...`) lẫn ở cách
     * viết trong nước (`0912...`). Số thuê bao Việt Nam không bao giờ bắt đầu bằng 0, nên mọi số
     * 0 đứng đầu đều là cách viết thừa, không phải dữ liệu thật. Dùng `ltrim` chứ không bỏ đúng
     * một chữ số: một chuỗi còn sót `00...` sau khi đã bỏ tiền tố gọi quốc tế mà chỉ bỏ một số 0
     * sẽ đẩy ra một giá trị còn số 0 kẹp giữa `84` và phần thuê bao — đúng thứ phá vỡ tính luỹ
     * đẳng nói ở docblock `phone()`.
     */
    private static function withoutTrunkPrefix(string $national): string
    {
        return ltrim($national, '0');
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }
}
