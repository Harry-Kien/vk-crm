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
không có nơi nào khác giữ bản đầy đủ). Trên máy chủ thật (`APP_ENV=production`) cấu hình đó **gửi
email báo lỗi mỗi đêm** — "không có bản sao ngoài máy chủ" — cho tới khi bật Google Drive (hoặc một
đĩa sao lưu khác nằm ngoài máy chủ): bản sao lưu nằm cùng ổ đĩa với dữ liệu thì máy chủ hỏng ổ là
mất cả hai.

**Mỗi môi trường một thư mục trên Google Drive.** Hệ thống đẩy bản sao lưu vào một thư mục con của
remote, đặt tên theo `BACKUP_NAME` (ví dụ `BACKUP_NAME="VK-CRM production"` → thư mục
`gdrive:VK-CRM-backups/vk-crm-production`). Nếu máy chủ thật và máy thử (staging) dùng chung một
Google Drive, **mỗi máy phải có `BACKUP_NAME` khác nhau** — đó là thứ giữ cho lượt dọn "giữ 30 bản"
của máy này không bao giờ đếm hay xoá bản sao lưu của máy kia. Đừng chép tay tệp giữa các thư mục
đó.

**Bản bị dọn trên Google Drive đi vào Thùng rác**, không mất ngay: Google giữ chúng thêm 30 ngày và
chúng **vẫn tính vào dung lượng** của tài khoản trong 30 ngày đó. Khi tính dung lượng Google Drive
cần mua, tính cho khoảng **60 bản** (30 bản đang giữ + khoảng 30 bản trong Thùng rác), không phải
30. Có thể dọn Thùng rác sớm bằng tay trên giao diện Google Drive nếu thiếu chỗ.

**Nếu một lượt sao lưu, dọn dẹp, hay đẩy lên Google Drive thất bại**, hệ thống gửi email báo lỗi
tới địa chỉ khai ở `BACKUP_NOTIFY_EMAIL` (hoặc mọi Admin đang hoạt động, nếu chưa khai địa chỉ đó).
Ngoài ra, **mỗi sáng lúc 08:00** hệ thống tự kiểm: bản mới nhất trên Google Drive phải dưới 36 giờ
tuổi — nếu các lượt đẩy đêm gần đây lặng lẽ không lên được, sáng hôm đó có email báo lỗi.
**Một email báo lỗi sao lưu không phải chuyện có thể để đó "xem sau"** — vụ việc mất một ngày sao
lưu vào đúng ngày máy chủ hỏng là vụ việc không lấy lại được.

---

## Bước 1 — Cài `rclone` trên máy chủ

`rclone` là một chương trình miễn phí, làm việc "chép tệp lên Google Drive" — hệ thống VK-CRM gọi
nó thay vì tự nói chuyện trực tiếp với Google (lý do kỹ thuật: xem
`docs/research/2026-09-26-sao-luu.md`, mục "Task 2").

`rclone` là MỘT tệp chạy duy nhất, không cần quyền quản trị để cài — cách dưới đây dùng được cả
trên VPS lẫn trên shared hosting (nơi không có `sudo`). Nhờ người quản trị máy chủ, hoặc tự làm qua
SSH bằng đúng tài khoản chạy ứng dụng, một lần:

```
cd ~
curl -O https://downloads.rclone.org/rclone-current-linux-amd64.zip
curl -O https://downloads.rclone.org/SHA256SUMS
```

Đối chiếu tệp vừa tải với bảng mã kiểm tra chính thức (dòng `rclone-v…-linux-amd64.zip` trong
`SHA256SUMS` phải trùng với kết quả của lệnh dưới — khác là tệp tải về bị hỏng hoặc bị tráo, dừng
lại):

```
sha256sum rclone-current-linux-amd64.zip
```

Rồi giải nén và đặt vào thư mục `bin` trong thư mục nhà của tài khoản đó:

```
unzip rclone-current-linux-amd64.zip
mkdir -p ~/bin
cp rclone-v*-linux-amd64/rclone ~/bin/rclone
chmod 755 ~/bin/rclone
rm -r rclone-current-linux-amd64.zip SHA256SUMS rclone-v*-linux-amd64
```

(Máy chủ dùng chip ARM thì thay `linux-amd64` bằng `linux-arm64`. **Không** dùng cách
`curl … | sudo bash` hay thấy trên mạng: nó chạy một kịch bản tải về với quyền quản trị mà không
cho ai xem trước, và không làm được trên shared hosting.)

