# VK-CRM M14 — Kế hoạch dùng Google Drive (Workspace) làm kho tài liệu phía sau CRM

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Viết ngày 2026-10-04**, đối chiếu với `main` @ `75f1d40`. Chủ văn phòng chốt ngày 2026-10-04 (quyết định cuối):
> - tệp hồ sơ nằm trên một **Shared Drive của Google Workspace do văn phòng sở hữu**, chỉ truy cập **qua CRM** bằng một **tài khoản dịch vụ** (service account);
> - không ai chia sẻ tệp Drive trực tiếp; mọi bảo đảm đang có phải còn nguyên;
> - "sao Drive sang Drive là vô ích; **máy chủ văn phòng là bản thứ hai**".
>
> Các sự kiện về gói ghi dưới đây đã đo ngày 2026-10-04 bằng `composer require --dry-run` trong container `webdevops/php:8.3-alpine` (PHP 8.3.33, Composer 2.10.3) chạy trên **bản sao** `composer.json`/`composer.lock` của `main`, không đụng vào repo. Các sự kiện về Google ghi kèm nguồn; mục nào chưa kiểm được trên Workspace thật thì Task 0 kiểm.

> **Sửa vòng 1 (2026-10-04), sau rà soát kế hoạch.** Mọi Critical và Important đã xử lý; phần lớn Minor đã gộp vào.
> - **Quay lui không còn xoá nhầm, và không còn tự đảo ngược.** Dọn vùng đệm đòi `media.disk = documents_remote`, kiểm lại từng dòng dưới khoá đẩy. Quay lui chỉ chạy khi công tắc **đã** là `local`, và xoá các mốc của dòng. Phụ lục C đổi thứ tự (R10, R11, Task 3, Task 6).
> - **Bản thứ hai là máy chủ văn phòng, đúng phán quyết.** Bỏ bản sao Drive → Drive ("giai đoạn A"). Vùng đệm chỉ được dọn khi văn phòng đã gửi **biên nhận từng tệp**, khớp md5 và ràng vào đúng Shared Drive. Bản ở văn phòng mã hoá bằng `rclone crypt`. Bảng mối đe doạ viết lại (R10, Task 7, Phụ lục D).
> - **Bật kho là một việc có mốc** (`vkcrm:storage:enable`, `storage.remote_enabled_at`). Tác vụ quét chỉ đẩy tệp tạo sau mốc; tệp cũ chỉ đi qua `migrate` (R2, R11).
> - **`StorageReadiness` chạy ở mọi môi trường** và là cổng của các lệnh. Quay lui chỉ cần công tắc `local`, cộng Drive cho tệp không còn bản cục bộ (R7, Task 5, Task 6).
> - **Các thay đổi khác:**
>   - test "không gọi Drive trước khi kiểm quyền" nay đỏ được (R3, Task 4);
>   - thư mục là **tiền tố** (R4);
>   - tên Drive có số thế hệ (R4);
>   - chỗ trống và thời gian của gói bàn giao (R12);
>   - pháp lý mặc định là **chặn** (R13);
>   - chỗ đặt khoá trên shared hosting (R6);
>   - huỷ tệp khi hết hạn lưu (R15, mới);
>   - `reindex` cho khôi phục thảm hoạ (R11);
>   - ngắt mạch tách phạm vi (R9);
>   - vai tài khoản dịch vụ phải đúng `fileOrganizer` (R5).

**Trả lời câu hỏi của chủ văn phòng ("lấy Drive Workspace làm kho có chuẩn và xịn để dùng trong doanh nghiệp không?").** Có, với điều kiện làm **đúng cách** dưới đây, và đó chính là thứ kế hoạch này dựng:
- Shared Drive thuộc **tổ chức**, không thuộc cá nhân nào; chỉ tài khoản dịch vụ của CRM là thành viên làm việc; nhân sự **không** mở tệp trên Drive.
- CRM vẫn là cổng duy nhất: phân quyền theo vụ, vụ hạn chế, nhóm D, đường tải ký, nhật ký tải giữ nguyên.
- **Bản thứ hai nằm ngoài Google, ở máy chủ văn phòng** (R10), kéo về và mã hoá.
  - Tới khi có máy văn phòng, máy chủ web vẫn giữ mọi tệp như hôm nay.
  - Thùng rác và lịch sử phiên bản của Drive **không phải** sao lưu.
- Gói Workspace phải là **Business Standard trở lên**: Business Starter có Shared Drive nhưng thiếu các công tắc giới hạn truy cập mà R5 dựa vào (Task 0 kiểm lại).
- Nhược điểm phải chấp nhận:
  - dữ liệu nằm ở nước ngoài (Luật 91/2025/QH15 Điều 20, R13);
  - Drive không phải kho đối tượng (giới hạn 400.000 mục mỗi Shared Drive, độ trễ API);
  - phụ thuộc một nhà cung cấp;
  - tệp trên Drive ở dạng **Google đọc được**: Google mã hoá khi lưu nhưng giữ khoá. Hôm nay bản duy nhất ngoài máy chủ là archive AES-256. Cần chủ văn phòng ký nhận (câu hỏi 8).
- Phương án thay thế mà chủ văn phòng **không** chọn: kho đối tượng chuẩn S3 đặt tại Việt Nam, không vướng chuyển dữ liệu ra nước ngoài. Thiết kế giữ tên đĩa theo **vai trò** (`documents_remote`), nên đổi nhà cung cấp về sau là một lần chuyển dữ liệu, không phải viết lại.

**Goal:**
- Tệp hồ sơ (giấy tờ khách nộp, văn bản phát hành, văn bản cơ quan nhà nước, hồ sơ nội bộ, gói bàn giao) **sống trên Shared Drive**. Máy chủ chỉ giữ vùng đệm ngắn hạn, và chỉ khi đã có bản ở máy chủ văn phòng.
- Tải lên **không bao giờ** hỏng vì Drive chậm hay sập; tải xuống khi Drive sập hiện **trang tiếng Việt**, không 500.
- Tệp cũ chuyển lên kho bằng một lệnh **có kiểm tra checksum, chạy lại được, chạy thử được, và quay lui được**. Quay lui không tự đảo ngược và không làm mất bản nào.
- Không lúc nào một tệp chỉ còn **một** bản. Bản trên máy chủ web chỉ bị dọn khi tệp đã có một bản **ngoài Google**.
- Chủ văn phòng có hướng dẫn từng bước cho phần agent không làm được, cho máy văn phòng, cho việc huỷ tệp hết hạn lưu, và dàn ý hồ sơ chuyển dữ liệu ra nước ngoài.

**Vì sao nói bằng rủi ro:** một ổ đĩa VPS hỏng hôm nay là mất mọi tệp kể từ bản sao lưu đêm qua; một kho thuộc tổ chức, có bản thứ hai ngoài nhà cung cấp, đỡ được điều đó. Nhưng chuyển tệp ra ngoài máy chủ mở ra ba lối rò mới mà hôm nay không có: **đường link Drive**, **chia sẻ lệch trên Drive**, và **khoá tài khoản dịch vụ**. Mỗi phán quyết dưới đây đóng một lối.

**Architecture:** Không panel mới, không route mới cho người dùng. M14 thêm:
1. **Một adapter Flysystem v3 của dự án** trên Drive REST v3 (`app/Support/Storage/GoogleDrive/`), đăng ký thành đĩa `documents_remote` (R1, R4).
2. **Ghi qua vùng đệm** (R2):
   - ba Action ghi tệp vẫn ghi xuống đĩa `private` như hôm nay;
   - sau commit, một job trên hàng đợi `storage` đẩy tệp lên kho, kiểm checksum, rồi đổi `media.disk`;
   - chỉ khi kho đã được **bật** bằng `vkcrm:storage:enable`.
3. **Đọc qua CRM** (R3, R12):
   - route tải ký hiện có mở luồng từ kho **sau** khi kiểm quyền và **trước** khi ghi nhật ký;
   - gói bàn giao tải tệp về thư mục làm việc trước khi nén.
4. **`StorageReadiness`** chạy ở mọi môi trường, cùng preflight, kiểm tra sức khoẻ hằng giờ và trang "Kho tài liệu" cho admin (R5, R7, R13).
5. **Lệnh** bật kho, chuyển tệp cũ, kiểm, quay lui, dựng lại chỉ mục, liệt kê tệp cần huỷ (R11, R15).
6. **Bản thứ hai ở máy chủ văn phòng** (R10):
   - script kéo về bằng `rclone`, với tài khoản chỉ đọc, đích mã hoá `crypt`, rồi gửi **biên nhận từng tệp**;
   - CRM nhập biên nhận và chỉ dọn vùng đệm của tệp có biên nhận khớp md5.

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint. Mọi lệnh qua công cụ làn (Task 0). **Một gói mới duy nhất được phép:** `google/auth` `^1.55` (R1). Không `google/apiclient`, không `masbug/flysystem-google-drive-ext`.

**Spec:** `docs/SPEC.md`:
- §2 (shared hosting, một dòng cron, danh sách extension), §3;
- §4.11 ("Tệp gắn qua medialibrary, collection `file`, disk `private`"), §4.12 (`document_downloads`), §4.19 (huỷ hồ sơ);
- §6.5, §6.6 (bước 2-6: kiểm tệp, quét virus, lưu), §6.12 (gói bàn giao);
- §10 mục 4 (tệp ở `storage/app/private`, route ký 5 phút, vẫn kiểm policy), mục 6 (nhật ký), mục 8 (sao lưu), mục 10 (404);
- §11 "Tải tệp", "Tài liệu nội bộ", "Bàn giao và lưu trữ"; §13; §14 mục 2, 5, 7.

---

## Hiện trạng trên `main`, đã đọc

| Thứ | Ở đâu | M14 đổi gì |
|---|---|---|
| Đĩa tệp hồ sơ | `config/filesystems.php:79` đĩa `private` = `storage/app/private`, không `serve`, tách gốc khỏi `local` | Thành **vùng đệm**. Thêm đĩa `documents_remote` |
| Collection tệp | `app/Models/Document.php:221-226` `addMediaCollection('file')->useDisk('private')->singleFile()` | Không đổi: tệp mới luôn vào vùng đệm |
| Kiểm tệp + quét virus | `StoresDocumentFile::guardFile()` (`:58`), gọi **trước** transaction ở `UploadStaffDocument:173` và `SubmitClientDocument:216` | Không đổi. Tệp chỉ tới kho sau khi đã qua cổng này |
| Ghi tệp | `StoresDocumentFile::storeFile()` (`:105`) gọi `addMedia()` **bên trong** transaction (`UploadStaffDocument:176/308`, `SubmitClientDocument:219/333`); tên trên đĩa là ULID viết thường cộng đuôi ≤ 8 ký tự (`storedFileName()`, `:127-133`) | Không đổi (R2). Đẩy lên kho xảy ra sau commit |
| Gói bàn giao | `BuildHandoverPackage::store()` gọi `addMedia($zipPath)` trong transaction khoá `matters` (`:284`, `:351`). `CollectHandoverEntries:114-129` lấy `$disk->path($relative)` làm `sourcePath`. `buildZip()` gọi `ZipArchive::addFile($entry->sourcePath)` (`:247`). Medialibrary **chép** zip vào `private/<id>/` rồi mới xoá nguồn (docblock `:78-89`) | `path()` không có nghĩa với đĩa từ xa: phải tải tệp về thư mục làm việc trước khi nén. Đỉnh dung lượng đổi (R12) |
| Thời gian của job gói | `GenerateHandoverPackage::TIMEOUT_SECONDS = 600` (`:49`, docblock `:24-31`: "đủ cho gói vài trăm MB"); `config/queue.php:107-114` kết nối `handover`, `retry_after` 900 ở `:112`; `routes/console.php:269-273` mục `queue.handover` (`--timeout=600`, `withoutOverlapping(15)`, docblock từ `:255`); `RequestHandoverPackage::STALE_AFTER_MINUTES = 60` (`:71`); `QueueHandoverScheduleTest:31-36`, `:54-63` ghim các số | Thêm thời gian tải từ Drive: tính lại cả bốn số (R12) |
| Đường tải | `DocumentDownloadController:132-166`: `getFirstMedia` → `Storage::disk($media->disk)` → `exists()` → ghi `document_downloads` + audit → `$disk->download()` (`fileResponse()`, `:408-419`) | `download()` của Laravel mở luồng **bên trong callback**, sau khi header 200 đã gửi và sau khi nhật ký đã ghi (`FilesystemAdapter::response()`, vendor `:345-369`). Với Drive, lỗi lúc đó thành một tệp hỏng **kèm** một dòng nhật ký nói là đã tải. R3 đảo thứ tự |
| Tên tải tiếng Việt | Docblock `fileResponse()` (`:396-407`): `HeaderUtils::makeDisposition()` ném lỗi với mọi tên có dấu nếu thiếu bản dự phòng ASCII; `download()` tự tính bản đó (`Str::ascii()` rồi bỏ `%`) | Controller tự dựng `StreamedResponse` thì phải chép đúng việc đó (R3) |
| Xoá tệp | Thư viện media xoá qua `DefaultFileRemover`, **nuốt** ngoại lệ bằng `report()` (vendor `DefaultFileRemover.php:31-53`). Ở **mọi** lượt xoá media, nó gọi `allFiles('<id>/')` rồi `deleteDirectory('<id>/')`, cả `<id>/conversions/` và `<id>/responsive-images/`; chạy ngay ở sự kiện `deleted`, **không** chờ commit. `BuildHandoverPackage::discardStoredFile()` (`:446-460`) gọi `deleteDirectory(dirname(...))` | Xoá trên kho = cho vào thùng rác (R8). Thư mục là **tiền tố** có `/` (R4). Lỗi để lại tệp mồ côi, có lệnh đối soát |
| Sao lưu | `config/backup.php:60-62` gói `storage/app/private`. `PushBackupArchiveToRclone` đẩy archive **mã hoá AES-256** lên Google Drive bằng `rclone`, remote `gdrive` = OAuth của `sao-luu@` phạm vi `drive` (`docs/SAO-LUU-KHOI-PHUC.md` Bước 3, `:131-166`, phạm vi ở `:149`). `GuardBackupEncryption` chặn archive không mã hoá ở production. `CheckRcloneRemoteFreshness` kiểm 36 giờ | CSDL sao lưu như cũ. Tệp có bản thứ hai ở máy văn phòng, mã hoá `crypt` (R10). Bảo đảm "mọi bản ngoài máy chủ đều mã hoá" bị đổi với tệp trên Kho: nói thẳng (R10) |
| Huỷ hồ sơ | `RecordMatterDestruction` docblock `:20-21`: "huỷ vật lý hồ sơ giấy và tệp là thao tác có biên bản, làm NGOÀI hệ thống"; SPEC §4.19 | Kho chỉ cho vào thùng rác, bản văn phòng không xoá: cần đường huỷ (R15) |
| Tiền lệ về gói Drive | `docs/research/2026-09-26-sao-luu.md:20-66` và `:125-140`: làn M8a **lật lại** quyết định dùng `masbug`, vì nó đòi hạ Guzzle toàn cục | R1 giữ đúng lý lẽ đó |
| Bằng chứng tiền M9 | `Document::booted()` `deleting` ném `DocumentReferencedByBillingRecord` (`:208`); `BuildHandoverPackage::keepsFileOf()` | Không đổi. Kho không bao giờ bỏ tệp của tài liệu bị chứng từ tiền tham chiếu |
| Test | `tests/Pest.php:46-53` `Storage::fake('private')` cho mọi test | Thêm `Storage::fake('documents_remote')` cùng chỗ. Đĩa giả **không bao giờ gửi HTTP**: test về thứ tự gọi Drive cần đĩa gián điệp hoặc adapter thật (R3) |
| Luật enum | `tests/Feature/ArchitectureTest.php:144-167` "mọi enum backed string có nhãn" chỉ quét `app/Enums/*.php` | Mọi enum mới của M14 nằm ở `app/Enums/` |
| Hàng đợi riêng | `config/queue.php:107-114` kết nối `handover` (database, `retry_after` 900 ở `:112`); `routes/console.php:269-273` mục `queue.handover` | Khuôn cho kết nối `storage` (R2) |
| Preflight | `RunPreflight::handle()` (`:54-68`) chỉ gọi `launchConditionRows()` (`:76-95`) khi `APP_ENV=production`; `storagePrivateExposureRow()` (`:321`) | Dòng kho production nối vào đó, **nhưng** cổng của lệnh và nghiệm thu ở làn đi qua `StorageReadiness`, chạy ở mọi môi trường (R7) |
| Khoá chồng lấn | `config/cache.php:18` store mặc định `database` (`CACHE_STORE=database` trong `.env.example`) | Khoá đẩy tệp nằm ở đó (R2) |
| MCP (M11, chưa vào `main`) | Kế hoạch M11 R4 dòng 134: "Nội dung tệp, đường tải — chỉ metadata, không trả signed URL". Làn `lane-m11` thêm `laravel/passport ^13.8`, `laravel/mcp ^1.0` vào `composer.json`, và đã khoá `firebase/php-jwt` v7.2.1 | Thêm: không mã tệp Drive, không đường Drive nào qua MCP (R14). Cùng `firebase/php-jwt` v7.2.1 với `google/auth`: tương thích (Ràng buộc toàn cục) |

---

## Ràng buộc toàn cục

- **Nhánh:** `m14-drive-storage`, cắt từ `main` hiện hành, worktree `D:\vkwt\lane-m14`. Làn khác đang chạy (M9 phần cuối, M10, M11, M12) chưa vào `main`.
  - Các tệp dùng chung với làn khác **chỉ nối thêm** ở cuối khối, không sắp lại dòng có sẵn: `routes/console.php`, `config/queue.php`, `config/vkcrm.php`, `RunPreflight::launchConditionRows()`, `lang/vi/preflight.php`, `lang/vi/activity.php`, `tests/Pest.php`, `.env.example`, `bootstrap/providers.php`, `bootstrap/app.php`, **`composer.json`, `composer.lock`**.
  - `composer.json`/`composer.lock`: làn M11 cũng đổi hai tệp này (`laravel/passport ^13.8`, `laravel/mcp ^1.0`). `firebase/php-jwt` v7.2.1 đã khoá ở đó và cũng là bản `google/auth` kéo theo: tương thích. Khi gộp:
    - `composer.json` gộp tay (nối dòng `require`);
    - `composer.lock` lấy bản của `main`, rồi chạy lại trong làn: chỉ lệch hash thì `composer update --lock`; thiếu `google/auth` thì `composer require google/auth:^1.55`;
    - dán diff lock và `composer audit --locked`.
  - Trước cổng merge, gộp `main` mới nhất vào nhánh và chạy lại toàn bộ test. Làn nào vào `main` sau thì làn đó gỡ xung đột.
- **Công cụ làn:** `/d/vkwt/m14-dev`, dựng theo đúng khuôn `/d/vkwt/m11-dev` (Task 0). Làn này **cài gói**, nên có `vendor` riêng trong worktree. **Không bao giờ** chạy composer vào `D:\crmkhachhang`: các làn khác mount `vendor` đó.
- **Test chỉ chạy qua công cụ làn**, với danh sách tệp tường minh (`vk-container-test`): Docker 9p trên Windows bỏ rơi tệp khi PHPUnit tự duyệt thư mục lớn (`bin/container-test:6-11`). Mỗi lúc một tiến trình test của làn; `test:mariadb` luôn tuần tự.
- PHP sàn **8.3**, cứng. Không gói nào đòi PHP 8.4. Không Redis, Horizon, Octane, Reverb, Pulse, Scout. Không worker thường trực: hàng đợi `storage` rút bằng cron như `handover`. **Một** dòng cron.
- Nghiệp vụ chỉ ở `app/Actions/`. Controller, listener, job, lệnh Artisan và trang Filament chỉ gọi Action. Adapter và client Drive là hạ tầng, nằm ở `app/Support/Storage/`, như `app/Support/Files/` và `app/Support/Backup/`. Enum mới nằm ở `app/Enums/` (luật nhãn chỉ quét ở đó).
- Định danh code tiếng Anh. Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/storage.php`, gồm trang 503, thư cảnh báo, dòng preflight, đầu ra lệnh Artisan dành cho người vận hành.
- Enum backed string có `label()` cho mọi cột trạng thái mới.
- **TDD với Pest.** Test đỏ trước. **Mutation probe cho mọi điều kiện mới**: xoá điều kiện, chạy lại, dán bằng chứng ĐỎ vào báo cáo, khôi phục. Một test âm không có cặp dương thì không tính.
- Test màn hình đi qua Livewire hoặc HTTP, không gọi thẳng Action.
- Trang Filament tự viết: `canAccess()` hỏi `Gate::forUser($account)`, `abort(404)` ở `mount()` **và** ở mọi action Livewire (đường `update`). Từ chối là 404, không 403.
- Không class Tailwind viết tay; chỉ style nội tuyến trên biến CSS của Filament. Trang lỗi 503 theo đúng khuôn `resources/views/errors/404.blade.php`.
- Vụ `restricted` không bao giờ lộ: M14 không thêm màn hình nào **liệt kê** tài liệu.
  - Trang, thư, biên nhận và lệnh của M14 chỉ hiện số đếm, mã `media`/`document` và tên Drive mờ. Không tiêu đề, không mã hồ sơ, không tên khách.
  - `Matter::scopeListableBy` vẫn là định nghĩa duy nhất; nếu sau này cần một danh sách, nó phải đi qua đó.
- **Không I/O mạng tới kho bên trong `DB::transaction`** (R2). Có test cấu trúc, cùng khuôn với test "không Mail:: trong transaction" ở `tests/Feature/ArchitectureTest.php:269`. Dọn vùng đệm và quay lui làm từng dòng **dưới khoá đẩy**, không trong transaction dài.
- `maxLength` của form bằng độ dài cột. MariaDB strict. Task có migration chạy vòng **MariaDB thật** (`migrate:fresh --seed`, rồi `migrate:reset` → `migrate`) và dán output.
- Đọc lại từng docblock vừa viết, đối chiếu với mã, trước khi commit.
- `git commit -- <path>`, không `add -A`, không commit trần. Message dạng `feat: M14 Task N — …`, kết thúc bằng `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, chép nguyên văn.
- Người rà soát mỗi task và rà soát cuối được brief **giả định có một Critical**. Riêng M14 thêm một câu: *giả định có một đường để tệp hồ sơ, hoặc đường dẫn tới nó, ra khỏi CRM mà không qua route tải ký; hoặc một lúc nào đó một tệp chỉ còn một bản, hoặc chỉ còn các bản nằm trong Google.*
- **Không tệp thật nào của khách lên Drive trong lúc làm milestone này.** Nghiệm thu thật (Task 8) dùng một Shared Drive **thử** riêng và dữ liệu seed.

---

## Phán quyết của chủ nhiệm

Các phán quyết ghi **"Phán quyết 2026-10-04"** là của controller ngày đó; chủ văn phòng đảo được, đảo thì sửa đúng task nêu tên. Phán quyết ghi "của chủ văn phòng" thì chỉ chủ văn phòng đảo. Task 8 chép tất cả vào PROGRESS.

**R1 — Gói: không `masbug/flysystem-google-drive-ext`. Adapter của dự án trên Drive REST v3, cộng `google/auth` để lấy token.** *(Phán quyết 2026-10-04.)*

Đã đo ngày 2026-10-04 trên bản sao `composer.json`/`composer.lock` của `main`:

| Gói | Kết quả | Hệ quả |
|---|---|---|
| `masbug/flysystem-google-drive-ext` (bản mới nhất `v2.5.0`, 2026-04-13, Apache-2.0) | **Thất bại.** Khai `guzzlehttp/guzzle ^6.3\|^7.0` và `guzzlehttp/psr7 ^1.7\|^2.0`; `main` khoá Guzzle `8.2.0`, psr7 `3.1.0`. Nhánh `2.x-dev` khai cùng ràng buộc | Với `-W`: **hạ** Guzzle 8.2.0→7.15.5, promises 3.0.2→2.5.3, psr7 3.1.0→2.13.1, gỡ `symfony/polyfill-php82`, thêm 7 gói. Đúng cái giá mà M8a đã từ chối (`docs/research/2026-09-26-sao-luu.md:125-140`) |
| `google/apiclient` `v2.20.1` | Giải sạch, **5 gói mới**, không hạ gì (`firebase/php-jwt` v7.2.1, `google/apiclient-services` v0.461.0, `google/auth` v1.55.1, `psr/cache` 3.0.0) | Kéo theo `apiclient-services`, cây lớn hàng nghìn lớp cho vài trăm dịch vụ, phải có móc dọn trong `composer.json`. Thừa cho sáu endpoint |
| **`google/auth` `v1.55.1`** | Giải sạch, **3 gói mới**: `google/auth` (Apache-2.0), `firebase/php-jwt` v7.2.1 (BSD-3-Clause), `psr/cache` 3.0.0 (MIT). Không hạ, không gỡ gói nào | Khai `php ^8.1`, `guzzlehttp/guzzle ^7.8.2\|\|^8.0`, `guzzlehttp/psr7 ^2.6.3\|\|^3.0`. Extension cần: `openssl` (đã có trong danh sách bắt buộc). Làn M11 đã khoá đúng `firebase/php-jwt` v7.2.1 |

