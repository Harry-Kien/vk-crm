# Kho tài liệu trên Google Drive — hướng dẫn cho chủ văn phòng và người cài đặt

Tài liệu này đi cùng milestone M14 (kế hoạch `docs/superpowers/plans/2026-10-04-m14-google-drive-storage.md`).
Từ M14, tệp hồ sơ có thể nằm trên một **Shared Drive của văn phòng** thay vì chỉ trên máy chủ web. CRM vẫn là
cửa duy nhất: mọi lượt tải đi qua CRM (kiểm quyền theo vụ, ghi nhật ký người tải), không ai mở tệp trực tiếp
trên Drive, và không link Drive nào rời máy chủ.

Ba phần của tài liệu:

- **Phụ lục A** — việc chủ văn phòng phải tự làm trên Google Workspace và Google Cloud (agent không làm được).
  Mỗi bước ghi tên **dòng kiểm** của nó: dòng mà `php artisan vkcrm:storage:check` (mọi môi trường) và
  `php artisan vkcrm:preflight` (production) in ra để xác nhận bước đó đã làm đúng.
- **Phụ lục C** — sổ tay chuyển đổi trên production: thứ tự bật kho với lưu lượng thật, và cách quay lui.
- **Huỷ tệp của hồ sơ đã quá hạn lưu** — CRM không xoá tệp trên kho; sổ tay này nói ai xoá ở đâu.

Hồ sơ pháp lý (chuyển dữ liệu cá nhân ra nước ngoài) có dàn ý riêng ở `docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md`.

> **PENDING OWNER.** Đến ngày viết tài liệu này (2026-10-04) văn phòng chưa có Workspace thật, tài khoản dịch vụ,
> khoá JSON hay Shared Drive nào cho CRM. Mọi lệnh `vkcrm:storage:*` mới chỉ chạy trên một Drive giả trong bộ
> test. Phụ lục A phải làm hai lần: một lần cho **"VK-CRM Kho (thử)"** (nghiệm thu M14, Task 8), một lần cho
> **"VK-CRM Kho tài liệu"** (production).

## Đọc dòng kiểm

`vkcrm:storage:check` in hai nhóm dòng, mỗi dòng dạng `[MỨC] khoá: câu`:

- **Dòng sẵn sàng** (kho dùng được không): `document_storage_driver`, `drive_credentials`, `drive_http_client`,
  `drive_reachable`, `drive_sharing`, `drive_root_folder`, `drive_roundtrip`.
- **Dòng trạng thái** (kho đang chạy ra sao): `document_storage_enabled`, `drive_item_count`,
  `document_push_backlog`, `document_office_copy`, `data_transfer_dossier` (chỉ production),
  `media_on_remote_while_local`, `disk_free_space_available`.

Có dòng ĐỎ thì lệnh thoát với mã 1. `drive_roundtrip` ghi một tệp thăm dò 1 KiB dưới `preflight/`, kiểm md5 do
Google tính, đọc lại rồi cho vào thùng rác của Shared Drive — đó là phép thử duy nhất chứng minh máy chủ thật sự
gọi được Google (một heartbeat chạy được không chứng minh điều đó).

Ngoài ra, mục lịch `storage.health` chạy **mỗi giờ** (phút 20): kiểm lại chia sẻ và thành viên, tồn đọng, biên
nhận văn phòng, đồng hồ hồ sơ 60 ngày; ghi trạng thái lên trang chủ admin (dòng đỏ trên dải sức khoẻ, chỉ quản
trị viên thấy) và trang **"Kho tài liệu"**, và gửi thư cảnh báo cho người nhận thư sao lưu (`BACKUP_NOTIFY_EMAIL`,
hoặc mọi quản trị viên đang hoạt động) — mỗi loại sự cố một thư mỗi ngày. Thư chỉ có loại sự cố và số đếm.

## Phụ lục A — Việc chủ văn phòng phải tự làm

