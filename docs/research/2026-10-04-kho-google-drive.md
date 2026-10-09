# Khảo sát kho Google Drive — M14 Task 0 (2026-10-04)

Đây là bản khảo sát làm trước khi viết dòng mã nào của M14 (kế hoạch
`docs/superpowers/plans/2026-10-04-m14-google-drive-storage.md`, Task 0). Nó gồm năm phần:

- dry-run gói trên `main` và trên bản sao `composer.*` của hai làn đang mở;
- kết quả kiểm lỗ hổng, giấy phép và ngày phát hành;
- bản đồ chỗ mã chạm tệp;
- những gì đã xác nhận trong `vendor/`;
- số đo trên dữ liệu seed, và các việc chờ chủ văn phòng.

Bối cảnh chạy:

- Worktree `D:\vkwt\lane-m14`, nhánh `m14-drive-storage`, gốc `d3d3e3f` (= `main` ngày 2026-10-04).
- Mọi lệnh PHP/composer chạy trong Docker qua `/d/vkwt/m14-dev`, image `webdevops/php:8.3-alpine`
  (PHP 8.3.33). `vendor` là của riêng làn.
- Sau mỗi dry-run, `git diff --exit-code composer.json composer.lock` đều sạch.
- **Không lệnh nào gọi endpoint Google** (phán quyết controller C2). Phần (c) ở mục 9 chỉ đọc
  trang trợ giúp công khai.

**Kết luận ngắn:**

- R1 đứng vững. `google/auth` ^1.55 giải sạch trên `main`, trên `lane-m11` và trên `lane-m12`,
  không hạ gói nào.
- Không media nào lệch khuôn R4.
- Không có lý do để dừng. Task 1 đi tiếp theo đúng R1.
- Có hai phát hiện cần controller quyết, nêu ở mục 12:
  - Google nay ghi giới hạn của Shared Drive là **500.000** mục, kể cả thùng rác (kế hoạch ghi
    400.000);
  - `FilesystemAdapter::checksum()` nuốt lỗi và trả `false` khi đĩa có `throw = false`.

## 1. Làn và công cụ

Agent dựng làn đã làm phần này ngày 2026-10-04, sổ cái làn ghi lại.

- Script `/d/vkwt/m14-dev` theo khuôn `/d/vkwt/m11-dev`:
  - `vendor` riêng trong worktree;
  - volume cache `vkcrm-lane-m14-composer-cache`;
  - mount `vk-container-test`;
  - hai CSDL `vk_crm_test_lane_m14` (test MariaDB) và `vk_crm_lane_m14` (seed, `db:artisan`);
  - cổng 8098.
- Bằng chứng công cụ: `PrivateDiskTest` có 7 passed; `pint --test` PASS trên 928 tệp.
- Mốc của cả bộ trên `d3d3e3f`: `1 risky, 1 todo, 29 skipped, 4340 passed (19040 assertions)`,
  không thất bại.
- Image làn có `openssl`, `posix`, `sodium`, `curl`, `pcntl`. `disk_free_space` có, và
  `disable_functions` rỗng. Không có `pcov`, `xdebug`, `rclone`.

Ô đầu tiên của Task 0 (worktree + script) vì thế đã xong.

## 2. Dry-run gói (R1)

### 2.1 `masbug/flysystem-google-drive-ext` trên `main`: thất bại, như kỳ vọng

```
$ /d/vkwt/m14-dev composer require --dry-run masbug/flysystem-google-drive-ext
  Problem 1
    - Root composer.json requires masbug/flysystem-google-drive-ext * -> satisfiable by masbug/flysystem-google-drive-ext[v1.0.0, ..., v1.3.3, v2.0.0, ..., v2.5.0].
    - masbug/flysystem-google-drive-ext[v1.0.0, ..., v1.1.5] require guzzlehttp/guzzle >=6.3 <7.0 -> found guzzlehttp/guzzle[6.3.0, ..., 6.5.8] but the package is fixed to 8.2.0 (lock file version) by a partial update and that version does not match. Make sure you list it as an argument for the update command.
    - masbug/flysystem-google-drive-ext[v1.2.0, ..., v1.2.1] require php ^7.2 -> your php version (8.3.33) does not satisfy that requirement.
    - masbug/flysystem-google-drive-ext[v1.2.2, ..., v1.3.3, v2.0.0, ..., v2.5.0] require guzzlehttp/guzzle ^6.3 | ^7.0 -> found guzzlehttp/guzzle[6.3.0, ..., 6.5.8, 7.0.0, ..., 7.15.5] but the package is fixed to 8.2.0 (lock file version) by a partial update and that version does not match. Make sure you list it as an argument for the update command.
Installation failed, reverting ./composer.json and ./composer.lock to their original content.
EXIT=2
```

Packagist cho thấy bản mới nhất `v2.5.0` (Apache-2.0) và nhánh `2.x-dev` vẫn khai:

- `guzzlehttp/guzzle ^6.3 | ^7.0`;
- `guzzlehttp/psr7 ^1.7|^2.0`;
- `google/apiclient ^2.2`.

`composer show -a` không in ngày phát hành của gói chưa cài. Ngày 2026-04-13 của `v2.5.0` lấy từ
kế hoạch R1, chưa đo lại.