Lý do chọn:
- `masbug` hỏng ở phụ thuộc. Mô hình của nó cũng sai cho việc này: nó dịch đường dẫn "ảo" thành đường dẫn hiển thị bằng cách **tìm theo tên**, trong khi Drive cho phép nhiều tệp trùng tên trong một thư mục. Ta cần khoá mờ có chỉ mục (R4), không cần đường dẫn đọc được.
- Sáu endpoint (`drives.get`, `permissions.list`, `files.create` resumable, `files.get`/`alt=media`, `files.update` để cho vào thùng rác, `files.list`) viết trên `Http` của Laravel thì test được bằng `Http::fake()`, như mọi lệnh gọi ra ngoài khác trong dự án.
- **Token tài khoản dịch vụ** (JWT RS256 đổi lấy access token) là phần dễ sai nhất và dễ lộ bí mật nhất, nên giao cho thư viện chính chủ của Google. HTTP của `google/auth` được chuyển qua `Http` của Laravel (tham số `httpHandler`), để `Http::fake()` phủ cả nó.

Giá nếu sai và đường lùi, theo thứ tự:
1. Nếu Task 0 thấy `google/auth` không còn giải sạch (trên `main` **hoặc** trên `lane-m11`), hoặc chủ văn phòng không cho thêm gói: tự ký JWT RS256 bằng `openssl_sign` (khoảng 60 dòng, extension đã bắt buộc), test với khoá RSA sinh lúc chạy. **Không gói mới.**
2. Nếu cả API cũng bị chặn (ví dụ hosting chặn gọi ra ngoài trừ vài đích): `rclone` gọi qua `Process`, như sao lưu M8a. Đây chỉ là đường lùi: một tiến trình con cho mỗi lượt tải trong request web thì giòn và khó test.

**R2 — Ghi qua vùng đệm cục bộ; đẩy lên kho sau commit, và chỉ tự đẩy tệp tạo sau khi bật kho. Không I/O mạng tới kho trong transaction.** *(Phán quyết 2026-10-04; sửa vòng 1.)*
- Ba Action ghi tệp (`UploadStaffDocument`, `SubmitClientDocument`, `BuildHandoverPackage`) **không đổi**. Chúng vẫn ghi xuống đĩa `private` trong transaction, sau `guardFile()` (kiểm tệp + quét virus).
  - Viết lại chúng để ghi thẳng lên Drive nghĩa là giữ khoá hàng `matters` và `matter_checklist_items` trong khi chờ mạng, với gói bàn giao tới 2 GB.
  - Nó cũng làm khách nộp CCCD lúc 11 giờ đêm nhận lỗi chỉ vì Google chậm.
- **Bật kho là một việc có mốc, không chỉ là một biến môi trường.**
  - `DOCUMENT_STORAGE=google_drive` chỉ **cho phép**; `vkcrm:storage:enable` (Task 6) mới **bật**, bằng cách ghi `settings.storage.remote_enabled_at`.
  - `DocumentStore::pushesNewFiles()` = công tắc `google_drive` **và** có mốc.
  - Lý do: không có mốc thì tác vụ quét bên dưới đẩy cả kho tệp cũ trong giờ làm việc ngay khi đổi biến, với ân hạn 24 giờ thay cho 30 ngày. Lệnh chuyển ngoài giờ (`--limit`, `--max-minutes`, chạy thử đo hạn mức) và cửa sổ quay lui 30 ngày không cần tải về đều thành vô nghĩa. `document_push_backlog` và thư cảnh báo cũng sẽ nổ cho mọi tệp cũ.
- Một listener trên sự kiện `created` của `Media` dispatch job `PushDocumentFile` bằng `->afterCommit()`, khi `pushesNewFiles()` và `media.disk = private`.
  - Sự kiện bắn lúc tạo, nên listener tự nó chỉ thấy tệp tạo sau khi bật.
  - Transaction rollback thì job bị bỏ, như mọi job sau commit.
- Job chạy trên **kết nối riêng `storage`** (driver `database`, hàng `storage`, `retry_after` 2400), rút bằng mục lịch riêng `queue.storage` (`--timeout=1800`), như `handover` (M7 R9). Một gói 2 GB đang đẩy không được giữ lượt của thư nhắc mốc hạn.
- Job chỉ gọi Action `PushDocumentFileToRemote`. Bất biến của nó:
  1. **khoá** `DocumentStore::pushLock($mediaId)` = `Cache::lock('document-push:{mediaId}', 2100)` trên store `database` (bảng `cache_locks`; store mặc định của dự án, chung mọi tiến trình).
     - TTL 2100 = `$timeout` 1800 của job + 300. TTL ngắn hơn thì khoá hết hạn giữa chừng, hai lượt tải một gói 2 GB chạy song song và đụng `object_key` unique. Có test ghim TTL > `$timeout`.
     - Không lấy được khoá → job `release(120)`.
     - `PurgeStagedDocumentCopies` và `vkcrm:storage:rollback` giữ **cùng** khoá này cho từng media.
  2. tính md5 và sha256 của tệp trong vùng đệm;
  3. tải lên kho tại **đúng khoá đường dẫn** của thư viện media (`<media_id>/<file_name>`). Tên trên Drive theo R4, gồm số thế hệ. Khoá không khớp khuôn của R4 thì **không** đẩy: log `critical`, tệp nằm lại vùng đệm, được đếm vào tồn đọng;
  4. **kiểm**: md5 do Google tính (`checksum()` qua `ChecksumProvider`, R4) và kích thước phải khớp. Lệch thì:
     - cho bản trên kho vào thùng rác (dòng chỉ mục `retired_reason = trashed`) rồi ném lỗi để job thử lại;
     - lượt sau tải lên với **thế hệ** kế tiếp (R4), nên tên trên Drive khác tên bản hỏng;
  5. **đổi đĩa bằng một câu UPDATE có điều kiện**: `UPDATE media SET disk='documents_remote', conversions_disk='documents_remote', remote_pushed_at=…, local_purge_after=…, checksum_md5=…, checksum_sha256=… WHERE id=? AND disk='private'`. 0 dòng nghĩa là:
     - media đã bị xoá: khi đó cho bản trên kho vào thùng rác;
     - hoặc media đã được đẩy rồi: không làm gì;
  6. trên production, lượt `Pushed` **đầu tiên** ghi `settings.storage.first_transfer_at`, một lần, không ghi đè (R13).
- **Bản trong vùng đệm không bị xoá ngay.**
  - Nó được giữ tới `local_purge_after` (mặc định 24 giờ, `DOCUMENT_STAGING_GRACE_HOURS`), và còn phải qua luật bản thứ hai của R10.
  - Nhờ đó một lượt tải đã nạp `media.disk = private` ngay trước lúc đổi đĩa vẫn đọc được tệp.
- **Tác vụ quét** `PushPendingDocumentFiles` (15 phút một lần) xếp lại job cho media ở `private` có `created_at >= remote_enabled_at` và đã quá 10 phút. Nó bắt lượt dispatch bị mất và job đã hết lượt thử.
  - **Có cận dưới.** Tệp tạo trước mốc (tệp cũ, kể cả tệp đã quay lui) chỉ đi qua `vkcrm:storage:migrate`.
  - Công tắc không còn là `google_drive` mà mốc còn → xoá mốc (audit `document_store_disabled_observed`) và không xếp gì. Đổi về `local` là **tắt**: bật lại phải chạy `enable`, và tệp tạo trong lúc tắt thành tệp cũ.
- **Media bị xoá:** listener `DiscardStagedCopyOnMediaDeleted` xoá bản trong vùng đệm (`private/<media_id>/`, khi `media.disk` khác `private`).
  - Nó chạy **sau commit** (`DB::afterCommit`), và chỉ khi dòng `media` thật sự không còn (đọc lại). Một lượt xoá bị rollback trong transaction không được để dòng `media`, đã khôi phục, mất bản trong vùng đệm.
  - Thư viện media tự xoá trên đĩa ghi ở `media.disk`, và lượt đó **không** chờ commit (vendor). Xem "Những chỗ … sẽ cắn".

**R3 — Đọc luôn qua CRM, và thứ tự là: kiểm quyền → mở luồng → ghi nhật ký → stream.** *(Phán quyết 2026-10-04; sửa vòng 1 ở cách khoá bằng test.)*
- Không redirect tới Drive. Không `webViewLink`, `webContentLink`, `thumbnailLink` hay mã tệp Drive nào rời máy chủ. Adapter luôn gửi `fields=` tường minh, không bao giờ xin các trường link.
- **Không lệnh gọi Drive nào trước khi chữ ký, người nhận và policy đã đạt.**
  - Hôm nay `DocumentDownloadController` kiểm actor → bản ghi → người nhận → `DocumentPolicy::download` → media, rồi mới chạm đĩa. Giữ nguyên.
  - Lý do: một id đoán mò không được tốn một lệnh gọi Google (khuếch đại tấn công từ chối dịch vụ), và thời gian trả lời không được lộ chuyện tệp có tồn tại.
  - **Khoá bằng hai test, mỗi test có cặp dương:**
    - (a) **Đĩa gián điệp**: một decorator đếm `fileExists`, `readStream`, `fileSize`, `mimeType`, `checksum`, bọc đĩa giả và đặt làm `documents_remote` bằng `Storage::set()`. Mọi nhánh từ chối đếm 0; nhánh thành công đếm đúng một `readStream`.
    - (b) **Adapter thật** dựng trên `Http::fake()` + `Http::preventStrayRequests()`, với chỉ mục đã gieo và token giả. Mọi nhánh từ chối `Http::assertNothingSent()`; nhánh thành công gửi đúng một `GET …alt=media`.
  - `Storage::fake('documents_remote')` của `tests/Pest.php` không bao giờ gửi HTTP. `Http::assertNothingSent()` trên nó là test **không thể đỏ**, nên không được tính.
  - Mutation probe bắt buộc: chuyển lời gọi `OpenStoredFile` lên trước `Gate::allows` → cả (a) lẫn (b) đỏ.
- **Mở luồng trước khi ghi `document_downloads`.**
  - Action `OpenStoredFile` mở luồng đọc. Với Drive đó là `GET …?alt=media`, chưa đọc nội dung.
  - Mở hỏng thì không có dòng nhật ký, không audit `document_downloaded`, và người dùng thấy trang 503 tiếng Việt. Mở được thì mới ghi nhật ký, rồi stream.
  - Còn một khe: luồng đứt **giữa chừng**, sau khi nhật ký đã ghi. Chấp nhận có chủ đích, ghi trong docblock: "lượt tải" nghĩa là văn phòng đã bắt đầu giao tệp cho người đó.
- **Header lấy từ dòng `media`** (`mime_type`, `size`), không hỏi kho.
  - `FilesystemAdapter::download()` của Laravel hỏi `mimeType()`, `size()` rồi `readStream()`: ba lệnh gọi, và luồng mở trong callback.
  - Controller tự dựng `StreamedResponse`, giữ nguyên `Cache-Control: private, no-store, max-age=0` và `X-Content-Type-Options: nosniff`.
  - `Content-Disposition` phải chép **đúng** bản dự phòng ASCII mà `download()` dựng: `Str::ascii($name)` rồi bỏ `%`, truyền làm `$filenameFallback` của `HeaderUtils::makeDisposition()`. Thiếu nó thì mọi tên tiếng Việt ném `InvalidArgumentException` (docblock `fileResponse()`, `:396-407`).
  - Một helper dùng chung cho nhánh cục bộ, nhánh kho và `HEAD`, để không có hai bản của luật tên tải.
- **`HEAD` không mở luồng**, không ghi nhật ký (hôm nay `HEAD` cũng không ghi): trả header từ dòng `media`. Nó vẫn hỏi `exists()` (từ chỉ mục, không mạng) để tệp thiếu là 404 như hôm nay.
- `exists()` của đĩa kho trả lời từ **chỉ mục** (R4), không gọi mạng. Chỉ mục nói có mà Drive trả 404: đó là `StoredFileMissing`, trả 404 kèm log mức `critical` và một dòng sức khoẻ kho (R5), không phải 503.

**R4 — Bố cục trên Drive: khoá mờ, chỉ mục trong CSDL, không tên người, không tên vụ; thư mục là tiền tố; tên có số thế hệ.** *(Phán quyết 2026-10-04; sửa vòng 1.)*
- **Khoá đối tượng** là đường dẫn thư viện media sinh sẵn, `<media_id>/<file_name>`. `file_name` đã là ULID viết thường cộng đuôi (`StoresDocumentFile::storedFileName()`, `:127-133`); gói bàn giao cũng vậy (`BuildHandoverPackage:353`).
  - Lệnh đẩy (R2) chỉ nhận khoá khớp `^\d+/[0-9a-z]{26}(\.[0-9a-z]{1,8})?$`.
  - Adapter nhận mọi khoá an toàn: thăm dò của preflight dùng `preflight/<ngẫu nhiên>.txt`.
  - Task 0 đếm media hiện có lệch khuôn.
- **Tên tệp trên Drive** = khoá, với `/` thay bằng `~`. Thế hệ 1 không có hậu tố; thế hệ N ≥ 2 thêm `~g<N>` trước đuôi. Ví dụ `1834~01k6xq0f9m2y7c4w8r3t5v6n1b.pdf`, `1834~01k6xq0f9m2y7c4w8r3t5v6n1b~g2.pdf`.
  - Khoá chứa `~` bị từ chối.
  - Khuôn tên đọc ngược (`DriveObjectName::parse`): `^(\d+)~([0-9a-z]{26})(?:~g([2-9]|[1-9]\d{1,2}))?(\.[0-9a-z]{1,8})?$`.
  - Tên đảo ngược được, nên dựng lại chỉ mục chỉ cần liệt kê tên, kể cả từ bản ở văn phòng (R10, R11).
  - Không `description`, không `appProperties` mang dữ liệu.
  - **Vì sao có số thế hệ:** bản hỏng có thể đã nằm ở máy văn phòng (kéo về bằng `copy --immutable`). Tải lại **cùng tên** thì lượt kéo báo lỗi ở mọi lần chạy, tệp đó không bao giờ có biên nhận, và vùng đệm của nó không bao giờ được dọn.
- **Thư mục là tiền tố, có dấu `/`.** Thư mục `d` (bỏ `/` cuối) gồm đúng các khoá sống thoả `object_key LIKE '<d đã thoát \, %, _>/%' ESCAPE '\'`.
  - `deleteDirectory`, `listContents` (nông và sâu), `directoryExists`, và do đó `allFiles`, đều theo đúng luật này.
  - Lý do: `DefaultFileRemover` gọi `allFiles('18/')` rồi `deleteDirectory('18/')`, cả `18/conversions/` và `18/responsive-images/`, ở **mọi** lượt xoá media. So khớp ngây thơ kiểu `LIKE '18%'` sẽ cho tệp của media 180–189, 1800… vào thùng rác, và các lượt tải của chúng thành 404.
- **Thư mục trên Drive**: `<thư mục gốc của môi trường>/<YYYY-MM>/`, theo tháng lúc đẩy. Mỗi năm thêm khoảng 12 mục.
  - Một thư mục con cho mỗi media (kiểu đường dẫn thư viện media) sẽ nhân đôi số mục, trong khi một Shared Drive chỉ chứa **400.000 mục** (Google Workspace Admin Help, "Shared drive limits").
  - Một thư mục phẳng duy nhất thì sẽ chạm giới hạn 500.000 mục con mỗi thư mục (lỗi `numChildrenInNonRootLimitExceeded`) và liệt kê chậm khi sao chép.
- Vì sao **mờ**, không theo vụ: người có quyền quản trị Workspace (và tài khoản dự phòng ở R5) nhìn thấy cây thư mục trên giao diện Drive.
  - Tên vụ, mã hồ sơ hay tên khách trên cây đó là một bản lộ thông tin nằm **ngoài** mọi kiểm soát của CRM, và đổi tên vụ thì cây sai.
  - Quyền theo vụ đã sống ở CRM; Drive chỉ cần giữ byte.
- **Chỉ mục `drive_objects`** ánh xạ khoá → mã tệp Drive, kèm thế hệ, kích thước, md5, MIME và biên nhận văn phòng.
  - Đọc, kiểm tồn tại, kích thước, liệt kê đều trả lời từ chỉ mục, nên một lượt tải tốn đúng **một** lệnh gọi Google.
  - Có cột `drive_id` ngay từ đầu: thêm Shared Drive thứ hai khi gần 400.000 mục, hay chuyển sang Shared Drive mới khi khôi phục, không phải sửa dữ liệu cũ.
- Adapter cài `League\Flysystem\ChecksumProvider` (có trong `league/flysystem` 3.36).
  - `checksum($path, ['checksum_algo' => 'md5'])` hỏi **Google** (`md5Checksum`), không đọc chỉ mục, để lần kiểm ở R2 là kiểm thật.
  - Với đĩa giả trong test, Flysystem tự tính md5 bằng cách đọc tệp: cùng một lời gọi `Storage::disk(...)->checksum()` chạy được cả hai nơi.
  - `sha256Checksum` của Google có thể vắng ("if available"), nên sha256 là bản ghi **của ta**, không phải điều kiện kiểm.

**R5 — Chia sẻ: chỉ thành viên. Tài khoản dịch vụ mang đúng vai "Người quản lý nội dung". Mã không bao giờ tạo quyền chia sẻ.** *(Phán quyết 2026-10-04; sửa vòng 1 ở vai và danh sách thành viên.)*
- **Vai tài khoản dịch vụ: Content manager (`fileOrganizer`), và chỉ vai đó.**
  - Vai này thêm, sửa và cho tệp vào thùng rác được. Nó **không** xoá vĩnh viễn, **không** chuyển tệp ra khỏi Shared Drive, **không** quản lý thành viên: chỉ Manager làm được ba việc đó (Google Workspace Learning Center, "Shared drive roles").
  - Một khoá bị lộ vì thế không xoá vĩnh viễn được kho, không chuyển tệp đi, không tự thêm người.
  - Vai khác là **ĐỎ**:
    - `organizer` thừa quyền;
    - `writer` (Contributor) không cho vào thùng rác được. `DefaultFileRemover` nuốt lỗi đó, nên mỗi lần xoá media để lại một tệp mồ côi lặng lẽ;
    - `reader`/`commenter` không ghi được.
- **Thành viên được phép** = tài khoản dịch vụ + danh sách `GOOGLE_DRIVE_ALLOWED_MEMBERS` (dạng `email:vai`, kiểm cả vai):
  - một tài khoản quản trị **dự phòng** (`organizer`, bật 2FA, chỉ dùng khi khôi phục thảm hoạ hay huỷ tệp theo R15);
  - tài khoản **máy văn phòng** `van-phong-kho@…` ở vai `reader`, cho bản thứ hai (R10).
  - `sao-luu@` **không** là thành viên Kho. Bản trước thêm nó cho bản sao Drive → Drive, nay đã bỏ (R10). Token của nó nằm trên máy chủ web với phạm vi `drive`.
- **Không lệnh `permissions.create`/`update`/`delete` nào trong mã.** Có test cấu trúc. Chỉ có `permissions.list` (GET) để kiểm.
- **`InspectDriveSharing`**, dùng chung cho `StorageReadiness` (lúc triển khai, lúc chạy lệnh) và kiểm tra sức khoẻ (mỗi giờ, để bắt lệch):

  | Mức | Điều kiện |
  |---|---|
  | **ĐỎ** | `restrictions.driveMembersOnly != true`; có quyền kiểu `anyone` hoặc `domain`; có thành viên ngoài danh sách được phép; một thành viên trong danh sách mang vai khác vai đã khai; tài khoản dịch vụ mang vai **khác `fileOrganizer`**; thư mục gốc không thuộc Shared Drive này hoặc đang ở thùng rác |
  | **VÀNG** | `sharingFoldersRequiresOrganizerPermission != true`; `domainUsersOnly = false` (kèm lời giải thích ở dưới) |

- Tài khoản dịch vụ có tên miền `gserviceaccount.com`, tức là **người ngoài tổ chức**.
  - Theo trợ giúp quản trị Workspace, khi tắt "cho phép người ngoài tổ chức truy cập tệp trong Shared Drive" thì người quản lý **không thêm được** thành viên ngoài. Task 0 thử trên Workspace thật.
  - Nếu đúng như vậy thì bật công tắc đó **cho riêng Shared Drive này**. Hàng rào thật khi đó là `driveMembersOnly` cộng danh sách thành viên mà ta kiểm mỗi giờ. Vì thế `domainUsersOnly = false` chỉ là VÀNG.
- **Không domain-wide delegation.** Một tài khoản dịch vụ có DWD với phạm vi `drive` mạo danh được **mọi** nhân sự và đọc Drive của họ. Lộ khoá khi đó là lộ cả văn phòng.

**R6 — Khoá tài khoản dịch vụ: một tệp ngoài repo và ngoài gốc web, chỉ đường dẫn nằm trong `.env`. Có biến thể cho shared hosting.** *(Phán quyết 2026-10-04; sửa vòng 1.)*
- `GOOGLE_DRIVE_CREDENTIALS_PATH` trỏ tới một tệp **ngoài** `base_path()` và ngoài thư mục gốc web. Hai biến thể:
  - **VPS:** `/etc/vkcrm/google-drive-key.json`, chủ sở hữu `root`, nhóm của PHP-FPM, quyền `0440`;
  - **shared hosting** (SPEC §2 bắt chạy được trên đó): không có `root`, không đổi nhóm được, và nhóm thường gồm nhiều tài khoản. Đặt trong thư mục nhà của tài khoản, ngoài `base_path()` và ngoài `public_html`/`www`/`htdocs`, ví dụ `~/.config/vkcrm/google-drive-key.json`. Chủ là chính tài khoản, quyền `0400` (hoặc `0600`), thư mục cha `0700`.
- Nội dung khoá không bao giờ nằm trong `.env`: `.env` hay bị chép, gửi nhau và in ra khi gỡ lỗi. Cũng không nằm trong CSDL: bản sao lưu CSDL sẽ mang theo nó.
- Dòng `drive_credentials` của `StorageReadiness`:
  - **ĐỎ** khi:
    - tệp không tồn tại hoặc không đọc được;
    - tệp nằm dưới `base_path()`, dưới `public_path()`, hay dưới một thư mục tên `public_html`, `www` hoặc `htdocs` (một lần `git add` hay một lỗi cấu hình web là lộ);
    - người khác đọc hoặc ghi được (`fileperms() & 0o006`);
    - nhóm ghi được (`& 0o020`);
    - JSON không có `type = service_account`, `client_email`, `private_key`.
  - **VÀNG** khi **nhóm đọc được** (`& 0o040`) mà không chứng minh được nhóm là riêng:
    - thiếu extension `posix`;
    - hoặc nhóm của tệp khác nhóm hiệu lực của tiến trình PHP;
    - hoặc `posix_getgrgid()['members']` có người khác ngoài người dùng của tiến trình.
  - **XANH** khi `0400`/`0600` của chính người dùng chạy PHP, hoặc `0440`/`0640` với nhóm riêng.
  - Việc đọc chủ, nhóm và thành viên đi qua một lớp `CredentialFileInspector` thay được trong test, vì container test chạy bằng một người dùng cố định.
- **Access token** (sống 1 giờ) được cache 50 phút trong store `file` (`vkcrm.storage.google_drive.token_cache_store`), **không** trong store mặc định `database`. Như vậy bản sao lưu CSDL không bao giờ mang token còn sống. Test dùng store `array`.
- Log, ngoại lệ và thư báo không bao giờ chứa header `Authorization`, access token, `private_key` hay thân phản hồi của endpoint token. Có test.
- **Xoay khoá**: tạo khoá mới → đặt lên máy chủ → `vkcrm:storage:check` xanh → xoá khoá cũ trong Google Cloud. Làm 12 tháng một lần, và ngay khi một người có quyền vào máy chủ nghỉ việc. Hướng dẫn ở Phụ lục A.

