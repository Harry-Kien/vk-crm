<?php

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ "quét virus") — dòng ClamAV của `vkcrm:preflight`.
 * Tệp riêng để không chạm `preflight.php` mà các làn khác cũng sửa.
 */
return [
    'disabled' => 'Quét virus đang TẮT (CLAMAV_ENABLED=false): tệp khách và nhân sự tải lên chỉ được kiểm loại tệp, không được quét mã độc. Cài clamd và bật theo docs/CAI-DAT.md, mục "Quét virus (ClamAV)".',
    'unreachable' => 'Quét virus đang BẬT nhưng daemon ClamAV không trả lời ở :socket — mọi tệp tải lên sẽ bị từ chối. Kiểm tra dịch vụ clamd và CLAMAV_SOCKET (docs/CAI-DAT.md, mục "Quét virus (ClamAV)").',
    'ok' => 'Quét virus đang bật, daemon ClamAV trả lời.',
];
