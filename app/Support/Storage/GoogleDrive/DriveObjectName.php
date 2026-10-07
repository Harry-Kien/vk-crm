<?php

namespace App\Support\Storage\GoogleDrive;

use InvalidArgumentException;

/**
 * Tên tệp trên Google Drive của một khoá đối tượng (kế hoạch M14, R4).
 *
 * - Tên = khoá với `/` thay bằng `~`. Thế hệ 1 không hậu tố; thế hệ N ≥ 2 thêm `~g<N>` ngay trước
 *   đuôi của tầng cuối (không đuôi thì ở cuối tên). Ví dụ `1834/01k6….pdf` thế hệ 2 →
 *   `1834~01k6…~g2.pdf`.
 * - Khoá chứa `~` bị từ chối: tên phải đảo ngược được.
 * - Thế hệ chỉ từ 1 tới 999 ({@see self::MAX_GENERATION}), đúng miền mà khuôn đọc ngược nhận.
 * - {@see self::parse()} chỉ nhận đúng khuôn của khoá thư viện media
 *   (`<media_id>/<ULID viết thường>[.<đuôi a-z0-9, 1–8>]`); tên lạ (thăm dò preflight, chữ hoa, `~g1`,
 *   số thế hệ có số 0 đầu) → `null`. Dựng lại chỉ mục và nhập biên nhận văn phòng chỉ có tên trong
 *   tay, nên luật đọc ngược phải chặt: không đoán.
 *
 * Vì sao có số thế hệ: bản hỏng có thể đã nằm ở máy văn phòng (`rclone copy --immutable`). Tải lại
 * CÙNG tên thì lượt kéo báo lỗi ở mọi lần chạy, tệp đó không bao giờ có biên nhận, và vùng đệm của
 * nó không bao giờ được dọn. Mỗi lần tải lại một khoá vì thế mang một tên chưa từng dùng.
 */
final class DriveObjectName
{
    public const MAX_GENERATION = 999;

    private const PATTERN = '/^(\d+)~([0-9a-z]{26})(?:~g([2-9]|[1-9]\d{1,2}))?(\.[0-9a-z]{1,8})?$/D';

    /**
     * Khoá chỉ mục của tệp THĂM DÒ (M14 Task 5 `drive_roundtrip`: `preflight/<26 ký tự>.txt`), và tên
     * Drive tương ứng (`/` thành `~`; lượt đo tốc độ của `vkcrm:storage:migrate --dry-run` dùng thẳng
     * tên này). {@see self::parse()} không nhận chúng; `vkcrm:storage:orphans`/`reindex` đếm chúng thành
     * một nhóm riêng thay vì "tên lạ" (rà soát Task 5, m7).
     */
    public const PROBE_KEY_PREFIX = 'preflight/';

    public const PROBE_NAME_PREFIX = 'preflight~';

    public static function fromKey(string $key, int $generation = 1): string
    {
        if (str_contains($key, '~')) {
            throw new InvalidArgumentException('Khoá đối tượng không được chứa ~.');
        }

        if ($generation < 1 || $generation > self::MAX_GENERATION) {
            throw new InvalidArgumentException('Thế hệ phải từ 1 tới '.self::MAX_GENERATION.'.');
        }

        $name = str_replace('/', '~', $key);

        if ($generation === 1) {
            return $name;
        }

        $base = strrpos($name, '~');
        $dot = strrpos($name, '.');

        return $dot !== false && $dot > ($base === false ? 0 : $base + 1)
            ? substr($name, 0, $dot).'~g'.$generation.substr($name, $dot)
            : $name.'~g'.$generation;
    }

    /** @return array{key: string, generation: int}|null */
    public static function parse(string $name): ?array
    {
        if (preg_match(self::PATTERN, $name, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return [
            'key' => $match[1].'/'.$match[2].($match[4] ?? ''),
            'generation' => $match[3] === null ? 1 : (int) $match[3],
        ];
    }
}