### 2.2 Cùng lệnh với `-W`: chạy được, nhưng hạ Guzzle của cả ứng dụng

```
$ /d/vkwt/m14-dev composer require --dry-run -W masbug/flysystem-google-drive-ext
Running composer update masbug/flysystem-google-drive-ext --with-all-dependencies
Lock file operations: 7 installs, 8 updates, 1 removal
  - Removing symfony/polyfill-php82 (v1.38.1)
  - Locking firebase/php-jwt (v7.2.1)
  - Locking google/apiclient (v2.20.1)
  - Locking google/apiclient-services (v0.461.0)
  - Locking google/auth (v1.55.1)
  - Downgrading guzzlehttp/guzzle (8.2.0 => 7.15.5)
  - Downgrading guzzlehttp/promises (3.0.2 => 2.5.3)
  - Downgrading guzzlehttp/psr7 (3.1.0 => 2.13.1)
  - Locking masbug/flysystem-google-drive-ext (v2.5.0)
  - Upgrading monolog/monolog (3.12.0 => 3.12.1)
  - Locking psr/cache (3.0.0)
  - Locking ralouphie/getallheaders (3.0.3)
  - Upgrading symfony/polyfill-intl-idn (v1.42.0 => v1.43.0)
  - Upgrading symfony/polyfill-intl-normalizer (v1.42.0 => v1.43.0)
  - Upgrading symfony/polyfill-mbstring (v1.38.2 => v1.43.0)
  - Upgrading symfony/polyfill-php80 (v1.37.0 => v1.43.0)
EXIT=0
```

Khớp R1: hạ ba gói Guzzle, gỡ `symfony/polyfill-php82`, thêm 7 gói. Kế hoạch không nhắc **5 lượt
nâng** đi kèm (`monolog/monolog` và bốn `symfony/polyfill-*`). Chúng có mặt vì `-W` mở khoá mọi
phụ thuộc, nên đây cũng là thay đổi ngoài phạm vi (C5). Đường này bị loại, như M8a đã loại
(`docs/research/2026-09-26-sao-luu.md:125-140`).

### 2.3 `google/auth:^1.55` trên `main`: 3 gói mới, không hạ gì

```
$ /d/vkwt/m14-dev composer require --dry-run google/auth:^1.55
Running composer update google/auth
Lock file operations: 3 installs, 0 updates, 0 removals
  - Locking firebase/php-jwt (v7.2.1)
  - Locking google/auth (v1.55.1)
  - Locking psr/cache (3.0.0)
Package operations: 3 installs, 0 updates, 0 removals
  - Installing psr/cache (3.0.0)
  - Installing firebase/php-jwt (v7.2.1)
  - Installing google/auth (v1.55.1)
Found 2 security vulnerability advisories affecting 1 package.
EXIT=0
```

Đã `require` thật trên một bản sao của `composer.json`/`composer.lock` (ngoài repo, trong thư mục
sổ cái của làn), rồi so hai lock bằng PHP trong container:

```
added: firebase/php-jwt, google/auth, psr/cache
removed: (none)
changed: (none)
content-hash 617d860a0ff94965e2fe38aacceb5ab2 -> fdba3f30dc0cc41095b7a0865e5511b4
```

`composer.json` của bản sao chỉ thêm đúng một dòng, `"google/auth": "^1.55"`. Lock diff vì thế
đúng phạm vi C5: ba gói cộng `content-hash`.

### 2.4 Trên bản sao `composer.*` của `lane-m11` (C4, bắt buộc)

Bản sao lấy bằng `git show m11-mcp-server:composer.json` và `git show m11-mcp-server:composer.lock`.
Nhánh `m11-mcp-server` lúc đó ở `1b99056`, sau là `776044a`; hai tệp này không đổi giữa hai commit.
Không chạm worktree `D:\vkwt\lane-m11`.

```
$ /d/vkwt/m14-dev composer -d .superpowers/sdd/m14/m11-copy require --dry-run google/auth:^1.55 --no-scripts --no-plugins --no-install
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking google/auth (v1.55.1)
  - Locking psr/cache (3.0.0)
EXIT=0
```

Đúng như kỳ vọng: `firebase/php-jwt` v7.2.1 đã khoá sẵn ở m11 (do `laravel/passport`), nên chỉ thêm
`google/auth` và `psr/cache`. Không hạ gì.

**Lưu ý đo được.** `m11-mcp-server` và `m12-pwa-push` cùng merge-base `47ee8e3` với `main`, chậm 30
commit. `composer.json` của cả hai **thiếu** `barryvdh/laravel-dompdf ^3.1`, gói mà M7 đã thêm vào
`main`. Dry-run trên hai bản sao này vì thế là dry-run trên cây **trước M7**. Xung đột thật xử lý ở
cổng merge, theo cách Ràng buộc toàn cục mô tả (gộp tay `composer.json`, lấy lock của `main` rồi
chạy lại).

### 2.5 Trên bản sao `composer.*` của `lane-m12` (khuyến nghị)

`m12-pwa-push` thêm `laravel-notification-channels/webpush ^13.0` và một mục
`extra.laravel.dont-discover`. Lock của nó có `web-token/jwt-library` 4.2.3, `minishlink/web-push`
v11.0.0, guzzle 8.2.0, và không có `firebase/php-jwt`.

