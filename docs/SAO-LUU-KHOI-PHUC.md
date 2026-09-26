# Sao lưu và khôi phục — VK-CRM

Tài liệu này viết cho **chủ văn phòng**, không cần biết lập trình. Nó giải thích hệ thống sao lưu
hoạt động thế nào, và hướng dẫn từng bước để bật đích Google Drive. Nếu có chỗ nào làm theo mà
không ra đúng kết quả mô tả, đó là lỗi của tài liệu này — dừng lại và nhờ người phụ trách kỹ thuật,
đừng đoán.

## Hệ thống sao lưu những gì, và giữ bao lâu

Mỗi đêm lúc 02:00 (giờ Việt Nam), hệ thống tự động:

1. **Dọn** các bản sao lưu quá hạn (theo đúng số lượng nêu bên dưới).
2. **Tạo một bản sao lưu mới**, gồm cơ sở dữ liệu (toàn bộ hồ sơ, vụ việc, khách hàng) và tệp
   khách hàng đã tải lên (giấy tờ, tài liệu). Bản này được **mã hoá bằng mật khẩu** trước khi rời
   máy chủ — kể cả khi nó chỉ nằm trên chính máy chủ.
3. Nếu đã bật đích Google Drive (hướng dẫn ở dưới), hệ thống **tự đẩy** bản sao lưu vừa tạo lên đó,
   rồi **kiểm tra lại** bản trên Google Drive có đúng dung lượng hay không trước khi coi là xong.
4. Sau khi đã đẩy và kiểm tra thành công, hệ thống dọn bớt các bản CŨ trên chính máy chủ (chỉ giữ
   vài bản gần nhất — máy chủ không cần giữ đủ 30 bản đầy đủ, vì bản đầy đủ đã có trên Google
   Drive).

**Số lượng giữ lại:**

| Nơi | Giữ lại | Vì sao |
|---|---|---|
| Google Drive | 30 bản (khoảng 1 tháng) | Đích lưu trữ chính, quy định ở SPEC §10 mục 8 |
| Máy chủ (đĩa trung chuyển) | 7 bản gần nhất (đổi được bằng `BACKUP_LOCAL_KEEP`) | Khôi phục nhanh khi cần, không cần tải lại từ Google Drive; đồng thời tránh đầy ổ đĩa máy chủ |

Nếu Google Drive **chưa bật cấu hình**, máy chủ tự giữ đủ 30 bản (không có đĩa trung chuyển, vì
không có nơi nào khác giữ bản đầy đủ).

**Nếu một lượt sao lưu, dọn dẹp, hay đẩy lên Google Drive thất bại**, hệ thống gửi email báo lỗi
tới địa chỉ khai ở `BACKUP_NOTIFY_EMAIL` (hoặc mọi Admin đang hoạt động, nếu chưa khai địa chỉ đó).
**Một email báo lỗi sao lưu không phải chuyện có thể để đó "xem sau"** — vụ việc mất một ngày sao
lưu vào đúng ngày máy chủ hỏng là vụ việc không lấy lại được.

---

## Bước 1 — Cài `rclone` trên máy chủ

`rclone` là một chương trình miễn phí, làm việc "chép tệp lên Google Drive" — hệ thống VK-CRM gọi
nó thay vì tự nói chuyện trực tiếp với Google (lý do kỹ thuật: xem
`docs/research/2026-09-26-sao-luu.md`, mục "Task 2").

Trên máy chủ Ubuntu/Debian, nhờ người quản trị máy chủ chạy (một lần, cần quyền quản trị):

```
curl https://rclone.org/install.sh | sudo bash
```

Kiểm tra đã cài xong:

```
rclone version
```

Lệnh trên phải in ra một số phiên bản (ví dụ `rclone v1.68.0`), không phải lỗi "command not
found".

---

## Bước 2 — Tạo một tài khoản Google RIÊNG cho việc sao lưu

**Không dùng Gmail cá nhân của bất kỳ ai** (kể cả chủ văn phòng) để chứa bản sao lưu. Lý do: nếu
người đó nghỉ việc, đổi mật khẩu, hay khoá tài khoản vì lý do gì đó không liên quan tới văn phòng,
toàn bộ bản sao lưu biến mất theo.

