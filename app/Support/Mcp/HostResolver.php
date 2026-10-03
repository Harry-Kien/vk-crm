<?php

namespace App\Support\Mcp;

/**
 * M11 R7 (Task 5) — phân giải một tên miền ra MỌI địa chỉ IP của nó (bản ghi A và AAAA), để
 * {@see MetadataDocumentFetcher} kiểm từng địa chỉ trước khi tải tài liệu CIMD (chặn SSRF [PL:164]).
 *
 * Tách thành một lớp riêng, phân giải qua container, để test thay bằng DNS giả: test không bao giờ
 * gọi mạng thật (kế hoạch Task 5), và "host phân giải về `127.0.0.1`" chỉ dựng được bằng một bộ phân
 * giải giả.
 *
 * `dns_get_record()` trước (có cả IPv6); hàm đó trả `false` hay rỗng (máy chủ chặn, lỗi DNS) thì lùi
 * về `gethostbynamel()` (chỉ IPv4). Không phân giải được thì trả danh sách rỗng, và bên gọi từ chối.
 */
class HostResolver
{
    /** @return list<string> mọi địa chỉ IP (v4 và v6) của `$host`, không trùng lặp */
    public function addresses(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $addresses = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