```
$ /d/vkwt/m14-dev composer -d .superpowers/sdd/m14/m12-copy require --dry-run google/auth:^1.55 --no-scripts --no-plugins --no-install
Lock file operations: 3 installs, 0 updates, 0 removals
  - Locking firebase/php-jwt (v7.2.1)
  - Locking google/auth (v1.55.1)
  - Locking psr/cache (3.0.0)
EXIT=0
```

Không hạ gì, không đụng hai thư viện JWT của web push.

## 3. `composer audit --locked`, trước và sau

Trước (lock của `main`, trong worktree):

```
$ /d/vkwt/m14-dev composer audit --locked
Found 2 security vulnerability advisories affecting 1 package:
| Package           | league/commonmark                                                                |
| Severity          | medium                                                                           |
| Advisory ID       | PKSA-m2dq-1fhr-29b1                                                              |
| URL               | https://github.com/advisories/GHSA-97jj-33gv-5xf9                                |
| Affected versions | >=1.3.0,<=2.10.1                                                                 |
| Package           | league/commonmark                                                                |
| Severity          | high                                                                             |
| Advisory ID       | PKSA-m4t9-vsgq-8khn                                                              |
| URL               | https://github.com/advisories/GHSA-3q6v-r5mr-hxv8                                |
| Affected versions | >=2.0.0,<=2.10.1                                                                 |
EXIT=1
```

Sau (bản sao đã `require google/auth:^1.55`): **đúng hai cảnh báo đó**, cùng `PKSA-m2dq-1fhr-29b1`
và `PKSA-m4t9-vsgq-8khn`, cùng mã thoát 1. Cây `google/auth` không thêm cảnh báo nào.

Hai cảnh báo của `league/commonmark` 2.10.1 có sẵn trên `main` và không thuộc M14. Controller xử
lý riêng (C5). `composer audit` in mã `PKSA-…`; mã `GHSA-…` nằm trong URL.

## 4. Giấy phép và ngày phát hành của ba gói mới

Đọc bằng `composer show --locked` trên bản sao đã `require`:

| Gói | Bản | Giấy phép | Phát hành | Ràng buộc đáng chú ý |
|---|---|---|---|---|
| `google/auth` | v1.55.1 | Apache-2.0 | 2026-09-28 | `php ^8.1`, `guzzlehttp/guzzle ^7.8.2\|\|^8.0`, `guzzlehttp/psr7 ^2.6.3\|\|^3.0`, `firebase/php-jwt ^6.0\|\|^7.0`, `psr/cache ^2.0\|\|^3.0`, `psr/http-client ^1.0`, `psr/http-message ^1.1\|\|^2.0`, `psr/log ^2.0\|\|^3.0` |
| `firebase/php-jwt` | v7.2.1 | BSD-3-Clause | 2026-09-28 | `php ^8.0`; mã nguồn nay ở `github.com/googleapis/php-jwt` |
| `psr/cache` | 3.0.0 | MIT | 2021-02-03 | `php >=8.0.0` |

- Cả ba đều tương thích PHP 8.3. Không gói nào đòi PHP 8.4.
- `google/auth` không khai `ext-openssl` trong `require`. Ký JWT RS256 của tài khoản dịch vụ vẫn
  cần `openssl`, và `openssl` đã nằm trong danh sách extension bắt buộc của SPEC §2.

## 5. Bản đồ chỗ chạm tệp (grep trên `d3d3e3f`)

```
=== Storage::disk(            (app/)
app/Actions/Backup/CheckBackupDestinations.php:89:            $disk = Storage::disk($diskName);
app/Actions/Backup/PushBackupArchiveToRclone.php:86:        $disk = Storage::disk($event->diskName);
app/Actions/Deployment/RunPreflight.php:325:        $disk = Storage::disk('private');
app/Actions/Matter/BuildHandoverPackage.php:453:            Storage::disk($media->disk)->deleteDirectory(dirname($media->getPathRelativeToRoot()));
app/Actions/Matter/CollectHandoverEntries.php:115:            $disk = Storage::disk($media->disk);
app/Http/Controllers/DocumentDownloadController.php:138:        $disk = Storage::disk($media->disk);
=== ->path(                   (app/; dòng của Request/Panel không phải đĩa)
app/Actions/Backup/PushBackupArchiveToRclone.php:98:        $absolutePath = $disk->path($archive);
app/Actions/Matter/CollectHandoverEntries.php:129:                sourcePath: $disk->path($relative),
app/Http/Middleware/RequirePortalPasswordChange.php:44:        (request->path(), không phải đĩa)
app/Providers/Filament/AdminPanelProvider.php:47 / PortalPanelProvider.php:83   (Panel::path)
=== getFirstMedia(
app/Actions/Matter/CollectHandoverEntries.php:99:            $media = $document->getFirstMedia('file');
app/Filament/Admin/Resources/Matters/RelationManagers/ChecklistRelationManager.php:367:  (chỉ đọc ->name)
app/Http/Controllers/DocumentDownloadController.php:132:        $media = $record->getFirstMedia('file');
=== addMedia(                 (dòng mã; các dòng còn lại là docblock)
app/Actions/Document/Concerns/StoresDocumentFile.php:109:        $document->addMedia($file)
app/Actions/Matter/BuildHandoverPackage.php:351:                    $storedMedia = $document->addMedia($zipPath)
=== ->download(
app/Http/Controllers/DocumentDownloadController.php:411:        return $disk->download($media->getPathRelativeToRoot(), ...
=== ->response(
(không có)
=== deleteDirectory(
app/Actions/Matter/BuildHandoverPackage.php:179:            File::deleteDirectory($workDirectory);
app/Actions/Matter/BuildHandoverPackage.php:453:            Storage::disk($media->disk)->deleteDirectory(dirname($media->getPathRelativeToRoot()));
app/Actions/Matter/RecordHandoverPackageFailure.php:145:            File::deleteDirectory($directory);
```

