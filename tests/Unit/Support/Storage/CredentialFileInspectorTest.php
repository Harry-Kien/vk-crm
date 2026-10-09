<?php

use App\Support\Storage\CredentialFileInspector;

/*
|--------------------------------------------------------------------------
| M14 Task 8 — lớp đọc sự kiện của tệp khoá, đo trên tệp THẬT
|--------------------------------------------------------------------------
|
| Mọi test của dòng `drive_credentials` thay lớp này bằng `Tests\Support\FakeCredentialFile`, vì luật
| nằm ở `StorageReadiness` và container test chạy bằng root (không dựng lại được "không đọc được"
| hay nhóm lạ bằng `chmod`). Đo độ phủ của Task 8 cho thấy phần của chính lớp thật — tệp vắng, nhóm
| của tệp, và bốn hàm `posix_*` — chưa test nào chạy tới. Ở đây: tệp thật trong thư mục tạm của hệ
| thống, so với chính các hàm PHP mà lớp bọc lại, và nhánh "thiếu posix" qua một lớp con.
*/

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/vkcrm-t8-inspector-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';
});

afterEach(function () {
    @unlink($this->path);
});

it('tệp không có: mọi sự kiện là false/null, không ném, không cảnh báo', function () {
    $inspector = new CredentialFileInspector;

    expect($inspector->isFile($this->path))->toBeFalse()
        ->and($inspector->isReadable($this->path))->toBeFalse()
        ->and($inspector->realPath($this->path))->toBeNull()
        ->and($inspector->permissions($this->path))->toBeNull()
        ->and($inspector->contents($this->path))->toBeNull()
        ->and($inspector->fileGroup($this->path))->toBeNull();
});

it('tệp thật: đúng chín bit quyền sau chmod, nội dung, đường thật và nhóm của tệp', function () {
    file_put_contents($this->path, '{"type":"service_account"}');
    $inspector = new CredentialFileInspector;

    chmod($this->path, 0o440);
    expect($inspector->permissions($this->path))->toBe(0o440);

    chmod($this->path, 0o604);
    expect($inspector->permissions($this->path))->toBe(0o604)
        ->and($inspector->isFile($this->path))->toBeTrue()
        ->and($inspector->isReadable($this->path))->toBeTrue()
        ->and($inspector->contents($this->path))->toBe('{"type":"service_account"}')
        ->and($inspector->realPath($this->path))->toBe(realpath($this->path))
        ->and($inspector->fileGroup($this->path))->toBe(filegroup($this->path));
});

it('có posix: nhóm và người dùng hiệu lực của tiến trình, thành viên phụ của nhóm là danh sách chuỗi', function () {
    $inspector = new CredentialFileInspector;

    if (! function_exists('posix_getegid')) {
        expect($inspector->posixAvailable())->toBeFalse();

        return;
    }

    $group = $inspector->processGroup();

    expect($inspector->posixAvailable())->toBeTrue()
        ->and($group)->toBe(posix_getegid())
        ->and($inspector->processUser())->toBe(posix_getpwuid(posix_geteuid())['name'])
        ->and($inspector->groupMembers($group))->toBe(array_values(array_map('strval', posix_getgrgid($group)['members'])))
        // Một gid không có trong /etc/group: không đọc được nhóm → null, không phải danh sách rỗng.
        ->and($inspector->groupMembers(2_000_000_001))->toBeNull();
});

it('thiếu posix (bị tắt trên shared hosting): nhóm, người dùng và thành viên nhóm đều null', function () {
    $inspector = new class extends CredentialFileInspector
    {
        public function posixAvailable(): bool
        {
            return false;
        }
    };

    expect($inspector->processGroup())->toBeNull()
        ->and($inspector->processUser())->toBeNull()
        ->and($inspector->groupMembers(0))->toBeNull();
});