Kiểm tra đã cài xong (dùng đường dẫn đầy đủ — thư mục `~/bin` không nhất thiết nằm trong `PATH` của
tiến trình chạy lịch hằng đêm):

```
~/bin/rclone version
```

Lệnh trên phải in ra một số phiên bản (ví dụ `rclone v1.68.0`), không phải lỗi "No such file".
Ghi lại đường dẫn đầy đủ của tệp (in bằng `echo ~/bin/rclone`, ví dụ `/home/vukhang/bin/rclone`) —
Bước 4 điền nó vào `BACKUP_RCLONE_BINARY`. Ở các bước dưới, chỗ nào ghi `rclone …` thì gõ đường
dẫn đầy đủ đó.

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

Không cần tạo thư mục con cho từng môi trường — hệ thống tự tạo `gdrive:VK-CRM-backups/<tên>`
(theo `BACKUP_NAME`, xem Bước 4) ở lượt đẩy đầu tiên.

---

## Bước 4 — Điền `.env` trên máy chủ

Mở tệp `.env` trên máy chủ (nhờ người quản trị máy chủ nếu chưa quen), điền các dòng sau (đã có
sẵn trong `.env.example`, chỉ cần điền giá trị thật):

```
BACKUP_DISKS=local_backups
BACKUP_NAME="VK-CRM production"
BACKUP_ARCHIVE_PASSWORD=<một-chuỗi-ngẫu-nhiên-dài-tự-tạo>
BACKUP_NOTIFY_EMAIL=<email-nhận-báo-lỗi>
BACKUP_RCLONE_REMOTE=gdrive:VK-CRM-backups
BACKUP_RCLONE_BINARY=<đường-dẫn-đầy-đủ-ghi-lại-ở-Bước-1>
BACKUP_RCLONE_CONFIG=
BACKUP_LOCAL_KEEP=7
BACKUP_RCLONE_TIMEOUT=
BACKUP_MAX_STORAGE_MB=
```

- `BACKUP_NAME`: tên của MÔI TRƯỜNG này. Nó đặt tên thư mục con trên Google Drive
  (`"VK-CRM production"` → `gdrive:VK-CRM-backups/vk-crm-production`) và tiền tố tên từng bản sao
  lưu. Máy thử (staging) dùng chung Google Drive thì đặt tên khác (ví dụ `"VK-CRM staging"`). Để
  trống thì dùng `APP_NAME`.
- `BACKUP_ARCHIVE_PASSWORD`: tự tạo một chuỗi dài, ngẫu nhiên (ví dụ bằng một trình quản lý mật
  khẩu). **Đây là chìa khoá duy nhất mở được bản sao lưu** — xem Bước 6 về nơi cất nó.
- `BACKUP_RCLONE_REMOTE`: đúng tên remote đã tạo ở Bước 3 (`gdrive`), cộng dấu hai chấm, cộng tên
  thư mục đã tạo (`VK-CRM-backups`). Để TRỐNG dòng này thì tắt hẳn việc đẩy lên Google Drive — máy
  chủ tự giữ đủ 30 bản như khi chưa có Google Drive, và trên máy chủ thật mỗi đêm có email báo lỗi
  "không có bản sao ngoài máy chủ".
- `BACKUP_RCLONE_BINARY`: đường dẫn đầy đủ tới tệp `rclone` đã cài ở Bước 1 (ví dụ
  `/home/vukhang/bin/rclone`). Chỉ để trống khi `rclone` đã nằm trong `PATH` của hệ thống (ví dụ
  người quản trị VPS tự cài vào `/usr/local/bin`) — lịch chạy hằng đêm không đọc `PATH` của phiên
  SSH của anh/chị.
- `BACKUP_RCLONE_CONFIG`: để trống trong tình huống thông thường (Bước 3 lưu cấu hình vào vị trí
  mặc định của `rclone`). Chỉ điền nếu dùng một tệp `rclone.conf` khác vị trí mặc định.
- `BACKUP_LOCAL_KEEP`: số bản giữ trên máy chủ khi đã bật Google Drive. Để trống là 7.
- `BACKUP_RCLONE_TIMEOUT`: hạn cho mỗi lệnh `rclone`, tính bằng giây. Để trống là 1800 (30 phút).
  Chỉ nới ra khi email báo lỗi nói lệnh `rclone` bị quá hạn (bản sao lưu rất lớn, mạng chậm).
