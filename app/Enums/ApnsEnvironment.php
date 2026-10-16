<?php

namespace App\Enums;

/**
 * Môi trường APNs mà token của một máy thuộc về: bản build chạy từ Xcode/TestFlight dùng `sandbox`,
 * bản App Store/phát hành nội bộ dùng `production`. Token của môi trường này bị môi trường kia từ
 * chối, nên mỗi dòng sổ máy nhớ môi trường của nó.
 */
enum ApnsEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return __('enums.apns_environment.'.$this->value);
    }

    /** Máy chủ HTTP/2 của APNs cho môi trường này. */
    public function host(): string
    {
        return match ($this) {
            self::Sandbox => 'api.sandbox.push.apple.com',
            self::Production => 'api.push.apple.com',
        };
    }
}