**Khuyến nghị (SPEC §10 mục 8, quyết định của chủ văn phòng ngày 2026-09-24):**

- Dùng **Google Workspace** (tài khoản email trả phí kiểu `sao-luu@luatvukhang.com`), không phải
  Gmail miễn phí.
- Trong tài khoản Workspace đó, tạo một **Shared Drive** (Ổ đĩa dùng chung) riêng cho việc sao lưu
  — ví dụ đặt tên "VK-CRM Backups". Shared Drive thuộc về TỔ CHỨC (Google Workspace), không thuộc
  về một cá nhân — một nhân viên rời tổ chức không kéo Shared Drive đi theo.
- Nếu chưa có Google Workspace và muốn dùng tạm Gmail thường trong lúc chờ nâng cấp: vẫn tạo một
  tài khoản Gmail RIÊNG cho việc này (không dùng email cá nhân đang dùng hằng ngày), và **đổi sang
  Google Workspace + Shared Drive càng sớm càng tốt** — Gmail thường không có Shared Drive, nên
  bước "team_drive" ở dưới sẽ không áp dụng, và bản sao lưu vẫn gắn với một tài khoản cá nhân.

---

## Bước 3 — Chạy `rclone config` để tạo remote tên "gdrive"

"Remote" là cách `rclone` gọi một đích lưu trữ đã cấu hình xong (ở đây là tài khoản/Shared Drive
Google Drive vừa tạo ở Bước 2). Việc này làm **một lần** trên máy chủ.

```
rclone config
```

`rclone` sẽ hỏi từng câu — trả lời theo thứ tự:

1. `n) New remote` — chọn `n` (tạo remote mới).
2. `name>` — gõ đúng chữ **`gdrive`** (tài liệu này và `.env` dùng đúng tên này ở các bước sau).
3. `Storage>` — gõ số tương ứng với **`Google Drive`** (rclone liệt kê một danh sách dài các loại
   lưu trữ; tìm dòng có chữ "Google Drive").
4. `client_id>` và `client_secret>` — để TRỐNG (nhấn Enter) trừ khi văn phòng đã tự tạo một OAuth
   App riêng trên Google Cloud Console (việc đó không bắt buộc; để trống, `rclone` dùng ứng dụng
   mặc định của chính nó).
5. `scope>` — chọn **`drive`** (toàn quyền đọc/ghi trên Drive). KHÔNG chọn `drive.readonly` — sao
   lưu cần quyền GHI.
6. `root_folder_id>` — để trống, trừ khi muốn giới hạn vào đúng một thư mục con cụ thể.
7. `service_account_file>` — để trống (văn phòng dùng xác thực OAuth cho một người dùng thật —
   tài khoản Google tạo ở Bước 2 — đơn giản hơn service account cho quy mô một văn phòng).
8. `Edit advanced config?` — chọn `n` (không).
9. `Use auto config?`:
   - Nếu máy chủ có trình duyệt và màn hình (hiếm gặp trên VPS) — chọn `y`, một cửa sổ trình duyệt
     sẽ mở ra để đăng nhập Google.
   - **Trường hợp thường gặp — máy chủ KHÔNG có trình duyệt (VPS/SSH):** chọn `n`. `rclone` sẽ in
     ra một dòng lệnh bắt đầu bằng `rclone authorize "drive" ...`. Làm theo **Bước 3b** dưới đây,
     rồi quay lại dán kết quả vào máy chủ.
10. Sau khi xác thực xong, `rclone` hỏi **`Configure this as a Shared Drive (Team Drive)?`** —
    - Nếu đã tạo Shared Drive ở Bước 2: chọn `y`, rồi chọn đúng Shared Drive "VK-CRM Backups" từ
      danh sách hiện ra.
    - Nếu tạm dùng Gmail thường (chưa có Shared Drive): chọn `n`.
11. `y) Yes this is OK` — xác nhận cấu hình vừa tạo.
12. `q) Quit config` — thoát.

### Bước 3b — Xác thực "headless" (máy chủ không có trình duyệt)

