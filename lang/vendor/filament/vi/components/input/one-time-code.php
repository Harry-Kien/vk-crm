<?php

/**
 * Ô nhập mã một lần của Filament (`Filament\Forms\Components\OneTimeCodeInput`).
 *
 * Gói `filament/support` KHÔNG có bản `vi` cho tệp này, nên `aria_label` rơi về tiếng Anh và in
 * thẳng vào markup: mỗi ô số từ thứ hai trở đi mang `aria-label="Character 2 of 6"`. Đây chính
 * là ô khách hàng gõ mã đăng nhập cổng (SPEC §8.1), nên với người dùng trình đọc màn hình thì
 * màn hình đầu tiên của toàn bộ hệ thống nói tiếng Anh với họ. Khoản nợ này được ghi từ rà soát
 * localisation của M3 và chuyển thành điều kiện vào của M5.
 *
 * Ghi đè của Laravel TRỘN chứ không thay thế (`FileLoader::loadNamespaceOverrides()` dùng
 * `array_replace_recursive`), nên một tệp chỉ chứa khoá còn thiếu là an toàn — phần còn lại của
 * gói vẫn nguyên.
 */
return [

    'aria_label' => 'Ký tự thứ :position trong :count',

];
