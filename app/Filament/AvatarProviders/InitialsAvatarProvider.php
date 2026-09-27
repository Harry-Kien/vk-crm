<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ảnh đại diện mặc định của cả hai panel: chữ cái đầu của tên, dựng NGAY TRÊN MÁY CHỦ thành một
 * ảnh SVG `data:` — thay cho `Filament\AvatarProviders\UiAvatarsProvider`, vốn trỏ `<img>` tới
 * `https://ui-avatars.com/api/?name=…`.
 *
 * **Vì sao thay.** Khảo sát CSP (docs/research/2026-09-26-csp-khao-sat.md, M8a Task 4) đo được
 * đó là vi phạm `img-src` DUY NHẤT còn lại sau khi script đã có nonce, trên mọi trang đã đăng
 * nhập của cả hai panel. Thêm `ui-avatars.com` vào CSP sẽ hợp thức hoá một điều chưa ai quyết:
 * mỗi lượt tải trang của khách gửi chữ cái đầu tên họ và địa chỉ IP của họ tới một bên thứ ba.
 * SPEC §3 chỉ quyết cho Bunny Fonts, và có lý do được viết ra; ui-avatars.com thì không.
 *
 * Chữ cái đầu lấy theo lối gọi tên tiếng Việt: chữ đầu của TỪ ĐẦU và TỪ CUỐI ("Nguyễn Văn An" →
 * "NA"), không phải hai từ đầu như ui-avatars ("NV" là họ và tên đệm, không phải người). Nền là
 * sắc độ 950 của màu `gray` của panel, chữ trắng — đúng như bản cũ trông.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.e($background).'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#FFFFFF" '
            .'font-family="system-ui, -apple-system, Segoe UI, Roboto, sans-serif" font-size="26" font-weight="600">'
            .e(static::initials(Filament::getNameForDefaultAvatar($record)))
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Chữ đầu của từ đầu và từ cuối, viết hoa; một từ thì một chữ. Bỏ dấu câu đứng đầu mỗi từ
     * (ví dụ tên tài khoản dịch vụ "[HỆ THỐNG] Quản trị") như `UiAvatarsProvider` vẫn làm.
     */
    public static function initials(string $name): string
    {
        $letters = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->map(fn (string $word): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter(fn (string $letter): bool => $letter !== '')
            ->values();

        $initials = $letters->count() > 1 ? $letters->first().$letters->last() : (string) $letters->first();

        return mb_strtoupper($initials);
    }
}