1. Trên máy chủ, sau khi chọn `n` ở câu "Use auto config?", `rclone` in ra một dòng dạng:
   ```
   rclone authorize "drive"
   ```
   (có thể dài hơn, kèm vài tham số khác — chép NGUYÊN VĂN dòng đó.)
2. Mở một cửa sổ dòng lệnh khác **trên máy tính cá nhân có trình duyệt** (Windows/Mac, đã cài sẵn
   `rclone` — cài giống Bước 1, hoặc tải bản Windows từ rclone.org), dán và chạy đúng dòng lệnh đó.
3. Một cửa sổ trình duyệt mở ra — đăng nhập bằng tài khoản Google đã tạo ở Bước 2, đồng ý cấp
   quyền cho rclone.
4. `rclone` trên máy tính cá nhân in ra một đoạn mã dài (bắt đầu bằng dấu `{`) — chép NGUYÊN VĂN
   đoạn đó.
5. Quay lại cửa sổ dòng lệnh trên MÁY CHỦ (vẫn đang chờ ở bước "Use auto config?"), dán đoạn mã đó
   vào, nhấn Enter.

### Kiểm tra remote vừa tạo

```
rclone lsd gdrive:
```

Lệnh này liệt kê các thư mục trong Google Drive (hoặc Shared Drive) vừa nối — không báo lỗi xác
thực là đã đúng. Sau đó tạo trước thư mục sẽ chứa bản sao lưu (ví dụ):

```
rclone mkdir gdrive:VK-CRM-backups
```

---

## Bước 4 — Điền `.env` trên máy chủ

Mở tệp `.env` trên máy chủ (nhờ người quản trị máy chủ nếu chưa quen), điền các dòng sau (đã có
sẵn trong `.env.example`, chỉ cần điền giá trị thật):

```
BACKUP_DISKS=local_backups
BACKUP_ARCHIVE_PASSWORD=<một-chuỗi-ngẫu-nhiên-dài-tự-tạo>
BACKUP_NOTIFY_EMAIL=<email-nhận-báo-lỗi>
BACKUP_RCLONE_REMOTE=gdrive:VK-CRM-backups
BACKUP_RCLONE_BINARY=
BACKUP_RCLONE_CONFIG=
BACKUP_LOCAL_KEEP=7
```

- `BACKUP_ARCHIVE_PASSWORD`: tự tạo một chuỗi dài, ngẫu nhiên (ví dụ bằng một trình quản lý mật
  khẩu). **Đây là chìa khoá duy nhất mở được bản sao lưu** — xem Bước 5 về nơi cất nó.
- `BACKUP_RCLONE_REMOTE`: đúng tên remote đã tạo ở Bước 3 (`gdrive`), cộng dấu hai chấm, cộng tên
  thư mục đã tạo (`VK-CRM-backups`). Để TRỐNG dòng này thì tắt hẳn việc đẩy lên Google Drive — máy
  chủ tự giữ đủ 30 bản như khi chưa có Google Drive.
- `BACKUP_RCLONE_BINARY` và `BACKUP_RCLONE_CONFIG`: để trống trong tình huống thông thường (bước 1
  và 3 dùng đúng vị trí mặc định của `rclone`). Chỉ điền nếu người quản trị máy chủ cố tình cài
  `rclone` ở một nơi khác, hoặc dùng một tệp `rclone.conf` khác vị trí mặc định.

Sau khi sửa `.env`, khởi động lại tiến trình chạy nền của ứng dụng (nếu có) để giá trị mới có hiệu
lực — hỏi người quản trị máy chủ cách làm đúng trên máy chủ cụ thể của văn phòng.

---

## Bước 5 — Chạy lệnh kiểm tra

```
php artisan vkcrm:backup-check
```

Lệnh này **không chờ tới đêm** — nó thử ngay lập tức: ghi một tệp nhỏ, đọc lại, xoá đi, trên MỖI
đích sao lưu (máy chủ, và Google Drive nếu đã điền `BACKUP_RCLONE_REMOTE`). Kết quả in ra bằng
tiếng Việt, từng dòng một đích.