Grep thêm, rộng hơn:

- `getTemporaryUrl`, `getFullUrl`, `->getUrl(` của media, `toInlineResponse`, `toResponse(`,
  `->stream(`, `MediaStream`, `copyMedia`, `->move(`: **không có** trong `app/`.
- `temporaryUrl()` chỉ xuất hiện trong docblock `DocumentsRelationManager.php:91`.
- Đường tải duy nhất là `DocumentDownloadController`.
- `useDisk('private')` chỉ ở `app/Models/Document.php:224`.
- `config/media-library.php:66` có `disk_name = env('MEDIA_DISK', 'private')`.

`Storage::fake(` trong `tests/`: **32 tệp**.

```
     27 Storage::fake('private')
     15 Storage::fake()                          (đĩa mặc định `local`)
      9 Storage::fake(BackupDisks::DEFAULT_DISK)
      … mỗi đĩa sao lưu riêng một lời gọi (backup_*, prune_*, cleanup_*, guard_*, 'x', $name, $disk)
tests/Pest.php:48, :52   ->beforeEach(fn () => Storage::fake('private'))   (hai beforeEach toàn cục)
```

`tests/Pest.php` **không** bật `Http::preventStrayRequests()` toàn cục. Test nào chạm Drive phải tự
gọi (C2).

## 6. Đối chiếu bảng "Hiện trạng trên `main`" của kế hoạch

Mọi số dòng đã kiểm lại trên `d3d3e3f` và **khớp**, không lệch quá 2 dòng:

| Chỗ | Dòng |
|---|---|
| `config/filesystems.php` | 79 |
| `Document.php` | 224 (`useDisk`), 210 (`DocumentReferencedByBillingRecord`) |
| `StoresDocumentFile` | 58 / 105 / 127 |
| `UploadStaffDocument` | 173 / 176 / 308 |
| `SubmitClientDocument` | 216 / 219 / 333 |
| `BuildHandoverPackage` | 78-89 / 179 / 247 / 284 / 351 / 353 / 446 |
| `CollectHandoverEntries` | 99 / 114-129 |
| `GenerateHandoverPackage::TIMEOUT_SECONDS` | 49 |
| `RequestHandoverPackage::STALE_AFTER_MINUTES` | 71 |
| `config/queue.php` | 107-114, 112 |
| `routes/console.php` | 252, 269-273 |
| `DocumentDownloadController` | 132 / 138 / 146 / 396-419 |
| `RunPreflight` | 54 / 76 / 321 / 325 / 442 |
| `config/cache.php` | 18 |
| `RecordMatterDestruction` | 20-21 |
| `ArchitectureTest` | 144, 269 |
| `config/backup.php` | 60-62 |
| `RcloneProcess` | 44 (`deleteFile`), 60 (`listJson`) |
| `config/livewire.php` | 167 |

Chỉ lệch tên và đường dẫn:

- `QueueHandoverScheduleTest` nằm ở `tests/Feature/Schedule/QueueHandoverScheduleTest.php`.
  - Khoá 15 phút ở `:31-38`.
  - Kết nối `handover` ở `:54-63`.
  - Test `:62` còn ghim `queue.connections.database.retry_after < TIMEOUT_SECONDS`. Với
    TIMEOUT 1200 thì vẫn đúng (90 < 1200).
- `config/backup.php` loại trừ tường minh `storage_path('framework')` (`:71-72`). Token cache ở
  store `file` (`storage/framework/cache/data`) vì thế không vào archive sao lưu, đúng ý R6.

**Tên thật thắng kế hoạch**, ghi lại cho Task 5 và Task 7:

- Không có lớp `PreflightRow`. Dòng preflight là mảng `['key' => string, 'level' => PreflightLevel,
  'message' => string]` do `RunPreflight::row()` (`:442`) dựng, với `App\Enums\PreflightLevel`
  (`Red`/`Yellow`/`Green`).
- `RcloneProcess::listJson()` **đã có** (`app/Support/Backup/RcloneProcess.php:60`); Task 7 không
  "thêm" nó.
- `RcloneProcess::deleteFile()` (M8a, `:44`) có sẵn. Test cấu trúc "không `deletefile`" của M14
  chỉ áp cho lời gọi của M14.
- `app/Mcp` chưa có trên nhánh này. Test cấu trúc của R14 ghi thành việc cho lúc gộp với M11.

## 7. Xác nhận trong `vendor/`

Phiên bản: `league/flysystem` 3.36.0, `laravel/framework` v13.31.0, `spatie/laravel-medialibrary`
11.23.8.

**`League\Flysystem\ChecksumProvider`** có trong `vendor/league/flysystem/src/ChecksumProvider.php`.
Interface chỉ có `checksum(string $path, Config $config): string`, ném `UnableToProvideChecksum`
hoặc `ChecksumAlgoIsNotSupported`.

