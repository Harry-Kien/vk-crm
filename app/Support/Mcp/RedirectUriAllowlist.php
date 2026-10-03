<?php

namespace App\Support\Mcp;

use Illuminate\Support\Arr;

/**
 * M11 R7 (Task 3) — allowlist redirect URI của đăng ký client động (DCR, `POST /oauth/register`),
 * so khớp CHÍNH XÁC. Thay cho `mcp.redirect_domains` của laravel/mcp, vốn chỉ so TIỀN TỐ
 * (`Str::startsWith`) và mặc định là `'*'` [DC:631], [PL:54].
 *
 * Nguồn: `config('vkcrm.mcp.redirect_uris')` (theo nền tảng, khoá chỉ để đọc) cộng
 * `config('vkcrm.mcp.extra_redirect_uris')` (`MCP_EXTRA_REDIRECT_URIS`). Một URI được nhận khi nó
 * khớp một mục theo đúng một trong ba cách:
 *
 * 1. **Bằng nhau từng ký tự.** Không chuẩn hoá gì: chữ hoa ở host, `/` cuối, cổng mặc định viết
 *    tường minh, query, fragment, thông tin người dùng, `/../` đều là một URI KHÁC.
 * 2. **Loopback, bỏ qua cổng** (RFC 8252 §7.3): mục và URI đều có dạng
 *    `http://<host>[:<cổng>]<phần còn lại>` với `<host>` là đúng `localhost`, `127.0.0.1` hoặc
 *    `[::1]` (chữ thường, scheme `http`), cổng 1–65535 không có số 0 đứng đầu; khớp khi CÙNG host và
 *    phần còn lại (đường dẫn, kể cả query nếu có) bằng nhau từng ký tự. Host phải đứng ngay trước
 *    `:<cổng>`, `/` hoặc hết chuỗi, nên `localhost.evil.example` hay `localhost:80@evil.example`
 *    không phải loopback và rơi về cách 1. Claude Code đổi cổng mỗi phiên [DC:739].
 * 3. **`{callback_id}` trong đường dẫn** của mục: đúng một đoạn `[A-Za-z0-9_-]{1,128}` (không `/`,
 *    không `.`, không `%`, nên không có `..` hay `%2F`). Chỉ mở rộng khi phần đứng trước `/` đầu
 *    tiên của đường dẫn (`scheme://host[:cổng]/`) không chứa dấu ngoặc nhọn nào: `{callback_id}` ở
 *    host, ở cổng, hay một mục không có đường dẫn thì mục đó chỉ còn so theo cách 1 (đúng chuỗi có
 *    `{` ấy thì khớp, và đó là một redirect URI mà trình duyệt không chuyển hướng tới được). Dấu `*`
 *    không có nghĩa gì: nó chỉ khớp chính nó.
 *    ChatGPT cấp một callback cho mỗi kết nối [PL:169].
 *
 * Lớp này chỉ trả lời "URI này có trong allowlist không". Việc `/oauth/authorize` chỉ nhận đúng URI
 * mà client đã đăng ký là của league/oauth2-server (`RedirectUriValidator`), và league 9.4.1 coi chỉ
 * `127.0.0.1` / `[::1]` là loopback: với `localhost`, cổng lúc authorize phải bằng cổng đã đăng ký
 * (`ClientRegistrationTest` ghim cả hai).
 */
final class RedirectUriAllowlist
{
    public const CALLBACK_ID = '{callback_id}';

    private const CALLBACK_ID_SEGMENT = '[A-Za-z0-9_-]{1,128}';

    /** `http://` + host loopback chữ thường, cổng tuỳ chọn, phần còn lại (rỗng hoặc bắt đầu bằng `/`). */
    private const LOOPBACK = '~^http://(localhost|127\.0\.0\.1|\[::1\])(?::([1-9][0-9]{0,4}))?(/.*)?$~sD';

    /** `scheme://authority/` không chứa dấu ngoặc nhọn: điều kiện để `{callback_id}` được mở rộng. */
    private const PATH_START = '~^[a-z][a-z0-9+.-]*://[^/{}]+/~D';

    public static function allows(string $uri): bool
    {
        foreach (self::entries() as $entry) {
            if (self::matches($entry, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mọi mục, phẳng (khoá nền tảng bị bỏ). Mục thêm đã được `config/vkcrm.php` cắt khoảng trắng và
     * bỏ mục rỗng.
     *
     * @return list<string>
     */
    public static function entries(): array
    {
        return array_values(array_map('strval', [
            ...Arr::flatten((array) config('vkcrm.mcp.redirect_uris', [])),
            ...(array) config('vkcrm.mcp.extra_redirect_uris', []),
        ]));
    }

    private static function matches(string $entry, string $uri): bool
    {
        if ($uri === $entry) {
            return true;
        }

        $loopbackEntry = self::withoutLoopbackPort($entry);

        if ($loopbackEntry !== null) {
            return $loopbackEntry === self::withoutLoopbackPort($uri);
        }

        // Mục không có `{callback_id}` cũng đi qua đây và ra đúng phép so cách 1 (mẫu chỉ có chữ).
        if (preg_match(self::PATH_START, $entry) !== 1) {
            return false;
        }

        $pattern = implode(self::CALLBACK_ID_SEGMENT, array_map(
            fn (string $literal): string => preg_quote($literal, '~'),
            explode(self::CALLBACK_ID, $entry),
        ));

        return preg_match('~^'.$pattern.'$~D', $uri) === 1;
    }

    /** `http://<host loopback><phần còn lại>` khi `$uri` là loopback (cổng hợp lệ), không thì `null`. */
    private static function withoutLoopbackPort(string $uri): ?string
    {
        if (preg_match(self::LOOPBACK, $uri, $match) !== 1) {
            return null;
        }

        if (($match[2] ?? '') !== '' && (int) $match[2] > 65535) {
            return null;
        }

        return 'http://'.$match[1].($match[3] ?? '');
    }
}
