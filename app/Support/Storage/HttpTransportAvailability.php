<?php

namespace App\Support\Storage;

/**
 * PHP có đường nào để gửi HTTP tới Google không (dòng `drive_http_client` của kiểm tra sẵn sàng, kế
 * hoạch M14 Task 5). Guzzle chọn bộ gửi đúng theo hai câu hỏi này (`GuzzleHttp\Utils::chooseHandler()`):
 * cURL khi có `curl_exec` và `curl_multi_exec`, không thì luồng PHP khi `allow_url_fopen` bật. Thiếu
 * cả hai thì mọi lệnh gọi Drive (và endpoint token) hỏng.
 *
 * Lớp riêng, không `final`, chỉ để test thay được: `function_exists()` và `ini_get()` không giả được.
 */
class HttpTransportAvailability
{
    public function curl(): bool
    {
        return function_exists('curl_exec') && function_exists('curl_multi_exec');
    }

    public function urlFopen(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }
}