- `BACKUP_MAX_STORAGE_MB`: hạn mức dung lượng mỗi đĩa sao lưu, tính bằng MB — vượt mức thì kiểm tra
  08:00 gửi email "bản sao lưu không lành mạnh". Để trống là 25000 (~24 GB, đủ 7 bản của một bản
  sao lưu 3 GB). Chọn theo dung lượng ổ đĩa máy chủ.

**⚠ `BACKUP_DISKS` PHẢI CÒN `local_backups` khi đã bật `BACKUP_RCLONE_REMOTE`.** Việc đẩy lên
Google Drive đi qua đúng bước ghi vào đĩa `local_backups` trên máy chủ — nếu ai đó sau này sửa
`BACKUP_DISKS` và lỡ bỏ mất `local_backups` (ví dụ gõ nhầm, hoặc dọn `.env` không cẩn thận), lượt
đẩy lên Google Drive mỗi đêm **lặng lẽ không chạy nữa**, dù không có lỗi nào hiện ra ngay lúc đó.
`php artisan vkcrm:backup-check` (Bước 5) bắt được ngay tình huống này; `backup:run` mỗi đêm cũng tự
gửi thư báo lỗi khi phát hiện — nhưng cách chắc nhất vẫn là không đụng vào `BACKUP_DISKS` sau khi
đã cấu hình xong, trừ khi THÊM một disk mới.

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

## Khôi phục thử

> ⚠ **Khôi phục mà không có `APP_KEY` CŨ (đúng bản đi kèm bản sao lưu đang khôi phục) mất vĩnh
> viễn mọi số CCCD và mọi bí mật hai lớp của nhân sự trong bản sao lưu đó.** Đây là lỗi KHÔNG sửa
> được sau khi đã xảy ra — tệp `.zip` vẫn mở được, cơ sở dữ liệu vẫn nạp được, ứng dụng vẫn chạy
> được, nhưng mọi cột `encrypted` (`clients.id_number`, secret 2FA của nhân sự) đọc ra sẽ ném lỗi
> `The MAC is invalid.` (hoặc tương đương) — không có "phục hồi lần hai". Trước khi làm bất cứ điều
> gì dưới đây, xác nhận đã lấy được **đúng** `APP_KEY` và `BACKUP_ARCHIVE_PASSWORD` đi cùng THỜI
> ĐIỂM của bản sao lưu đang khôi phục (Bước 6 ở trên) — không phải giá trị hiện tại của `.env` nếu
> `APP_KEY` từng bị đổi.

R3 (kế hoạch M8, `docs/superpowers/plans/2026-09-21-m8-security-and-launch.md`): "sao lưu chưa
khôi phục thử thì chưa phải sao lưu". Mục này ghi lại quy trình cho **máy chủ thật**, và bảng số đo
thật của một lượt khôi phục thử đã chạy trên máy dev bằng `tools/backup/restore-drill.sh` (kịch
bản đó làm lại đúng các bước dưới đây trong container, tự động, để diễn tập định kỳ không cần làm
tay).

### Quy trình cho máy chủ thật

Khi cần khôi phục thật (máy chủ hỏng, cần dựng lại, hoặc diễn tập định kỳ — khuyến nghị ít nhất mỗi
quý một lần, ghi kết quả vào `docs/PROGRESS.md`):

1. **Lấy hai chìa khoá TRƯỚC TIÊN**, từ chỗ cất riêng (Bước 6 ở trên, KHÔNG phải từ máy chủ đã
   hỏng): `APP_KEY` và `BACKUP_ARCHIVE_PASSWORD` — đúng bản đi kèm THỜI ĐIỂM của bản sao lưu sẽ
   dùng. Không có cả hai thì dừng lại ở đây; đọc tiếp không giải quyết được gì.
2. **Lấy bản sao lưu** — tải tệp `.zip` mới nhất (hoặc bản ở đúng ngày cần khôi phục) từ Google
   Drive, hoặc từ đĩa `local_backups` trên máy chủ văn phòng nếu còn. Bản trên Google Drive nằm
   trong thư mục của môi trường (`gdrive:VK-CRM-backups/<tên theo BACKUP_NAME>`, xem Bước 4); tên
   tệp mang ngày giờ tạo, nên bản mới nhất là dòng CUỐI của danh sách. Trên máy khôi phục đã cài
   `rclone` và nối remote `gdrive` (Bước 1 và 3):
   ```
   rclone lsf gdrive:VK-CRM-backups/vk-crm-production/
   rclone copy gdrive:VK-CRM-backups/vk-crm-production/vk-crm-production-2026-09-27-02-00-12.zip ./khoi-phuc/
   ```
   Lệnh thứ hai tải đúng một tệp (thay tên tệp bằng tên thấy ở lệnh thứ nhất) vào thư mục
   `./khoi-phuc/`. Không có `rclone` thì tải bằng trình duyệt từ giao diện Google Drive — cùng
   một tệp.