**`Illuminate\Filesystem\FilesystemAdapter::checksum()`** ở `FilesystemAdapter.php:688`:

```php
    public function checksum($path, array $options = [])
    {
        try {
            return $this->driver->checksum($path, $options);
        } catch (UnableToProvideChecksum $e) {
            if ($this->throwsExceptions()) {
                throw $e;
            }
            $this->report($e);
            return false;
        }
    }
```

**`League\Flysystem\Filesystem::checksum()`** (`Filesystem.php:219-238`) có hai đường đọc cả tệp:

- adapter không cài `ChecksumProvider` thì tự tính bằng cách **đọc cả tệp**
  (`calculateChecksumFromStream`);
- adapter ném `ChecksumAlgoIsNotSupported` thì cũng **đọc cả tệp** để tự tính.

`LocalFilesystemAdapter` (đĩa giả của test) cài `ChecksumProvider` bằng `hash_file()`.

**`DefaultPathGenerator::getPath()`** trả `getBasePath($media).'/'`, tức `<id>/` có dấu `/`.
`getPathForConversions()` trả `<id>/conversions/`, `getPathForResponsiveImages()` trả
`<id>/responsive-images/`. Khi `media-library.prefix` khác rỗng, gốc là `<prefix>/<id>`.

**`DefaultFileRemover`** (`DefaultFileRemover.php:17-123`). Hành vi thật khác mô tả của kế hoạch
một chút; đây là bản đúng:

```php
    public function removeAllFiles(Media $media): void
    {
        if ($media->conversions_disk && $media->disk !== $media->conversions_disk) {
            $this->removeFromConversionsDirectory($media, $media->conversions_disk);
            $this->removeFromResponsiveImagesDirectory($media, $media->conversions_disk);
            $this->removeFromMediaDirectory($media, $media->conversions_disk);
        }
        $this->removeFromConversionsDirectory($media, $media->disk);
        $this->removeFromResponsiveImagesDirectory($media, $media->disk);
        $this->removeFromMediaDirectory($media, $media->disk);
    }
    // removeFromMediaDirectory, cho $directory = '<id>/':
                try {
                    $allFilePaths = $this->filesystem->disk($disk)->allFiles($directory);
                    $imagePaths = array_filter($allFilePaths,
                        static fn (string $path) => Str::afterLast($path, '/') === $media->file_name);
                    foreach ($imagePaths as $imagePath) {
                        $this->filesystem->disk($disk)->delete($imagePath);
                    }
                    if (! $this->filesystem->disk($disk)->allFiles($directory)) {
                        $this->filesystem->disk($disk)->deleteDirectory($directory);
                    }
                } catch (Exception $exception) {
                    report($exception);
                }
```

- **Thứ tự** trên mỗi đĩa: `<id>/conversions/` → `<id>/responsive-images/` → `<id>/`.
- Mỗi thư mục đi qua bốn bước:
  1. `allFiles(dir)`;
  2. `delete()` từng đường có basename đúng `file_name` (conversion và ảnh responsive dùng tên
     riêng);
  3. `allFiles(dir)` lần nữa;
  4. `deleteDirectory(dir)`, **chỉ khi rỗng**.
- Lỗi bị bắt bằng `Exception`, không phải `Throwable`, rồi `report()`.
- Khi `conversions_disk` khác `disk`, ba thư mục trên `conversions_disk` được xử lý **trước**.
  Câu UPDATE đổi đĩa của R2 đặt cả hai cột cùng giá trị, nên đường này không xảy ra trên dữ liệu
  của M14.
- `BuildHandoverPackage::discardStoredFile()` gọi `deleteDirectory(dirname(...))`, tức `'<id>'`
  **không** dấu `/`.

**Flysystem chuẩn hoá đường dẫn trước khi tới adapter.** `WhitespacePathNormalizer::normalizePath()`
tách theo `/` và bỏ phần rỗng, nên cả `'18/'` lẫn `'18'` tới adapter thành `'18'`. Adapter không
bao giờ thấy dấu `/` cuối. Luật "thư mục `d` = các khoá `LIKE 'd/%'`" của R4 phải tự thêm `/`.
Test của Task 2 đi qua `Storage::disk()`, không gọi thẳng adapter.

**`exists()`** của Laravel gọi `Filesystem::has()` = `fileExists($p) || directoryExists($p)`
(`Filesystem.php:46-51`). Một tệp thiếu vì thế hỏi cả hai. Cả hai phải trả lời từ chỉ mục, không
gọi mạng (R3).

**`readStream()`** của Laravel (`FilesystemAdapter.php:740-751`) chỉ bắt `UnableToReadFile`. Với
`throw = false`, nó `report()` rồi trả `null`. Ngoại lệ khác (ví dụ `DocumentStorageUnavailable`
của ta) đi thẳng ra ngoài. `delete()`/`deleteDirectory()` cũng chỉ bắt lỗi `UnableTo…` tương ứng.

**`MediaObserver::deleted()`** (`MediaObserver.php:55-65`) gọi `$filesystem->removeAllFiles($media)`
ngay ở sự kiện `deleted`. Không `ShouldHandleEventsAfterCommit`, không `afterCommit`. Một lượt xoá
media trong transaction rồi rollback vẫn đã chạy việc xoá tệp.