| # | Việc | Dòng kiểm |
|---|---|---|
| 0 | **Kiểm gói Workspace**: admin.google.com → Thanh toán → Gói đăng ký. Cần Business Standard trở lên. Nếu là Business Starter, nâng gói trước (Starter có thể không bật được "chỉ thành viên" cho Shared Drive) | `drive_sharing` |
| 1 | **Tạo Shared Drive** bằng tài khoản siêu quản trị (đã bật xác minh 2 bước): drive.google.com → Bộ nhớ dùng chung → Mới. Tên không chứa tên khách hay mã hồ sơ | `drive_reachable` |
| 2 | **Cài đặt Shared Drive** (chuột phải → Cài đặt bộ nhớ dùng chung): <br>• "Cho phép người không phải thành viên truy cập tệp": **TẮT** <br>• "Cho phép người quản lý nội dung chia sẻ thư mục": **TẮT** <br>• "Cho phép người ngoài tổ chức truy cập tệp": **TẮT**, trừ khi bước 6 báo lỗi (khi đó **BẬT riêng cho bộ nhớ này** và báo lại; dòng kiểm sẽ là VÀNG, có chủ đích) <br>• "Cho phép người xem và người nhận xét tải xuống, sao chép, in": để **BẬT** (tài khoản văn phòng là người xem, cần tải để kéo về) | `drive_sharing` |
| 3 | **Google Cloud**: console.cloud.google.com → tạo project `vkcrm-kho` thuộc tổ chức của văn phòng → "APIs & Services" → bật **Google Drive API** | `drive_reachable` |
| 4 | **Tạo tài khoản dịch vụ** `vkcrm-kho` (IAM & Admin → Service Accounts). **Không** cấp vai trò IAM nào. **Không** bật domain-wide delegation | `drive_sharing` |
| 5 | **Tạo khoá JSON** (tài khoản dịch vụ → Keys → Add key → JSON), tải về **một lần**. Nếu bị chặn bởi chính sách tổ chức "Disable service account key creation": IAM & Admin → Organization Policies → ràng buộc đó → miễn trừ **riêng project `vkcrm-kho`** (cần vai Organization Policy Administrator) | `drive_credentials` |
| 6 | **Thêm tài khoản dịch vụ vào Shared Drive** (Quản lý thành viên), email dạng `vkcrm-kho@vkcrm-kho.iam.gserviceaccount.com`, vai **Người quản lý nội dung**, đúng vai đó, không vai nào khác. Bỏ "Thông báo cho mọi người" | `drive_sharing`, `drive_roundtrip` |
| 7 | **Thêm tài khoản máy văn phòng** `van-phong-kho@…`, vai **Người xem**. Làm khi đã có, hoặc sắp có, máy văn phòng. **Không** thêm `sao-luu@` | `drive_sharing`, `document_office_copy` |
| 8 | **Không thêm ai khác. Không chia sẻ tệp hay thư mục nào.** Tài khoản siêu quản trị đã tạo bộ nhớ là Người quản lý dự phòng | `drive_sharing`, `storage.health` |
| 9 | **Đưa khoá lên máy chủ** (người cài đặt làm cùng chủ văn phòng). Xoá tệp trên máy tính cá nhân, **cả trong thùng rác**. Không gửi khoá qua email, Zalo hay Drive. Mất khoá thì tạo khoá mới (bước 5) và xoá khoá cũ. <br>• **VPS:** `/etc/vkcrm/google-drive-key.json`, chủ sở hữu `root`, nhóm PHP-FPM (ví dụ `www-data`), `chmod 0440`. <br>• **Shared hosting:** `/home/<tài khoản>/.config/vkcrm/google-drive-key.json` (ngoài thư mục mã nguồn, ngoài `public_html`), `chmod 700 /home/<tài khoản>/.config/vkcrm`, `chmod 0400` tệp khoá. Ghi đường dẫn TUYỆT ĐỐI vào `.env`: PHP không hiểu `~` | `drive_credentials` |
| 10 | **Gửi người cài đặt**: mã Shared Drive (phần cuối URL `drive.google.com/drive/folders/<MÃ>` khi mở Shared Drive), email tài khoản dịch vụ, danh sách thành viên được phép kèm vai (email dự phòng `:organizer`, email văn phòng `:reader`) | — |
| 11 | Người cài đặt điền `.env` (**giữ** `DOCUMENT_STORAGE=local`): `GOOGLE_DRIVE_CREDENTIALS_PATH`, `GOOGLE_DRIVE_SHARED_DRIVE_ID`, `GOOGLE_DRIVE_ALLOWED_MEMBERS`; chạy `php artisan optimize` rồi `php artisan vkcrm:storage:init` (tạo thư mục gốc `vkcrm-<APP_ENV>` và in mã của nó); điền `GOOGLE_DRIVE_ROOT_FOLDER_ID`; `php artisan optimize`; chạy `php artisan vkcrm:storage:check`: mọi dòng sẵn sàng phải XANH (riêng `drive_sharing` được VÀNG nếu bước 2 phải bật "người ngoài tổ chức") | `document_storage_driver`, `drive_credentials`, `drive_http_client`, `drive_reachable`, `drive_sharing`, `drive_root_folder`, `drive_roundtrip` |
| 12 | **DPA và hồ sơ** (pháp lý): Admin console → Tài khoản → Cài đặt tài khoản → Pháp lý và tuân thủ → "Security and Privacy Additional Terms" → chấp nhận **Cloud Data Processing Addendum**, lưu PDF. Luật sư lập hồ sơ theo `docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md`. Ghi trên trang **"Kho tài liệu"** trong /admin: ngày DPA, và ngày hồ sơ **hoặc** ý kiến luật sư cho chuyển trước (ngày + căn cứ). Thiếu cả hai thì production không bật được kho | `data_transfer_dossier` |
| 13 | **Mỗi tháng**: Admin console → Báo cáo → Kiểm tra và điều tra → Sự kiện nhật ký Drive, lọc theo Shared Drive kho. Chỉ được thấy tài khoản dịch vụ và tài khoản văn phòng. Thấy người khác thì báo ngay | `storage.health` (kiểm tra sức khoẻ mỗi giờ: thành viên và vai) |
| 14 | **Xoay khoá** 12 tháng một lần, và ngay khi một người có quyền vào máy chủ nghỉ việc: bước 5 (khoá mới) → bước 9 (đặt lên máy chủ) → `php artisan vkcrm:storage:check` XANH → xoá khoá cũ trong Google Cloud | `drive_credentials`, `drive_reachable` |