- Mọi dòng đều có chữ **"OK"** và lệnh kết thúc với dòng "Tất cả đích sao lưu đều ổn" — đã cấu
  hình đúng.
- Có dòng chữ **"LỖI"** — đọc chi tiết lỗi ngay sau dấu gạch ngang, đối chiếu lại Bước 3/4. Lỗi
  thường gặp nhất: gõ sai tên remote ở `BACKUP_RCLONE_REMOTE` (thiếu dấu `:`, hoặc sai tên thư mục
  so với lúc `rclone mkdir`).

Chỉ kiểm một đích cụ thể (ví dụ chỉ Google Drive, không đụng tới đĩa máy chủ):

```
php artisan vkcrm:backup-check rclone
```

**Chạy lại lệnh này sau MỖI lần đổi `.env` liên quan tới sao lưu**, và định kỳ (ví dụ đầu mỗi
tháng) để chắc token Google chưa hết hạn.

---

## Bước 6 — Cất `APP_KEY` và `BACKUP_ARCHIVE_PASSWORD` ở NƠI KHÁC

Đây là bước **quan trọng nhất** của toàn bộ tài liệu này, và là bước dễ bị bỏ qua nhất.

Bản sao lưu chứa dữ liệu **đã được mã hoá hai lớp**:

1. Toàn bộ archive được nén và khoá bằng `BACKUP_ARCHIVE_PASSWORD` — không có mật khẩu này thì
   không mở được tệp `.zip` ra để xem gì cả.
2. Bên trong cơ sở dữ liệu, một số cột nhạy cảm (ví dụ số CCCD/CMND của khách hàng, và bí mật xác
   thực hai lớp của nhân sự) lại được mã hoá RIÊNG bằng `APP_KEY` của ứng dụng. `APP_KEY` **KHÔNG
   nằm trong bản sao lưu** (`laravel-backup` không sao lưu tệp `.env`).

**Hệ quả: nếu chỉ có bản sao lưu mà KHÔNG có cả hai chìa khoá này, KHÔNG khôi phục được gì có
nghĩa.** Mất `APP_KEY` là mất vĩnh viễn mọi số CCCD và mọi bí mật 2FA của nhân sự trong bản sao lưu
đó — kể cả khi tệp sao lưu vẫn còn nguyên trên Google Drive.

**Quy tắc cất giữ:**

- Cất **`APP_KEY`** (giá trị thật trong `.env` trên máy chủ, dòng bắt đầu bằng `APP_KEY=base64:`)
  và **`BACKUP_ARCHIVE_PASSWORD`** ở **HAI NƠI**, cả hai đều **NGOÀI MÁY CHỦ**.
- **KHÔNG BAO GIỜ** cất chung chỗ với bản sao lưu (không bỏ vào cùng thư mục Google Drive
  "VK-CRM-backups", không gửi kèm trong cùng một email báo lỗi).
- Gợi ý chỗ cất: một trình quản lý mật khẩu có chia sẻ nhóm (ví dụ 1Password/Bitwarden của văn
  phòng), CỘNG một bản giấy hoặc USB cất trong két sắt văn phòng — hai nơi độc lập, để một nơi mất
  (cháy văn phòng, tài khoản quản lý mật khẩu bị khoá) vẫn còn nơi kia.
- Ghi rõ ngày lấy giá trị, vì `APP_KEY` có thể đổi nếu ứng dụng từng chạy `php artisan key:generate`
  lại (không nên làm việc này sau khi đã có dữ liệu thật — nhưng nếu lỡ xảy ra, bản sao lưu cũ cần
  đúng `APP_KEY` CŨ, không phải key hiện tại).

---

## Khôi phục thử (Task 3 — CHƯA VIẾT)

> Phần này để trống có chủ đích. Quy trình khôi phục đầy đủ — kèm số đo thời gian thật, làm trên
> một container sạch, mở lại một hồ sơ và giải mã được một số CCCD — thuộc M8a Task 3 (R3: "Sao lưu
> chưa khôi phục thử thì chưa phải sao lưu"). Task 3 điền tiếp đúng vào mục này, không tạo tệp mới.
