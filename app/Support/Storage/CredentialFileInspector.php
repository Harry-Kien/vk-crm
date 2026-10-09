<?php

namespace App\Support\Storage;

/**
 * Đọc các sự kiện về tệp khoá tài khoản dịch vụ Google mà dòng `drive_credentials` của kiểm tra sẵn
 * sàng cần (kế hoạch M14, R6): tệp có không, đọc được không, quyền, nội dung, nhóm của tệp, và nhóm,
 * người dùng, thành viên nhóm của TIẾN TRÌNH PHP đang chạy.
 *
 * Tách ra một lớp, và không `final`, chỉ để test thay được (`app()->instance(...)`): container test
 * chạy bằng một người dùng cố định (root), nên "không đọc được", chủ, nhóm và thành viên nhóm không
 * dựng lại được bằng `chmod`. Luật — vị trí nào là ĐỎ, bit quyền nào là VÀNG — nằm ở
 * `App\Actions\Storage\StorageReadiness`, không ở đây: lớp này chỉ trả sự kiện, không kết luận.
 *
 * - Không bao giờ ném: hàm hệ thống trả `false` hay hàm `posix_*` bị tắt (`disable_functions`, hay
 *   gặp trên shared hosting) đều thành `null`/`false`. Cảnh báo PHP của lần gọi bị nuốt (`@`).
 * - Không ghi log, không trả nội dung khoá cho ai ngoài nơi gọi: {@see self::contents()} chỉ được
 *   giải JSON để kiểm hình dạng và lấy `client_email`; `private_key` không bao giờ được in.
 */
class CredentialFileInspector
{
    /**
     * Hai thư mục của ứng dụng mà tệp khoá không được nằm dưới: gốc mã nguồn (`base_path()`, một lần
     * `git add` là lộ) và gốc web (`public_path()`; trên shared hosting nó có thể nằm NGOÀI gốc mã nguồn).
     *
     * @return array{base: string, public: string}
     */
    public function applicationRoots(): array
    {
        return ['base' => base_path(), 'public' => public_path()];
    }

    public function isFile(string $path): bool
    {
        return @is_file($path);
    }

    public function isReadable(string $path): bool
    {
        return @is_readable($path);
    }

    /** Đường dẫn đã giải symlink và `..`, hoặc `null` khi tệp không có. */
    public function realPath(string $path): ?string
    {
        $real = @realpath($path);

        return $real === false ? null : $real;
    }

    /** Chín bit quyền (`0o777`), hoặc `null` khi không đọc được. */
    public function permissions(string $path): ?int
    {
        clearstatcache(true, $path);

        $permissions = @fileperms($path);

        return $permissions === false ? null : $permissions & 0o777;
    }

    public function contents(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** Mã nhóm (gid) của tệp, hoặc `null`. */
    public function fileGroup(string $path): ?int
    {
        $group = @filegroup($path);

        return $group === false ? null : $group;
    }

    /** Có đủ bốn hàm `posix_*` để chứng minh nhóm của tệp là riêng không. */
    public function posixAvailable(): bool
    {
        return function_exists('posix_getegid')
            && function_exists('posix_geteuid')
            && function_exists('posix_getpwuid')
            && function_exists('posix_getgrgid');
    }

    /** Nhóm HIỆU LỰC của tiến trình PHP (`posix_getegid()`), hoặc `null` khi thiếu posix. */
    public function processGroup(): ?int
    {
        return $this->posixAvailable() ? posix_getegid() : null;
    }

    /** Tên người dùng hiệu lực của tiến trình PHP, hoặc `null`. */
    public function processUser(): ?string
    {
        if (! $this->posixAvailable()) {
            return null;
        }

        $user = @posix_getpwuid(posix_geteuid());

        return is_array($user) && is_string($user['name'] ?? null) ? $user['name'] : null;
    }

    /**
     * Thành viên PHỤ của một nhóm (`posix_getgrgid()['members']`, tức các dòng trong `/etc/group`),
     * hoặc `null` khi không đọc được nhóm. Người nhận nhóm đó làm nhóm CHÍNH không nằm trong danh sách
     * này: đó là giới hạn của chính hệ điều hành, và lý do câu VÀNG nói "không chứng minh được".
     *
     * @return list<string>|null
     */
    public function groupMembers(int $group): ?array
    {
        if (! $this->posixAvailable()) {
            return null;
        }

        $info = @posix_getgrgid($group);

        if (! is_array($info) || ! is_array($info['members'] ?? null)) {
            return null;
        }

        return array_values(array_map('strval', $info['members']));
    }
}