Ghi chú cho từng dòng kiểm khi nó không XANH:

- `drive_credentials` ĐỎ khi: đường dẫn trống; tệp không có hay PHP không đọc được; tệp nằm dưới thư mục mã nguồn,
  dưới `public/`, hay dưới một thư mục tên `public_html`, `www`, `htdocs`; người khác đọc hoặc ghi được (ví dụ
  `0644`); nhóm ghi được (ví dụ `0460`); nội dung không phải khoá JSON của tài khoản dịch vụ. VÀNG khi nhóm đọc
  được (`0440`/`0640`) mà không chứng minh được nhóm là riêng (thiếu extension `posix`, nhóm của tệp không phải
  nhóm của PHP, nhóm có thành viên khác) — trên shared hosting, đổi sang `0400`.
- `drive_reachable` ĐỎ: kiểm đồng hồ máy chủ (NTP — lệch giờ làm Google từ chối khoá), tường lửa hay hosting có
  chặn `oauth2.googleapis.com` và `www.googleapis.com` không, mã Shared Drive, và tài khoản dịch vụ có còn là thành
  viên không.
- `drive_sharing` ĐỎ khi có quyền "bất kỳ ai có link" hay "cả tên miền", thành viên ngoài danh sách
  `GOOGLE_DRIVE_ALLOWED_MEMBERS`, thành viên trong danh sách mang vai khác vai đã khai, tài khoản dịch vụ mang vai
  khác "Người quản lý nội dung", hay "chỉ thành viên" bị tắt.
- `drive_roundtrip` ĐỎ ở bước "cho vào thùng rác": tài khoản dịch vụ đang ở vai Contributor — sửa về "Người quản
  lý nội dung" (bước 6), nếu không mỗi lần xoá tài liệu sẽ để lại một tệp mồ côi trên Drive.

## Phụ lục C — Sổ tay chuyển đổi trên production (thứ tự với lưu lượng thật)

1. Gộp M14 vào `main`, triển khai với `DOCUMENT_STORAGE=local`. Hành vi không đổi; chỉ thêm bảng, cột và mục lịch
   không làm gì.
2. Chủ văn phòng làm Phụ lục A cho Shared Drive production, và phần máy văn phòng khi có máy. Luật sư làm hồ sơ
   theo `docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md` và trả lời câu hỏi 3a (có được chuyển trước khi nộp hồ sơ không).
3. Điền `.env` (vẫn `local`), `php artisan vkcrm:storage:init`, `php artisan optimize`,
   `php artisan vkcrm:storage:check`: mọi dòng sẵn sàng XANH.
4. Trên trang **"Kho tài liệu"**: ghi ngày DPA, và ngày hồ sơ **hoặc** ý kiến luật sư cho chuyển trước. Thiếu thì
   bước 6 bị từ chối (`data_transfer_dossier` ĐỎ).
5. `php artisan vkcrm:storage:migrate --dry-run`: ghi số tệp, dung lượng, thời gian ước tính, chỗ trống máy chủ.
6. Đặt `DOCUMENT_STORAGE=google_drive`, `php artisan optimize`, rồi `php artisan vkcrm:storage:enable`.
   - Từ lúc `enable` xong, **tệp mới** tự lên kho; tệp cũ đứng yên.
   - Lượt đẩy thật đầu tiên tự ghi **ngày chuyển dữ liệu đầu tiên** (đồng hồ 60 ngày nộp hồ sơ); xem trên trang
     "Kho tài liệu". Từ ngày 45 chưa có ngày hồ sơ thì có thư nhắc mỗi ngày; quá ngày 60 thì `data_transfer_dossier`
     ĐỎ.
   - Quên `enable` thì `document_storage_enabled` ĐỎ và có thư cảnh báo `not_enabled`: tệp mới vẫn nằm trên máy chủ.
7. Ngoài giờ làm việc (từ 19:00): `php artisan vkcrm:storage:migrate --max-minutes=240`, lặp các đêm sau cho tới
   khi hết. Tải xuống vẫn chạy suốt.
