<?php

namespace App\Actions\Mcp;

use App\Support\Mcp\McpClientRepository;
use App\Support\Mcp\MetadataDocumentFetcher;
use App\Support\Mcp\RedirectUriAllowlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use JsonException;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * M11 R7 (Task 5) — CIMD phía máy chủ uỷ quyền: một `client_id` là URL HTTPS của một tài liệu JSON
 * mô tả client (Client ID Metadata Document). Đổi URL đó ra dòng `oauth_clients` mang cờ `is_mcp`,
 * khoá theo URL (`metadata_url`), hoặc `null` (Passport trả `invalid_client`, như với một id không
 * tồn tại). Gọi từ {@see McpClientRepository} mỗi lần league/oauth2-server tra một client có id chứa
 * `://` (`/oauth/authorize`, `/oauth/token`); lớp này là nơi DUY NHẤT quyết URL nào được nhận.
 *
 * Trả `null`, theo thứ tự kiểm, khi:
 * 1. cờ {@see self::enabled()} tắt (mặc định). Không tải gì;
 * 2. URL không đúng hình dạng ({@see self::isAcceptableUrl()}): không phải `https://` + host
 *    trong `vkcrm.mcp.client_id_metadata_hosts` (đúng chuỗi, chữ thường, không tên miền con) + đường
 *    dẫn gồm các đoạn chữ an toàn, không `.`/`..`; có cổng, thông tin người dùng, query, fragment, mã
 *    hoá phần trăm, `/` cuối; dài hơn {@see self::MAX_URL_LENGTH}. Không tải gì;
 * 3. không có tài liệu hợp lệ trong cache, và:
 *    - đã đủ {@see self::FETCHES_PER_MINUTE} lần tải trong phút này, TOÀN hệ thống (không tải): lời
 *      gọi vô danh tới `/oauth/authorize` với một URL mới ở mỗi lần sẽ bắt PHP-FPM chờ mạng, nên số
 *      lần tải phải có trần;
 *    - tải không được ({@see MetadataDocumentFetcher}: DNS, IP không công khai, lỗi mạng, khác 200,
 *      quá 16 KB);
 *    - tài liệu không đạt ({@see self::metadataFrom()}): không phải JSON; không có `client_id` ĐÚNG
 *      bằng URL (JSON không phải đối tượng thì không có); `token_endpoint_auth_method` khác `none`;
 *      `redirect_uris` không phải danh sách 1–10 chuỗi nằm trong allowlist R7
 *      ({@see RedirectUriAllowlist}).
 *    Hai trường hợp sau được ghi log cảnh báo (`client_id`, lý do; không nội dung tài liệu). Lần
 *    hỏng KHÔNG được nhớ: lần sau tải lại;
 * 4. dòng client của URL đã bị thu hồi (`revoked`). Tài liệu hợp lệ không hồi sinh nó.
 *
 * **Cache** ({@see self::CACHE_SECONDS}, store mặc định, ở production là `database` —
 * `config/cache.php`): chỉ phần ĐÃ KIỂM (`name`, `redirect_uris`) của tài liệu hợp lệ. Mỗi lần đọc
 * lại, `redirect_uris` được kiểm lại với allowlist HIỆN TẠI: gỡ một URI khỏi allowlist có hiệu lực
 * ngay, không chờ cache hết hạn (bản cache không còn đạt thì tải lại, và tài liệu vẫn trỏ URI đó thì
 * bị từ chối).
 *
 * **Upsert theo URL:** dòng chưa có thì tạo (công khai, không secret, `authorization_code` +
 * `refresh_token`, `is_mcp`); có rồi thì cập nhật tên, redirect URI, và giữ nguyên `id` — token đã cấp
 * trỏ về `id` đó. Tên là `client_name` của tài liệu, cắt còn 255 ký tự (cột `oauth_clients.name`,
 * MariaDB strict), thiếu hay rỗng thì là host; nó do nền tảng tự khai, nên màn hình nào hiện client
 * phải hiện host của redirect URI, không chỉ tên này. Hai request tạo cùng lúc: mục unique của `metadata_url` giữ một dòng, request thua
 * đọc lại dòng thắng. Mọi client CIMD của một nền tảng là MỘT dòng dùng chung cho mọi nhân sự (cùng
 * URL); "kết nối" của từng người là token của người đó, không phải dòng client.
 *
 * Không ghi nhật ký hoạt động: lời gọi này vô danh (chưa có nhân sự nào) và một client không mở được
 * gì khi chưa có nhân sự đồng ý ở `/oauth/authorize` (cùng lý do với DCR, `RegisterMcpClient`).
 */
class ResolveClientIdMetadataDocument
{
    /** Độ dài cột `oauth_clients.metadata_url`. */
    public const MAX_URL_LENGTH = 255;

    /** Tài liệu hợp lệ được dùng lại một ngày trước khi tải lại. */
    public const CACHE_SECONDS = 86400;