3. **Dựng một môi trường SẠCH** — máy chủ mới hoặc máy chủ đã cài lại từ đầu theo `README.md`/
   `docs/CAI-DAT.md` (mã nguồn qua Git, `composer install`, extension PHP đầy đủ), với MỘT cơ sở
   dữ liệu MariaDB RỖNG (không phải cơ sở dữ liệu cũ còn sót lại — một cơ sở dữ liệu cũ có thể che
   giấu một lỗi nạp dump bằng dữ liệu vốn đã có sẵn).
4. **Giải nén** bằng `BACKUP_ARCHIVE_PASSWORD` lấy ở bước 1. Archive dùng mã hoá AES-256
   (`ZipArchive::EM_AES_256`) — chương trình `unzip` tiêu chuẩn trên nhiều bản Linux (và trên
   Alpine) KHÔNG mở được kiểu mã hoá này và báo lỗi mập mờ kiểu "unsupported compression method";
   dùng `7z x -p'<mật khẩu>' <tệp>.zip` (gói `p7zip`) hoặc một đoạn PHP ngắn qua `ZipArchive`
   (`$zip->setPassword($pw); $zip->extractTo($thư_mục);`) — đây chính xác là cách
   `tools/backup/restore-drill.sh` làm.
5. **Nạp bản dump CSDL** — tệp nằm ở `db-dumps/<tên-driver>-<tên-csdl>.sql` sau khi giải nén.
   ⚠ Bản dump tạo bằng `mariadb-dump` (bản mới) mở đầu bằng dòng
   `/*M!999999\- enable the sandbox mode */`. Nạp bằng client **`mariadb`** (không phải `mysql` cũ
   hay MySQL, thứ không đọc được dòng đó và dừng lại với lỗi cú pháp ngay dòng đầu tiên):
   ```
   mariadb -u<user> -p<mật khẩu CSDL> <tên-csdl> < duong-dan/db-dumps/ten-tep.sql
   ```
   Máy chủ đích PHẢI có sẵn gói mang lệnh `mariadb`/`mariadb-dump` (ví dụ `apt install
   mariadb-client` trên Ubuntu/Debian) — đây là điều kiện cần đã nêu ở SPEC §10 mục 8 (lệnh kiểm
   tự động `vkcrm:preflight` thuộc M8 Task 8, **chưa có** — hiện phải kiểm tay bằng
   `mariadb-dump --version`; xem `docs/CAI-DAT.md`, mục "Khi đưa lên máy chủ thật").
6. **Chép tệp hồ sơ** — mọi mục trong archive có tiền tố `storage/app/private/` (đường TƯƠNG ĐỐI
   tính từ gốc ứng dụng — xem đoạn giải thích `relative_path` ở docblock
   `config/backup.php`) chép về ĐÚNG thư mục `storage/app/private/` của máy chủ mới, giữ nguyên
   cấu trúc thư mục con.
7. **Đặt `APP_KEY`** trong `.env` của máy chủ mới bằng ĐÚNG giá trị lấy ở bước 1 — làm TRƯỚC khi
   cho ứng dụng chạy thật (trước khi ai đăng nhập hay đọc một hồ sơ nào).
8. **`php artisan migrate:status`** — xác nhận không có migration nào "đang chờ" (mọi dòng đều có
   `Ran`). Nếu có dòng chưa chạy, đó là dấu hiệu bản dump cũ hơn mã nguồn đang triển khai — dừng
   lại, đối chiếu lại phiên bản mã nguồn với thời điểm bản sao lưu trước khi đi tiếp.
9. **Mở thử một hồ sơ khách hàng bất kỳ** và xác nhận đọc được `id_number` (không ném lỗi giải
   mã), rồi **mở thử một tài liệu** và xác nhận tệp mở được, đúng nội dung. Đây là bước "coi là
   xong" duy nhất được chấp nhận — `migrate:status` xanh không đủ, vì nó không chạm tới cột
   `encrypted` hay tệp nhị phân nào.