8. `php artisan vkcrm:storage:verify --all`. Phải sạch.
9. Khi có máy văn phòng:
   - lượt kéo đầu tiên có thể mất nhiều đêm; khoá của script ngăn hai lượt chồng nhau;
   - CRM nhập biên nhận lúc 07:00 hằng ngày;
   - kiểm số "Media trên kho chưa có biên nhận văn phòng" giảm dần trên trang "Kho tài liệu".
10. Vùng đệm trên máy chủ tự dọn **chỉ** cho tệp đã ở kho, quá thời gian ân hạn (24 giờ tệp mới, 30 ngày tệp cũ), và
    có biên nhận văn phòng khớp md5 từ 24 giờ trở lên. Chưa có máy văn phòng thì không dọn gì
    (`document_office_copy` VÀNG nhắc điều đó).
11. **Quay lui**, bất cứ lúc nào, **đúng thứ tự này**:
    1. đặt `DOCUMENT_STORAGE=local`, `php artisan optimize`. **Trước tiên**: `rollback` từ chối khi công tắc còn
       `google_drive`, vì nếu không, tác vụ quét đẩy lại mọi tệp vừa quay lui trong vòng 15 phút;
    2. `php artisan vkcrm:storage:rollback`. Tệp còn bản cục bộ được đổi về ngay, không cần Drive; tệp đã dọn được
       tải về và kiểm md5 (cần khoá và Drive tới được, nhưng **không** cần chia sẻ đúng). Mã thoát 1 thì chạy lại
       khi Drive tới được;
    3. `php artisan vkcrm:storage:check`: không còn `media_on_remote_while_local`.

    Bản trên kho và bản ở văn phòng còn nguyên. Bật lại sau này là bước 6 rồi bước 7: không tệp nào tải lên lần hai
    (md5 khớp).

Các lệnh `enable`, `migrate`, `verify`, `rollback`, `reindex`, `orphans`, `destruction-list` thuộc M14 Task 6.

## Huỷ tệp của hồ sơ đã quá hạn lưu

CRM **không bao giờ** xoá vĩnh viễn tệp trên kho: tài khoản dịch vụ chỉ cho tệp vào thùng rác (vai "Người quản lý
nội dung" không xoá vĩnh viễn được — có chủ đích, để một khoá bị lộ không xoá được kho), và bản ở máy văn phòng
không bao giờ bị xoá tự động. Khi một hồ sơ hết hạn lưu (đã ghi quyết định tiêu huỷ trên trang vụ việc), hoặc khi
chủ thể dữ liệu yêu cầu xoá theo Luật 91/2025, việc huỷ tệp là thao tác **có biên bản**, làm ở **bốn nơi**:

1. **Liệt kê** (người vận hành, quản trị viên):
   `php artisan vkcrm:storage:destruction-list <mã vụ việc> --by=<email quản trị viên>`. Lệnh từ chối khi vụ chưa
   có quyết định tiêu huỷ, hoặc người `--by` không phải quản trị viên. Nó in ba danh sách — chỉ mã và tên mờ, không
   tiêu đề, không mã hồ sơ:
   - tên tệp trên Drive (mọi thế hệ, cả bản đã vào thùng rác hay bị thay);
   - đường vùng đệm còn trên máy chủ;
   - đường tương ứng trong kho mã hoá của máy văn phòng (`vkoffice:kho/<YYYY-MM>/<tên>`).
2. **Trên Shared Drive** (tài khoản quản trị dự phòng, vai Người quản lý): tìm từng tên trong danh sách, xoá vĩnh
   viễn, kể cả bản trong thùng rác.
3. **Trên máy văn phòng** (người giữ máy văn phòng): `rclone deletefile` cho từng tệp trong remote `crypt`.
4. **Trên máy chủ web** (người vận hành): xoá các thư mục vùng đệm trong danh sách.

Archive sao lưu CSDL cũ (đã mã hoá AES-256) trên "VK-CRM Backups" tự hết dần theo vòng giữ 30 bản; bản
kéo về máy văn phòng (`ARCHIVE_DIR` của `office-pull.sh`) thì không tự hết — người giữ máy văn phòng xoá tay các
archive cũ (Phụ lục D của `docs/SAO-LUU-KHOI-PHUC.md`, "Những điều cần biết"). Biên bản huỷ ghi đủ bốn nơi, người
làm, ngày làm. Ai làm từng bước và biên bản lưu ở đâu là câu hỏi 10 của kế hoạch M14 — **PENDING OWNER**.

Thùng rác 30 ngày và lịch sử phiên bản của Drive **không** phải sao lưu: bản thứ hai của mọi tệp là bản mã hoá ở
máy văn phòng (hoặc, trước khi có máy văn phòng, vùng đệm trên máy chủ cộng archive sao lưu đêm).
