<?php

namespace App\Support\Mcp;

use App\Actions\Mcp\ResolveClientIdMetadataDocument;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * M11 R7 (Task 5) — tải MỘT tài liệu CIMD (Client ID Metadata Document) theo luật chống SSRF
 * [PL:164] của kế hoạch Task 5. Lớp này chỉ trả lời "thân tài liệu là gì, hay không có"; hình dạng
 * URL, allowlist host, giới hạn số lần tải, cache và nội dung tài liệu là việc của
 * {@see ResolveClientIdMetadataDocument}, bên gọi duy nhất. URL tới đây đã qua phép kiểm hình dạng:
 * `https://<host>/<đường dẫn>`, không cổng, không thông tin người dùng, không query, không fragment.
 *
 * Trả `null` (không tải, hay tải mà không dùng được) khi:
 * - **DNS:** `$host` không phân giải được, hoặc BẤT KỲ địa chỉ nào của nó không phải địa chỉ công
 *   khai: loopback, dải riêng, link-local (gồm `169.254.169.254` siêu dữ liệu đám mây), CGNAT, dải
 *   dành riêng, IPv4 ánh xạ trong IPv6 ({@see self::isPublic()}). Không request nào đi ra.
 * - request lỗi kết nối, hết giờ, hay lỗi truyền của Guzzle;
 * - trạng thái khác ĐÚNG 200 — kể cả 3xx: không theo chuyển hướng (`allow_redirects: false`), nên
 *   một chuyển hướng không đưa request tới một host chưa được kiểm;
 * - thân dài hơn {@see self::MAX_BYTES} byte.
 *
 * **Ghim IP.** Request đi tới ĐÚNG địa chỉ vừa kiểm (`CURLOPT_RESOLVE` = `host:443:<IP đầu tiên>`):
 * curl không phân giải lại, nên một DNS đổi câu trả lời giữa lúc kiểm và lúc tải (DNS rebinding)
 * không đưa request vào mạng nội bộ. TLS vẫn kiểm chứng chỉ theo `$host`. Vì vậy `curl` là extension
 * bắt buộc ở production (`config/vkcrm.php`, `deployment.required_extensions`).
 *
 * **Giới hạn thời gian và kích thước:** kết nối và cả request {@see self::TIMEOUT_SECONDS} giây;
 * `CURLOPT_MAXFILESIZE` để curl tự bỏ một phản hồi khai `Content-Length` lớn hơn giới hạn (và, từ
 * curl 8.4, cả phản hồi không khai), cộng phép đếm độ dài thân sau khi nhận.
 */
class MetadataDocumentFetcher
{
    /** Kế hoạch Task 5: "tối đa 16 KB". */
    public const MAX_BYTES = 16384;

    /** Kế hoạch Task 5: "timeout 5 giây". Claude chờ discovery, đăng ký và token tối đa 10 giây [PL:179]. */
    public const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly HostResolver $resolver) {}

    public function fetch(string $url, string $host): ?string
    {
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                return null;
            }
        }

        $pinned = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"],
                    CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                ],
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->accept('application/json')
                ->get($url);
        } catch (ConnectionException|TransferException) {
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $body = $response->body();

        return strlen($body) > self::MAX_BYTES ? null : $body;
    }

    /**
     * Địa chỉ công khai, định tuyến được trên Internet: không thuộc dải riêng (RFC 1918, `fc00::/7`),
     * không thuộc dải dành riêng của PHP (`0.0.0.0/8`, `127.0.0.0/8`, `169.254.0.0/16`, `::1`,
     * `::ffff:0:0/96`, `fe80::/10`…), và mang thuộc tính Global của RFC 6890 (loại thêm CGNAT
     * `100.64.0.0/10`, `198.18.0.0/15`, `2001:db8::/32`…). Chuỗi không phải IP thì không công khai.
     */
    public static function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;
    }
}