10. **So dòng vài bảng chính** (`clients`, `matters`, `documents`, `users`) với số liệu ghi nhận
    lúc sao lưu (nếu có) — một hệ thống giám sát tốt (`backup:monitor`, Task 1) nên đã cảnh báo từ
    trước nếu bản sao lưu bị cắt cụt, nhưng đối chiếu lại ở đây là lớp phòng thủ cuối.

### Bảng số đo thật (lượt khôi phục thử ngày 2026-09-27, máy dev, `tools/backup/restore-drill.sh`)

Đo trên máy dev (Docker Desktop, Windows), KHÔNG phải máy chủ sản xuất — số đo thật trên máy chủ
sản xuất sẽ khác (mạng, cấu hình máy, dung lượng dữ liệu thật), nhưng THỨ TỰ các bước và việc bước
nào tốn thời gian nhất thì giữ nguyên. Diễn tập lại trên máy chủ thật khi có, ghi đè bảng này.

Lượt đo dưới đây chạy lúc 20:14 ngày 2026-09-27, sau lượt rà soát cuối M8a (kịch bản nay dựng cả
"máy nguồn" trong một bản sao riêng — bước 1 — nên có 13 bước thay vì 12 như lượt đo đầu).

| # | Bước | Thời gian |
|---|---|---:|
| 1 | Dựng bản sao mã nguồn NGUỒN (không dữ liệu, không `.env`, không cache) | 10,77 s |
| 2 | Xây CSDL nguồn riêng (`migrate:fresh --seed`) | 42,66 s |
| 3 | Tạo dữ liệu thử (khách hàng + tài liệu) | 11,82 s |
| 4 | `backup:run` thật (dump CSDL + tệp, mã hoá AES-256) | 15,14 s |
| 5 | Định vị archive | 0,11 s |
| 6 | Dựng bản sao mã nguồn SẠCH cho bản khôi phục (không `storage/app/private`) | 8,09 s |
| 7 | Dựng MariaDB sạch tạm thời | 4,65 s |
| 8 | Giải nén archive bằng mật khẩu | 1,22 s |
| 9 | Nạp bản dump vào MariaDB sạch | 0,92 s |
| 10 | Chép tệp hồ sơ về `storage/app/private` | 0,11 s |
| 11 | `migrate:status` trên bản khôi phục | 19,05 s |
| 12 | Giải mã `id_number` + so checksum tệp + đếm dòng | 11,18 s |
| 13 | Chứng minh thất bại với `APP_KEY` mới | 8,75 s |
| | **TỔNG** | **134,46 s (~2 phút 14 giây)** |

Dữ liệu ở lượt đo này: 13 khách hàng, 21 vụ việc, 49 tài liệu (99 mục trong archive, 76 KB nén —
dữ liệu mẫu, không phải quy mô dữ liệu thật của văn phòng sau vài năm vận hành). Bước 2
(`migrate:fresh --seed`), bước 11 (`migrate:status`) và bước 4 (`backup:run`) tốn thời gian nhất
trong lượt đo này vì chi phí khởi động container (`apk add mariadb-client`, khởi động PHP trong
container mới cho mỗi bước) và vì máy dev đang chạy chung Docker với các làn khác — trên máy chủ
thật, nơi các gói cần thiết đã cài sẵn và không phải khởi động container mới cho mỗi bước, quy
trình thật (mục "Quy trình cho máy chủ thật" ở trên) sẽ nhanh hơn đáng kể; ngược lại, dữ liệu thật
sau nhiều năm (hàng GB tệp hồ sơ) sẽ làm bước giải nén và bước chép tệp (8, 10) chậm hơn nhiều so
với 49 tệp mẫu ở đây. Diễn tập định kỳ trên dữ liệu thật là cách duy nhất biết con số thật.

**Bằng chứng "APP_KEY là một nửa của bản sao lưu" (R3), đo được thật, không suy luận:** lặp lại
bước 12 với một `APP_KEY` ngẫu nhiên KHÁC (không phải khoá đã tạo dữ liệu) trên ĐÚNG bản khôi phục
vừa nạp — `Illuminate\Contracts\Encryption\DecryptException: The MAC is invalid.` Cơ sở dữ liệu vẫn
nạp được, `migrate:status` vẫn xanh, tệp tài liệu vẫn mở được (nó không mã hoá bằng `APP_KEY`) —
nhưng `id_number` của MỌI khách hàng vĩnh viễn không đọc lại được. Đây chính xác là kịch bản
"khôi phục sinh khoá mới vẫn thành công nhưng mất mọi số CCCD" mà R3 cảnh báo.

