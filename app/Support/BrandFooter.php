<?php

namespace App\Support;

/**
 * Chân thư: những thông tin pháp lý của văn phòng ĐÃ CÓ, theo đúng thứ tự in ra.
 *
 * Tồn tại thành một lớp riêng vì cùng một danh sách phải in ở hai nơi — bản HTML
 * (`resources/views/emails/layout.blade.php`) và bản văn bản thuần
 * (`resources/views/emails/layout-text.blade.php`). Hai bản sao của cùng một điều kiện là hai
 * bản sẽ lệch nhau, và bản lệch ở đây có hình dạng khó thấy nhất: một dòng trắng hoặc một nhãn
 * cụt đuôi trong bản mà ít người mở ra đọc.
 *
 * **Vì sao lọc chứ không in thẳng:** `config/vkcrm.php` cố ý để TRỐNG bốn thông tin (mã số thuế,
 * Đoàn Luật sư, số giấy đăng ký hoạt động, địa chỉ văn phòng) — website của văn phòng không đăng
 * chúng và chủ văn phòng chưa cung cấp, nên một giá trị phỏng đoán trên văn bản gửi khách còn tệ
 * hơn một chỗ trống. Chỗ trống ấy phải BIẾN MẤT khỏi thư, không được trở thành "Mã số thuế:"
 * treo lơ lửng. Ngày chúng được điền vào `.env`, danh sách tự dài ra, không ai phải sửa mã.
 */
final class BrandFooter
{
    /**
     * @return list<string>
     */
    public static function legalLines(): array
    {
        /** @var array<string, mixed> $brand */
        $brand = config('vkcrm.brand');

        $lines = [];

        // Khoá ngôn ngữ rỗng = in nguyên văn: địa chỉ không cần nhãn, ba thông tin còn lại thì có.
        foreach ([
            ['', $brand['office_address'] ?? null],
            ['emails.footer.tax_code', $brand['tax_code'] ?? null],
            ['emails.footer.bar_association', $brand['bar_association'] ?? null],
            ['emails.footer.licence_number', $brand['licence_number'] ?? null],
        ] as [$key, $value]) {
            if (blank($value)) {
                continue;
            }

            $lines[] = $key === '' ? (string) $value : __($key, ['value' => $value]);
        }

        return $lines;
    }
}