**R7 — Công tắc `DOCUMENT_STORAGE`; kiểm sẵn sàng chạy ở mọi môi trường; máy dev và test dùng đĩa cục bộ; một test tích hợp bật bằng biến môi trường.** *(Phán quyết 2026-10-04; sửa vòng 1.)*
- `DOCUMENT_STORAGE=local` (mặc định) giữ đúng hành vi hôm nay: tệp nằm ở `private`, không job đẩy nào chạy. `google_drive` **cho phép** R2, còn `vkcrm:storage:enable` **bật** nó.
- Giá trị lạ (gõ sai) → `DocumentStore::usesRemote()` trả `false` (tệp ở lại máy chủ, không mất), **và** dòng `document_storage_driver` ĐỎ, vì người vận hành đang tin tệp ở trên kho.
- Đĩa `documents_remote` **luôn** có trong `config/filesystems.php`, kể cả khi công tắc là `local`.
  - Media đã đẩy lên kho vẫn phải đọc được sau khi ai đó tắt công tắc.
  - Adapter dựng lười, nên thiếu khoá thì chỉ hỏng lúc dùng, với `DocumentStorageMisconfigured`.
- **`StorageReadiness`** (Task 5) là **một** định nghĩa của "kho dùng được", chạy ở **mọi** `APP_ENV`. Nó gồm các dòng `document_storage_driver`, `drive_credentials`, `drive_http_client`, `drive_reachable`, `drive_sharing`, `drive_root_folder`, `drive_roundtrip`.
  - `RunPreflight` chỉ gọi dòng điều kiện ra mắt khi `APP_ENV=production` (`handle()`, `:54-68`), nên nó không làm được cổng ở làn hay ở máy thử.
  - Preflight production chỉ gói `StorageReadiness` lại.
  - Các lệnh (`enable`, `migrate`) và nghiệm thu ở làn (`vkcrm:storage:check`) hỏi thẳng `StorageReadiness`.
- Test:
  - `tests/Pest.php` giả `documents_remote` cho **mọi** test, như `private` hôm nay. Có một test nhân chứng (khuôn `PrivateDiskTest`).
  - Hành vi riêng của Drive (thùng rác thay cho xoá, md5 do máy chủ tính, lỗi 403/429, upload resumable, tiền tố thư mục) được test ở tầng adapter bằng `Http::fake()`.
  - Test về **thứ tự** gọi Drive dùng đĩa gián điệp hoặc adapter thật (R3), không dùng đĩa giả trần.
- **Một test sống** `tests/Feature/Storage/GoogleDriveLiveTest.php`:
  - chỉ chạy khi có `DRIVE_LIVE_TEST=1` cùng `DRIVE_LIVE_CREDENTIALS`, `DRIVE_LIVE_SHARED_DRIVE_ID`, `DRIVE_LIVE_ROOT_FOLDER_ID`, trỏ vào **Shared Drive thử**;
  - không đủ biến thì test tự bỏ qua, kèm lý do. CI không có bí mật này.

**R8 — Trên kho, "xoá" là cho vào thùng rác. CRM không bao giờ xoá vĩnh viễn, không bao giờ ghi đè.**
- `delete`/`deleteDirectory` của adapter gọi `files.update {"trashed": true}`. Dòng chỉ mục được đánh dấu: `object_key = NULL`, `former_key` giữ khoá cũ, `retired_reason = trashed`, `retired_at`.
- Thùng rác của Shared Drive tự xoá sau 30 ngày. Content manager không xoá vĩnh viễn được, nên mã không có đường nào làm việc đó. Huỷ thật khi hết hạn lưu là việc của người có quyền, theo R15.
- **Ghi vào một khoá đã có thì bị từ chối** (`UnableToWriteFile`): tệp hồ sơ là bất biến.
  - Drive giữ phiên bản cũ của một tệp bị ghi đè, nhưng đó không phải bảo đảm; bất biến ở mã mới là bảo đảm.
  - Máy văn phòng kéo bằng `rclone copy --immutable` (R10), nên một tệp bị đổi trên kho sẽ làm lượt kéo báo lỗi: đó là tín hiệu giả mạo.
- Các đường dẫn tới xoá **không đổi**: chỉ thư viện media xoá tệp (gói bàn giao cũ không ai tham chiếu, dọn khi rollback).
  - Không bao giờ tới đường xoá, như hôm nay: tài liệu bị chứng từ tiền tham chiếu (M9), tài liệu đã rút (M7 Task 7), tài liệu đã có lượt tải của khách.

**R9 — Hiệu năng, lỗi và điều người dùng thấy.** *(Phán quyết 2026-10-04; sửa vòng 1 ở ngắt mạch.)*
- **Thời gian chờ:** kết nối 5 giây; lệnh metadata 30 giây; tải xuống không giới hạn tổng nhưng `read_timeout` 60 giây giữa hai khối; mỗi khối tải lên 120 giây.
- **Thử lại**, theo hướng dẫn của Google (backoff mũ, thêm ngẫu nhiên tới 1 giây, trần 32 giây):
  - thử lại khi gặp 429, 5xx, lỗi kết nối, hoặc 403 có lý do `rateLimitExceeded`/`userRateLimitExceeded`;
  - 401: làm mới token **một** lần;
  - trong request web: tối đa một lần thử lại rồi trả 503; trong job: tối đa 4 lần mỗi lượt, rồi tới backoff của job (`[60, 300, 900]`, `$tries = 4`).
- **Lỗi không thử lại** → `DocumentStorageMisconfigured`, kèm lý do tiếng Việt và thư cảnh báo (R13). Gồm:
  - `storageQuotaExceeded`;
  - `teamDriveFileLimitExceeded` (400.000 mục);
  - `numChildrenInNonRootLimitExceeded`;
  - `insufficientFilePermissions`;
  - `teamDriveMembershipRequired`;
  - 404 trên Shared Drive hay thư mục gốc.
- **Ngắt mạch, hai phạm vi riêng:** `web` (request HTTP) và `job` (lệnh Artisan, hàng đợi), chọn theo `app()->runningInConsole()`.
  - Trạng thái nằm ở store `file`, cùng chỗ với token: chung mọi tiến trình PHP-FPM trên máy, và không phụ thuộc CSDL đang chậm. Khoá là `drive-breaker:web` và `drive-breaker:job`.
  - Chỉ lỗi tạm thời của **đọc** và **metadata** được đếm. Lỗi của một khối tải lên được thử lại trong phiên resumable và **không** đếm.
  - 3 lỗi trong 60 giây → mở mạch 60 giây **cho phạm vi đó**. Trong lúc đó adapter ném `DocumentStorageUnavailable` ngay, không chờ mạng.
  - Một job tải lên gặp ba lỗi khối không được biến mọi lượt tải xuống trên web thành 503.
  - Trên shared hosting chỉ có vài tiến trình PHP-FPM; mười lượt tải cùng chờ hết thời gian chờ là treo cả hai panel.
- **Tải lên luôn resumable** (một đường mã cho mọi kích thước), khối 8 MiB (`GOOGLE_DRIVE_CHUNK_MB`).
  - `GOOGLE_DRIVE_CHUNK_MB` là số nguyên MiB từ 1 tới 64, nên luôn là bội của 256 KiB như Google đòi.
  - Gặp lỗi giữa chừng thì hỏi trạng thái phiên (`Content-Range: bytes */<tổng>`) rồi tải tiếp từ byte Google đã nhận.
  - Bộ nhớ tối đa một khối. Một job bị giết thì lượt sau bắt đầu phiên mới.
  - Chấp nhận: tệp người dùng tối đa 20 MB, gói bàn giao tối đa 2048 MB (`config/media-library.php`).
- **Hạn mức của Google** (Drive API, "Usage limits"):
  - đơn vị hạn mức mỗi phút cho mỗi project và mỗi người dùng cao hơn nhu cầu của một văn phòng nhiều bậc;
  - tải lên 750 GB/ngày cho mỗi người dùng, và tài khoản dịch vụ tính là một người dùng. Lệnh chuyển tệp cũ in con số này ở chế độ chạy thử.
- **Không cache nội dung tệp.** Một bộ đệm cục bộ chính là bản sao không kiểm soát của tệp khách trên máy chủ, ngoài luật dọn của R10.
  - Độ trễ thêm của một lượt tải là thời gian tới byte đầu của **một** `GET`.
  - Thứ được cache: access token (R6), mã thư mục tháng (bảng `drive_folders`), metadata (chỉ mục `drive_objects`).
- **Điều người dùng thấy khi kho sập:**
  - **Tải xuống**, cả hai panel: trang 503 `errors/storage-unavailable` với `Retry-After: 120`.
    - Câu chữ: "Kho tài liệu tạm thời chưa truy cập được. Tài liệu vẫn được lưu an toàn; vui lòng thử lại sau ít phút."
    - Trên cổng khách kèm hotline từ `OfficeProfile`. Không 500, không lộ chi tiết kỹ thuật.
  - **Tải lên**: không thấy gì, vì tệp vào vùng đệm (R2).
  - **Gói bàn giao**: trạng thái "lỗi" với lý do tiếng Việt, qua đường `RecordHandoverPackageFailure` sẵn có; luật sư bấm sinh lại được.
  - **Admin**: một dòng đỏ trên `SystemHealthWidget` (R13).

**R10 — Lúc nào cũng có hai bản. Bản thứ hai nằm ở máy chủ văn phòng, ngoài Google. Vùng đệm chỉ được dọn theo biên nhận từng tệp.** *(Phán quyết **của chủ văn phòng** 2026-10-04: "sao Drive sang Drive là vô ích; máy chủ văn phòng là bản thứ hai". Sửa vòng 1: bản trước có "giai đoạn A" sao Kho sang Shared Drive sao lưu, và dùng nó làm điều kiện dọn. Đã bỏ; lý do ở cuối mục.)*

- **Bất biến:**
  - mỗi tệp luôn có ít nhất hai bản: vùng đệm + kho, hoặc kho + bản ở văn phòng;
  - bản trên máy chủ web chỉ bị dọn khi đã có một bản **ngoài Google**.
- **Điều kiện dọn.** `PurgeStagedDocumentCopies` (mỗi giờ) chọn ứng viên bằng một truy vấn, rồi với **từng** media, dưới `DocumentStore::pushLock($id)`, **đọc lại** dòng và chỉ xoá bản trong vùng đệm khi cả bốn điều đúng:
  1. `media.disk = 'documents_remote'`;
  2. `local_purge_after IS NOT NULL AND local_purge_after <= now()`;
  3. có dòng `drive_objects` **sống** của khoá đó, đúng `drive_id` đang cấu hình, với `md5 = media.checksum_md5`;
  4. dòng đó có `office_copied_at <= now() − office.purge_margin_hours` (mặc định 24).

  Đạt thì xoá `private/<media_id>/`, rồi `UPDATE media SET local_purge_after = NULL WHERE id = ? AND disk = 'documents_remote'`. Bản cục bộ đã mất từ trước thì chỉ đặt lại cột.
  - Điều 1 là thứ bản trước thiếu: một media đã quay lui về `private` mà vẫn mang mốc cũ sẽ bị xoá **bản duy nhất** mà dòng đó trỏ tới.
  - Biên độ 24 giờ của điều 4 che lệch đồng hồ giữa hai máy, và cho người vận hành một ngày để thấy một biên nhận sai trước khi nó có hậu quả.
- **Chưa có máy văn phòng thì vùng đệm không bao giờ bị dọn.**
  - Máy chủ web giữ mọi tệp như hôm nay, và archive sao lưu đêm (AES-256) vẫn chứa chúng.
  - Preflight `document_office_copy` VÀNG nhắc.
  - Đó vẫn là hai bản, và bản ngoài Google vẫn là archive mã hoá như hôm nay.
- **Máy văn phòng kéo về** (Task 7, Phụ lục D). Mô hình kéo: chiếm được máy chủ web cũng không với tới bản ở văn phòng.
  - Tài khoản Google riêng `van-phong-kho@…` (Workspace, bật 2FA, không ai dùng hằng ngày): **Viewer** trên Kho, **Contributor** trên "VK-CRM Backups".
    - Remote rclone `vkkho` của nó: phạm vi `drive.readonly`, `team_drive` = mã Shared Drive kho, `root_folder_id` = mã thư mục gốc. Đổi vai trên Drive cũng không làm token này ghi được.
    - Thông tin đăng nhập **chỉ nằm trên máy văn phòng**.
  - Đích là remote **`crypt`** trên ổ của máy văn phòng: tên và nội dung tệp đều mã hoá.
    - Mật khẩu `crypt` và mật khẩu cấu hình rclone cất cùng chỗ với `APP_KEY`/`BACKUP_ARCHIVE_PASSWORD` (`docs/SAO-LUU-KHOI-PHUC.md` Bước 6), **không bao giờ** trên máy chủ web.
    - Script chạy dưới một tài khoản hệ điều hành riêng mà nhân sự không đăng nhập.
    - Lý do: máy văn phòng nằm giữa văn phòng, nơi nhân sự bị loại khỏi vụ `restricted` làm việc hằng ngày. Bản thô ở đó là tệp `restricted` đọc được ngoài CRM.
  - `rclone copy --immutable`, không bao giờ `sync`, `move`, `delete*`, `purge`. Có khoá chống chạy chồng: hai lượt `rclone copy` cùng lúc sinh tệp trùng.
  - Sau mỗi lượt sao: `rclone cryptcheck vkkho: vkoffice:kho --one-way --files-from <tên chưa có biên nhận> --match <tệp>`. Lệnh này so **nội dung** đã mã hoá với nguồn, từng tệp; danh sách "chưa có biên nhận" giữ trên máy văn phòng.
  - **Biên nhận**: một tệp JSON `receipt-<UTC>.json` ghi:
    - `team_drive` và `root_folder_id` mà remote nguồn **thật sự** dùng (đọc từ cấu hình rclone, không gõ tay);
    - giờ chạy, số lỗi;
    - danh sách `{tên, md5 của Drive, cỡ}` cho đúng các tệp `cryptcheck` báo khớp.

    Script đẩy biên nhận vào `office-receipts/<BACKUP_NAME>/` trên "VK-CRM Backups". Biên nhận chỉ chứa tên mờ, md5 và cỡ: không dữ liệu khách.
  - Cũng kéo thư mục archive CSDL (đã AES-256) về, cũng bằng `copy`.
  - Mỗi tháng, `office-pull.sh --check-monthly` chạy `cryptcheck` toàn bộ.
- **CRM nhập biên nhận** (`ImportOfficeReceipts`, mỗi ngày 07:00, đọc qua remote `gdrive` sẵn có của M8a bằng `RcloneProcess`):
  - từ chối **cả tệp** khi `team_drive`/`root_folder_id` khác `GOOGLE_DRIVE_SHARED_DRIVE_ID`/`ROOT_FOLDER_ID`, khi sai khuôn, quá 32 MiB, hay `started_at` ở tương lai;
  - mỗi dòng chỉ đặt `drive_objects.office_copied_at = now()` khi tên đọc ngược ra đúng khoá **và** thế hệ của một dòng sống trên đúng `drive_id`, **và** md5 khớp dòng đó. Dòng không khớp được đếm và báo, không ghi gì;
  - **không gì khác** ghi cột này. Có test cấu trúc.
  - Đánh dấu nằm trên **đối tượng Drive**, không trên `media`. Quay lui rồi chuyển lại thì dùng lại đúng đối tượng đó, và biên nhận cũ vẫn đúng. Tải lại thế hệ mới thì phải có biên nhận mới.
- **Bản thứ hai đỡ được gì.** Bảng này thay bảng của bản trước, vốn nói sai rằng bản sao Drive → Drive đỡ được khoá lộ và mã độc trên máy chủ.

  | Sự cố | Bản còn lại |
  |---|---|
  | Lỗi CRM xoá hoặc ghi sai | Bản ở văn phòng (`copy`, không bao giờ xoá); vùng đệm trong thời gian ân hạn |
  | Lộ khoá tài khoản dịch vụ | Khoá đó chỉ cho tệp vào thùng rác và ghi phiên bản mới trên Kho; không chạm được máy văn phòng. Tệp bị sửa làm lượt kéo `--immutable` báo lỗi, và lỗi đó tới CRM qua biên nhận (tín hiệu giả mạo) |
  | Máy chủ web bị chiếm, mã độc tống tiền | Kho, vì tài khoản dịch vụ không xoá vĩnh viễn được; và bản ở văn phòng. Token `gdrive` của `sao-luu@` trên máy chủ web (phạm vi `drive`, `SAO-LUU-KHOI-PHUC.md:149`) xoá được archive trên "VK-CRM Backups", nhưng `sao-luu@` không là thành viên Kho, và máy văn phòng đã kéo archive về |
  | Google khoá Workspace, quản trị viên Workspace bị chiếm | Bản ở văn phòng. Trước khi có máy văn phòng: vùng đệm, vì nó chưa bao giờ được dọn |
  | Máy văn phòng hỏng, cháy, mất | Kho, cộng vùng đệm của tệp chưa dọn. Kéo lại toàn bộ sang máy mới |
  | Mất cùng lúc Workspace và máy văn phòng | **Không đỡ được.** Chấp nhận |

- **Mã hoá: một bảo đảm cũ bị đổi, nói thẳng.**
  - Hôm nay bản duy nhất của tệp khách nằm ngoài máy chủ là archive AES-256: `GuardBackupEncryption` chặn archive không mã hoá ở production (SPEC §2, đính chính 2026-10-01).
  - Sau M14, tệp trên Kho ở dạng **Google đọc được**: Google mã hoá khi lưu, bằng khoá của Google. Đó là hệ quả trực tiếp của việc chọn Drive làm kho, và cần chữ ký của chủ văn phòng (câu hỏi 8).
  - Bản ở văn phòng mã hoá bằng `crypt`. Archive CSDL như cũ.
  - Ghi đính chính SPEC §10 mục 8 (Task 8) và Phụ lục B mục 7. "CSDL sao lưu như cũ" không được che điều này.
- **Vì sao không mã hoá tệp phía CRM trước khi đẩy lên Kho:**
  - khoá giải mã phải nằm trên máy chủ web, vì CRM phải đọc, nên nó không che được máy chủ web bị chiếm;
  - mất khoá là mất mọi tệp;
  - quản trị viên dự phòng không còn khôi phục được bằng tay;
  - nó chỉ che Google và quản trị viên Workspace.

  Ngoài phạm vi M14. Câu hỏi 8 nêu lựa chọn này để chủ văn phòng biết.
- **CSDL:** `backup:run` 02:00 không đổi. `config/backup.php` vẫn gói `storage/app/private`. Nay đó là vùng đệm cộng bản chưa có biên nhận, tức đúng những tệp chưa có bản ngoài Google. Sửa docblock. Chỉ mục `drive_objects` nằm trong CSDL nên được sao lưu theo.
- Thùng rác 30 ngày và lịch sử phiên bản của Drive **không** được tính là sao lưu ở bất kỳ đâu trong tài liệu.
- **Vì sao bỏ "giai đoạn A" của bản trước** (sao Kho sang Shared Drive sao lưu bằng `rclone`):
  - Nó trái phán quyết đã ghi của chủ văn phòng. Nó lại là điều kiện **duy nhất** để dọn vùng đệm, trong khi máy văn phòng được ghi là "không chặn go-live": sau lượt dọn, mọi tệp chỉ còn trong **một** tenant Google, đúng sự cố mà chính bản đó liệt kê là không đỡ được.
  - Nó chạy trên máy chủ web qua remote `gdrive` có phạm vi `drive` của `sao-luu@`, người tạo "VK-CRM Backups". Cùng một máy chủ bị chiếm vừa có khoá dịch vụ vừa có token xoá được bản sao.
  - Nó không bao giờ xoá nên tích luỹ mọi tệp, mọi bản đã vào thùng rác và archive, và chạm giới hạn 400.000 mục trước Kho.
  - Điều kiện dọn của nó là một mốc thời gian toàn cục cộng một chuỗi `mirror.source` tự do. Nguồn sai hay rỗng làm `rclone check --one-way` xanh và mọi bản trong vùng đệm thành dọn được; danh sách Drive còn có thể trễ sau một lượt đẩy vừa xong.

  Biên nhận từng tệp, ràng vào đúng mã Shared Drive và thư mục gốc, thay cả hai điều đó. Các góp ý riêng cho bản sao (thời gian chờ 1800 giây của `RcloneProcess`, chung mutex giữa lịch và lệnh tay, giới hạn mục của Shared Drive sao lưu) không còn áp dụng. Khoá chống chạy chồng chuyển sang script văn phòng.
- **Vì sao không theo góp ý "đổi `sao-luu@` thành Content manager trên Shared Drive sao lưu":**
  - góp ý đó nhắm vào bản sao Drive → Drive, nay đã bỏ;
  - `sao-luu@` là người tạo "VK-CRM Backups", nên là Manager. Đổi vai của nó là việc của M8a, và cần một Manager khác;
  - archive CSDL trên đó đã có bản ở văn phòng.

  Ghi vào PROGRESS như một việc sau cho M8a, không làm ở M14.

**R11 — Bật kho, chuyển tệp cũ: cùng một Action với luồng sống; chạy thử, chạy lại, chạy tiếp, quay lui được; quay lui không tự đảo ngược; dựng lại chỉ mục được cho cả Shared Drive mới.** *(Phán quyết 2026-10-04; sửa vòng 1.)*
- **`vkcrm:storage:enable`.**
  - Từ chối (mã 2) khi:
    - công tắc, đọc qua `config()` tức là sau `optimize`, chưa là `google_drive`;
    - `StorageReadiness` có dòng ĐỎ;
    - hoặc, trên production, cổng pháp lý của R13 chưa đạt.
  - Đạt thì ghi `storage.remote_enabled_at`, audit `document_store_enabled`.
  - Đã có mốc thì in mốc cũ và thoát 0, **không** dời mốc: dời mốc làm tệp tạo giữa hai mốc lọt khỏi tác vụ quét.
- **`vkcrm:storage:migrate`** gọi **đúng** `PushDocumentFileToRemote` cho từng media còn ở `private`, từ id nhỏ tới lớn. Không có bản thứ hai của luật kiểm checksum.
  - Từ chối (mã 2) khi chưa bật (`pushesNewFiles()` sai) hoặc `StorageReadiness` có ĐỎ, trừ `--dry-run`.
  - `--dry-run`: đếm số media và tổng dung lượng, đo tốc độ bằng một tệp thăm dò 1 MiB, ước thời gian, in hạn mức 750 GB/ngày và chỗ trống máy chủ. Không ghi gì.
  - `--limit=N`, `--max-minutes=M`.
  - `--keep-local-days=30`: bản cục bộ của tệp cũ được giữ ít nhất 30 ngày, để quay lui không phải tải lại.
- **Chạy lại được và chạy tiếp được** tự nhiên: trạng thái nằm ở `media.disk`, đổi bằng câu UPDATE có điều kiện.
  - Một tệp đã lên kho từ lượt trước nhưng chưa kịp đổi đĩa: chỉ mục có khoá đó và md5 khớp → **không** tải lần hai.
  - md5 lệch → cho bản trên kho vào thùng rác rồi tải lại với thế hệ mới (R4).
- **`vkcrm:storage:verify [--all|--sample=N]`**:
  - so md5/kích thước trên kho với `media.checksum_md5`/`media.size`;
  - báo tệp đã vào thùng rác hoặc đã bị đổi;
  - tệp của vụ đã ghi quyết định huỷ (R15) được báo thành nhóm riêng, không tính là lỗi.
- **`vkcrm:storage:rollback`:**
  - **Từ chối (mã 2), không đổi gì, trừ khi công tắc đã là `local`** (đọc qua `config()`).
    - Thứ tự ngược lại (quay lui trước, đổi công tắc sau, như Phụ lục C của bản trước) làm `storage.push-pending` nhặt lại trong vòng 15 phút mọi dòng vừa quay lui.
    - Action thấy chỉ mục có md5 khớp và đổi chúng về kho ngay: quay lui tự đảo ngược trong im lặng.
  - Đầu lượt xoá mốc `storage.remote_enabled_at`.
  - Với mỗi media trên kho, dưới `pushLock($id)`:
    - bản cục bộ còn và md5 khớp `checksum_md5` → đổi đĩa;
    - không còn → tải về một tệp tạm cạnh đích, kiểm md5, đổi tên vào chỗ, rồi mới đổi đĩa.
  - Đổi đĩa bằng `UPDATE media SET disk='private', conversions_disk='private', local_purge_after=NULL, remote_pushed_at=NULL WHERE id=? AND disk='documents_remote'`. Hai cột mốc về `NULL` để không luật dọn nào còn nhắm vào dòng này.
  - Chỉ media **cần tải về** mới cần Drive.
    - Khi đó, và chỉ khi đó, kiểm `drive_credentials` và `drive_reachable`.
    - Hỏng thì bỏ qua đúng những media đó, báo số, mã thoát 1; media có bản cục bộ vẫn được kéo về.
    - Lệch chia sẻ (`drive_sharing` ĐỎ) **không** chặn quay lui: đó đúng là lúc cần kéo tệp về.
  - Bản trên kho và dòng chỉ mục (kể cả `office_copied_at`) **không** bị chạm. Chuyển lại lần sau dùng lại chúng (md5 khớp, không tải lần hai).