### Diễn tập lại trên máy dev

```
tools/backup/restore-drill.sh
```

Chạy từ Git Bash, trong worktree này. Kịch bản tự làm lại toàn bộ 13 bước ở trên trong container
tạm: "máy nguồn" là một BẢN SAO mã nguồn riêng của lượt chạy (seed, dữ liệu thử và `backup:run`
chạy trong bản sao đó, không ghi gì vào `storage/app/private`, `storage/app/backups` hay
`bootstrap/cache` của worktree), CSDL nguồn là `vk_crm_lane_m8_drill_<thời gian>_<PID>` do chính
lượt chạy tạo và xoá (không đụng `vk_crm` hay `vk_crm_lane_m8`; hai lượt chạy cùng lúc không đụng
nhau), đích khôi phục là một container MariaDB tạm thời tên `vkcrm-lane-m8-restore-<thời gian>_<PID>`
và một bản sao mã nguồn sạch thứ hai. Kịch bản dừng ngay nếu một bản sao có
`bootstrap/cache/config.php` (cache cấu hình làm Laravel bỏ qua CSDL và khoá dùng một lần). Mọi
thứ của lượt chạy nằm dưới `storage/app/_drill/` (git bỏ qua) và bị xoá khi xong, kể cả khi lỗi
giữa chừng. Không chạy trên máy chủ thật — kịch bản này giả lập, không thay thế quy trình thật ở
trên (nó cũng KHÔNG dùng SFTP/rclone, vì đích thử là chính máy dev).

### Đóng gói bàn giao M7 — có sao lưu lại không?

Kế hoạch M8 (Task 5) yêu cầu phán quyết "có loại gói bàn giao (M7 Task 4) khỏi bản sao lưu hay
không — nó là bản sao thứ hai của tệp đã có". Tại thời điểm viết mục này, **M7 chưa merge vào
nhánh này** (làn M8a cắt từ `b34e95c`, trước M7), nên chưa có gói bàn giao nào tồn tại để đo dung
lượng hay tần suất sinh ra. Phán quyết dưới đây vì vậy là TẠM, ghi lại để M7 (hoặc lượt gộp làn sau
M7) đọc và xác nhận lại, không phải viết mã ở Task 3 này:

**Phán quyết tạm:** KHÔNG loại trừ gói bàn giao khỏi `include` của `config/backup.php`. Lý do dự
kiến: gói bàn giao (theo mô tả M7 Task 4 trong kế hoạch) là một tệp TỔNG HỢP sinh ra TỪ dữ liệu đã
có trong CSDL và `storage/app/private` — về lý thuyết "sao lưu lại nó" là dư thừa, vì phục hồi được
CSDL + tệp gốc thì dựng lại được gói bàn giao bất cứ lúc nào bằng đúng lệnh đã sinh ra nó lần đầu.
Nhưng loại trừ nó đòi hỏi: (a) gói bàn giao phải luôn tái sinh được TỰ ĐỘNG mà không cần con người
nhớ chạy lại lệnh — nếu M7 không đảm bảo điều này (ví dụ gói bàn giao có thể được chỉnh tay sau khi
sinh, hoặc sinh ra một lần và không lưu lại "công thức" đủ để tái tạo y hệt), loại trừ nó khỏi sao
lưu là mất dữ liệu thật; (b) biết chắc gói bàn giao luôn nằm ở một thư mục cố định, tách biệt khỏi
`storage/app/private`, để loại trừ đúng chỗ mà không loại nhầm tài liệu khách hàng thật đứng cạnh
nó. Không mục nào trong hai điều trên có thể xác nhận trước khi đọc mã M7 thật. Vì vậy, cho tới khi
người đọc lại phán quyết này (sau khi M7 merge) xác nhận cả (a) và (b), **giữ nguyên: sao lưu tất
cả những gì nằm trong `storage/app/private`**, kể cả khi có trùng lặp — dư thừa vài phần trăm dung
lượng archive rẻ hơn một gói bàn giao không khôi phục lại được. Xem thêm PROGRESS-note trong báo
cáo Task 3 (`.superpowers/sdd/2026-09-26-m8a-backup-csp/task-3-report.md`).