Ba điều khác trong medialibrary:

- `MediaObserver::updating()` chỉ dời tệp khi `media-library.moves_media_on_update` bật. Ở đây nó
  là `false` (`config/media-library.php:202`). Câu UPDATE có điều kiện của R2, viết bằng query
  builder đúng như R2 mô tả, không bắn sự kiện model nào. Viết bằng `$media->update()` thì
  `updating` chạy, nhưng vẫn không dời tệp vì cờ này tắt.
- `Filesystem::getMediaDirectory()` (11.23.8, `:346-364`) **không** gọi `makeDirectory()`. Tệp mới
  luôn ghi vào `private` (collection `file` khai `useDisk('private')`). Medialibrary vì thế không
  bao giờ ghi vào đĩa kho; chỉ Action đẩy của ta ghi.
- `copyToMediaLibrary()` (`:140`) **chép** tệp bằng `fopen()` + `put()` luồng, như docblock
  `BuildHandoverPackage:78-89` mô tả.

## 8. Đo trên dữ liệu seed

```
$ /d/vkwt/m14-dev seed                      # migrate:fresh --seed vào vk_crm_lane_m14, 42,8 giây
$ /d/vkwt/m14-dev db:artisan tinker --execute="require '…/measure.php';"
media rows: 56
total size (bytes): 43430
largest: id=1 size=832 mime=application/pdf file_name=01m42f2zrv0r3tb046zqjxztwv.pdf
by disk: {"private":56}
by conversions_disk: {"private":56}
by model_type/collection: {"document#file":56}
by mime: {"application\/pdf":56}
file_name not matching R4 /^[0-9a-z]{26}(\.[0-9a-z]{1,8})?$/: 0
object key (getPathRelativeToRoot) not matching #^\d+/[0-9a-z]{26}(\.[0-9a-z]{1,8})?$#: 0
keys containing "~": 0
media whose file is missing on its disk: 0
files under private disk: 56 bytes=43430
max media id: 56
media extensions: {"pdf":56}
```

- **0 media lệch khuôn R4**, cả trên `file_name` lẫn trên khoá đầy đủ `<id>/<file_name>`. Không
  phải dừng.
- Dữ liệu seed chỉ có PDF nhỏ (tối đa 832 byte) và **không có gói bàn giao**.
- Tên tệp của gói bàn giao được kiểm bằng mã thay vì bằng dữ liệu:
  `Str::lower((string) Str::ulid()).'.zip'` (`BuildHandoverPackage.php:353`). ULID viết thường
  thuộc bảng chữ Crockford, tập con của `[0-9a-z]`, và `zip` khớp `[0-9a-z]{1,8}`.
- Tệp người dùng tải lên đi qua `StoresDocumentFile::storedFileName()`: đuôi lọc `[^a-z0-9]` rồi cắt
  còn 8 ký tự. Khuôn R4 vì thế đúng cho mọi tên mà mã hôm nay sinh ra.

**Một giả định ẩn của khuôn R4.** Khuôn `^\d+/…` chỉ đúng khi `media-library.prefix` rỗng
(`config/media-library.php:388`, `env('MEDIA_PREFIX', '')`). `.env.example` không có
`MEDIA_PREFIX`. Ai đặt nó thì mọi khoá thành `<prefix>/<id>/…`; lệnh đẩy sẽ từ chối mọi tệp (log
`critical`), và tệp nằm lại vùng đệm, không mất. Đề xuất: Task 1 hoặc Task 5 ghim
`config('media-library.prefix') === ''`, bằng một test hoặc một dòng `StorageReadiness`.

## 9. Việc cần Workspace / Shared Drive thật: PENDING OWNER

Chưa có Workspace, tài khoản dịch vụ, khoá JSON hay Shared Drive thử nào (C2). Không script tạm
nào được chạy.

| Mục | Trạng thái | Còn thiếu |
|---|---|---|
| (a) Thêm được tài khoản dịch vụ (`…@….iam.gserviceaccount.com`, tức người ngoài tổ chức) khi tắt "Cho phép người ngoài tổ chức truy cập tệp" không | **PENDING OWNER** | Phụ lục A bước 0–6 cho "VK-CRM Kho (thử)". Thử bước 6 với công tắc TẮT, báo lại có thêm được không |
| (b) Content manager gọi được `drives.get` (`restrictions`, `capabilities`) và `permissions.list` trên mã Shared Drive không | **PENDING OWNER** | Phụ lục A bước 0–9 (cần khoá JSON trên máy chạy thử) |
| (c) Mục trong thùng rác có tính vào giới hạn mục không | **Đã trả lời từ tài liệu** (xem dưới) | Không |
| (d) Tài khoản dịch vụ ở vai `writer` (Contributor) có bị từ chối khi cho tệp vào thùng rác không | **PENDING OWNER** | Như (b), cộng một lần đổi tạm vai của tài khoản dịch vụ sang Contributor trên Shared Drive **thử**, rồi trả lại Content manager |
| (e) `rclone` có `cryptcheck --one-way --files-from --match` và `lsjson --hash --hash-type md5`; remote `drive.readonly` với `team_drive` + `root_folder_id` liệt kê đúng cây tháng | **PENDING OWNER** | Máy dev không có `rclone` (`which rclone` rỗng); không có image rclone trong Docker; tải và cài cần chủ văn phòng cho phép. Thử trên máy văn phòng (Phụ lục D) sau khi có Shared Drive thử |
| (f) Trên hosting đích, `disk_free_space` và `posix` có bị tắt không | **PENDING OWNER** | Hosting đích chưa biết. Khi biết, chạy `php -r 'var_dump(function_exists("disk_free_space"), extension_loaded("posix"), ini_get("disable_functions"));'` bằng PHP dòng lệnh **và** qua một trang PHP-FPM tạm (hai SAPI có thể khác `disable_functions`). Image dev có cả hai, `disable_functions` rỗng |