- **`vkcrm:storage:reindex --drive=<id> --root=<id> [--dry-run]`**: dựng lại `drive_objects` của **một** Shared Drive từ danh sách tệp (tên → khoá + thế hệ, R4).
  - Hai tuỳ chọn phải bằng `GOOGLE_DRIVE_SHARED_DRIVE_ID`/`ROOT_FOLDER_ID` đang cấu hình. Khác → mã 2: "đổi `.env` và chạy `optimize` trước". Như vậy không ai dựng chỉ mục cho một drive mà ứng dụng không dùng.
  - Khoá tìm thấy trên drive này mà đang có dòng sống ở drive **khác** → dòng cũ thành `retired_reason = superseded` (`object_key = NULL`, `former_key` giữ khoá). Không xoá dòng nào. Bản trước đụng unique `object_key` ở đúng bước này.
  - Dựng lại `drive_folders` của thư mục gốc mới từ các thư mục tháng có sẵn, để lượt đẩy sau không tạo thư mục trùng tên.
  - **Tệp trùng tên** cùng khoá và cùng thế hệ, ví dụ một job bị giết sau khi tải lên xong nhưng trước khi ghi chỉ mục, nên lượt sau tải lại cùng tên:
    - chọn tệp có md5 bằng `media.checksum_md5`;
    - các tệp còn lại được báo, không ghi, không xoá;
    - không có `checksum_md5` để so thì không chọn, chỉ báo.
  - Nhiều thế hệ của một khoá: chọn thế hệ cao nhất có md5 khớp.
  - Tên không khớp khuôn R4 thì báo, không ghi.
- **`vkcrm:storage:orphans`**: **chỉ báo cáo** tệp trên kho không có media, media trên kho không có tệp, và tệp trùng tên. Không tự xoá gì.
- **Thứ tự với lưu lượng thật** (sổ tay ở Phụ lục C):
  1. gộp mã với `local`;
  2. điều kiện tiên quyết; `vkcrm:storage:check` xanh; cổng pháp lý;
  3. đặt `google_drive`, `optimize`, `enable`. Từ lúc này **chỉ tệp mới** tự đẩy;
  4. chuyển tệp cũ ngoài giờ làm việc, nhiều đêm nếu cần;
  5. `verify --all`;
  6. máy văn phòng kéo về và gửi biên nhận;
  7. vùng đệm tự dọn theo biên nhận.

  Trong suốt quá trình, mỗi media nằm trọn ở **một** đĩa tại mỗi thời điểm, và bản cục bộ còn cho tới khi có biên nhận. Tải xuống không bao giờ gãy.

**R12 — Gói bàn giao dựng từ bản tải về; kiểm chỗ trống đúng đỉnh thật; thời gian của job tính lại.** *(Sửa vòng 1.)*
- `CollectHandoverEntries` thôi gọi `$disk->path()`. `HandoverEntry` mang `disk`, `relativePath`, `size`, `md5` (khi có) thay cho `sourcePath`. `exists()` vẫn kiểm từ chỉ mục, không tốn mạng.
- `BuildHandoverPackage::buildZip()`:
  1. **Kiểm chỗ trống trước**, khi đo được. Đo qua `App\Support\Files\FreeSpace::bytes()`, trả `null` khi `function_exists('disk_free_space')` sai hoặc hàm trả `false`.
     - Nhiều shared hosting tắt hàm này trong `disable_functions`, và gọi một hàm bị tắt là `Error`: gói hỏng kể cả ở chế độ `local`.
     - Đo được thì cần `free(work_dir) >= 2 × T + 50 MB` **và** `free(gốc đĩa private) >= T + 50 MB`, với T = tổng kích thước nguồn. Hai đường cùng một ổ thì điều đầu bao điều sau. Thiếu → `HandoverPackageFailed::insufficientWorkSpace()`.
     - Không đo được → bỏ kiểm, log `warning`. Preflight `disk_free_space_available` VÀNG.
  2. Gọi `MaterialiseStoredFile` cho từng mục: đĩa cục bộ thì dùng thẳng đường dẫn; đĩa kho thì stream về `work_dir/src/<NN>`, kiểm kích thước và md5.
  3. `addFile()`, đóng zip, kiểm zip đọc lại được với đúng số entry, như hôm nay.
  4. **Xoá `work_dir/src/` ngay sau khi kiểm zip xong, trước `store()`.**
     - `store()` để medialibrary **chép** zip vào `private/<id>/` rồi mới xoá nguồn (docblock `BuildHandoverPackage:78-89`). Nếu `src/` còn, đỉnh là `src` + zip + bản chép ≈ 3T.
     - Xoá `src/` trước thì đỉnh là max(`src` + zip, zip + bản chép) ≈ 2T, đúng con số của bước 1.
     - Có test khẳng định `src/` không còn lúc medialibrary chép.
- Kho sập → `HandoverPackageFailed::storageUnavailable()`, lý do tiếng Việt; luật sư sinh lại được. Tệp mất trên kho → `missingFile()` như hôm nay.
- Thư mục làm việc vẫn bị xoá trong `finally`. Không zip dở dang nào sống sót.
- **Thời gian của job.** `GenerateHandoverPackage::TIMEOUT_SECONDS` 600 ("đủ cho gói vài trăm MB") nay còn phải gồm việc tải tới 2 GB từ Drive. Ước lượng trên shared hosting: tải 2 GB ở 5 MB/s ≈ 410 giây, nén ≈ 120–300 giây, chép ≈ 60 giây. Số mới:

  | Số | Cũ | Mới |
  |---|---|---|
  | `GenerateHandoverPackage::TIMEOUT_SECONDS` | 600 | **1200** |
  | `retry_after` của kết nối `handover` | 900 | **1500** |
  | `--timeout` của `queue.handover` | 600 | **1200** |
  | `withoutOverlapping` của `queue.handover` | 15 | **25** |
  | `RequestHandoverPackage::STALE_AFTER_MINUTES` | 60 | **90** |

  - `STALE_AFTER_MINUTES` phải lớn hơn `retry_after + $tries × $timeout + backoff` = 1500 + 2 × 1200 + 120 giây ≈ 67 phút; nếu không, nút "sinh lại" mở khoá giữa một lượt còn sống.
  - `QueueHandoverScheduleTest` ghim cả ba quan hệ:
    - `retry_after > TIMEOUT_SECONDS`;
    - `--timeout` của lịch = `TIMEOUT_SECONDS`;
    - `STALE_AFTER_MINUTES × 60 > retry_after + tries × timeout + Σbackoff`.
- Gói mới vào vùng đệm như mọi tệp (R2). Lời khẳng định "giải nén kiểm tra được" của SPEC §13 được chứng minh lại với media nằm trên đĩa kho giả.

**R13 — Pháp lý: hồ sơ chuyển dữ liệu ra nước ngoài và DPA. Mặc định là CHẶN trên production, cho tới khi luật sư trả lời câu hỏi 3a hoặc đã ghi ngày hồ sơ.** *(Phán quyết 2026-10-04 về cơ chế; sửa vòng 1: bản trước mặc định VÀNG không chặn, tức là chọn sai chiều an toàn. Nội dung pháp lý do luật sư của văn phòng quyết.)*
- Lưu tệp hồ sơ trên Google là "sử dụng nền tảng ở ngoài lãnh thổ để xử lý dữ liệu cá nhân thu thập tại Việt Nam", tức là **chuyển dữ liệu cá nhân xuyên biên giới** theo khoản 1 Điều 20 Luật 91/2025/QH15 (`docs/research/2026-09-24-mcp-phap-ly-goi.md:279`, `:323`).
- Thủ tục theo tài liệu nghiên cứu:
  - bên chuyển lập hồ sơ đánh giá tác động và gửi bản chính cho Cục A05 trong **60 ngày** kể từ lần chuyển đầu tiên (`:324`);
  - **nhưng** Nghị định 356/2025 đưa hồ sơ DPIA và hồ sơ chuyển dữ liệu sang **cơ chế tiền kiểm**: A05 xem xét 15 ngày xem hồ sơ **đạt hay không đạt**, bổ sung trong 30 ngày (`:326`, ghi "verified");
  - tài liệu để ngỏ việc **có phải chờ A05 chấp thuận trước khi bắt đầu chuyển** hay chỉ cần nộp trong 60 ngày (`:386`);
  - hồ sơ cập nhật 6 tháng một lần hoặc khi đổi bên xử lý (`:326`);
  - DPA **không thay** hồ sơ (`:398`).
- Phân loại dữ liệu:
  - hồ sơ vụ việc nên coi là dữ liệu nhạy cảm (`:396`);
  - **ảnh CCCD/CMND là dữ liệu nhạy cảm** theo Nghị định 356 (`:327`, `:328`; số CCCD dạng chữ là dữ liệu cơ bản). Đó đúng là loại tệp khách nộp nhiều nhất qua cổng, và là ví dụ của chính kế hoạch này.
- Tài liệu nghiên cứu đó là thông tin tham khảo, **không phải tư vấn pháp lý**. Luật sư của văn phòng xác nhận trước khi bật trên dữ liệu thật.
- Tài liệu đó khuyến nghị "giữ dữ liệu gốc trên VPS tại Việt Nam" (`:378`). M14 **đi ngược** khuyến nghị đó theo quyết định của chủ văn phòng. Kế hoạch ghi rõ, và đưa bản ở máy văn phòng (R10) vào câu hỏi 3c.
- Cơ chế trong app:
  - **Trang "Kho tài liệu"** (Task 5) có các ô, lưu trong `settings` qua Action `RecordDataTransferDossier`, audit `data_transfer_dossier_recorded`:
    - **ngày lập/nộp hồ sơ**;
    - **số hoặc mã hồ sơ** (`string(100)`);
    - **ngày chấp nhận DPA**;
    - **ý kiến của luật sư cho phép chuyển trước khi nộp hồ sơ**: ngày (`storage.transfer_before_dossier_on`) cộng căn cứ (`storage.transfer_before_dossier_basis`, ≤ 200 ký tự, ví dụ số và ngày văn bản ý kiến).
  - **Cổng production.** Khi công tắc là `google_drive` mà chưa có **ngày hồ sơ** và cũng chưa có **ý kiến cho chuyển trước**:
    - `vkcrm:storage:enable` từ chối (mã 2);
    - preflight `data_transfer_dossier` ĐỎ.
    - Không bật thì `pushesNewFiles()` sai, nên không tệp nào rời máy chủ.
    - Ngoài production (máy dev, làn, Shared Drive thử với dữ liệu seed) cổng không áp dụng: không có dữ liệu cá nhân thật.
  - **Lần chuyển đầu tiên tự ghi.** Lượt `Pushed` đầu tiên trên production ghi `storage.first_transfer_at` một lần, không ghi đè (R2 bước 6). Không dựa vào trí nhớ người vận hành.
  - **Đồng hồ 60 ngày.** Có `first_transfer_at` mà chưa có ngày hồ sơ:
    - từ **ngày 45**: preflight VÀNG kèm số ngày còn lại, và kiểm tra sức khoẻ gửi thư loại `transfer_dossier_due` (mỗi ngày một thư);
    - quá **ngày 60**: ĐỎ.
- **DPA:**
  - Đường đi: Admin console → Tài khoản → Cài đặt tài khoản → Pháp lý và tuân thủ → "Security and Privacy Additional Terms" → Review and Accept **Cloud Data Processing Addendum**. Cần tài khoản siêu quản trị.
  - Nếu hợp đồng Workspace đã gộp sẵn CDPA thì chấp nhận lại cũng không đổi gì (Google Workspace Admin Help, "Privacy compliance and records").
  - Lưu bản PDF và ngày chấp nhận vào hồ sơ.
- Task 8 nghiệm thu chỉ bằng dữ liệu seed trên Shared Drive thử, ngoài production, nên không ghi `first_transfer_at` và không bắt đầu đồng hồ.

**R14 — Không quyền mới, không đường MCP mới.**
- Trang "Kho tài liệu" dùng `settings.manage` có sẵn (chỉ admin, như trang "Thông tin văn phòng"). Không đính chính SPEC §5.
- Không model hay presenter MCP nào đọc `drive_objects`, `drive_folders`, hay các cột `remote_*`/`checksum_*` của `media`. Có test cấu trúc nếu `app/Mcp` đã có trên nhánh lúc gộp; nếu chưa, ghi việc này vào PROGRESS cho làn M11.
- Service worker của M12 không bao giờ cache route tải (M12 R4). M14 không đổi URL của route tải.

**R15 — Huỷ tệp của hồ sơ đã quá hạn lưu: CRM vẫn không xoá; CRM in danh sách để người có quyền huỷ ở từng nơi.** *(Phán quyết 2026-10-04, thêm ở vòng 1.)*
- Vì sao cần:
  - Hôm nay `RecordMatterDestruction` chỉ ghi quyết định; "việc huỷ vật lý hồ sơ giấy và tệp là thao tác có biên bản, làm NGOÀI hệ thống" (docblock `:20-21`, SPEC §4.19). Với tệp trên máy chủ, người vận hành xoá được.
  - Với kho thì không: tài khoản dịch vụ chỉ cho vào thùng rác, và bản ở văn phòng không bao giờ xoá.
  - Không đường nào huỷ được tệp khi hết hạn lưu, hay khi chủ thể yêu cầu xoá theo Luật 91.
- **`vkcrm:storage:destruction-list {matter} --by=<email>`.**
  - Từ chối (mã 2) khi vụ chưa có `matter_archives.destroyed_at`, hoặc khi người `--by` không qua `Gate::forUser($user)->allows('recordDestruction', $matter)` (chỉ admin). Người được đọc lại từ CSDL, như `RecordMatterDestruction`.
  - In ba danh sách:
    - tên trên Drive (mọi thế hệ, cả dòng đã vào thùng rác hay bị thay);
    - đường vùng đệm còn trên máy chủ;
    - đường tương ứng trong remote `crypt` của văn phòng (`vkoffice:kho/<YYYY-MM>/<tên>`).
  - Chỉ mã và tên mờ: không tiêu đề, không mã hồ sơ, không `file_id`.
  - Audit `matter_storage_destruction_listed` với người `--by` và số tệp.
