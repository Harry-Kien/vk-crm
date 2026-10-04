<?php

namespace App\Support;

/**
 * Chân thư: những thông tin pháp lý của văn phòng ĐÃ CÓ, theo đúng thứ tự in ra.
 *
 * Tồn tại thành một lớp riêng vì cùng một danh sách phải in ở ba nơi — bản HTML
 * (`resources/views/emails/layout.blade.php`), bản văn bản thuần
 * (`resources/views/emails/layout-text.blade.php`) và chân `MUC-LUC.pdf` của gói bàn giao
 * (`App\Actions\Matter\RenderHandoverIndex`). Hai bản sao của cùng một điều kiện là hai bản sẽ
 * lệch nhau, và bản lệch ở đây có hình dạng khó thấy nhất: một dòng trắng hoặc một nhãn cụt đuôi
 * trong bản mà ít người mở ra đọc.
 *
 * **Vì sao lọc chứ không in thẳng:** bốn thông tin (mã số thuế, Đoàn Luật sư, số giấy đăng ký hoạt
 * động, địa chỉ văn phòng) có thể còn TRỐNG — `config/vkcrm.php` cố ý để trống chúng (website của
 * văn phòng không đăng chúng, và một giá trị phỏng đoán trên văn bản gửi khách còn tệ hơn một chỗ
 * trống), và chủ văn phòng sẽ tự nhập sau ở trang "Thông tin văn phòng" (M7 Task 10). Chỗ trống
 * ấy phải BIẾN MẤT khỏi thư, không được trở thành "Mã số thuế:" treo lơ lửng. Ngày chúng được
 * điền, danh sách tự dài ra, không ai phải sửa mã.
 *
 * Giá trị đọc qua {@see OfficeProfile} (bảng `settings` → cấu hình), không đọc thẳng cấu hình.
 */
final class BrandFooter
{
    /**
     * `$office` — đối tượng mà người gọi đã dùng cho phần còn lại của cùng lần render (tên pháp
     * lý, hotline), để cả chân thư đến từ MỘT lần đọc; bỏ trống thì đọc mới.
     *
     * @return list<string>
     */
    public static function legalLines(?OfficeProfile $office = null): array
    {
        $office ??= OfficeProfile::current();

        $lines = [];

        // Khoá ngôn ngữ rỗng = in nguyên văn: địa chỉ không cần nhãn, ba thông tin còn lại thì có.
        foreach ([
            ['', $office->officeAddress()],
            ['emails.footer.tax_code', $office->taxCode()],
            ['emails.footer.bar_association', $office->barAssociation()],
            ['emails.footer.licence_number', $office->licenceNumber()],
        ] as [$key, $value]) {
            if (blank($value)) {
                continue;
            }

            $lines[] = $key === '' ? (string) $value : __($key, ['value' => $value]);
        }

        return $lines;
    }
}