**Đường lùi ghi sẵn cho (b).** Nếu Content manager không gọi được `permissions.list`, R5 lùi về:

- `restrictions` cộng vai của chính tài khoản dịch vụ ở mức ĐỎ;
- danh sách thành viên thành VÀNG "không kiểm được — chủ văn phòng tự rà mỗi tháng".

**(c), đọc từ tài liệu Google công khai ngày 2026-10-04.** Không gọi API.

- Google Workspace Admin Help, "Shared drive limits in Google Drive"
  (`support.google.com/a/answer/7338880`) ghi giới hạn mỗi Shared Drive là **500.000 mục**, và
  con số đó tính cả tệp, thư mục, lối tắt và **mục trong thùng rác**. Câu trả lời cho (c) vì thế
  là **có**.
- Cùng trang ghi:
  - lồng thư mục tối đa 100 cấp;
  - tải lên và sao chép 750 GB mỗi người dùng mỗi 24 giờ;
  - tối đa 600 thành viên.
- Trang "Resolve errors" của Drive API
  (`developers.google.com/workspace/drive/api/guides/handle-errors`) ghi:
  - `numChildrenInNonRootLimitExceeded` là giới hạn 500.000 mục con trực tiếp của một thư mục;
  - `teamDriveFileLimitExceeded` là khi Shared Drive vượt giới hạn mục.
- **Lệch kế hoạch:** R4, R9 và "Những chỗ … sẽ cắn" ghi **400.000** mục mỗi Shared Drive. Tài liệu
  hiện hành ghi **500.000**. Ngưỡng VÀNG 300.000 của `drive_item_count` vẫn là ngưỡng thận trọng,
  và đúng ở cả hai con số. Controller quyết: sửa số trong kế hoạch, hoặc giữ 400.000 làm trần nội
  bộ có chủ đích. Mục 12 có đề xuất.

**Ghi thêm cho R5, cùng nguồn trợ giúp** (Google Workspace Learning Center, "shared drive roles",
`support.google.com/a/users/answer/12380484`):

- Content manager cho tệp và thư mục vào thùng rác được. Nó **không** xoá vĩnh viễn được, và
  **không** thêm hay bớt thành viên Shared Drive được.
- Nó **thêm hoặc bớt được người trên từng thư mục**, trừ khi tắt "Cho phép người quản lý nội dung
  chia sẻ thư mục" (`sharingFoldersRequiresOrganizerPermission`).
- Vì vậy một khoá bị lộ, khi công tắc đó còn bật, chia sẻ được một thư mục tháng. Hàng rào thật
  khi đó là `driveMembersOnly = true` (ĐỎ nếu sai): nó chặn chia sẻ cho người ngoài danh sách
  thành viên.
- Phân mức của R5 (`sharingFoldersRequiresOrganizerPermission != true` là VÀNG) đứng vững. Phụ lục
  A bước 2 vẫn TẮT công tắc này.

## 10. Phụ thuộc vào làn khác chưa vào `main`

Đo ngày 2026-10-04 bằng `git diff --name-only $(git merge-base main <nhánh>) <nhánh>`. Kết quả lọc
theo 29 tệp **đã có** mà kế hoạch M14 sẽ sửa: `.env.example`, `RunPreflight`,
`BuildHandoverPackage`, `CollectHandoverEntries`, `RecordMatterDestruction`,
`RequestHandoverPackage`, `HandoverPackageFailed`, `SystemHealthWidget`,
`DocumentDownloadController`, `GenerateHandoverPackage`, `RcloneProcess`, `HandoverEntry`,
`bootstrap/app.php`, `bootstrap/providers.php`, `composer.json`, `composer.lock`,
`config/backup.php`, `config/filesystems.php`, `config/queue.php`, `config/vkcrm.php`,
`docs/SAO-LUU-KHOI-PHUC.md`, `lang/vi/activity.php`, `lang/vi/enums.php`, `lang/vi/handover.php`,
`lang/vi/preflight.php`, `routes/console.php`, `QueueHandoverScheduleTest`, `tests/Pest.php`,
`tools/backup/restore-drill.sh`.