- **Sổ tay "Huỷ tệp của hồ sơ đã quá hạn lưu"** (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`):
  - Manager dự phòng xoá vĩnh viễn trên Kho, kể cả trong thùng rác;
  - người giữ máy văn phòng chạy `rclone deletefile` cho từng tệp trong `crypt`;
  - người vận hành xoá thư mục vùng đệm;
  - archive CSDL cũ hết dần theo vòng giữ 30 bản.

  Biên bản huỷ ghi đủ bốn nơi.
- `verify` và `orphans` báo tệp của vụ đã ghi huỷ thành một nhóm riêng, không tính là lỗi.
- Đính chính SPEC §4.19 và docblock `RecordMatterDestruction` (Task 6, Task 8).

---

## Mô hình dữ liệu

**`drive_objects`** — chỉ mục khoá → tệp Drive. Bảng của hạ tầng: không màn hình, không blameable.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigIncrements | |
| `drive_id` | string(128), index | Shared Drive chứa tệp |
| `object_key` | string(255) nullable, **unique** | `<media_id>/<file_name>`. `NULL` khi dòng đã rời chỉ mục sống: MariaDB cho nhiều `NULL` trong unique, không cần partial index |
| `generation` | unsignedSmallInteger, default 1 | Lần tải thứ mấy của khoá này; > 1 thì tên Drive có hậu tố `~g<N>` (R4) |
| `former_key` | string(255) nullable, index | Khoá cũ, sau khi rời chỉ mục sống |
| `retired_reason` | string(20) nullable | enum `DriveObjectRetirement`: `trashed`, `superseded` |
| `retired_at` | timestamp nullable, index | |
| `file_id` | string(128) unique | Mã tệp Drive. **Không bao giờ rời máy chủ** |
| `parent_id` | string(128) | Thư mục tháng |
| `size` | unsignedBigInteger | Do Google trả về lúc tải lên xong |
| `md5` | char(32) | `md5Checksum` của Google |
| `mime_type` | string(255) nullable | |
| `office_copied_at` | timestamp nullable, index | Lúc CRM nhập một biên nhận văn phòng có đúng tên, thế hệ và md5 của dòng này (R10). Chỉ `ImportOfficeReceipts` ghi |
| `created_at` / `updated_at` | timestamps | |

**`drive_folders`** — thư mục tháng, để không bao giờ tạo hai thư mục cùng tên (Drive cho phép trùng tên). Tạo dưới `Cache::lock('drive-folder:{root}:{name}', 60)`.

| Cột | Kiểu |
|---|---|
| `id` | bigIncrements |
| `drive_id` | string(128), index |
| `root_folder_id` | string(128) |
| `name` | string(20) (`YYYY-MM`) |
| `folder_id` | string(128) unique |
| `created_at` / `updated_at` | timestamps |

Unique `(root_folder_id, name)`.

**`media`** (bảng của thư viện media, migration của dự án) — thêm:

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `remote_pushed_at` | timestamp nullable | Lúc đổi đĩa sang kho (R2). Quay lui đặt về `NULL` |
| `local_purge_after` | timestamp nullable, index | Bản cục bộ được giữ ít nhất tới lúc này; `NULL` = không còn bản cục bộ, chưa đẩy, hoặc đã quay lui |
| `checksum_md5` | char(32) nullable | Tính từ vùng đệm lúc đẩy |
| `checksum_sha256` | char(64) nullable | Bản ghi toàn vẹn của ta (R4) |

Index thêm: `(disk, created_at)`, cho `PushPendingDocumentFiles` và lệnh chuyển tệp; `(disk, local_purge_after)`, cho dọn vùng đệm.

**`system_health`** (dòng singleton, `2026_09_23_101800`) — thêm:

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `document_store_status` | string(20) nullable | enum `DocumentStoreStatus`: `ok`, `degraded`, `unavailable`, `misconfigured` |
| `document_store_checked_at` | timestamp nullable | |
| `document_store_detail` | text nullable | Câu tiếng Việt, **không** mã tệp, không bí mật |
| `last_office_receipt_at` | timestamp nullable | Lúc nhập biên nhận văn phòng hợp lệ gần nhất (R10) |
| `last_office_receipt_error` | text nullable | Biên nhận bị từ chối, hay báo lỗi phía văn phòng; câu tiếng Việt |

**`settings`** (khoá–giá trị, M7 Task 10, `value` là `text`) — các khoá mới, không migration:

| Khoá | Giá trị | Ai ghi |
|---|---|---|
| `storage.remote_enabled_at` | thời điểm ISO-8601 | `vkcrm:storage:enable`; bị xoá bởi `rollback` và bởi `PushPendingDocumentFiles` khi thấy công tắc không còn `google_drive` (R2) |
| `storage.first_transfer_at` | thời điểm | `PushDocumentFileToRemote`, chỉ trên production, một lần (`insertOrIgnore` trên khoá unique, rồi `UPDATE … WHERE value IS NULL`) |
| `storage.transfer_dossier_on` | ngày `Y-m-d` | `RecordDataTransferDossier` |
| `storage.transfer_dossier_reference` | ≤ 100 ký tự `mb_strlen` | `RecordDataTransferDossier` |
| `storage.dpa_accepted_on` | ngày | `RecordDataTransferDossier` |
| `storage.transfer_before_dossier_on` | ngày | `RecordDataTransferDossier` |
| `storage.transfer_before_dossier_basis` | ≤ 200 ký tự | `RecordDataTransferDossier` |
| `storage.office_receipt_cursor` | tên tệp biên nhận đã nhập gần nhất | `ImportOfficeReceipts` |

Model `DriveObject`, `DriveFolder`:
- dùng `RestrictedToClientPortal`; policy từ chối mọi thao tác với mọi người (không màn hình nào);
- `PortalCoverageTest` xanh **không** thêm dòng miễn trừ;
- không cần alias morph: không bảng nào trỏ morph tới chúng; `Audit::record` ghi chủ thể là `Document`, `Matter`, hoặc `null` cho các lượt chạy lệnh.

---

## Review Focus

1. **Tệp, hoặc đường tới tệp, ra khỏi CRM mà không qua route tải ký.** Link Drive, mã tệp, một quyền chia sẻ, `temporaryUrl()`, một route `serve`. Test cấu trúc ở Task 2 và Task 4.
2. **Lệnh gọi Drive xảy ra trước khi kiểm quyền.**
   - Mỗi nhánh từ chối của route tải được kiểm bằng đĩa gián điệp (0 lời gọi) **và** bằng adapter thật trên `Http::fake()` + `preventStrayRequests()` (`assertNothingSent()`), có cặp dương (Task 4).
   - Một `assertNothingSent()` trên đĩa giả trần là test không đỏ được: người rà soát gạch nó.
3. **Trạng thái nửa vời.**
   - Media trỏ vào kho mà tệp chưa có hoặc lệch md5.
   - Vùng đệm bị dọn khi chưa có biên nhận, hoặc khi dòng đã quay lui về `private`.
   - Quay lui bị tác vụ quét đảo ngược.
   - Xoá media mà bản cục bộ ở lại vĩnh viễn, hoặc mất khi transaction xoá rollback.
   - (Task 3, 6, 7.)
4. **Bí mật.** Khoá tài khoản dịch vụ (cả quyền tệp trên shared hosting), access token trong log, ngoại lệ, CSDL, bản sao lưu, thư báo. Mật khẩu `crypt` không bao giờ ở máy chủ web (Task 2, 5, 7).
5. **Nhóm D, vụ `restricted`, cách ly giữa khách.** Bộ test hiện có chạy lại với media nằm trên đĩa kho giả (Task 4). Bản ở văn phòng là bản mã hoá (Task 7).
6. **I/O mạng trong `DB::transaction`** (Task 1 test cấu trúc, Task 3, 4).
7. **Bản ngoài Google trước khi dọn.** Chỉ `ImportOfficeReceipts` ghi `office_copied_at`. Biên nhận ràng vào đúng Shared Drive và thư mục gốc, khớp từng tên, thế hệ và md5. Không đường nào khác làm một dòng thành dọn được (Task 3, 7).

---

## Tasks

### - [ ] Task 0 — Khảo sát, dựng làn, xác minh gói (không viết mã sản phẩm)

**Files:** `/d/vkwt/m14-dev` (mới, ngoài repo), `docs/research/2026-10-04-kho-google-drive.md` (mới).

- [ ] Worktree `D:\vkwt\lane-m14`, nhánh `m14-drive-storage` từ `main`. Script `/d/vkwt/m14-dev` chép khuôn `/d/vkwt/m11-dev`:
  - `vendor` riêng, volume cache composer riêng;
  - `vk-container-test`;
  - CSDL `vk_crm_test_lane_m14` / `vk_crm_lane_m14`;
  - cổng phục vụ riêng.
- [ ] Chạy lại trong làn và dán output:
  - `composer require --dry-run masbug/flysystem-google-drive-ext` (kỳ vọng thất bại vì Guzzle 8);
  - `… -W` (kỳ vọng hạ ba gói);
  - `composer require --dry-run google/auth:^1.55` (kỳ vọng 3 gói mới, không hạ);
  - cùng lệnh đó trên **bản sao** `composer.json`/`composer.lock` của `lane-m11` (kỳ vọng `firebase/php-jwt` v7.2.1 đã khoá, không hạ gì).

  Kết quả khác R1 thì **dừng lại báo cáo**.
- [ ] `composer audit --locked` trước và sau khi thêm `google/auth`. Không có cảnh báo mới từ cây `google/auth`.
  - Ngày 2026-10-04 bản lock của `main` **đã** có hai cảnh báo cho `league/commonmark` ≤ 2.10.1 (`GHSA-97jj-33gv-5xf9` trung bình, `GHSA-3q6v-r5mr-hxv8` cao). Không thuộc M14: ghi vào báo cáo cho controller.
- [ ] Kiểm giấy phép từng gói mới (Apache-2.0, BSD-3-Clause, MIT) và ngày phát hành gần nhất.
- [ ] Grep và dán: mọi `Storage::disk(`, `->path(`, `getFirstMedia(`, `addMedia(`, `->download(`, `->response(`, `deleteDirectory(` trong `app/`; mọi `Storage::fake(` trong `tests/`. Đối chiếu với bảng "Hiện trạng". Tên thật trên `main` thắng tên trong kế hoạch.
- [ ] Xác nhận trong `vendor/`:
  - `League\Flysystem\ChecksumProvider` và `Illuminate\Filesystem\FilesystemAdapter::checksum()`;
  - `DefaultFileRemover` nuốt ngoại lệ, và gọi `allFiles`/`deleteDirectory` với `<id>/` có dấu `/` (`DefaultPathGenerator::getPath()`);
  - observer xoá tệp của medialibrary chạy ở `deleted`, không chờ commit.
- [ ] Đo trên dữ liệu seed:
  - số media, tổng dung lượng, tệp lớn nhất;
  - **số media có `file_name` lệch khuôn R4**. Khác 0 thì dừng lại báo cáo: R4 cần một khuôn rộng hơn.
- [ ] **Nếu chủ văn phòng đã làm xong Phụ lục A cho Shared Drive thử** thì thử bằng một script tạm (không commit) và ghi kết quả:
  - (a) thêm được tài khoản dịch vụ khi tắt "người ngoài tổ chức" không;
  - (b) Content manager gọi được `drives.get` (`restrictions`, `capabilities`) và `permissions.list` trên mã Shared Drive không;
  - (c) mục trong thùng rác có tính vào giới hạn 400.000 không (đọc tài liệu Google);
  - (d) tài khoản dịch vụ ở vai `writer` có bị từ chối khi cho tệp vào thùng rác không (xác nhận R5);
  - (e) `rclone` (bản cài trên máy dev hoặc máy văn phòng) có `cryptcheck --one-way --files-from --match` và `lsjson --hash --hash-type md5`; một remote `drive.readonly` với `team_drive` + `root_folder_id` liệt kê đúng cây tháng;
  - (f) trên hosting đích (nếu đã biết): `disk_free_space` và `posix` có bị tắt không.

  Chưa có thì ghi **PENDING OWNER** cho từng mục và đi tiếp. Task 1–7 dùng đĩa giả.
  - Nếu (b) không gọi được `permissions.list` thì R5 lùi về: kiểm `restrictions` cộng vai của chính tài khoản dịch vụ ở mức ĐỎ, còn danh sách thành viên thành VÀNG "không kiểm được — chủ văn phòng tự rà mỗi tháng".

**Test bắt buộc:** không có (task khảo sát). Báo cáo dán đủ output.

Commit: `docs: M14 Task 0 — khảo sát kho Google Drive: dry-run gói trên main và lane-m11, bản đồ chỗ chạm tệp, việc chờ chủ văn phòng`.

### - [x] Task 1 — Nền: gói, cấu hình, migration, model, enum, ngoại lệ, móc test

**Files:**
- `composer.json`, `composer.lock`;
- `config/vkcrm.php` (khối `storage`), `config/filesystems.php` (đĩa `documents_remote`), `config/queue.php` (kết nối `storage`), `.env.example`;
- `database/migrations/2026_10_04_*` (bốn migration: `drive_objects`, `drive_folders`, cột và index `media`, cột `system_health`);
- `app/Models/DriveObject.php`, `DriveFolder.php`, `app/Policies/DriveObjectPolicy.php`, `DriveFolderPolicy.php`;
- `app/Enums/DocumentStoreStatus.php`, `app/Enums/DriveObjectRetirement.php`, `app/Enums/PushOutcome.php`;
- `app/Exceptions/DocumentStorageUnavailable.php`, `DocumentStorageMisconfigured.php`, `StoredFileMissing.php`;
- `app/Support/Storage/DocumentStore.php`;
- `app/Providers/DocumentStorageServiceProvider.php`, `bootstrap/providers.php`;
- `lang/vi/storage.php`, `lang/vi/enums.php`;
- `tests/Pest.php`, tests.

**Interfaces:**
```php
final class DocumentStore {
    public const REMOTE_DISK = 'documents_remote';
    public const STAGING_DISK = 'private';
    public static function usesRemote(): bool;        // driver === 'google_drive'
    public static function driverIsValid(): bool;     // 'local' | 'google_drive'
    public static function remoteEnabledAt(): ?\Carbon\CarbonImmutable; // settings storage.remote_enabled_at
    public static function pushesNewFiles(): bool;    // usesRemote() && remoteEnabledAt() !== null
    public static function pushLock(int $mediaId): \Illuminate\Contracts\Cache\Lock; // 'document-push:{id}', TTL lock_ttl_seconds, store lock_store
    public static function remote(): \Illuminate\Contracts\Filesystem\Filesystem;
    public static function staging(): \Illuminate\Contracts\Filesystem\Filesystem;
}

enum PushOutcome: string { case Pushed = 'pushed'; case AlreadyRemote = 'already_remote'; case Gone = 'gone'; case Disabled = 'disabled'; case Locked = 'locked'; /* label() */ }
enum DriveObjectRetirement: string { case Trashed = 'trashed'; case Superseded = 'superseded'; /* label() */ }
```

Khối cấu hình `vkcrm.storage`:
- `driver` ← `DOCUMENT_STORAGE` (`local`);
- `staging_grace_hours` ← `DOCUMENT_STAGING_GRACE_HOURS` (24);
- `push_alert_minutes` ← `DOCUMENT_PUSH_ALERT_MINUTES` (60);
- `lock_store` `database`, `lock_ttl_seconds` 2100;
- `google_drive.credentials_path`, `shared_drive_id`, `root_folder_id`, `allowed_members` (danh sách phẩy, dạng `email:vai`);
- `google_drive.connect_timeout` 5, `timeout` 30, `read_timeout` 60, `chunk_mb` 8, `token_cache_store` `file`, `breaker_store` `file`, `item_warn` 300000, `item_limit` 400000;
- `office.receipts_path` ← `DOCUMENT_OFFICE_RECEIPTS_PATH` (chuỗi remote rclone, ví dụ `gdrive:VK-CRM-backups/office-receipts/vk-crm-production`; trống = chưa có máy văn phòng);
- `office.max_age_hours` 36, `office.purge_margin_hours` 24, `office.receipt_max_bytes` 33554432.

Mọi số đọc theo thành ngữ `?:` + `max(1, …)` của `config/backup.php`: trống là mặc định, không phải 0.

- [x] Cài `google/auth:^1.55` bằng `/d/vkwt/m14-dev composer require`. Dán lock diff.
- [x] Đĩa `documents_remote`: driver `google-drive`, **không** `serve`, **không** `url`, `throw => true` (lỗi kho phải nổ ra ngoài, không thành `false` lặng lẽ).
  - Driver đăng ký bằng `Storage::extend('google-drive', …)` trong provider mới. Adapter dựng lười: thiếu cấu hình thì `DocumentStorageMisconfigured` lúc dùng, không lúc boot.
  - **Task 1 đăng ký một adapter giữ chỗ ném `DocumentStorageMisconfigured` ở mọi lời gọi; Task 2 thay bằng adapter thật.**
- [x] Kết nối `storage` trong `config/queue.php`: `retry_after` 2400 > `PushDocumentFile::$timeout` 1800. Docblock nói vì sao, như khối `handover`.
- [x] Bốn migration theo "Mô hình dữ liệu", đúng kiểu và độ dài. Vòng MariaDB thật.
- [x] `tests/Pest.php`: thêm `Storage::fake('documents_remote')` vào **cả hai** `beforeEach`. Thêm test nhân chứng: đĩa kho trong test luôn là đĩa giả.
- [x] Test cấu trúc "không I/O kho trong transaction": trong `app/Actions/` và `app/Support/Storage/`, không có `DocumentStore::remote(`, `->writeStream(`, `->readStream(`, `->checksum(` hay `Http::` nằm trong closure của `DB::transaction(`. Cùng cách quét với `ArchitectureTest.php:269`.

**Test bắt buộc:**
- Mặc định: công tắc `local`, `usesRemote() = false`. `google_drive`: `true`. Giá trị lạ (`gooogle_drive`): `false` **và** `driverIsValid() = false`. Mỗi vế một mutation probe.
- `pushesNewFiles()`: bảng (công tắc × mốc) đủ bốn ô; chỉ `google_drive` + có mốc là `true`. Mỗi vế một probe.
- `pushLock()`: TTL > `PushDocumentFile::$timeout` (ghim bằng số, không bằng hằng của chính nó).
- Đĩa `documents_remote` không có khoá `serve`/`url`; route `storage.documents_remote` không tồn tại (khuôn `PrivateDiskTest`).
- Migration:
  - các cột có đúng kiểu;
  - hai dòng `drive_objects` cùng `object_key = NULL` được phép;
  - hai dòng cùng `object_key` khác `NULL` bị từ chối (chạy thêm `test:mariadb`).
- `PortalCoverageTest` xanh không miễn trừ; `ClientUser` không đọc được hai model mới.
- `DocumentStoreStatus`, `DriveObjectRetirement`, `PushOutcome` có `label()` cho mọi case (`ArchitectureTest` "mọi enum backed string có nhãn", quét `app/Enums/`).
- `EnvExampleTest`: mỗi biến mới đúng một dòng mẫu.
- Test cấu trúc "không I/O kho trong transaction" đỏ khi cố ý chèn một vi phạm.

Commit: `feat: M14 Task 1 — nền kho tài liệu: google/auth, cấu hình DOCUMENT_STORAGE và mốc bật kho, đĩa documents_remote, kết nối hàng đợi storage, chỉ mục drive_objects có thế hệ và biên nhận văn phòng, cột media và system_health`.

### - [x] Task 2 — `DriveAdapter` và `DriveClient` trên Drive REST v3 (R1, R4, R5, R6, R8, R9)

**Files:**
- `app/Support/Storage/GoogleDrive/DriveAdapter.php`, `DriveClient.php`, `DriveTokenProvider.php` (interface), `ServiceAccountTokenProvider.php`, `DriveCircuitBreaker.php`, `DriveObjectIndex.php`, `DriveObjectName.php`, `DriveApiError.php`;
- `app/Providers/DocumentStorageServiceProvider.php`;
- `tests/Unit/Support/Storage/GoogleDrive/*Test.php`, `tests/Feature/Storage/GoogleDriveLiveTest.php`.

**Interfaces:**
```php
interface DriveTokenProvider { public function token(): string; public function forget(): void; }

final class DriveObjectName {
    public static function fromKey(string $key, int $generation = 1): string; // '/' → '~', thế hệ ≥ 2 thêm '~g<N>' trước đuôi
    /** @return array{key: string, generation: int}|null */
    public static function parse(string $name): ?array;                      // khuôn R4; tên lạ → null
}

final class DriveClient {
    public function drive(string $driveId): array;                 // restrictions, capabilities
    public function drivePermissions(string $driveId): array;      // GET, phân trang
    public function folder(string $parentId, string $name): string;// tạo thư mục (không tìm theo tên)
    /** @param resource $stream */
    public function upload(string $parentId, string $name, string $mime, $stream, int $size): array; // id,size,md5Checksum
    public function metadata(string $fileId): array;                // id,size,md5Checksum,sha256Checksum,trashed,parents,driveId,mimeType
    /** @return resource */
    public function open(string $fileId);                           // GET alt=media, stream
    public function trash(string $fileId): void;
    public function rename(string $fileId, string $name): void;
    public function copy(string $fileId, string $parentId, string $name): array;
    public function children(string $folderId, ?string $pageToken = null): array;
}

final class DriveAdapter implements \League\Flysystem\FilesystemAdapter, \League\Flysystem\ChecksumProvider { /* … */ }
```

- [x] `ServiceAccountTokenProvider`:
  - dùng `Google\Auth\Credentials\ServiceAccountCredentials`, phạm vi `https://www.googleapis.com/auth/drive`;
  - `httpHandler` chuyển request PSR-7 qua `Http` của Laravel;
  - cache token 50 phút trong store `token_cache_store`.

  Phạm vi `drive.file` hẹp hơn nhưng không đọc được `drives.get`. Khoá bị lộ thì xin được mọi phạm vi, nên thu hẹp chỉ che token bị lộ trong một giờ. Ghi lý do vào docblock.
- [x] `DriveClient`:
  - mọi request có `supportsAllDrives=true`; mọi danh sách có `corpora=drive&driveId=…&includeItemsFromAllDrives=true`;
  - `fields=` tường minh, không bao giờ có `webViewLink`, `webContentLink`, `thumbnailLink`, `permissions`, `exportLinks`;
  - thời gian chờ, thử lại, phân loại lỗi theo R9;
  - ngắt mạch theo R9: phạm vi `web`/`job`, store `breaker_store`. Chỉ lỗi đọc và metadata được đếm.
- [x] Upload resumable:
  - `POST /upload/drive/v3/files?uploadType=resumable` lấy URI phiên;
  - `PUT` từng khối với `Content-Range`, nhận 308 kèm `Range`, đi tiếp từ byte đã nhận;
  - lỗi giữa chừng thì hỏi `bytes */<tổng>`;
  - md5 tính dần trong lúc đọc, so với `md5Checksum` trả về; lệch thì cho tệp vào thùng rác rồi ném lỗi;
  - tệp 0 byte: một `PUT` rỗng, không hỏng.
- [x] `DriveAdapter`:

  | Phương thức | Hành vi |
  |---|---|
  | `write` / `writeStream` | Khoá đã có trong chỉ mục (dòng sống) → `UnableToWriteFile` (R8). Nếu không: thế hệ = 1 + số dòng đã rời chỉ mục có `former_key` = khoá → thư mục tháng (bảng `drive_folders` dưới khoá) → upload với `DriveObjectName::fromKey($key, $generation)` → ghi dòng chỉ mục |
  | `read` / `readStream` | Chỉ mục → `DriveClient::open()`. Drive 404 → `UnableToReadFile` bọc `StoredFileMissing`; lỗi tạm thời → `DocumentStorageUnavailable` |
  | `fileExists`, `fileSize`, `mimeType`, `lastModified` | Trả lời từ chỉ mục, không gọi mạng |
  | `listContents($d, $deep)`, `directoryExists($d)` | Từ chỉ mục, **tiền tố có `/`** (R4): `object_key LIKE '<d thoát>/%' ESCAPE '\'`. Nông thì chỉ mục con trực tiếp. Không gọi mạng |
  | `delete` | Cho vào thùng rác + đánh dấu chỉ mục (`retired_reason = trashed`). Khoá không có → không làm gì |
  | `deleteDirectory($d)` | Như `delete` cho **đúng** các khoá sống dưới tiền tố `<d>/`; không có → không làm gì |
  | `createDirectory`, `setVisibility` | Không làm gì (thư mục là ảo). `visibility()` trả `private` |
  | `move` | Đổi tên trên Drive (giữ thế hệ) + cập nhật chỉ mục. Thư viện media gọi khi `file_name` đổi |
  | `copy` | `files.copy` + dòng chỉ mục mới |
  | `checksum` | Chỉ `md5`, hỏi Google. Thuật toán khác → `UnableToProvideChecksum` |

- [x] Khoá chứa `~`, `..`, ký tự điều khiển, hoặc bắt đầu bằng `/` → từ chối.
- [x] Log: phương thức, đường endpoint, mã trạng thái, `reason`, lần thử. **Không** header, không token, không thân phản hồi token, không nội dung khoá.
- [ ] Test sống (R7): ghi 10 MB ngẫu nhiên với khối 4 MiB, kiểm checksum, đọc lại so byte, liệt kê, cho vào thùng rác, xác nhận `trashed`, đọc `drives.get` và `permissions.list`. **Task 2: đã viết `tests/Feature/Storage/GoogleDriveLiveTest.php`, tự bỏ qua khi thiếu biến `DRIVE_LIVE_*`; lượt chạy thật là PENDING OWNER (Task 8 Phần 2, phán quyết C2).**

**Test bắt buộc** (`Http::fake()`, mỗi trường hợp một `it()`):
- Token:
  - JWT có `iss` là `client_email`, `scope` đúng, `aud` = endpoint token, `exp − iat ≤ 3600`;
  - chữ ký kiểm được bằng khoá công khai **sinh lúc chạy** (`openssl_pkey_new`);
  - token được cache; 401 làm mới đúng một lần.
- Mọi request đã ghi có `supportsAllDrives=true` (`Http::assertSent` duyệt hết).
  - Không request nào có `fields` chứa chữ `Link` hay `permissions`.
  - **Không** request `POST`/`PATCH`/`DELETE` nào tới `/permissions`.
  - Thêm test cấu trúc: chuỗi `/permissions` chỉ xuất hiện ở `drivePermissions()`, với `GET`.
- Upload:
  - 3 khối;
  - 308 rồi lỗi kết nối rồi tiếp đúng byte;
  - md5 lệch → `files.update trashed=true` được gửi và ngoại lệ ném ra;
  - tệp 0 byte;
  - `GOOGLE_DRIVE_CHUNK_MB` trống, 0, âm hay lớn hơn 64 → rơi về 8 (thành ngữ `?:` + `max`), và mọi `Content-Range` gửi đi (trừ khối cuối) có độ dài chia hết cho 262144.
- Ghi đè một khoá đã có → `UnableToWriteFile`, không request nào gửi đi.
- Ghi lại một khoá mà bản trước đã vào thùng rác → tên gửi đi có `~g2`; `DriveObjectName` đọc ngược đúng `{key, 2}`; dataset tên lạ (`preflight~x.txt`, `18~ABC….pdf` viết hoa, `18~…~g1.pdf`, `18~…~g01.pdf`) → `null`.
- Hai lượt ghi cùng tháng chỉ tạo **một** thư mục tháng.
- **Tiền tố thư mục:**
  - chỉ mục có media 18, 180, 1800 và 18 có cả `18/conversions/x.jpg`;
  - `deleteDirectory('18/')` gửi **đúng một** `files.update trashed=true`, cho đúng tệp của 18;
  - `allFiles('18/')` chỉ trả khoá của 18;
  - sau đó `fileExists` của 180 và 1800 vẫn `true`;
  - khoá có `%` hay `_` trong tiền tố không khớp nhầm.
  - Mutation probe: bỏ `/` khỏi tiền tố → đỏ.
- Bảng phân loại lỗi (dataset):
  - 429, 500, 503, 403 `rateLimitExceeded` → thử lại;
  - 403 `storageQuotaExceeded`, `teamDriveFileLimitExceeded`, `insufficientFilePermissions` → `DocumentStorageMisconfigured`, không thử lại;
  - 404 tệp → `StoredFileMissing`.
- Ngắt mạch:
  - ba lỗi đọc rồi lời gọi thứ tư ném ngay, `Http::assertSentCount` không tăng; sau 60 giây (`travel`) thì thử lại;
  - ba lỗi **khối tải lên** trong phạm vi `job` không mở mạch nào;
  - ba lỗi đọc trong phạm vi `job` không mở mạch `web`.
  - Mỗi vế một probe.
- `delete` gửi `trashed=true`, **không** `DELETE`; chỉ mục có `object_key = NULL`, `former_key` = khoá cũ, `retired_reason = trashed`.
- Log của một lỗi 401/500 không chứa `Bearer`, `access_token`, `private_key`, `-----BEGIN`.
- `fileExists`, `fileSize`, `mimeType`, `listContents` không gửi request nào.

Commit: `feat: M14 Task 2 — adapter Flysystem cho Google Drive: khoá mờ có số thế hệ, thư mục là tiền tố, chỉ mục, tải lên resumable kiểm md5, thùng rác thay xoá, thử lại, ngắt mạch tách web và job, không link và không quyền chia sẻ nào`.

### - [x] Task 3 — Ghi qua vùng đệm, đẩy lên kho, dọn bản cục bộ theo biên nhận (R2, R10)

**Files:**
- `app/Listeners/QueueDocumentFilePush.php`, `app/Listeners/DiscardStagedCopyOnMediaDeleted.php`;
- `app/Jobs/PushDocumentFile.php`;
- `app/Actions/Storage/PushDocumentFileToRemote.php` (dùng `app/Enums/PushOutcome.php` của Task 1);
- `app/Actions/Schedule/PushPendingDocumentFiles.php`, `app/Actions/Schedule/PurgeStagedDocumentCopies.php`;
- `routes/console.php` (nối thêm);
- `lang/vi/activity.php`;
- tests.

**Interfaces:**
```php
final class PushDocumentFileToRemote {
    public function handle(int $mediaId, ?\Carbon\CarbonInterface $keepLocalUntil = null): \App\Enums\PushOutcome;
}
```

- [x] Action theo đúng sáu bước của R2.
  - `keepLocalUntil` mặc định `now() + staging_grace_hours`; lệnh chuyển tệp truyền `+30 ngày`.
  - Không lấy được `pushLock()` → `Locked`.
  - Tệp vùng đệm không còn mà media vẫn ở `private` → **không đổi đĩa**, log `critical`, `StoredFileMissing`.
  - `first_transfer_at` chỉ trên production, chỉ ở `Pushed`, không ghi đè.
- [x] Job `PushDocumentFile`:
  - kết nối và hàng `storage`; `$timeout = 1800`, `$tries = 4`, `backoff = [60, 300, 900]`, `$failOnTimeout = true`;
  - `Locked` → `release(120)`; `DocumentStorageUnavailable` → `release(60)`;
  - `DocumentStorageMisconfigured` → `fail()`, cảnh báo đi qua kiểm tra sức khoẻ (Task 5).
- [x] Listener `created` theo R2: điều kiện là `DocumentStore::pushesNewFiles()` **và** `disk = private`.
- [x] `DiscardStagedCopyOnMediaDeleted` theo R2: `DB::afterCommit`, đọc lại sự tồn tại của dòng, chỉ khi `disk` khác `private`.
- [x] Mục lịch, mỗi mục một `->name()`, `withoutOverlapping(<phút>)` có hạn, không mục nào 1440:
  - `queue.storage` mỗi phút: `queue:work storage --queue=storage --stop-when-empty --max-time=50 --timeout=1800`, `withoutOverlapping(40)`, `runInBackground()`;
  - `storage.push-pending` 15 phút một lần, `withoutOverlapping(15)`;
  - `storage.purge-staged` mỗi giờ, `withoutOverlapping(60)`.
- [x] `PushPendingDocumentFiles` theo R2: cận dưới `created_at >= remote_enabled_at`; cận trên 10 phút; công tắc không phải `google_drive` mà còn mốc thì xoá mốc, audit, không xếp gì.
- [x] `PurgeStagedDocumentCopies`: bốn điều kiện của R10, đọc lại từng dòng dưới `pushLock()`. Xoá `private/<media_id>/`, đặt `local_purge_after = NULL` bằng UPDATE có điều kiện `disk = 'documents_remote'`.
  - **Không bao giờ** chạm đĩa kho (test cấu trúc: lớp này không gọi `DocumentStore::remote()` và không dùng `Http`).
  - Không lấy được khoá thì bỏ qua dòng đó tới lượt sau.

**Test bắt buộc:**
- `DOCUMENT_STORAGE=local`: tải lên như hôm nay, **không** job nào được dispatch (`Queue::fake`).
- `google_drive` **chưa có mốc**: không job nào. Có mốc, qua Livewire thật của `DocumentsRelationManager` và trang nộp của cổng khách: job dispatch **sau commit**; transaction rollback (ép lỗi sau `addMedia`) → không job nào. Probe cho vế mốc.
- **FileGuard và quét virus vẫn chặn trước**: tệp `.svg`, tệp `.pdf` mang MIME `application/x-dosexec`, tệp bị `VirusScanner` giả từ chối → không media, không job, đĩa kho giả rỗng.
- Đẩy thành công:
  - tệp có trên đĩa kho giả, đúng byte;
  - `media.disk = documents_remote`; hai checksum đúng;
  - `local_purge_after = now()+24h`; bản cục bộ **vẫn còn**.
- md5 trên kho lệch (đĩa giả bị sửa giữa chừng bằng một decorator) → không đổi đĩa, bản trên kho bị xoá (`retired_reason = trashed`), job thử lại; lượt sau ghi thế hệ 2.
- Khoá lệch khuôn R4 → không đẩy, log `critical`, media còn ở `private`.
- Chạy hai lần → `AlreadyRemote`, không ghi lần hai. Media bị xoá trước khi đổi đĩa → `Gone`, bản trên kho bị xoá.
- Hai job cùng một media (khoá) → đúng một lần tải lên; job thứ hai `release(120)`.
- Lượt tải đã nạp `disk = private` trước khi đổi đĩa vẫn trả đúng byte sau khi đổi (bản trong thời gian ân hạn).
- `first_transfer_at`:
  - production + `Pushed` lần đầu → ghi;
  - lần hai → không đổi;
  - `APP_ENV=testing` → không ghi;
  - `AlreadyRemote` → không ghi.
  - Mỗi vế một probe.
- **Dọn**, mỗi điều kiện một test riêng chỉ sai đúng điều đó → giữ, kèm mutation probe:
  - `disk = private` dù `local_purge_after` đã qua và biên nhận khớp (trạng thái mà bản quay lui cũ để lại);
  - chưa tới `local_purge_after`;
  - `local_purge_after` là `NULL`;
  - không có dòng chỉ mục sống;
  - dòng chỉ mục ở `drive_id` khác;
  - md5 chỉ mục khác `checksum_md5`;
  - `office_copied_at` là `NULL`;
  - `office_copied_at` mới hơn biên độ.

  Đủ bốn điều → xoá, cột về `NULL`.
- **Dọn kiểm lại dưới khoá**: dòng là ứng viên lúc truy vấn, nhưng đổi về `private` trước khi lấy được khoá (móc ở bước lấy khoá) → giữ. Probe: bỏ lần đọc lại → đỏ.
- Media bị xoá (gói bàn giao cũ) trong transaction đã commit, khi còn bản cục bộ → bản cục bộ bị xoá theo. Xoá trong một transaction **rollback** → bản cục bộ còn, dòng `media` còn. Probe: bỏ `afterCommit` → đỏ.
- `PushPendingDocumentFiles`:
  - xếp media ở `private` tạo sau mốc và quá 10 phút;
  - **không** xếp media tạo trước mốc (tệp cũ);
  - không xếp media dưới 10 phút;
  - không xếp gì khi công tắc là `local`;
  - `local` + còn mốc → mốc bị xoá + audit `document_store_disabled_observed`.
  - Mỗi vế một probe.
- Mục lịch: tên, tần suất, khoá chồng lấn (khuôn `QueueHandoverScheduleTest`); `retry_after` > `$timeout`; TTL khoá > `$timeout`.

Commit: `feat: M14 Task 3 — ghi qua vùng đệm: job đẩy tệp lên kho sau commit trên hàng đợi storage, chỉ cho tệp tạo sau mốc bật kho, khoá đẩy có hạn, kiểm md5 rồi đổi đĩa có điều kiện, dọn bản cục bộ chỉ khi media ở kho và có biên nhận văn phòng khớp md5`.

### - [x] Task 4 — Đọc qua CRM: route tải, gói bàn giao, xoá, khi kho sập (R3, R8, R9, R12)

**Files:**
- `app/Http/Controllers/DocumentDownloadController.php`;
- `app/Actions/Storage/OpenStoredFile.php`, `app/Actions/Storage/MaterialiseStoredFile.php`, `app/Support/Storage/StoredFileStream.php`, `app/Support/Files/FreeSpace.php`;
- `app/Actions/Matter/CollectHandoverEntries.php`, `app/Actions/Matter/BuildHandoverPackage.php`, `app/Support/Handover/HandoverEntry.php`, `app/Exceptions/HandoverPackageFailed.php`;
- `app/Jobs/GenerateHandoverPackage.php` (`TIMEOUT_SECONDS`, docblock), `app/Actions/Matter/RequestHandoverPackage.php` (`STALE_AFTER_MINUTES`, docblock), `config/queue.php` (`retry_after` của `handover`, docblock), `routes/console.php` (`--timeout`, `withoutOverlapping` của `queue.handover`, docblock);
- `resources/views/errors/storage-unavailable.blade.php`, `bootstrap/app.php` (render `DocumentStorageUnavailable` → 503);
- `lang/vi/storage.php`, `lang/vi/handover.php`;
- `tests/Support/SpyFilesystem.php` (decorator đếm lời gọi), `tests/Feature/Schedule/QueueHandoverScheduleTest.php`, tests.

**Interfaces:**
```php
final class StoredFileStream { public function __construct(public $resource, public int $size, public ?string $mimeType) {} }
final class OpenStoredFile { public function handle(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): StoredFileStream; } // ném DocumentStorageUnavailable | StoredFileMissing
final class MaterialiseStoredFile { public function handle(Media $media, string $targetPath): string; } // trả đường dẫn cục bộ đã kiểm size/md5
final class FreeSpace { public function bytes(string $path): ?int; } // null khi disk_free_space bị tắt hoặc trả false
```

- [x] Controller theo thứ tự R3.
  - Nhánh `HEAD` trả header từ `media`, không mở luồng. `StoredFileMissing` → 404 + log `critical`.
  - Giữ nguyên tên tải (`staffDownloadName`, `portalDownloadName`) và các header.
  - Một helper `Content-Disposition` dùng chung, có bản dự phòng `Str::ascii` rồi bỏ `%`.
- [x] Trang 503 render cho **cả** request của panel admin lẫn cổng khách, có `Retry-After: 120`. Câu chữ từ `lang/vi/storage.php`; hotline qua `OfficeProfile`.
- [x] Gói bàn giao theo R12:
  - hai lý do mới trong `HandoverPackageFailed`: `insufficientWorkSpace`, `storageUnavailable`, câu tiếng Việt cho luật sư;
  - xoá `src/` trước `store()`;
  - đo chỗ trống qua `FreeSpace`, bỏ qua khi hàm bị tắt;
  - năm số thời gian của bảng R12, sửa docblock cả bốn tệp.
- [x] Một trợ giúp test `pushToRemote(Media $media)` gọi **Action thật** của Task 3 trên đĩa giả, để các bộ test hiện có chạy được với media đã ở trên kho.
- [x] Một trợ giúp test `bindRealDriveAdapter(array $indexRows)`: adapter thật, `Http::fake()` + `Http::preventStrayRequests()`, `DriveTokenProvider` giả không gọi HTTP.

**Test bắt buộc:**
- **Bộ hồi quy với media trên kho** (dataset `['local', 'remote']`, phần `remote` dùng `pushToRemote()`): `DocumentDownloadTest`, `HandoverPackageDownloadTest`, `RetractedDocumentNoticeTest`, cùng các test nhóm D và cách ly khách của SPEC §11. Danh sách tệp tường minh trong báo cáo.
- **Không chạm kho trước khi kiểm quyền.** Các nhánh từ chối:
  - chữ ký hỏng hoặc hết hạn;
  - sai người nhận;
  - khách xin tài liệu nhóm D;
  - nhân sự ngoài đội ngũ xin tài liệu vụ `restricted`;
  - khách xin tài liệu khách khác;
  - tài liệu đã rút;
  - khách đã bị vô hiệu hoá.

  Mỗi nhánh chạy hai lần:
  - (a) với `SpyFilesystem` làm `documents_remote`: **0** lời gọi mọi loại;
  - (b) với `bindRealDriveAdapter()`: `Http::assertNothingSent()`.

  Cặp dương:
  - cùng media, người có quyền: (a) đúng một `readStream`;
  - (b) đúng một request, là `GET` có `alt=media`.

  **Mutation probe:** chuyển `OpenStoredFile` lên trước `Gate::allows` → (a) và (b) đều đỏ; dán bằng chứng.
- `HEAD` của người có quyền: chỉ `fileExists` (spy), không `readStream`; với adapter thật không request nào; không dòng nhật ký.
- Kho sập (decorator đĩa giả ném `DocumentStorageUnavailable`):
  - nhân sự và khách đều nhận 503, trang tiếng Việt, `Retry-After`;
  - **không** dòng `document_downloads`, không audit `document_downloaded`, không `data_exported`.
- Kho trả 404 → 404, không dòng nhật ký, có log `critical`.
- Thành công:
  - đúng byte; `Content-Length = media.size`; `Content-Type = media.mime_type`;
  - `Cache-Control` và `nosniff` như cũ;
  - đúng một dòng `document_downloads`.
- **Tên tải trên nhánh kho:**
  - tiêu đề tiếng Việt có dấu → `Content-Disposition` có cả `filename=` ASCII lẫn `filename*=UTF-8''…`, không ngoại lệ;
  - tiêu đề có `%` → không ngoại lệ;
  - tiêu đề toàn chữ Hán (bản dự phòng chỉ còn đuôi) → không ngoại lệ.
  - Probe: bỏ bản dự phòng → đỏ.
- Gói bàn giao từ media trên kho:
  - giải nén tệp thật bằng `ZipArchive`; tên tiếng Việt có dấu còn nguyên; nhóm D, xoá mềm, B còn nháp, đã rút đều vắng mặt (giữ khuôn M7 R2: khẳng định trên zip đã ghi, không trên mảng đầu vào);
  - thiếu chỗ trống (`FreeSpace` giả trả `2T + 50 MB − 1`) → lỗi tiếng Việt, không zip dở. Đúng biên `2T + 50 MB` → được. Probe cho biên;
  - `FreeSpace` trả `null` (hàm bị tắt) → gói **vẫn** dựng được, có log `warning`. Chạy cả ở chế độ `local`;
  - `work_dir/src` không còn lúc medialibrary chép zip (móc vào lúc `store()` bắt đầu). Probe: dời bước xoá xuống sau `store()` → đỏ;
  - kho sập giữa lúc tải về → `storageUnavailable`, thư mục làm việc bị xoá, bấm sinh lại được;
  - md5 tải về lệch → lỗi, không zip.
- `QueueHandoverScheduleTest`: ghim `TIMEOUT_SECONDS` 1200, `retry_after` 1500, `--timeout=1200`, `withoutOverlapping(25)`, `STALE_AFTER_MINUTES` 90, cùng ba quan hệ của R12. Test `RequestHandoverPackageTest` về kẹt dùng hằng mới.
- Sinh lại gói:
  - bản trước **không ai tham chiếu** → bản trên kho bị cho vào thùng rác;
  - bản trước bị **chứng từ tiền tham chiếu** (M9), hoặc **khách đã tải** → bản trên kho còn nguyên.
- Rút tài liệu trên kho: tệp không bị chạm; đường tải ký phát trước lúc rút trả 404, không gọi Drive (adapter thật).
- Test cấu trúc: không chỗ nào trong `app/` gọi `temporaryUrl(`, `->url(` trên đĩa kho, hay chứa `drive.google.com`, `webViewLink`, `webContentLink`.

Commit: `feat: M14 Task 4 — đọc qua CRM: route tải mở luồng sau khi kiểm quyền và trước khi ghi nhật ký (khoá bằng đĩa gián điệp và adapter thật), tên tải có bản dự phòng ASCII, trang 503 tiếng Việt khi kho sập, gói bàn giao tải tệp về thư mục làm việc, xoá nguồn trước khi lưu, kiểm chỗ trống khi đo được, thời gian job tính lại`.

### - [ ] Task 5 — Sẵn sàng, preflight, kiểm tra sức khoẻ, trang "Kho tài liệu", hướng dẫn chủ văn phòng, dàn ý hồ sơ pháp lý (R5, R6, R7, R13)

Hướng dẫn và kiểm tra viết **cùng một task**, để mỗi bước của chủ văn phòng có đúng một dòng kiểm lại nó.

**Files:**
- `app/Actions/Storage/StorageReadiness.php`, `app/Support/Storage/CredentialFileInspector.php`;
- `app/Actions/Deployment/RunPreflight.php` (nối thêm dòng);
- `app/Actions/Storage/InspectDriveSharing.php`, `InitialiseDocumentStore.php`, `RecordDataTransferDossier.php`;
- `app/Actions/Schedule/CheckDocumentStoreHealth.php`;
- `app/Console/Commands/StorageInitCommand.php`, `StorageCheckCommand.php`;
- `app/Filament/Admin/Pages/DocumentStorePage.php` + view;
- `app/Filament/Admin/Widgets/SystemHealthWidget.php` + view;
- `app/Mail/Staff/DocumentStoreAlert.php` + view;
- `routes/console.php`;
- `lang/vi/preflight.php`, `lang/vi/storage.php`, `lang/vi/activity.php`;
- `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md` (Phụ lục A + C, sổ tay huỷ của R15), `docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md` (Phụ lục B);
- tests.

**Interfaces:**
```php
final class StorageReadiness {
    /** @return list<PreflightRow> các dòng sẵn sàng, ở MỌI APP_ENV */
    public function rows(): array;
    /** @return list<PreflightRow> các dòng trạng thái (bật kho, tồn đọng, bản văn phòng, hồ sơ, số mục...) */
    public function stateRows(): array;
    public function isReady(): bool; // không dòng ĐỎ nào trong rows()
}
```

- [ ] **Dòng sẵn sàng** (`StorageReadiness::rows()`, mọi môi trường). Preflight production nối chúng vào `launchConditionRows()` khi công tắc là `google_drive` **hoặc** đã có media trên kho; dòng `document_storage_driver` luôn có.

  | Khoá | Mức |
  |---|---|
  | `document_storage_driver` | ĐỎ khi giá trị lạ |
  | `drive_credentials` | R6 (cả biến thể shared hosting) |
  | `drive_http_client` | ĐỎ khi thiếu cả `curl` lẫn `allow_url_fopen` |
  | `drive_reachable` | ĐỎ, câu gợi ý: đồng hồ máy chủ (NTP), tường lửa chặn `oauth2.googleapis.com`/`www.googleapis.com`, sai mã Shared Drive |
  | `drive_sharing` | R5 |
  | `drive_root_folder` | R5 |
  | `drive_roundtrip` | ĐỎ. Ghi một tệp thăm dò 1 KiB dưới khoá `preflight/<ngẫu nhiên>.txt`, kiểm md5, đọc lại, cho vào thùng rác; luôn dọn trong `finally`, như `storagePrivateExposureRow()` |

- [ ] **Dòng trạng thái** (`StorageReadiness::stateRows()`; preflight production và `vkcrm:storage:check`):

  | Khoá | Mức |
  |---|---|
  | `document_storage_enabled` | ĐỎ khi công tắc `google_drive` mà chưa có mốc (`enable` chưa chạy): tệp mới vẫn nằm trên máy chủ trong khi người vận hành tin chúng ở trên kho |
  | `drive_item_count` | VÀNG từ 300.000 mục |
  | `document_push_backlog` | VÀNG khi có media ở `private` tạo **sau mốc** và quá `push_alert_minutes`. Tệp cũ chờ chuyển được in thành số riêng, không làm dòng này vàng |
  | `document_office_copy` | VÀNG khi chưa cấu hình `office.receipts_path` ("chưa có máy văn phòng: vùng đệm giữ mọi tệp"), khi biên nhận gần nhất quá `office.max_age_hours`, hoặc khi `last_office_receipt_error` khác rỗng. Kèm số media trên kho chưa có biên nhận |
  | `data_transfer_dossier` | Chỉ production, theo R13: ĐỎ khi công tắc `google_drive` và chưa có ngày hồ sơ lẫn ý kiến cho chuyển trước; VÀNG từ ngày 45 kể từ `first_transfer_at` khi chưa có ngày hồ sơ; ĐỎ quá ngày 60 |
  | `media_on_remote_while_local` | VÀNG khi công tắc `local` mà còn media trên kho |
  | `disk_free_space_available` | VÀNG khi `FreeSpace` trả `null` (gói bàn giao không kiểm được chỗ trống), mọi chế độ |

- [ ] `vkcrm:storage:check`: in cả hai nhóm dòng ở **mọi** `APP_ENV`; mã thoát giống `vkcrm:preflight`. Đây là thứ Task 8 dùng ở làn, nơi `APP_ENV` không phải `production`.
- [ ] `CheckDocumentStoreHealth` mỗi giờ (`storage.health`, `withoutOverlapping(30)`):
  - chạy `InspectDriveSharing` (khi có media trên kho hoặc công tắc `google_drive`), đếm tồn đọng, độ tươi biên nhận văn phòng, số mục, đồng hồ hồ sơ;
  - ghi các cột `document_store_*` của `system_health`;
  - đổi sang `degraded`/`unavailable`/`misconfigured`, hoặc tới ngày 45 của đồng hồ hồ sơ → thư `staff.document_store_alert` tới người nhận của `ResolveBackupNotificationRecipients` (cùng người vận hành nhận thư sao lưu). Xếp hàng sau commit; chống trùng theo loại sự cố mỗi ngày qua `outbound_messages`, chỉ tính `status = sent`;
  - loại sự cố: `sharing_drift`, `unavailable`, `misconfigured`, `not_enabled`, `push_backlog`, `office_copy_stale`, `office_copy_error`, `transfer_dossier_due`;
  - thư chỉ có số đếm và loại sự cố, không mã tệp, không tiêu đề.
- [ ] `SystemHealthWidget`: một dòng đỏ khi trạng thái kho khác `ok`. Chỉ người có `settings.manage` thấy dòng này; dòng heartbeat sẵn có giữ nguyên.
- [ ] `vkcrm:storage:init`: tạo thư mục gốc `vkcrm-<APP_ENV>` trong Shared Drive, in mã để điền `GOOGLE_DRIVE_ROOT_FOLDER_ID`. Có thư mục cùng tên thì liệt kê và dừng, không tạo thêm. Audit `document_store_initialised`.
- [ ] Trang "Kho tài liệu" (admin):
  - `canAccess()` hỏi `Gate::forUser()->allows('settings.manage')`, `abort(404)` ở `mount()` và ở action lưu;
  - hiện:
    - chế độ, mốc bật kho, trạng thái, lúc kiểm gần nhất;
    - số tệp mới chờ đẩy và tệp cũ nhất, số tệp cũ chờ chuyển;
    - số bản cục bộ còn giữ, số media trên kho chưa có biên nhận văn phòng, biên nhận gần nhất;
    - số mục so với 400.000;
    - lần chuyển đầu tiên và số ngày còn lại của đồng hồ 60 ngày;
  - form R13 gọi `RecordDataTransferDossier`: `maxLength(100)` cho mã hồ sơ, `maxLength(200)` cho căn cứ ý kiến luật sư.
- [ ] Hai tài liệu cho chủ văn phòng:
  - chép Phụ lục A, C và sổ tay huỷ (R15) vào `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`;
  - chép Phụ lục B vào `docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md`.

  Mỗi bước của Phụ lục A ghi tên dòng kiểm nó.

**Test bắt buộc:**
- Mỗi dòng sẵn sàng và trạng thái có test ĐỎ/VÀNG/XANH tương ứng (`Http::fake` cho Drive), **chạy với `APP_ENV=testing`** qua `StorageReadiness` và qua `vkcrm:storage:check`. Preflight production chỉ cần một test rằng nó nối đúng các dòng đó.
- Mỗi điều kiện của `InspectDriveSharing` một test, kèm mutation probe:
  - quyền `anyone`;
  - quyền `domain`;
  - thành viên lạ;
  - thành viên trong danh sách mang vai khác vai đã khai;
  - vai của tài khoản dịch vụ, dataset `organizer` / `writer` / `reader` / `commenter` → ĐỎ, `fileOrganizer` → XANH;
  - `driveMembersOnly = false`;
  - `sharingFoldersRequiresOrganizerPermission = false` (VÀNG);
  - `domainUsersOnly = false` (VÀNG).
- `drive_credentials` (với `CredentialFileInspector` giả cho chủ, nhóm và thành viên):
  - ĐỎ:
    - tệp nằm dưới `base_path()`;
    - tệp dưới một thư mục `public_html`;
    - quyền `0644`;
    - quyền `0460` (nhóm ghi được);
    - JSON thiếu `private_key`;
    - đường dẫn trống.
  - VÀNG:
    - `0440` với nhóm có thành viên khác;
    - `0440` khi thiếu `posix`.
  - XANH:
    - `0400` của chính người dùng;
    - `0440` với nhóm riêng.
  - Mỗi vế một probe.
- Công tắc `local` và không có media trên kho → không dòng kho nào trong preflight production, trừ `document_storage_driver` và `disk_free_space_available`.
- `document_storage_enabled`: `google_drive` không mốc → ĐỎ; có mốc → XANH.
- `document_push_backlog`: một tệp cũ (tạo trước mốc) ở `private` không làm dòng này vàng; một tệp tạo sau mốc quá hạn thì có. Probe.
- `data_transfer_dossier`:
  - production + `google_drive`, không ngày, không ý kiến → ĐỎ;
  - có ý kiến cho chuyển trước → XANH;
  - có ngày hồ sơ → XANH;
  - `first_transfer_at` + 44 ngày → XANH, + 45 → VÀNG, + 61 → ĐỎ (khi chưa có ngày hồ sơ);
  - ngoài production → không dòng.
  - Mỗi vế một probe.
- Kiểm tra sức khoẻ:
  - thành viên lạ xuất hiện giữa hai lần chạy → `misconfigured` + **một** thư;
  - chạy lại cùng ngày → không thư thứ hai;
  - ngày 45 → thư `transfer_dossier_due`;
  - máy chủ thư hỏng → dòng `failed`, không ném lỗi ra scheduler.
- Trang "Kho tài liệu":
  - luật sư, trưởng phòng, trợ lý, kế toán → 404, kể cả gọi thẳng action lưu qua Livewire;
  - admin lưu ngày → audit `data_transfer_dossier_recorded`;
  - mã 101 ký tự, căn cứ 201 ký tự → lỗi validation tiếng Việt (chạy thêm `test:mariadb`);
  - sau khi lưu, `data_transfer_dossier` thành XANH.
- `ActivityLogEventTranslationsTest`: mọi sự kiện mới có nhãn.
- Thư và trang không chứa tiêu đề tài liệu, mã hồ sơ, tên khách hay `file_id` (chuỗi đánh dấu đặt vào các trường đó).

Commit: `feat: M14 Task 5 — StorageReadiness chạy ở mọi môi trường, preflight và kiểm tra sức khoẻ kho mỗi giờ (chia sẻ chỉ thành viên, vai fileOrganizer, khoá dịch vụ kể cả trên shared hosting, mốc bật kho, tồn đọng, bản văn phòng, đồng hồ hồ sơ 60 ngày), trang Kho tài liệu, hướng dẫn chủ văn phòng và dàn ý hồ sơ chuyển dữ liệu ra nước ngoài`.

### - [ ] Task 6 — Bật kho, chuyển tệp cũ, kiểm, quay lui, dựng lại chỉ mục, liệt kê tệp cần huỷ (R11, R13, R15)

**Files:**
- `app/Actions/Storage/EnableRemoteDocumentStore.php`, `MigrateDocumentsToRemote.php`, `PullDocumentsToLocal.php`, `VerifyRemoteDocuments.php`, `RebuildDriveIndex.php`, `ListRemoteOrphans.php`, `ListMatterFilesForDestruction.php`;
- `app/Console/Commands/StorageEnableCommand.php`, `StorageMigrateCommand.php`, `StorageVerifyCommand.php`, `StorageRollbackCommand.php`, `StorageReindexCommand.php`, `StorageOrphansCommand.php`, `StorageDestructionListCommand.php`;
- `app/Actions/Matter/RecordMatterDestruction.php` (chỉ docblock: trỏ tới R15);
- `lang/vi/storage.php`, `lang/vi/activity.php`;
- tests.

- [ ] Lệnh chỉ là vỏ: đọc tuỳ chọn, gọi Action, in kết quả tiếng Việt, chọn mã thoát (0 xong; 1 có tệp lỗi; 2 điều kiện tiên quyết không đạt).
- [ ] `enable` theo R11 và cổng R13. `migrate` từ chối khi chưa bật hoặc `StorageReadiness::isReady()` sai, trừ `--dry-run`.
- [ ] `rollback` theo R11: cổng **duy nhất** là công tắc `local`; Drive chỉ cần cho media không còn bản cục bộ; khoá từng media; UPDATE có điều kiện xoá hai mốc; xoá mốc bật kho.
- [ ] Mỗi lượt `migrate`/`rollback` ghi **một** audit tổng (`document_store_migration_run`, `document_store_rollback_run`): số tệp, số byte, số lỗi, thời gian. Không danh sách tệp.
- [ ] `reindex` theo R11: `--drive`/`--root` phải bằng cấu hình; dòng ở drive khác thành `superseded`; dựng lại `drive_folders`; chọn bản trùng có md5 khớp, báo phần còn lại; tên lạ thì báo. `--dry-run` in khác biệt. Không bao giờ xoá dòng nào.
- [ ] `orphans`: thêm nhóm "trùng tên" và nhóm "vụ đã ghi huỷ".
- [ ] `destruction-list` theo R15.
- [ ] Sổ tay chuyển đổi (Phụ lục C) cập nhật theo tên lệnh và tuỳ chọn thật.

**Test bắt buộc:**
- `enable`:
  - công tắc `local` → mã 2, không mốc;
  - `StorageReadiness` có ĐỎ → mã 2;
  - production thiếu cổng R13 → mã 2;
  - đạt → mốc + audit;
  - chạy lại → mốc **không** đổi.
  - Mỗi vế một probe.
- `--dry-run`: không media nào đổi, đĩa kho giả rỗng, không dòng chỉ mục, không audit; in số tệp, số byte, ước thời gian, chỗ trống.
- Chuyển theo thứ tự id; `--limit` và `--max-minutes` (đồng hồ giả) dừng đúng chỗ; chạy lần hai chỉ làm phần còn lại.
- Tệp đã có trên kho (chỉ mục + md5 khớp) → không tải lần hai; md5 lệch → cho vào thùng rác rồi tải lại với thế hệ 2.
- Tệp cục bộ mất → báo, không đổi đĩa, mã thoát 1, các tệp khác vẫn được chuyển.
- **Song song với lưu lượng thật**: giữa hai lô, một tải lên mới qua Livewire (đi đường job) và một lượt tải xuống một media vừa đổi đĩa đều đúng.
- `local_purge_after = +30 ngày` cho tệp chuyển bằng lệnh.
- **Quay lui:**
  - công tắc `google_drive` → **mã 2, không đổi gì** (probe);
  - còn bản cục bộ → đổi về `private`, không đọc kho, `local_purge_after` và `remote_pushed_at` về `NULL` (probe cho từng cột);
  - bản cục bộ đã dọn → tải về, kiểm md5, rồi đổi; md5 lệch → không đổi, báo lỗi;
  - Drive không tới được (`Http::fake` lỗi kết nối) → media có bản cục bộ vẫn quay lui, media cần tải về bị bỏ qua, mã thoát 1;
  - `drive_sharing` ĐỎ (thành viên lạ) → quay lui vẫn chạy;
  - mốc bật kho bị xoá.
  - Bản trên kho và chỉ mục còn nguyên sau quay lui; chuyển lại lần nữa không tải lần hai.
- **Quay lui không tự đảo ngược**: sau quay lui, `PushPendingDocumentFiles` không xếp gì. Đặt lại `google_drive` mà chưa `enable` → vẫn không. Sau `enable` mới → vẫn không xếp media đã quay lui (tạo trước mốc mới); chỉ `migrate` chuyển chúng.
- **Quay lui rồi dọn**: quay lui → `ImportOfficeReceipts` nhập một biên nhận mới khớp (Task 7, hoặc đặt `office_copied_at` trực tiếp nếu Task 7 chưa có) → `PurgeStagedDocumentCopies` → tệp **còn**, tải xuống trả đúng byte.
- `verify --all` bắt được một tệp đã vào thùng rác và một tệp bị đổi nội dung (decorator đĩa giả); tệp của vụ đã ghi huỷ ở nhóm riêng, mã thoát 0 nếu chỉ có nhóm đó.
- `reindex` (`Http::fake` liệt kê thư mục tháng):
  - dựng lại đúng khoá và thế hệ;
  - `--drive` khác cấu hình → mã 2;
  - drive mới chứa khoá đang sống ở drive cũ → dòng cũ thành `superseded`, dòng mới sống, không vi phạm unique;
  - `drive_folders` có thư mục tháng của gốc mới, và một lượt đẩy sau đó không tạo thư mục tháng thứ hai;
  - hai tệp trùng tên, một khớp md5 → chọn đúng tệp đó, tệp kia được báo;
  - tên lạ bị báo, không ghi.
- `orphans` liệt kê cả ba nhóm; không lệnh xoá nào được gửi.
- `destruction-list`:
  - vụ chưa ghi huỷ → mã 2;
  - `--by` là luật sư → mã 2;
  - admin → in tên Drive (mọi thế hệ, cả dòng đã rời chỉ mục), đường vùng đệm, đường `crypt` theo tháng; audit `matter_storage_destruction_listed`;
  - đầu ra không chứa tiêu đề, mã hồ sơ hay `file_id` (chuỗi đánh dấu).
- `StorageReadiness` chưa xanh → `migrate` mã thoát 2, không ghi gì.

Commit: `feat: M14 Task 6 — bật kho có mốc và cổng pháp lý, chuyển tệp cũ (chạy thử, chạy tiếp, giữ bản cục bộ 30 ngày), kiểm checksum, quay lui chỉ khi đã tắt kho và không tự đảo ngược, dựng lại chỉ mục cho Shared Drive mới, báo tệp mồ côi và trùng tên, liệt kê tệp cần huỷ`.

### - [ ] Task 7 — Bản ở máy chủ văn phòng: kéo về có mã hoá, biên nhận từng tệp, CRM nhập biên nhận (R10)

**Files:**
- `app/Actions/Storage/ImportOfficeReceipts.php`, `app/Support/Storage/OfficeReceipt.php` (đọc và kiểm khuôn);
- `app/Support/Backup/RcloneProcess.php` (thêm `listJson()`, `cat()`, có tham số thời gian chờ riêng);
- `app/Console/Commands/StorageOfficeReceiptsCommand.php`;
- `routes/console.php`;
- `config/backup.php` (chỉ docblock), `config/vkcrm.php`;
- `tools/backup/office-pull.sh`, `tools/backup/restore-drill.sh`;
- `docs/SAO-LUU-KHOI-PHUC.md`;
- tests.

- [ ] `ImportOfficeReceipts`:
  1. `rclone lsjson <office.receipts_path>` (thời gian chờ 120 giây, không dùng mặc định 1800 của sao lưu); lấy các tệp `receipt-*.json` có tên lớn hơn `storage.office_receipt_cursor`, theo thứ tự tên;
  2. với từng tệp: bỏ khi lớn hơn `office.receipt_max_bytes`; `rclone cat`; `OfficeReceipt::parse()` kiểm khuôn:
     - `format = 1`;
     - `kho.team_drive` và `kho.root_folder_id` bằng cấu hình;
     - `started_at` không ở tương lai quá 5 phút;
     - mỗi dòng có tên qua `DriveObjectName::parse`, md5 `^[0-9a-f]{32}$`, cỡ là số nguyên ≥ 0;
  3. sai khuôn hay sai drive → **không** dòng nào được đánh dấu, ghi `last_office_receipt_error`, cursor vẫn tiến qua tệp đó (không kẹt mãi), log `warning`;
  4. mỗi dòng hợp lệ:
     ```sql
     UPDATE drive_objects SET office_copied_at = now()
      WHERE drive_id = ? AND object_key = ? AND generation = ? AND md5 = ? AND office_copied_at IS NULL
     ```
     Đếm khớp, không khớp;
  5. `errors > 0` trong biên nhận → vẫn đánh dấu các dòng khớp, và ghi `last_office_receipt_error` ("máy văn phòng báo N lỗi; có thể có tệp bị đổi trên kho");
  6. ghi `last_office_receipt_at`, cursor;
  7. lỗi `rclone` → `BackupHasFailed('rclone:office-receipts')`, cùng đường thư của M8a.

  Chưa cấu hình → không làm gì, không ném lỗi.
- [ ] Mục lịch `storage.office-receipts` 07:00 hằng ngày, `withoutOverlapping(60)`. Lệnh tay `vkcrm:storage:office-receipts` gọi cùng Action, dưới cùng khoá `Cache::lock('storage-office-receipts', 600)`.
- [ ] `office-pull.sh`, chạy trên máy văn phòng (Linux cron, hoặc Windows Task Scheduler qua Git Bash):
  - khoá chống chạy chồng bằng `mkdir` (có trên mọi nền), ghi PID; khoá của PID đã chết thì gỡ;
  - `rclone copy vkkho: vkoffice:kho --immutable`;
  - danh sách chưa có biên nhận = `rclone lsf -R --files-only vkkho:` trừ `receipted.txt` (`comm -23` trên danh sách đã `sort`);
  - `rclone cryptcheck vkkho: vkoffice:kho --one-way --files-from todo.txt --match match.txt`;
  - `rclone lsjson -R --files-only --hash --hash-type md5 --files-from match.txt vkkho:` → dựng biên nhận JSON (`format`, `kho.team_drive`/`kho.root_folder_id` đọc từ `rclone config show vkkho`, `started_at`, `finished_at`, `errors`, `files`);
  - `rclone copyto` biên nhận lên `vkbackups:office-receipts/<BACKUP_NAME>/receipt-<UTC>.json`;
  - chỉ sau khi đẩy biên nhận thành công mới nối `match.txt` vào `receipted.txt`;
  - kéo archive CSDL: `rclone copy vkbackups:VK-CRM-backups/<BACKUP_NAME>/ <thư mục archive> --immutable`;
  - `--check-monthly`: `cryptcheck` toàn bộ, ghi nhật ký; tệp lệch được đưa vào `errors` của biên nhận kế tiếp;
  - **không bao giờ** `sync`, `move`, `delete`, `deletefile`, `purge`, `rmdir`, `cleanup`.

  Mã thoát khác 0 khi có lỗi; nhật ký ở một tệp cạnh script.
- [ ] `restore-drill.sh` thêm bước: sau khi khôi phục CSDL, `vkcrm:storage:verify --sample=20`.
- [ ] Tài liệu thêm diễn tập "mất kho" trên Shared Drive thử:
  1. `rclone copy vkoffice:kho <kho-mới>:` (crypt giải mã tên và nội dung);
  2. đặt `GOOGLE_DRIVE_SHARED_DRIVE_ID`/`ROOT_FOLDER_ID` mới, `optimize`;
  3. `vkcrm:storage:reindex --drive=<mới> --root=<mới>`;
  4. `verify --all`;
  5. một tải lên mới đi vào thư mục tháng của gốc mới.
- [ ] `docs/SAO-LUU-KHOI-PHUC.md`:
  - bảng "hệ thống sao lưu những gì" viết lại cho chế độ kho;
  - nói thẳng: thùng rác và phiên bản Drive không phải sao lưu; archive đêm chỉ còn vùng đệm khi đã có biên nhận; tệp trên Kho không mã hoá phía văn phòng;
  - mục "Đóng gói bàn giao M7 — có sao lưu lại không?" cập nhật: gói nằm trên kho và ở văn phòng như mọi tệp;
  - thêm Phụ lục D làm hướng dẫn cài máy văn phòng.

**Test bắt buộc** (`Process::fake`):
- argv của mọi lời gọi `RcloneProcess` của M14 **không bao giờ** chứa `sync`, `--delete`, `--delete-before`, `--delete-after`, `--delete-during`, `move`, `purge`, `deletefile`, `rmdir`, `cleanup` (test cấu trúc). Cùng danh sách đó vắng trong văn bản `office-pull.sh`, trừ dòng chú thích nói chúng bị cấm.
- `office-pull.sh`:
  - `bash -n` sạch;
  - có `--immutable` ở mọi `rclone copy`;
  - có `--one-way` ở `cryptcheck`;
  - có khoá chống chạy chồng.
- Nhập biên nhận:
  - hợp lệ → đúng các dòng khớp được đánh dấu;
  - `team_drive` khác → không dòng nào, có lỗi;
  - `root_folder_id` khác → không dòng nào;
  - md5 khác → dòng đó không;
  - thế hệ khác → không;
  - dòng ở `drive_id` khác → không;
  - tên lạ → đếm, không ghi;
  - tệp quá cỡ → bỏ;
  - `started_at` ở tương lai → bỏ;
  - nhập lại cùng tệp → không đổi gì (cursor);
  - `errors > 0` → có lỗi, các dòng khớp vẫn được đánh dấu;
  - lỗi `rclone` → `BackupHasFailed`.
  - Mỗi vế một probe.
- Test cấu trúc: chỉ `ImportOfficeReceipts` ghi `office_copied_at` (quét `app/`).
- Đi trọn đường: đẩy → nhập biên nhận → `travel` qua biên độ và `local_purge_after` → dọn xoá bản cục bộ. Thiếu biên nhận → giữ.
- Chưa cấu hình → không tiến trình nào, `document_office_copy` VÀNG.
- Mục lịch: tên, giờ, khoá chồng lấn, không 1440 (khuôn `BackupScheduleTest`).

Commit: `feat: M14 Task 7 — bản thứ hai ở máy chủ văn phòng: script kéo về bằng rclone chỉ đọc vào remote crypt, kiểm cryptcheck từng tệp, biên nhận ràng vào đúng Shared Drive; CRM nhập biên nhận và chỉ dọn vùng đệm của tệp đã có bản ngoài Google; diễn tập khôi phục kho`.

### - [ ] Task 8 — Nghiệm thu với Shared Drive thật, tài liệu, cổng merge

**Phần 1 — với đĩa giả (bắt buộc xanh):**
- [ ] `/d/vkwt/m14-dev test` cả bộ xanh; `pint --test` sạch; `test:mariadb` xanh, **tuần tự**; `migrate:fresh --seed` và vòng `migrate:reset` → `migrate` trên MariaDB thật.
- [ ] Liệt kê theo tên và chạy: các test SPEC §11 "Tải tệp", "Tài liệu nội bộ", "Cách ly dữ liệu giữa khách hàng", "Bàn giao và lưu trữ", với dataset media trên kho của Task 4.
- [ ] Độ phủ ≥ 80% cho `app/Actions/Storage/`, `app/Support/Storage/`, và policy mới (SPEC §11).

**Phần 2 — với Shared Drive thử (PENDING OWNER được phép).** Chủ văn phòng làm Phụ lục A cho "VK-CRM Kho (thử)", và tạo một thư mục biên nhận thử trên "VK-CRM Backups". Làn chạy bản phục vụ của nó với `DOCUMENT_STORAGE=google_drive` trên CSDL `vk_crm_lane_m14`, dữ liệu seed. `APP_ENV` của làn không phải `production`, nên cổng R13 không áp dụng và mọi kiểm tra đi qua `vkcrm:storage:check`, **không** qua `vkcrm:preflight`. Mỗi bước ghi kết quả và số đo vào PROGRESS:
- [ ] `vkcrm:storage:init`; `vkcrm:storage:check`: mọi dòng sẵn sàng xanh, `document_storage_enabled` ĐỎ (chưa bật) là đúng; `vkcrm:storage:enable`; `check` lại thì dòng đó xanh. `GoogleDriveLiveTest` xanh.
- [ ] `migrate --dry-run` → `migrate` → `verify --all`. Đo tốc độ tải lên/tải xuống (MB/s) và thời gian tới byte đầu.
- [ ] Nhân sự tải một tài liệu; khách tải một tài liệu trên cổng (kể cả trên điện thoại); tên tiếng Việt có dấu tải về đúng. Khách cố tải tài liệu nhóm D bằng cách sửa URL → 404. Dòng `document_downloads` đúng.
- [ ] Sinh gói bàn giao từ vụ mẫu đã kết thúc, giải nén, dán danh sách entry và thời gian dựng.
- [ ] Rút một tài liệu: tệp còn trên Drive, đường cũ trả 404.
- [ ] **Máy văn phòng giả lập** trên máy dev Windows: Git Bash + rclone, remote `vkkho` (`drive.readonly`, tài khoản thử), `vkoffice` = `crypt` trên một thư mục cục bộ, `vkbackups` trỏ thư mục biên nhận thử.
  - Chạy `office-pull.sh`.
  - Chạy `vkcrm:storage:office-receipts` trong làn.
  - Đếm dòng có `office_copied_at`.
  - `travel` không có ở đây, và cấu hình không nhận 0 (thành ngữ `?:` + `max(1, …)`): lùi `local_purge_after` và `office_copied_at` hai ngày bằng một câu SQL trên CSDL làn (dữ liệu seed), chạy `storage.purge-staged` bằng tay, xác nhận bản cục bộ chỉ mất ở tệp có biên nhận khớp.
  - Mở thư mục `crypt` bằng Explorer: không đọc được tên hay nội dung (chụp ảnh).
- [ ] **Quay lui theo thứ tự mới:** chạy `rollback` khi công tắc còn `google_drive` → mã 2. Đặt `local`, `optimize`, `rollback` toàn bộ → `check` không còn `media_on_remote_while_local`. Đợi một vòng `storage.push-pending` → không media nào quay lại kho. Đặt `google_drive`, `optimize`, `enable`, `migrate` lại: không lượt tải lên thứ hai (đếm request trong log).
- [ ] Giả kho sập (chặn đường ra `googleapis.com` của container): tải xuống hiện trang 503 (chụp ảnh); tải lên vẫn được; sau khi mở lại, job tự đẩy.
- [ ] Giả lệch chia sẻ: chủ văn phòng thêm một tài khoản thử vào Shared Drive → trong vòng một giờ có thư cảnh báo và dòng đỏ trên trang chủ → gỡ ra → trạng thái về `ok`.
- [ ] Diễn tập "mất kho" của Task 7 trên một Shared Drive thử thứ hai.
- [ ] Mở giao diện Drive bằng tài khoản dự phòng: cây chỉ có thư mục tháng và tên dạng `1834~01k6….pdf`, không tên khách, không mã hồ sơ (chụp ảnh).
- [ ] Nếu chưa có thông tin đăng nhập: PROGRESS ghi **"M14 — xong phần mã, chờ chủ văn phòng"**, liệt kê đúng các bước trên còn thiếu. Production giữ `DOCUMENT_STORAGE=local`.

**Phần 3 — tài liệu:**
- [ ] `docs/SPEC.md`, đính chính kèm ngày 2026-10-04:
  - §2: kho Google Drive; gọi ra ngoài tới `googleapis.com`; `curl` hoặc `allow_url_fopen`; hàng đợi `storage` rút bằng cron; `disk_free_space` nên bật (tắt thì gói bàn giao không kiểm được chỗ trống); `posix` nên có (để kiểm quyền tệp khoá);
  - §4.11: tệp vào vùng đệm `private` rồi lên `documents_remote` sau khi bật kho; bốn cột mới của `media`;
  - §4.12: dòng nhật ký chỉ ghi khi đã mở được luồng (R3);
  - §4.19: huỷ tệp của hồ sơ đã quá hạn lưu trên kho, ở văn phòng và trên máy chủ, theo sổ tay R15; lệnh `vkcrm:storage:destruction-list`;
  - §10 mục 4: tệp nằm trên Shared Drive, chỉ tài khoản dịch vụ truy cập; `storage/app/private` là vùng đệm; route ký + policy giữ nguyên;
  - §10 mục 8:
    - CSDL như cũ;
    - tệp có bản thứ hai ở máy chủ văn phòng, kéo về và mã hoá `crypt`, khoá không ở máy chủ web;
    - vùng đệm chỉ dọn theo biên nhận;
    - **tệp trên Kho không mã hoá phía văn phòng** (khác bảo đảm "mọi bản ngoài máy chủ đều AES-256" của hôm nay), kèm ngày chủ văn phòng ký nhận (câu hỏi 8);
  - §11 "Tải tệp": các test mới của Task 4;
  - §13: dòng M14.
- [ ] `docs/PROGRESS.md`: thêm dòng M14 vào bảng. "Ghi chú M14" gồm:
  - mọi phán quyết R1–R15, kèm các ghi chú "vì sao không theo góp ý";
  - kết quả Task 0, số đo;
  - việc chờ chủ văn phòng;
  - việc sau cho M8a (vai của `sao-luu@` trên "VK-CRM Backups").
- [ ] `README.md` và `docs/CAI-DAT.md`:
  - biến môi trường mới;
  - chỗ đặt khoá và quyền tệp (cả shared hosting);
  - Bước 7 thêm các dòng preflight và `vkcrm:storage:check`;
  - Bước 8 thêm các mục lịch;
  - sổ tay chuyển đổi.
- [ ] `docs/QUY-TRINH.md`: một đoạn cho nhân sự — tài liệu chỉ mở qua CRM; không ai mở, chia sẻ hay chép tệp trên Drive; thấy tệp trên Drive bằng đường nào khác thì báo quản trị; máy văn phòng chứa bản mã hoá, không ai ngoài người giữ khoá được mở.

**Phần 4 — cổng merge:**
- [ ] Gộp `main` mới nhất vào nhánh (kể cả `composer.json`/`composer.lock` theo Ràng buộc toàn cục), chạy lại Phần 1.
- [ ] Rà soát toàn nhánh bằng một agent Opus, brief **giả định có một Critical** cộng câu riêng của M14 ở Ràng buộc toàn cục.
- [ ] Merge vào `main`, push GitHub (repo riêng `Harry-Kien/vk-crm`), chờ CI xanh (`gh run watch`), dán kết quả. Dừng lại báo cáo.

Commit (tài liệu): `docs: M14 Task 8 — nghiệm thu kho Google Drive, đính chính SPEC §2 §4.11 §4.12 §4.19 §10 §11 §13, ghi chú M14`.

---

## Những chỗ đã biết trước là sẽ cắn

- **`masbug` cài được nếu thêm `-W`**, bằng cách hạ Guzzle của **cả ứng dụng**. M8a đã từ chối đúng cái giá này (`docs/research/2026-09-26-sao-luu.md:125-140`). Đừng "sửa nhanh" bằng `-W`.
- **`$disk->path()` trên đĩa không cục bộ không báo lỗi**: nó trả một chuỗi đường dẫn không tồn tại, và `ZipArchive::addFile()` hỏng lúc `close()`. `CollectHandoverEntries:129` là chỗ duy nhất hôm nay; test cấu trúc cấm `->path(` trên đĩa kho.
- **`FilesystemAdapter::download()`/`response()` mở luồng bên trong callback**, sau khi header 200 đã gửi. Lỗi kho lúc đó là một tệp hỏng, không phải một trang lỗi (R3). Tự dựng `StreamedResponse` thì phải chép cả bản dự phòng ASCII của tên tải.
- **`Storage::fake()` không bao giờ gửi HTTP.** Mọi `Http::assertNothingSent()` trên đĩa giả đều xanh, kể cả khi mã gọi kho trước khi kiểm quyền. Dùng đĩa gián điệp hoặc adapter thật (R3).
- **`DefaultFileRemover` nuốt ngoại lệ** (`report()`). Kho sập lúc xoá media, hay tài khoản dịch vụ mang vai `writer`, là tệp mồ côi lặng lẽ trên Drive. `vkcrm:storage:orphans` là lưới, và nó chỉ báo cáo.
- **`DefaultFileRemover` xoá theo thư mục `<id>/` ở mọi lượt xoá media.** Adapter khớp tiền tố mà quên dấu `/` sẽ cho tệp của media khác vào thùng rác (R4).
- **Thư viện media xoá tệp ở sự kiện `deleted`, không chờ commit.** Một lượt xoá media trong transaction sau đó rollback vẫn cho bản trên kho vào thùng rác, trong khi dòng `media` sống lại. Bản trong vùng đệm còn (listener của ta chờ commit); `verify` báo; khôi phục bằng quay lui đúng media đó, hoặc Manager lấy lại từ thùng rác. Không sửa vendor.
- **Drive cho trùng tên.** Không bao giờ tìm tệp hay thư mục theo tên; mọi thứ đi qua mã tệp trong chỉ mục, và thư mục tháng qua bảng `drive_folders` dưới khoá.
  - Một job bị giết sau khi tải lên xong nhưng trước khi ghi chỉ mục để lại một tệp trùng tên. `orphans` báo; `reindex` chọn bản có md5 khớp.
- **Quay lui trước, tắt kho sau, là quay lui tự đảo ngược.** Lệnh quay lui từ chối khi công tắc còn `google_drive` (R11).
- **Đổi biến `DOCUMENT_STORAGE` không đủ để bật kho.** Phải chạy `enable`; quên thì dòng `document_storage_enabled` ĐỎ và thư cảnh báo. Đổi về `local` thì mốc tự bị xoá, bật lại phải `enable` lần nữa.
- **Tài khoản dịch vụ là người ngoài tổ chức.** Shared Drive có thể không cho thêm nó khi tắt "người ngoài tổ chức" (R5). Kiểm ở Task 0.
- **Tổ chức Google Cloud tạo mới được "bảo mật mặc định"**: ràng buộc `iam.disableServiceAccountKeyCreation` chặn tạo khoá JSON. Chủ văn phòng (vai Organization Policy Administrator) phải miễn cho **riêng project này** (Google Cloud, "Secure-by-default organizations"). Phụ lục A bước 5.
- **Business Starter** có Shared Drive nhưng thiếu kiểm soát truy cập mục trong Shared Drive (nguồn thứ cấp). `driveMembersOnly` có thể không bật được → `drive_sharing` ĐỎ → phải nâng gói.
- **Lệch đồng hồ máy chủ** làm hỏng JWT (`invalid_grant`). Câu ĐỎ của `drive_reachable` nhắc kiểm NTP.
- **Hosting chặn gọi ra ngoài.** Heartbeat chạy được không chứng minh `oauth2.googleapis.com` và `www.googleapis.com` gọi được. `drive_roundtrip` mới chứng minh.
- **Shared hosting:**
  - không có `/etc` và không đổi nhóm được: khoá đặt trong thư mục nhà, `0400` (R6);
  - `disk_free_space` và `posix` hay bị tắt: gói bàn giao bỏ kiểm chỗ trống, dòng khoá thành VÀNG thay vì đoán.
- **Token trong store `database`** sẽ vào bản sao lưu CSDL. Vì thế store `file` (R6).
- **`env()` ngoài tệp cấu hình trả `null` sau `config:cache`** (docblock `RunPreflight`). Mọi khoá của M14 đọc qua `config()`, kể cả cổng của `enable` và `rollback`: đổi `.env` mà quên `optimize` thì lệnh thấy giá trị cũ, và từ chối.
- **Preflight chỉ chạy dòng ra mắt khi `APP_ENV=production`.** Ở làn và máy thử, dùng `vkcrm:storage:check` (R7).
- **Đĩa giả của test là đĩa cục bộ.** Nó không có thùng rác, không tính md5 phía máy chủ, không trả 429. Đừng tin một test luồng chỉ vì đĩa giả xanh: hành vi riêng của Drive nằm ở test adapter (Task 2) và test sống.
- **Cửa sổ chuyển đổi tốn đĩa:**
  - bản cục bộ giữ ít nhất 30 ngày, và tới khi có biên nhận văn phòng thì giữ mãi;
  - archive sao lưu đêm vẫn lớn như hôm nay trong suốt thời gian đó;
  - kiểm chỗ trống máy chủ trước khi chạy `migrate` (chế độ chạy thử in con số).
- **Chưa có máy văn phòng thì không có gì được dọn.** Máy chủ web phải đủ chỗ cho toàn bộ kho, như hôm nay. Đó là có chủ đích (R10).
- **Gói bàn giao cần chỗ trống gấp đôi tổng tệp** trên máy chủ, **chỉ khi** `src/` bị xoá trước lúc medialibrary chép zip. Quên bước đó là gấp ba (R12).
- **Job gói bàn giao nay dài gấp đôi.** `STALE_AFTER_MINUTES` phải theo kịp, nếu không nút "sinh lại" mở khoá giữa một lượt còn sống (R12).
- **400.000 mục mỗi Shared Drive**, có thể tính cả thùng rác. `drive_item_count` VÀNG từ 300.000. Khi tới đó cần một việc nhỏ: Shared Drive thứ hai. Cột `drive_id` đã có.
- **Tài khoản quản trị dự phòng đọc được mọi tệp trên giao diện Drive.** Tên mờ không phải mã hoá. Đó là kiểm soát vận hành: dùng khi khôi phục thảm hoạ hay huỷ tệp, và chủ văn phòng rà nhật ký Drive mỗi tháng (Phụ lục A bước 13).
- **Biên nhận là lời của máy văn phòng.** Một máy văn phòng bị chiếm có thể gửi biên nhận giả và làm vùng đệm bị dọn sớm. Kho vẫn còn bản; `cryptcheck` hằng tháng và `verify` là lưới. Không biên nhận nào làm CRM xoá gì trên kho.
- **`cryptcheck` tải lại tệp nguồn** để mã hoá và so. Mỗi tệp mới được tải về văn phòng hai lần (một lần sao, một lần kiểm), và lượt hằng tháng tải lại toàn bộ. Tính vào băng thông văn phòng (Phụ lục D).
- **Mất mật khẩu `crypt` là mất bản ở văn phòng.** Cất cùng chỗ với `APP_KEY` (Phụ lục D).
- **`sha256Checksum` của Google có thể vắng.** Chỉ md5 cộng kích thước là điều kiện kiểm.
- **Kết nối hàng đợi `storage` và timeout của job**: `retry_after` phải lớn hơn `$timeout`, nếu không job tải lên 2 GB bị nhặt lại và chạy song song với chính nó (đúng lỗi M7 R9 đã tránh). TTL của khoá đẩy cũng vậy. Có test ghim cả hai.
- **Livewire lưu tệp tạm trên đĩa mặc định** (`config/livewire.php:167`, rơi về đĩa mặc định `local`). Không đổi: đó là tệp tạm, không phải kho.

---

## Câu hỏi cho chủ văn phòng và luật sư của văn phòng

Không chặn Task 0–7. Mỗi mục có mặc định an toàn ở trên.

1. **Gói Google Workspace** của văn phòng là gói nào? Cần Business Standard trở lên cho R5.
2. **Tài khoản dịch vụ và "người ngoài tổ chức"** (R5): nếu Workspace chỉ cho thêm tài khoản dịch vụ khi bật công tắc này cho riêng Shared Drive kho, chủ văn phòng có chấp nhận không? Hàng rào thật khi đó là "chỉ thành viên" cộng danh sách thành viên kiểm mỗi giờ.
3. **Luật sư** (R13):
   - (a) Có được bắt đầu lưu trên Google **trước** khi nộp hồ sơ chuyển dữ liệu ra nước ngoài, hay phải nộp trước? Nghị định 356 đưa hồ sơ sang tiền kiểm, nên câu hỏi gồm cả việc có phải chờ A05 trả lời "đạt" không (`mcp-phap-ly-goi.md:326`, `:386`). **Mặc định trong app: chặn trên production** cho tới khi có ngày hồ sơ, hoặc ý kiến bằng văn bản của luật sư cho phép chuyển trước (ghi trên trang "Kho tài liệu").
   - (b) Lưu trên đám mây của bên xử lý có DPA có bị coi là "tiết lộ" theo Điều 25 Luật Luật sư không, và có cần thêm điều khoản đồng ý vào hợp đồng dịch vụ pháp lý không?
   - (c) Văn phòng có thuộc diện lưu trữ trong nước của Nghị định 333/2026 Điều 19 không (`mcp-phap-ly-goi.md:390`)? Nếu có: bản ở máy chủ văn phòng (R10) thành **bắt buộc trước khi bật**.
   - (d) Ảnh CCCD/CMND là dữ liệu nhạy cảm theo Nghị định 356 (`:327`). Có cần biện pháp riêng cho loại tệp này không, ví dụ không đẩy lên kho mà giữ ở máy chủ?
4. **Máy chủ văn phòng** (Phụ lục D): bao giờ có, ở đâu, ai giữ mật khẩu `crypt` và mật khẩu cấu hình rclone? Tới khi có, máy chủ web giữ mọi tệp và cần đủ chỗ cho toàn bộ kho.
5. **Tài khoản quản trị dự phòng**: ai giữ? Có muốn bỏ hẳn thành viên người khỏi Shared Drive kho (chỉ còn tài khoản dịch vụ và tài khoản văn phòng), để khi khôi phục hay huỷ tệp thì siêu quản trị tự thêm mình qua Admin console không?
6. **Thời gian ân hạn**: 24 giờ cho tệp mới, 30 ngày cho tệp chuyển bằng lệnh, cộng 24 giờ sau biên nhận. Có đủ chỗ trống trên máy chủ không?
7. **Sửa tài liệu chung trên Google Docs** nằm **ngoài** phạm vi: tệp tải về, sửa trên máy, tải lên thành version mới. Xác nhận đúng ý chủ văn phòng.
8. **Mã hoá** (R10):
   - Chủ văn phòng có chấp nhận rằng tệp trên Kho ở dạng Google đọc được (Google mã hoá khi lưu bằng khoá của Google), trong khi hôm nay mọi bản ngoài máy chủ đều là archive AES-256 của văn phòng? Cần chữ ký và ngày, ghi vào SPEC §10 mục 8.
   - Lựa chọn khác (ngoài M14): CRM mã hoá tệp trước khi đẩy. Nó che Google và quản trị viên Workspace, nhưng không che máy chủ web bị chiếm, và mất khoá là mất mọi tệp.
9. **Tài khoản `van-phong-kho@…`** (Phụ lục D): tạo một tài khoản Workspace riêng cho máy văn phòng, có tốn thêm một giấy phép không, hay dùng một nhóm/tài khoản sẵn có?
10. **Huỷ tệp hết hạn lưu** (R15): ai làm từng bước của sổ tay huỷ (Manager trên Drive, người giữ máy văn phòng, người vận hành máy chủ), và biên bản huỷ lưu ở đâu?

---

## Phụ lục A — Việc chủ văn phòng phải tự làm (agent không làm được)

Làm hai lần: một lần cho **"VK-CRM Kho (thử)"** (Task 8), một lần cho **"VK-CRM Kho tài liệu"** (production). Cột phải là dòng kiểm lại bước đó, in bởi `vkcrm:storage:check` (mọi môi trường) và `vkcrm:preflight` (production).

| # | Việc | Dòng kiểm |
|---|---|---|
| 0 | **Kiểm gói Workspace**: admin.google.com → Thanh toán → Gói đăng ký. Cần Business Standard trở lên. Nếu là Business Starter, nâng gói trước | `drive_sharing` |
| 1 | **Tạo Shared Drive** bằng tài khoản siêu quản trị (đã bật xác minh 2 bước): drive.google.com → Bộ nhớ dùng chung → Mới. Tên không chứa tên khách hay mã hồ sơ | `drive_reachable` |
| 2 | **Cài đặt Shared Drive** (chuột phải → Cài đặt bộ nhớ dùng chung): <br>• "Cho phép người không phải thành viên truy cập tệp": **TẮT** <br>• "Cho phép người quản lý nội dung chia sẻ thư mục": **TẮT** <br>• "Cho phép người ngoài tổ chức truy cập tệp": **TẮT**, trừ khi bước 6 báo lỗi (khi đó **BẬT riêng cho bộ nhớ này** và báo lại) <br>• "Cho phép người xem và người nhận xét tải xuống, sao chép, in": để **BẬT** (tài khoản văn phòng là người xem, cần tải để kéo về) | `drive_sharing` |
| 3 | **Google Cloud**: console.cloud.google.com → tạo project `vkcrm-kho` thuộc tổ chức của văn phòng → "APIs & Services" → bật **Google Drive API** | `drive_reachable` |
| 4 | **Tạo tài khoản dịch vụ** `vkcrm-kho` (IAM & Admin → Service Accounts). **Không** cấp vai trò IAM nào. **Không** bật domain-wide delegation | `drive_sharing` |
| 5 | **Tạo khoá JSON** (tài khoản dịch vụ → Keys → Add key → JSON), tải về **một lần**. Nếu bị chặn bởi chính sách tổ chức "Disable service account key creation": IAM & Admin → Organization Policies → ràng buộc đó → miễn trừ **riêng project `vkcrm-kho`** (cần vai Organization Policy Administrator) | `drive_credentials` |
| 6 | **Thêm tài khoản dịch vụ vào Shared Drive** (Quản lý thành viên), email dạng `vkcrm-kho@vkcrm-kho.iam.gserviceaccount.com`, vai **Người quản lý nội dung**, đúng vai đó, không vai nào khác. Bỏ "Thông báo cho mọi người" | `drive_sharing`, `drive_roundtrip` |
| 7 | **Thêm tài khoản máy văn phòng** `van-phong-kho@…` (Phụ lục D bước 2), vai **Người xem**. Làm khi đã có, hoặc sắp có, máy văn phòng. **Không** thêm `sao-luu@` | `drive_sharing`, `document_office_copy` |
| 8 | **Không thêm ai khác. Không chia sẻ tệp hay thư mục nào.** Tài khoản siêu quản trị đã tạo bộ nhớ là Người quản lý dự phòng | `drive_sharing` (mỗi giờ) |
| 9 | **Đưa khoá lên máy chủ** (người cài đặt làm cùng chủ văn phòng). Xoá tệp trên máy tính cá nhân, **cả trong thùng rác**. Không gửi khoá qua email, Zalo hay Drive. Mất khoá thì tạo khoá mới (bước 5) và xoá khoá cũ. <br>• **VPS:** `/etc/vkcrm/google-drive-key.json`, chủ sở hữu `root`, nhóm PHP-FPM (ví dụ `www-data`), `chmod 0440`. <br>• **Shared hosting:** `~/.config/vkcrm/google-drive-key.json` (ngoài thư mục mã nguồn, ngoài `public_html`), `chmod 700 ~/.config/vkcrm`, `chmod 0400` tệp khoá | `drive_credentials` |
| 10 | **Gửi người cài đặt**: mã Shared Drive (phần cuối URL `drive.google.com/drive/folders/<MÃ>`), email tài khoản dịch vụ, danh sách thành viên được phép kèm vai (email dự phòng `:organizer`, email văn phòng `:reader`) | — |
| 11 | Người cài đặt điền `.env` (giữ `DOCUMENT_STORAGE=local`), chạy `vkcrm:storage:init`, điền `GOOGLE_DRIVE_ROOT_FOLDER_ID`, `php artisan optimize`, chạy `vkcrm:storage:check` | mọi dòng sẵn sàng |
| 12 | **DPA và hồ sơ** (R13): Admin console → Tài khoản → Cài đặt tài khoản → Pháp lý và tuân thủ → chấp nhận Cloud Data Processing Addendum, lưu PDF. Luật sư lập hồ sơ theo Phụ lục B. Ghi trên trang **"Kho tài liệu"**: ngày DPA, và ngày hồ sơ **hoặc** ý kiến luật sư cho chuyển trước. Thiếu cả hai thì production không bật được kho | `data_transfer_dossier` |
| 13 | **Mỗi tháng**: Admin console → Báo cáo → Kiểm tra và điều tra → Sự kiện nhật ký Drive, lọc theo Shared Drive kho. Chỉ được thấy tài khoản dịch vụ và tài khoản văn phòng. Thấy người khác thì báo ngay | kiểm tra sức khoẻ mỗi giờ (thành viên) |
| 14 | **Xoay khoá** 12 tháng một lần, và khi người có quyền vào máy chủ nghỉ việc: bước 5 → bước 9 → `vkcrm:storage:check` xanh → xoá khoá cũ trong Google Cloud | `drive_credentials` |

---

## Phụ lục B — Dàn ý hồ sơ đánh giá tác động chuyển dữ liệu cá nhân ra nước ngoài

> Dàn ý này là thông tin chuẩn bị cho luật sư của văn phòng, **không phải tư vấn pháp lý**. Mẫu biểu chính thức (Nghị định 356/2025, Mẫu số 09/10; mẫu nào cho hồ sơ nào còn phải kiểm, `mcp-phap-ly-goi.md:386`) và nội dung cuối cùng do luật sư quyết.

1. **Bên chuyển:** tên pháp lý, mã số thuế, địa chỉ (lấy từ trang "Thông tin văn phòng"), người đại diện, người hoặc bộ phận phụ trách bảo vệ dữ liệu cá nhân.
2. **Bên nhận / bên xử lý:**
   - thực thể Google ký hợp đồng Workspace với văn phòng (ghi đúng tên trên hoá đơn), vai trò bên xử lý;
   - nơi lưu trữ: các trung tâm dữ liệu của Google ở nước ngoài; Workspace không có vùng dữ liệu Việt Nam.
3. **Mục đích:** lưu trữ tệp hồ sơ vụ việc của khách hàng phục vụ dịch vụ pháp lý; truy cập chỉ qua hệ thống CRM của văn phòng.
4. **Loại dữ liệu:**
   - dữ liệu **cơ bản**: họ tên, ngày sinh, số giấy tờ tuỳ thân dạng chữ, địa chỉ, số điện thoại (`mcp-phap-ly-goi.md:328`);
   - dữ liệu **nhạy cảm**:
     - **ảnh CCCD/CMND**: Nghị định 356 đưa vào nhóm nhạy cảm (`:327`, `:328`), và đây là loại tệp khách nộp nhiều nhất;
     - đời sống riêng tư, sức khoẻ, tài chính, thông tin liên quan tội phạm do văn phòng thu thập (`:396`);
     - hồ sơ vụ việc nói chung nên coi là nhạy cảm;
   - dữ liệu của **bên thứ ba** (bên đối lập, người liên quan) không thể lấy đồng ý.
5. **Chủ thể:** khách hàng, các bên trong vụ việc, nhân sự văn phòng.
6. **Căn cứ xử lý và chuyển:** hợp đồng dịch vụ pháp lý; đồng ý của khách (nếu luật sư kết luận cần, câu hỏi 3b); nghĩa vụ giữ bí mật theo Điều 25 Luật Luật sư.
7. **Biện pháp bảo vệ** (mô tả đúng hệ thống đã dựng, kể cả chỗ yếu):
   - chỉ một tài khoản dịch vụ truy cập, vai Người quản lý nội dung (không xoá vĩnh viễn, không chia sẻ); Shared Drive "chỉ thành viên"; kiểm thành viên và vai mỗi giờ;
   - tên tệp trên Drive không chứa thông tin cá nhân;
   - mã hoá khi truyền (TLS);
   - mã hoá khi lưu trên Google là **mã hoá mặc định của Google, khoá do Google giữ**: về kỹ thuật Google đọc được tệp. Văn phòng **không** mã hoá tệp trước khi gửi (R10; chủ văn phòng ký nhận ở câu hỏi 8);
   - mọi lượt tải đi qua CRM: kiểm quyền theo vụ, ghi nhật ký người tải, IP, thời điểm;
   - 2FA bắt buộc cho nhân sự;
   - bản thứ hai tại máy chủ văn phòng ở Việt Nam, **mã hoá** bằng `rclone crypt`, khoá do văn phòng giữ ngoài máy chủ web;
   - sao lưu CSDL mã hoá AES-256;
   - huỷ tệp khi hết hạn lưu hay theo yêu cầu của chủ thể theo sổ tay R15;
   - quy trình sự cố: thông báo Bộ Công an trong 72 giờ (`mcp-phap-ly-goi.md:338`).
8. **Đánh giá rủi ro và tác động**, với biện pháp giảm thiểu tương ứng (xoay khoá, bản trong nước có mã hoá, DPA):
   - truy cập trái phép do lộ khoá;
   - quản trị viên Workspace hay Google đọc tệp;
   - yêu cầu của cơ quan nước ngoài đối với nhà cung cấp;
   - nhà cung cấp ngừng dịch vụ.
9. **Hợp đồng:** Cloud Data Processing Addendum (ngày chấp nhận, bản PDF). DPA đáp ứng yêu cầu có thoả thuận với bên nhận, **không thay** hồ sơ (`mcp-phap-ly-goi.md:398`).
10. **Thủ tục:**
    - gửi bản chính cho Cục A05 (Bộ Công an) trong 60 ngày kể từ lần chuyển đầu tiên (`:324`). Ngày đó do hệ thống tự ghi và hiện trên trang "Kho tài liệu";
    - Nghị định 356 áp dụng **tiền kiểm**: A05 xem xét 15 ngày xem hồ sơ đạt hay không đạt, bổ sung trong 30 ngày (`:326`). Có phải chờ kết quả trước khi chuyển hay không là câu hỏi 3a; trong app, mặc định là chặn;
    - cập nhật 6 tháng một lần hoặc khi đổi bên xử lý;
    - kèm **hồ sơ đánh giá tác động xử lý dữ liệu cá nhân** (DPIA), lập riêng.
11. **Lưu trữ trong nước:** kết luận của luật sư về Nghị định 333/2026 Điều 19 (câu hỏi 3c).

---

## Phụ lục C — Sổ tay chuyển đổi trên production (thứ tự với lưu lượng thật)

1. Gộp M14 vào `main`, triển khai với `DOCUMENT_STORAGE=local`. Hành vi không đổi; chỉ thêm bảng, cột và mục lịch không làm gì.
2. Chủ văn phòng làm Phụ lục A cho Shared Drive production, và Phụ lục D khi có máy văn phòng. Luật sư làm Phụ lục B và trả lời câu hỏi 3a.
3. Điền `.env` (vẫn `local`), `vkcrm:storage:init`, `php artisan optimize`, `vkcrm:storage:check`: mọi dòng sẵn sàng XANH.
4. Trên trang "Kho tài liệu": ghi ngày DPA, và ngày hồ sơ **hoặc** ý kiến luật sư cho chuyển trước (R13). Thiếu thì bước 6 bị từ chối.
5. `vkcrm:storage:migrate --dry-run`: ghi số tệp, dung lượng, thời gian ước tính, chỗ trống máy chủ.
6. Đặt `DOCUMENT_STORAGE=google_drive`, `php artisan optimize`, rồi `vkcrm:storage:enable`.
   - Từ lúc `enable` xong, **tệp mới** tự lên kho; tệp cũ đứng yên.
   - Lượt đẩy thật đầu tiên tự ghi **ngày chuyển dữ liệu đầu tiên** (đồng hồ 60 ngày, R13); xem trên trang "Kho tài liệu".
7. Ngoài giờ làm việc (từ 19:00): `vkcrm:storage:migrate --max-minutes=240`, lặp các đêm sau cho tới khi hết. Tải xuống vẫn chạy suốt.
8. `vkcrm:storage:verify --all`. Phải sạch.
9. Khi có máy văn phòng (Phụ lục D):
   - lượt kéo đầu tiên có thể mất nhiều đêm; khoá của script ngăn hai lượt chồng nhau;
   - CRM nhập biên nhận lúc 07:00 hằng ngày;
   - kiểm số "media trên kho chưa có biên nhận" giảm dần trên trang "Kho tài liệu".
10. Vùng đệm tự dọn **chỉ** cho tệp đã ở kho, quá thời gian ân hạn (24 giờ tệp mới, 30 ngày tệp cũ), và có biên nhận văn phòng khớp md5 từ 24 giờ trở lên. Chưa có máy văn phòng thì không dọn gì.
11. **Quay lui**, bất cứ lúc nào, **đúng thứ tự này**:
    1. đặt `DOCUMENT_STORAGE=local`, `php artisan optimize`. **Trước tiên**: `rollback` từ chối khi công tắc còn `google_drive`, vì nếu không, tác vụ quét đẩy lại mọi tệp vừa quay lui trong vòng 15 phút;
    2. `vkcrm:storage:rollback`. Tệp còn bản cục bộ được đổi về ngay, không cần Drive; tệp đã dọn được tải về và kiểm md5 (cần khoá và Drive tới được, nhưng **không** cần chia sẻ đúng). Mã thoát 1 thì chạy lại khi Drive tới được;
    3. `vkcrm:storage:check`: không còn `media_on_remote_while_local`.

    Bản trên kho và bản ở văn phòng còn nguyên. Bật lại sau này là bước 6 rồi bước 7: không tệp nào tải lên lần hai (md5 khớp).

---

## Phụ lục D — Máy chủ văn phòng: bản thứ hai ngoài Google (việc chủ văn phòng và người cài đặt)

Không chặn việc bật kho. Chặn việc **dọn vùng đệm**: chưa có máy này thì máy chủ web giữ mọi tệp.

| # | Việc | Dòng kiểm |
|---|---|---|
| 1 | **Máy**: một máy luôn bật trong văn phòng (Windows hoặc Linux). Ổ trống ≥ 2 lần dung lượng kho; bật mã hoá ổ đĩa (BitLocker / LUKS); có UPS. Băng thông: mỗi tệp mới được tải về hai lần (sao và kiểm), lượt kiểm hằng tháng tải lại toàn bộ | — |
| 2 | **Tài khoản Google** `van-phong-kho@…` (Workspace, bật 2FA, không ai dùng để làm việc hằng ngày). Vai **Người xem** trên Kho (Phụ lục A bước 7), vai **Người đóng góp** trên "VK-CRM Backups" (để gửi biên nhận; không cho vào thùng rác được) | `drive_sharing` |
| 3 | **Tài khoản hệ điều hành riêng** `vkcrm-saoluu` trên máy văn phòng. Nhân sự không đăng nhập bằng nó; thư mục bản sao chỉ nó đọc được | — |
| 4 | **Cài `rclone`** (như `docs/SAO-LUU-KHOI-PHUC.md` Bước 1), và Git Bash nếu là Windows | — |
| 5 | **`rclone config`** dưới tài khoản `vkcrm-saoluu`, ba remote: <br>• `vkkho`: Google Drive, phạm vi **`drive.readonly`**, `team_drive` = mã Shared Drive kho, `root_folder_id` = mã thư mục gốc (đúng hai giá trị trong `.env` của máy chủ); <br>• `vkbackups`: Google Drive, phạm vi `drive`, `team_drive` = "VK-CRM Backups"; <br>• `vkoffice`: **`crypt`** trên thư mục cục bộ (ví dụ `D:\VKCRM-saoluu\kho`), mã hoá tên tệp `standard`, mật khẩu và salt do rclone sinh. <br>Đặt mật khẩu cho tệp cấu hình rclone (`rclone config` → `s` → `a`) | `document_office_copy` |
| 6 | **Cất ba mật khẩu** (`crypt`, salt, cấu hình rclone) cùng chỗ với `APP_KEY` và `BACKUP_ARCHIVE_PASSWORD` (`docs/SAO-LUU-KHOI-PHUC.md` Bước 6). **Không bao giờ** đặt chúng trên máy chủ web, không gửi qua email, Zalo hay Drive. Mất mật khẩu `crypt` là mất bản ở văn phòng | — |
| 7 | **Lịch**: `tools/backup/office-pull.sh` mỗi đêm 01:00 (Task Scheduler hoặc cron, dưới `vkcrm-saoluu`, mật khẩu cấu hình rclone qua kho mật khẩu của hệ điều hành); `office-pull.sh --check-monthly` ngày 1 hằng tháng | `document_office_copy` |
| 8 | **Người cài đặt** điền `DOCUMENT_OFFICE_RECEIPTS_PATH` trên máy chủ (ví dụ `gdrive:VK-CRM-backups/office-receipts/vk-crm-production`), `optimize`, chạy `vkcrm:storage:office-receipts` | `document_office_copy` |
| 9 | **Kiểm**: trang "Kho tài liệu" hiện biên nhận gần nhất, và số media chưa có biên nhận giảm dần. Mở thư mục bản sao bằng Explorer: tên và nội dung không đọc được | — |
| 10 | **Mỗi tháng**: xem nhật ký của `--check-monthly`. Có tệp lệch thì báo người cài đặt (có thể có tệp bị sửa trên kho) | `document_office_copy` |
