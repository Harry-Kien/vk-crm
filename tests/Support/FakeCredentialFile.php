<?php

namespace Tests\Support;

use App\Support\Storage\CredentialFileInspector;

/**
 * M14 Task 5 — {@see CredentialFileInspector} giả cho dòng `drive_credentials` (kế hoạch M14, R6).
 *
 * Container test chạy bằng MỘT người dùng cố định (root): `chmod` không làm tệp thành "không đọc
 * được", và chủ, nhóm, thành viên nhóm của tệp không dựng lại được. Lớp này trả đúng các sự kiện mà
 * test đặt cho TỆP KHOÁ; phần còn lại của luật (vị trí so với `base_path()`/`public_path()`, tên thư
 * mục cha, giải JSON, phép AND trên bit quyền) vẫn là mã thật của `StorageReadiness`.
 *
 * - `realPath()` trả nguyên đường dẫn (hoặc `$resolved`, như một symlink đã giải): test đặt đường dẫn
 *   mà nó muốn luật vị trí nhìn thấy. Thư mục gốc của ứng dụng cũng đặt được (`$roots`): `base_path()`
 *   của container test là `/var/www/html`, có đoạn `www` — luật "thư mục gốc web" sẽ che mất luật
 *   `base_path()` nếu test dùng đường thật.
 * - Mặc định là một khoá hợp lệ `0600` của chính người dùng chạy PHP: dòng XANH. Mỗi test đổi đúng
 *   một sự kiện.
 */
final class FakeCredentialFile extends CredentialFileInspector
{
    /**
     * @param  list<string>|null  $members
     * @param  array{base: string, public: string}|null  $roots
     */
    public function __construct(
        public int $mode = 0o600,
        public ?string $json = null,
        public bool $exists = true,
        public bool $readable = true,
        public bool $posix = true,
        public int $fileGroup = 1001,
        public int $processGroup = 1001,
        public string $processUser = 'vkcrm',
        public ?array $members = [],
        public ?array $roots = null,
        public ?string $resolved = null,
    ) {
        $this->json ??= (string) json_encode(self::validKey());
    }

    public function applicationRoots(): array
    {
        return $this->roots ?? parent::applicationRoots();
    }

    /** @return array<string, string> khoá tài khoản dịch vụ hợp lệ về HÌNH DẠNG (khoá riêng giả) */
    public static function validKey(): array
    {
        return [
            'type' => 'service_account',
            'project_id' => 'vk-crm-test',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIfake\n-----END PRIVATE KEY-----\n",
            'client_email' => FakeGoogleDrive::SERVICE_ACCOUNT,
        ];
    }

    public function isFile(string $path): bool
    {
        return $this->exists;
    }

    public function isReadable(string $path): bool
    {
        return $this->exists && $this->readable;
    }

    public function realPath(string $path): ?string
    {
        if (! $this->exists) {
            return null;
        }

        // Chỉ chính tệp khoá được "giải" sang `$resolved`; thư mục gốc của ứng dụng giữ nguyên.
        return $this->resolved !== null && ! in_array($path, $this->applicationRoots(), true) ? $this->resolved : $path;
    }

    public function permissions(string $path): ?int
    {
        return $this->exists ? $this->mode : null;
    }

    public function contents(string $path): ?string
    {
        return $this->exists && $this->readable ? $this->json : null;
    }

    public function fileGroup(string $path): ?int
    {
        return $this->exists ? $this->fileGroup : null;
    }

    public function posixAvailable(): bool
    {
        return $this->posix;
    }

    public function processGroup(): ?int
    {
        return $this->posix ? $this->processGroup : null;
    }

    public function processUser(): ?string
    {
        return $this->posix ? $this->processUser : null;
    }

    public function groupMembers(int $group): ?array
    {
        return $this->posix ? $this->members : null;
    }
}