| Nhánh | Merge-base, so với `main` | Tệp chung với M14 |
|---|---|---|
| `m9-final` | `35ec313`, +9/−5 | `RunPreflight.php`, `lang/vi/activity.php`, `lang/vi/handover.php`, `lang/vi/preflight.php`, `routes/console.php` |
| `m9-final-portal` | `35ec313`, +2/−5 | `lang/vi/handover.php` |
| `m10-intake` | `75f1d40`, +20/−1 | `.env.example`, `config/vkcrm.php`, `lang/vi/activity.php`, `lang/vi/enums.php`, `routes/console.php` |
| `m10-t6` | `c4949cf`, +11/−87 | `lang/vi/activity.php`, `lang/vi/enums.php` |
| `m10-t7` | `c4949cf`, +12/−87 | `.env.example`, `config/vkcrm.php`, `lang/vi/activity.php`, `lang/vi/enums.php`, `routes/console.php` |
| `m11-mcp-server` | `47ee8e3`, +11/−30 | `.env.example`, `bootstrap/app.php`, `composer.json`, `composer.lock`, `config/vkcrm.php`, `lang/vi/activity.php`, `lang/vi/enums.php`, `routes/console.php` |
| `m12-pwa-push` | `47ee8e3`, +11/−30 | `.env.example`, `RunPreflight.php`, `DocumentDownloadController.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `composer.json`, `composer.lock`, `config/vkcrm.php`, `lang/vi/activity.php`, `lang/vi/preflight.php`, `routes/console.php` |
| `m13-team-performance` | `35ec313`, +10/−5 | cùng năm tệp như `m9-final` (nhánh cắt từ `m9-final` cộng commit kế hoạch) |

- `m12-pwa-push` còn đổi `app/Models/Document.php` (`downloadUrlFor()` ký trên bí danh
  `documents.download.portal` / `documents.download.admin`), `routes/web.php` và
  `resources/views/errors/403|404.blade.php`.
  - Test M14 dựng URL tải bằng `Document::downloadUrlFor()`, không viết cứng tên route.
  - Trang 503 mới của M14 theo khuôn `errors/404.blade.php`, nên lúc gộp với m12 phải đối chiếu.
- Mọi tệp trong danh sách "chỉ nối thêm" của Ràng buộc toàn cục: chỉ nối ở cuối khối.

## 11. Ghi chú cho các task sau (từ vendor, không đổi kế hoạch)

- **Task 1/2: `throw` của đĩa `documents_remote`.** `FilesystemAdapter::checksum()` trả `false`
  (mục 7) khi đĩa có `throw = false`, như đĩa `private` hôm nay. Bước kiểm md5 của R2 phải coi
  `false` là **lệch**, hoặc đĩa kho đặt `throw = true`. Không thì `false !== $md5` chỉ đúng vì
  may. Có test cho đường này.
- **Task 2: `checksum()` của adapter.** Với `md5`, adapter trả `md5Checksum` của Google. Với thuật
  toán khác, nó **không** được ném `ChecksumAlgoIsNotSupported`, vì Flysystem sẽ tải cả tệp từ
  Drive để tự tính (mục 7). Nó ném `UnableToProvideChecksum` thay vào đó. sha256 của R4 luôn tính
  từ vùng đệm.
- **Task 2: đường dẫn tới adapter đã chuẩn hoá** (không `/` cuối, không `/` đầu). Test chuỗi gọi
  của `DefaultFileRemover` đi qua `Storage::disk('documents_remote')`.
- **Task 2: `exists()` = `fileExists || directoryExists`.** Cả hai trả lời từ chỉ mục. Test đếm lời
  gọi HTTP của R3 (b) khẳng định 0 request cho nhánh 404.
- **Task 4: `readStream()` với `throw = false` trả `null`** khi gặp `UnableToReadFile`.
  `OpenStoredFile` không được coi `null` là luồng.
- **Task 3: listener xoá vùng đệm** chạy sau commit, còn `MediaObserver::deleted()` thì không chờ
  (mục 7). Kế hoạch đã ghi điều này; vendor xác nhận.

## 12. Việc cho controller và chủ văn phòng

1. **R1 đứng vững.** Task 1 `composer require google/auth:^1.55`; lock diff chỉ gồm ba gói và
   `content-hash` (mục 2.3).
2. **Giới hạn Shared Drive: 500.000 mục, kể cả thùng rác.** Kế hoạch ghi 400.000 (R4, R9, Task 5
   `drive_item_count`, "Những chỗ … sẽ cắn"). Đề xuất: giữ ngưỡng VÀNG 300.000 và ĐỎ ở 400.000
   như trần nội bộ có chủ đích, chừa 100.000 mục cho thùng rác 30 ngày và tệp mồ côi. Sửa câu chữ
   trong tài liệu (Task 8) cho đúng con số của Google. Controller quyết.
3. **`checksum()` trả `false`** khi `throw = false` (mục 11). Đề xuất Task 1 đặt `throw = true` cho
   đĩa `documents_remote`, hoặc Task 3 coi `false` là lệch, có test.
4. **`MEDIA_PREFIX`** phải rỗng để khuôn R4 đúng (mục 8). Đề xuất ghim ở Task 1 hoặc Task 5.
5. **`league/commonmark` 2.10.1**: hai cảnh báo có sẵn trên `main` (mục 3). Không thuộc M14 (C5).
6. **Dry-run trên m11/m12 là dry-run trên cây trước M7**: thiếu `barryvdh/laravel-dompdf` (mục 2.4).
   Xung đột thật xử lý ở cổng merge.
7. **PENDING OWNER: (a), (b), (d), (e), (f)** (mục 9), cùng Phụ lục A bước 0–9 cho Shared Drive
   **thử**. Không mục nào chặn Task 1–7; chúng dùng đĩa giả và `Http::fake()`.
