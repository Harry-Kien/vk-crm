<?php

namespace App\Support;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * Nơi DUY NHẤT đọc chín thông tin văn phòng sửa được trong app (M7 Task 10): bốn thông tin pháp
 * lý (mã số thuế, Đoàn Luật sư, số Giấy đăng ký hoạt động, địa chỉ trụ sở), tên pháp lý, hotline,
 * Zalo, website và email liên hệ (Reply-To của mọi thư).
 *
 * # Thứ tự: bảng `settings` → `config('vkcrm.brand.*')`
 *
 * Giá trị admin lưu ở trang "Thông tin văn phòng" (khoá `office.<trường>`) thắng; ô để TRỐNG nghĩa
 * là "dùng cấu hình" — giá trị `.env` hoặc mặc định của `config/vkcrm.php`. Trống ở cả hai nơi thì
 * trả `null` (chuỗi rỗng hay chỉ có khoảng trắng cũng là trống), và mọi nơi in ra — chân thư, chân
 * `MUC-LUC.pdf` qua {@see BrandFooter} — bỏ hẳn dòng đó, không để lại nhãn treo.
 *
 * Muốn một trường mà cấu hình đang có giá trị TRỐNG HẲN (ví dụ không in hotline nào), không làm
 * được từ trang: đặt biến đó RỖNG trong `.env` (`BRAND_HOTLINE=` — giữ dòng, bỏ giá trị; xoá cả dòng
 * thì mặc định của `config/vkcrm.php` quay lại) rồi để trống ô trên trang. Đó là chủ ý — chín
 * trường này nằm trên mọi thư gửi khách, và "trống = dùng cấu hình" giữ cho một cú xoá nhầm trên
 * trang không bao giờ làm mất tên pháp lý khỏi chân thư.
 *
 * Màu, logo, font, lockup, tagline, short_name KHÔNG ở đây: chúng vẫn đọc thẳng cấu hình, vì
 * `BrandingTest` ghim chúng và một màu sửa nhầm làm hỏng cả hai panel. Test cấu trúc
 * (`tests/Feature/Support/OfficeProfileTest.php`) đỏ khi bất kỳ tệp nào khác trong `app/`,
 * `resources/views/`, `routes/` đọc một trong chín trường này từ cấu hình.
 *
 * # Một đối tượng cho MỘT lần render — không gì sống lâu hơn
 *
 * Mỗi {@see self::current()} là một đối tượng MỚI; nó đọc bảng `settings` đúng MỘT lần, lúc một
 * trường được hỏi lần đầu, rồi giữ kết quả cho chính nó. Người gọi giữ đối tượng trong suốt một
 * lần render (một view, một `content()` của thư) để mọi trường của cùng một chân thư đến từ cùng
 * một lần đọc. Không thuộc tính `static`, không binding `singleton`/`scoped`: worker hàng đợi sống
 * qua nhiều job, và một giá trị giữ qua hai job là thư của job sau mang thông tin cũ.
 *
 * Hệ quả, và là luật: **thư đang nằm trong hàng đợi dùng giá trị ở LÚC RENDER, không phải lúc xếp
 * hàng.** Mẫu thư đọc service này trong `content()`/`toMail()`/view — chạy khi job gửi thư chạy —
 * và không chụp giá trị nào vào thuộc tính lúc khởi tạo. Admin sửa hotline lúc 10:00 thì một thư
 * xếp hàng lúc 09:59 nhưng gửi lúc 10:01 mang hotline mới.
 */
final class OfficeProfile
{
    public const KEY_PREFIX = 'office.';

    /**
     * Chín trường sửa được, và giới hạn KÝ TỰ của từng trường — cùng một con số cho `maxLength()`
     * của form (`OfficeProfilePage`) và luật `max:` của Action (`UpdateOfficeProfile`). Cột
     * `settings.value` là `text`, nên đây là giới hạn của TRƯỜNG chứ không của cột:
     *
     *  - `tax_code` 14: `0123456789-001`, dạng dài nhất (13 chữ số và một gạch);
     *  - `hotline` 20: độ dài ô NHẬP (`+84 (0) 236 3888 999` còn vừa); giá trị lưu sau chuẩn hoá
     *    là cách viết trong nước, tối đa 11 chữ số;
     *  - `licence_number` 100, `office_address` 500;
     *  - `reply_to` 254: trần của một địa chỉ thư theo RFC 5321;
     *  - còn lại 255.
     *
     * @var array<string, int>
     */
    public const FIELDS = [
        'legal_name' => 255,
        'tax_code' => 14,
        'bar_association' => 255,
        'licence_number' => 100,
        'office_address' => 500,
        'hotline' => 20,
        'zalo' => 255,
        'website' => 255,
        'reply_to' => 254,
    ];

    /**
     * Giá trị đã lưu của chín trường, nạp ở lần hỏi đầu tiên; `null` = chưa nạp.
     *
     * @var array<string, ?string>|null
     */
    private ?array $stored = null;

    /** Một đối tượng MỚI — xem docblock lớp, mục "Một đối tượng cho MỘT lần render". */
    public static function current(): self
    {
        return new self;
    }

    /** Khoá `settings.key` của một trường: `office.<trường>`. */
    public static function settingKey(string $field): string
    {
        self::assertField($field);

        return self::KEY_PREFIX.$field;
    }

    /** Giá trị đang dùng: đã lưu (không rỗng) → cấu hình (không rỗng) → `null`. */
    public function value(string $field): ?string
    {
        return $this->stored($field) ?? $this->configured($field);
    }

    /** Chỉ giá trị đã lưu trong bảng `settings`; `null` khi chưa lưu hoặc lưu rỗng. */
    public function stored(string $field): ?string
    {
        self::assertField($field);

        $this->stored ??= Setting::query()
            ->whereIn('key', array_map(self::settingKey(...), array_keys(self::FIELDS)))
            ->pluck('value', 'key')
            ->all();

        $value = $this->stored[self::settingKey($field)] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /** Chỉ giá trị của cấu hình (`.env` hoặc mặc định); `null` khi rỗng. */
    public function configured(string $field): ?string
    {
        self::assertField($field);

        $value = config('vkcrm.brand.'.$field);

        return filled($value) ? (string) $value : null;
    }

    public function legalName(): ?string
    {
        return $this->value('legal_name');
    }

    public function taxCode(): ?string
    {
        return $this->value('tax_code');
    }

    public function barAssociation(): ?string
    {
        return $this->value('bar_association');
    }

    public function licenceNumber(): ?string
    {
        return $this->value('licence_number');
    }

    public function officeAddress(): ?string
    {
        return $this->value('office_address');
    }

    public function hotline(): ?string
    {
        return $this->value('hotline');
    }

    public function zalo(): ?string
    {
        return $this->value('zalo');
    }

    public function website(): ?string
    {
        return $this->value('website');
    }

    /** Hộp thư liên hệ THẬT của văn phòng — Reply-To của mọi thư (`App\Mail\BrandedMailable`). */
    public function replyTo(): ?string
    {
        return $this->value('reply_to');
    }

    private static function assertField(string $field): void
    {
        if (! array_key_exists($field, self::FIELDS)) {
            throw new InvalidArgumentException("[{$field}] không phải một thông tin văn phòng sửa được trong app.");
        }
    }
}