    /** Trần số lần tải tài liệu của cả hệ thống trong một phút. */
    public const FETCHES_PER_MINUTE = 30;

    public const RATE_LIMITER = 'mcp-cimd-fetch';

    /** Như đăng ký động (`RegisterClientController::MAX_REDIRECT_URIS`). */
    public const MAX_REDIRECT_URIS = 10;

    /** `https://` + host chữ thường + một hay nhiều đoạn `/<chữ, số, . _ ~ ->`. */
    private const URL_SHAPE = '#^https://([a-z0-9.-]+)((?:/[A-Za-z0-9._~-]+)+)$#D';

    public function __construct(private readonly MetadataDocumentFetcher $fetcher) {}

    /** Cờ `vkcrm.mcp.client_id_metadata_documents` (`MCP_CLIENT_ID_METADATA_DOCUMENTS`), mặc định tắt. */
    public static function enabled(): bool
    {
        return config('vkcrm.mcp.client_id_metadata_documents') === true;
    }

    public static function isAcceptableUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_URL_LENGTH || preg_match(self::URL_SHAPE, $url, $match) !== 1) {
            return false;
        }

        if (! in_array($match[1], (array) config('vkcrm.mcp.client_id_metadata_hosts', []), true)) {
            return false;
        }

        foreach (explode('/', substr($match[2], 1)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function handle(string $url): ?Client
    {
        if (! self::enabled() || ! self::isAcceptableUrl($url)) {
            return null;
        }

        $cacheKey = 'mcp:cimd:'.hash('sha256', $url);
        $metadata = Cache::get($cacheKey);

        if (! is_array($metadata) || ! self::redirectUrisAllowed($metadata['redirect_uris'] ?? null)) {
            $metadata = $this->fetchMetadata($url);

            if ($metadata === null) {
                return null;
            }

            Cache::put($cacheKey, $metadata, self::CACHE_SECONDS);
        }

        return $this->upsert($url, $metadata);
    }

    /** @return array{name: string, redirect_uris: list<string>}|null */
    private function fetchMetadata(string $url): ?array
    {
        if (RateLimiter::tooManyAttempts(self::RATE_LIMITER, self::FETCHES_PER_MINUTE)) {
            return null;
        }

        RateLimiter::hit(self::RATE_LIMITER, 60);

        $host = (string) parse_url($url, PHP_URL_HOST);
        $body = $this->fetcher->fetch($url, $host);
        $metadata = $body === null ? 'fetch' : self::metadataFrom($body, $url, $host);

        if (is_string($metadata)) {
            Log::warning('MCP CIMD: tài liệu metadata client bị từ chối.', ['client_id' => $url, 'reason' => $metadata]);

            return null;
        }

        return $metadata;
    }

    /**
     * Phần đã kiểm của tài liệu, hoặc mã lý do từ chối (chuỗi, chỉ để ghi log).
     *
     * @return array{name: string, redirect_uris: list<string>}|string
     */
    private static function metadataFrom(string $body, string $url, string $host): array|string
    {
        try {
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'json';
        }

        // JSON không phải đối tượng (danh sách, chuỗi, số) không có `client_id`: cùng lý do từ chối.
        if (! is_array($document) || ($document['client_id'] ?? null) !== $url) {
            return 'client_id';
        }

        if (($document['token_endpoint_auth_method'] ?? null) !== 'none') {
            return 'token_endpoint_auth_method';
        }

        $redirectUris = $document['redirect_uris'] ?? null;

        if (! self::redirectUrisAllowed($redirectUris)) {
            return 'redirect_uris';
        }

        $name = is_string($document['client_name'] ?? null) ? trim($document['client_name']) : '';

        return [
            'name' => $name === '' ? $host : mb_substr($name, 0, 255),
            'redirect_uris' => $redirectUris,
        ];
    }

    /** @phpstan-assert-if-true list<string> $redirectUris */
    private static function redirectUrisAllowed(mixed $redirectUris): bool
    {
        if (! is_array($redirectUris) || ! array_is_list($redirectUris)
            || $redirectUris === [] || count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return false;
        }

        foreach ($redirectUris as $redirectUri) {
            if (! is_string($redirectUri) || ! RedirectUriAllowlist::allows($redirectUri)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array{name: string, redirect_uris: list<string>}  $metadata */
    private function upsert(string $url, array $metadata): ?Client
    {
        $attributes = [
            'name' => $metadata['name'],
            'redirect_uris' => $metadata['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
        ];

        $client = Passport::client()->newQuery()->where('metadata_url', $url)->first();

        if ($client === null) {
            try {
                return Passport::client()->newQuery()->forceCreate([
                    ...$attributes,
                    'metadata_url' => $url,
                    'is_mcp' => true,
                    'secret' => null,
                    'provider' => null,
                    'revoked' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                $client = Passport::client()->newQuery()->where('metadata_url', $url)->firstOrFail();
            }
        }

        if ($client->revoked) {
            return null;
        }

        $client->forceFill($attributes)->save();

        return $client;
    }
}
