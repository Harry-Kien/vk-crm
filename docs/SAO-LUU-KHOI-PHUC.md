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

### Khi đã bật kho tài liệu Google Drive (M14)

Từ M14, tệp hồ sơ có thể nằm trên Shared Drive "Kho" thay vì trên máy chủ
(`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`). Tệp mới vẫn vào máy chủ trước (thư mục `storage/app/private`,
gọi là **vùng đệm**), được đẩy lên Kho ngay sau đó, và chỉ bị dọn khỏi máy chủ khi **máy chủ văn
phòng** (Phụ lục D) đã kéo tệp đó về, kiểm nội dung, và gửi **biên nhận** cho đúng tệp đó. Ở chế độ
này, hệ thống giữ những bản sau:

| Dữ liệu | Bản chính | Bản thứ hai, ngoài Google | Mã hoá bằng khoá của văn phòng? |
|---|---|---|---|
| Cơ sở dữ liệu (hồ sơ, vụ việc, khách hàng, chỉ mục kho `drive_objects`) | MariaDB trên máy chủ | Archive đêm 02:00 (30 bản trên "VK-CRM Backups"); máy văn phòng kéo mọi archive về mỗi đêm | Có: AES-256 bằng `BACKUP_ARCHIVE_PASSWORD` |
| Tệp chưa có biên nhận văn phòng (tệp mới, hoặc mọi tệp khi chưa có máy văn phòng) | Kho, cộng vùng đệm trên máy chủ | Vùng đệm nằm trong archive đêm | Archive: có. Bản trên Kho: **không** |
| Tệp đã có biên nhận văn phòng | Kho | Máy văn phòng, remote `crypt` | Bản ở văn phòng: có (`crypt`). Bản trên Kho: **không** |
| Gói bàn giao M7 | Như mọi tệp ở hai dòng trên | Như mọi tệp | Như mọi tệp |
| Biên nhận của máy văn phòng | "VK-CRM Backups", thư mục `office-receipts` | — | Không cần: chỉ tên mờ, md5 và cỡ, không dữ liệu khách |

Nói thẳng ba điều:

- **Thùng rác 30 ngày và lịch sử phiên bản của Google Drive KHÔNG phải sao lưu.** Chúng nằm trong
  chính Workspace có thể bị khoá hay bị chiếm, và Google tự xoá chúng. Không chỗ nào trong tài liệu
  này tính chúng là một bản.
- **Archive đêm chỉ còn chứa vùng đệm.** Khi một tệp đã có biên nhận văn phòng và được dọn khỏi máy
  chủ, archive của các đêm SAU không còn tệp đó: bản của nó lúc ấy là Kho và máy văn phòng. Chưa có
  máy văn phòng thì không tệp nào được dọn, và archive vẫn chứa mọi tệp như trước M14.
- **Tệp trên Kho không được văn phòng mã hoá.** Google mã hoá khi lưu bằng khoá của Google; người có
  quyền quản trị Workspace (và tài khoản quản trị dự phòng) mở được tệp trên giao diện Drive. Chỉ bản
  ở máy văn phòng (`crypt`) và archive CSDL (AES-256) được mã hoá bằng khoá của văn phòng. Trước M14,
  bản duy nhất của tệp khách nằm ngoài máy chủ là archive AES-256; đây là một bảo đảm đã đổi, và
  cần chữ ký của chủ văn phòng (câu hỏi 8 của kế hoạch M14, `docs/PHAP-LY-LUU-TRU-NUOC-NGOAI.md`).

Bản nào còn lại khi có sự cố:

| Sự cố | Bản còn lại |
|---|---|
| Lỗi của CRM xoá hoặc ghi sai | Bản ở văn phòng (chỉ chép thêm, không bao giờ xoá); vùng đệm trong thời gian ân hạn |
| Lộ khoá tài khoản dịch vụ của Kho | Khoá đó chỉ cho tệp vào thùng rác, không chạm được máy văn phòng. Tệp bị sửa trên Kho làm lượt kéo của máy văn phòng báo lỗi, và lỗi đó tới CRM qua biên nhận |
| Máy chủ web bị chiếm, mã độc tống tiền | Bản ở văn phòng; Kho có thể bị ghi đè nội dung hay cho vào thùng rác |
| Google khoá Workspace, hay quản trị viên Workspace bị chiếm | Bản ở văn phòng. Trước khi có máy văn phòng: vùng đệm trên máy chủ, vì nó chưa bao giờ được dọn |
| Máy văn phòng hỏng, cháy, mất | Kho, cộng vùng đệm của tệp chưa dọn. Kéo lại toàn bộ sang máy mới (Phụ lục D) |
| Mất **cùng lúc** Workspace và máy văn phòng | **Không đỡ được.** Văn phòng đã chấp nhận rủi ro này |

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
tuổi. Lúc 08:00, bản của đêm qua mới khoảng 6 giờ tuổi và bản của đêm hôm trước khoảng 30 giờ — nên
lượt kiểm này chỉ báo khi **HAI đêm liền** không lên được (kể cả khi chúng hỏng lặng lẽ, không có
email nào), vào sáng sau đêm thứ hai. Một đêm hỏng đơn lẻ được báo bằng email lỗi của chính lượt
đẩy đêm đó, không phải bởi lượt kiểm 08:00 — chủ văn phòng đã chọn giữ ngưỡng 36 giờ.
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

Sau khi sửa `.env`, giá trị mới chỉ có hiệu lực khi cấu hình được nạp lại: trên máy chủ đã cache
cấu hình (`php artisan optimize`, `docs/CAI-DAT.md` Bước 7), chạy lần lượt `php artisan
optimize:clear`, `php artisan vkcrm:preflight`, `php artisan optimize` — hỏi người quản trị máy chủ
nếu chưa quen.

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
   thực hai lớp của nhân sự) lại được mã hoá RIÊNG bằng `APP_KEY` của ứng dụng — và cột so trùng số
   CCCD của kiểm tra xung đột lợi ích được băm bằng chính `APP_KEY` đó (M8 Task 4). `APP_KEY`
   **KHÔNG nằm trong bản sao lưu** (`laravel-backup` không sao lưu tệp `.env`).

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

**Cùng chỗ đó, cặp khoá thông báo đẩy (M12): `VAPID_PRIVATE_KEY` và `VAPID_PUBLIC_KEY`** (hai dòng
trong `.env`, sinh một lần ở `docs/CAI-DAT.md`, Bước 3), kèm `VAPID_SUBJECT`. Khoá riêng này cùng
hạng bí mật với `APP_KEY`: ai có nó thì ký được lời gửi tới điện thoại đã đăng ký của nhân sự và
khách. Nó **không** nằm trong bản sao lưu (`.env` không được sao lưu), nên:

- khôi phục mà còn cặp khoá CŨ: mọi điện thoại đã bật thông báo tiếp tục nhận, không ai phải làm gì;
- mất cặp khoá (hay nghi bị lộ): sinh cặp mới rồi chạy `php artisan vkcrm:push-reset` — đăng ký cũ
  chết im lặng với khoá mới (máy chủ push trả 401/403, không tự dọn), lệnh xoá hết chúng và ghi nhật
  ký; mọi người bật lại thông báo trên từng máy. Không mất dữ liệu hồ sơ nào: khoá này chỉ dùng để
  gửi thông báo.

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
   tệp mang ngày giờ tạo (năm-tháng-ngày-giờ-phút-giây), nên SẮP XẾP theo tên thì bản mới nhất là
   dòng cuối. `rclone lsf` KHÔNG hứa in theo thứ tự nào, nên luôn nối thêm `| sort`. Trên máy khôi
   phục đã cài `rclone` và nối remote `gdrive` (Bước 1 và 3):
   ```
   rclone lsf gdrive:VK-CRM-backups/vk-crm-production/ | sort
   rclone lsf gdrive:VK-CRM-backups/vk-crm-production/ | sort | tail -1
   rclone copy gdrive:VK-CRM-backups/vk-crm-production/vk-crm-production-2026-09-27-02-00-12.zip ./khoi-phuc/
   ```
   Lệnh thứ nhất liệt kê mọi bản, cũ trước mới sau; lệnh thứ hai chỉ in tên bản mới nhất. Lệnh thứ
   ba tải đúng một tệp (thay tên tệp bằng tên thấy ở hai lệnh trên) vào thư mục
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
   mariadb-client` trên Ubuntu/Debian) — đây là điều kiện cần đã nêu ở SPEC §10 mục 8, kiểm tự
   động bằng `php artisan vkcrm:preflight` (M8 Task 1, `App\Actions\Deployment\RunPreflight`; xem
   `docs/CAI-DAT.md`, mục "Khi đưa lên máy chủ thật").
6. **Chép tệp hồ sơ** — mọi mục trong archive có tiền tố `storage/app/private/` (đường TƯƠNG ĐỐI
   tính từ gốc ứng dụng — xem đoạn giải thích `relative_path` ở docblock
   `config/backup.php`) chép về ĐÚNG thư mục `storage/app/private/` của máy chủ mới, giữ nguyên
   cấu trúc thư mục con.
7. **Đặt `APP_KEY`** trong `.env` của máy chủ mới bằng ĐÚNG giá trị lấy ở bước 1 — làm TRƯỚC khi
   cho ứng dụng chạy thật (trước khi ai đăng nhập hay đọc một hồ sơ nào). Ba dòng khoá thông báo
   đẩy (`VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`) thì tuỳ đây là khôi phục THẬT hay
   khôi phục THỬ:
   - **Khôi phục thật** (máy này thay máy chủ hỏng, cùng tên miền): đặt ba dòng bằng cặp khoá cất ở
     Bước 6 — còn khoá cũ thì điện thoại đã bật thông báo tiếp tục nhận. Không còn khoá cũ: KHÔNG
     chép khoá của máy khác, sinh cặp mới theo `docs/CAI-DAT.md`, Bước 3, rồi chạy
     `php artisan vkcrm:push-reset` và báo mọi người bật lại thông báo.
   - **Khôi phục thử / diễn tập định kỳ** (máy chủ tạm, máy thật vẫn chạy): để TRỐNG cả ba dòng
     `VAPID_*` — thông báo đẩy tắt, `vkcrm:preflight` báo VÀNG ở dòng khoá thông báo đẩy, đúng như
     mong đợi — và KHÔNG cài dòng cron `schedule:run` (`docs/CAI-DAT.md`, Bước 8). Bản sao mang đủ
     đăng ký điện thoại và hàng đợi của máy thật: chép khoá thật sang thì bản diễn tập đẩy thông báo
     thật tới điện thoại của nhân sự và khách (mốc thời hạn, tài liệu, khoản thu), và khoá ký của máy
     thật nằm trên một máy không phải máy thật. Không cron thì bản diễn tập cũng không gửi thư nhắc
     hay chạy sao lưu chồng lên máy thật.
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
11. **Ở chế độ kho tài liệu (M14)**: archive chỉ mang vùng đệm; tệp đã dọn khỏi máy chủ nằm trên
    Kho, và bản CSDL vừa nạp chỉ trỏ tới chúng qua chỉ mục `drive_objects`. Đặt đúng các biến
    `GOOGLE_DRIVE_*` và đặt khoá tài khoản dịch vụ như lúc cài (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`,
    Phụ lục A), `php artisan optimize`, rồi chạy `php artisan vkcrm:storage:verify --sample=20`: hai
    mươi media trên kho, chọn ngẫu nhiên, phải khớp md5 và cỡ. Kho cũng mất thì làm "Diễn tập mất
    kho" ở dưới với bản ở máy văn phòng. `tools/backup/restore-drill.sh` có cùng bước này (bước 12b).

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

Bước 12b (M14) chạy `vkcrm:storage:verify --sample=20` trên bản khôi phục. Lượt diễn tập trên máy dev
không có khoá Google nào và dữ liệu thử không có media nào trên kho, nên ở đây bước đó chỉ chứng minh
lệnh chạy được trên CSDL vừa khôi phục; bản mã chưa có lệnh đó (trước M14 Task 6) thì bước in "bỏ
qua".

### Diễn tập "mất kho" (M14) — dựng lại Kho từ bản ở máy văn phòng

Tình huống: Shared Drive "Kho" không còn dùng được (Workspace bị khoá, Kho bị xoá nhầm bởi một quản
trị viên, …), còn máy văn phòng thì còn. Diễn tập trên một **Shared Drive THỬ** với dữ liệu seed,
không bao giờ trên Kho thật. Chưa chạy: cần Workspace, Shared Drive thử và máy văn phòng giả lập
(**PENDING OWNER**, M14 Task 8 Phần 2).

1. **Dựng Kho mới và trỏ máy chủ vào nó.** Tạo Shared Drive mới, thêm tài khoản dịch vụ đúng vai
   "Người quản lý nội dung" và các thành viên được phép (Phụ lục A của
   `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`). Đặt `GOOGLE_DRIVE_SHARED_DRIVE_ID` mới trong `.env`,
   `php artisan optimize`, `php artisan vkcrm:storage:init` (in mã thư mục gốc mới), đặt
   `GOOGLE_DRIVE_ROOT_FOLDER_ID` bằng mã đó, `php artisan optimize`, rồi
   `php artisan vkcrm:storage:check`: các dòng sẵn sàng phải XANH.
2. **Chép bản ở văn phòng lên Kho mới.** Trên máy văn phòng, tạo tạm một remote `vkkhomoi` (Google
   Drive, phạm vi `drive`, `team_drive` = Shared Drive mới, `root_folder_id` = thư mục gốc mới) bằng
   tài khoản quản trị dự phòng — tài khoản `van-phong-kho@` chỉ có quyền đọc. Rồi:
   ```
   rclone copy vkoffice:kho vkkhomoi: --immutable
   ```
   Remote `crypt` giải mã cả tên lẫn nội dung trên đường đi, nên Kho mới nhận đúng các thư mục tháng
   và đúng tên tệp mờ như Kho cũ. Xong thì gỡ remote tạm đó khỏi cấu hình rclone của máy văn phòng.
3. **Dựng lại chỉ mục:**
   `php artisan vkcrm:storage:reindex --drive=<mã Shared Drive mới> --root=<mã thư mục gốc mới>`.
   Lệnh đọc tên tệp (khoá + thế hệ đảo ngược được) và dựng lại cả các thư mục tháng; nó từ chối khi
   hai mã khác `.env` đang dùng.
4. **Kiểm toàn bộ:** `php artisan vkcrm:storage:verify --all`. Phải sạch.
5. **Một lượt tải lên mới** (một tài liệu thử) phải vào thư mục tháng của gốc MỚI: kiểm trên giao
   diện Drive bằng tài khoản quản trị dự phòng.
6. **Trỏ máy văn phòng vào Kho mới:** sửa `team_drive` và `root_folder_id` của remote `vkkho` (vẫn
   phạm vi `drive.readonly`, vai Người xem của `van-phong-kho@` trên Shared Drive mới), rồi đổi tên
   `receipted.txt` trong thư mục trạng thái của `office-pull.sh` thành `receipted-<ngày>.txt`.
   Biên nhận ràng vào mã Shared Drive: các dòng chỉ mục vừa dựng lại thuộc Kho mới và chưa có biên
   nhận, nên lượt kéo kế tiếp phải kiểm và ghi biên nhận lại mọi tệp. Giữ `receipted.txt` cũ thì
   không tệp nào được ghi biên nhận cho Kho mới, và vùng đệm không bao giờ được dọn.

Các lệnh `reindex`, `verify` thuộc M14 Task 6.

### Đóng gói bàn giao M7 — có sao lưu lại không?

**Cập nhật M14 (kho tài liệu):** gói bàn giao là một tệp như mọi tệp hồ sơ. Ở chế độ kho, nó vào
vùng đệm trên máy chủ, được đẩy lên Kho, được máy văn phòng kéo về và ghi biên nhận, rồi được dọn
khỏi máy chủ theo đúng luật của mọi tệp (bảng "Khi đã bật kho tài liệu Google Drive" ở đầu tài liệu).
Nó nằm trong archive đêm khi chưa có biên nhận, như mọi tệp. Phán quyết tạm dưới đây (không loại gói
khỏi `include`) giữ nguyên.

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

---

## Phụ lục D — Máy chủ văn phòng: bản thứ hai ngoài Google (M14)

Phụ lục này dành cho **chủ văn phòng** và **người cài đặt**. Nó **không chặn** việc bật kho tài
liệu, nhưng **chặn việc dọn vùng đệm**: chưa có máy này thì máy chủ web giữ mọi tệp như trước M14
(dòng kiểm `document_office_copy` VÀNG nhắc điều đó), và archive đêm vẫn chứa mọi tệp.

Mô hình là **kéo**: máy văn phòng tự kéo tệp từ Kho về, máy chủ web không có đường nào ghi vào máy
văn phòng. Chiếm được máy chủ web cũng không với tới bản ở văn phòng. Mọi việc dưới đây chưa chạy
thật lần nào (cần Workspace, Shared Drive thử và một máy văn phòng): **PENDING OWNER**, nghiệm thu ở
M14 Task 8 Phần 2.

| # | Việc | Dòng kiểm |
|---|---|---|
| 1 | **Máy**: một máy luôn bật trong văn phòng (Windows hoặc Linux). Ổ trống ít nhất **gấp đôi** dung lượng Kho; bật mã hoá ổ đĩa (BitLocker hoặc LUKS); có UPS. Băng thông: mỗi tệp mới được tải về hai lần (một lần chép, một lần kiểm), và lượt kiểm hằng tháng tải lại toàn bộ Kho | — |
| 2 | **Tài khoản Google** `van-phong-kho@…` (Workspace, bật xác thực hai lớp, không ai dùng để làm việc hằng ngày). Vai **Người xem** trên Kho (Phụ lục A bước 7 của `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`), vai **Người đóng góp** trên "VK-CRM Backups" (thêm và sửa được tệp, không xoá được). Vai đó đủ để gửi biên nhận, và cũng đủ để ghi đè nội dung archive CSDL: xem "Token `vkbackups` ghi đè được archive" ở "Những điều cần biết" | `drive_sharing` |
| 3 | **Tài khoản hệ điều hành riêng** `vkcrm-saoluu` trên máy văn phòng. Nhân sự không đăng nhập bằng nó; thư mục bản sao chỉ nó đọc được | — |
| 4 | **Cài `rclone`** (như Bước 1, bản Windows hoặc Linux), và **Git Bash** nếu là Windows | — |
| 5 | **`rclone config`** dưới tài khoản `vkcrm-saoluu`, ba remote (mục D.5); đặt mật khẩu cho tệp cấu hình rclone | `document_office_copy` |
| 6 | **Cất ba mật khẩu** (mật khẩu `crypt`, salt, mật khẩu cấu hình rclone) cùng chỗ với `APP_KEY` và `BACKUP_ARCHIVE_PASSWORD` (Bước 6). **Không bao giờ** đặt chúng trên máy chủ web, không gửi qua email, Zalo hay Drive. **Mất mật khẩu `crypt` là mất bản ở văn phòng** | — |
| 7 | **Đặt script và lịch**: `office-pull.sh` mỗi đêm 01:00, `office-pull.sh --check-monthly` ngày 1 hằng tháng (mục D.7) | `document_office_copy` |
| 8 | **Người cài đặt** điền `DOCUMENT_OFFICE_RECEIPTS_PATH` trên máy chủ web, `php artisan optimize`, chạy `php artisan vkcrm:storage:office-receipts` (mục D.8) | `document_office_copy` |
| 9 | **Kiểm**: trang "Kho tài liệu" hiện biên nhận gần nhất, và số "media trên kho chưa có biên nhận" giảm dần qua các đêm. Mở thư mục bản sao bằng Explorer: tên và nội dung tệp không đọc được | — |
| 10 | **Mỗi tháng**: xem `office-pull.log` của lượt `--check-monthly`. Có tệp lệch thì báo người cài đặt (có thể có tệp bị sửa trên Kho) | `document_office_copy` |

### D.5 — Ba remote rclone

Chạy `rclone config` dưới tài khoản `vkcrm-saoluu` (không dưới tài khoản của ai khác: cấu hình
rclone nằm trong thư mục nhà của tài khoản chạy nó):

- **`vkkho`** — Kho, **chỉ đọc**:
  - `Storage>`: Google Drive; `scope>`: **`drive.readonly`**. Đổi vai trên Drive cũng không làm token
    này ghi được; `office-pull.sh` từ chối chạy khi scope khác `drive.readonly`;
  - đăng nhập bằng `van-phong-kho@…` (Bước 3b nếu máy không có trình duyệt);
  - `Configure this as a Shared Drive?` → `y`, chọn Shared Drive kho: đó là `team_drive`;
  - `root_folder_id`: **đúng** giá trị `GOOGLE_DRIVE_ROOT_FOLDER_ID` trong `.env` của máy chủ web.
    `team_drive` cũng phải bằng `GOOGLE_DRIVE_SHARED_DRIVE_ID`. CRM từ chối cả biên nhận khi hai mã
    này khác cấu hình của nó: script đọc chúng từ `rclone config show vkkho`, không gõ tay.
- **`vkbackups`** — "VK-CRM Backups", để gửi biên nhận và kéo archive CSDL: Google Drive, `scope>`
  `drive`, đăng nhập bằng `van-phong-kho@…`, Shared Drive "VK-CRM Backups". Vai Người đóng góp cho
  **thêm và sửa** tệp: tải được tệp mới, và tải được phiên bản mới đè lên tệp người khác tạo (kể cả
  archive CSDL do máy chủ web đẩy lên); chỉ không xoá, không cho vào thùng rác và không di chuyển
  được. Không có vai nào của Shared Drive chỉ cho thêm mà không cho sửa. Rủi ro và lưới đỡ: "Token
  `vkbackups` ghi đè được archive" ở "Những điều cần biết".
- **`vkoffice`** — bản sao trên ổ của máy văn phòng, **mã hoá**:
  - `Storage>`: `crypt`; `remote>`: một thư mục cục bộ, ví dụ `D:\VKCRM-saoluu\kho` (Windows) hay
    `/srv/vkcrm-saoluu/kho` (Linux);
  - `filename_encryption>`: `standard`; `directory_name_encryption>`: `true`;
  - mật khẩu và salt: chọn `g` để rclone sinh, độ dài 256 bit; **chép cả hai** vào chỗ cất ở bước 6.

Rồi đặt mật khẩu cho chính tệp cấu hình rclone: `rclone config` → `s` (Set configuration password)
→ `a`. Lịch chạy không gõ được mật khẩu, nên rclone hỏi nó qua `RCLONE_PASSWORD_COMMAND`: một lệnh in
mật khẩu từ kho mật khẩu của hệ điều hành (Windows Credential Manager, ví dụ qua mô-đun PowerShell
`CredentialManager`; hoặc `secret-tool` trên Linux). Mẫu ở `tools/backup/office-pull.conf.example`.

Kiểm ba remote: `rclone lsd vkkho:` (thấy các thư mục tháng `YYYY-MM`), `rclone lsd vkbackups:` (thấy
`VK-CRM-backups`), `rclone lsd vkoffice:` (trống lúc đầu, không báo lỗi).

### D.7 — Script và lịch

1. Chép `tools/backup/office-pull.sh` và `tools/backup/office-pull.conf.example` từ mã nguồn sang
   máy văn phòng, ví dụ `D:\VKCRM-saoluu\` (hoặc `/srv/vkcrm-saoluu/`). Đổi tên tệp mẫu thành
   `office-pull.conf` cạnh script và sửa: đường dẫn `rclone`, `ENV_FOLDER` (thư mục của môi trường
   trên "VK-CRM Backups", theo `BACKUP_NAME`: `"VK-CRM production"` → `vk-crm-production`),
   `ARCHIVE_DIR`, `STATE_DIR`, `RCLONE_PASSWORD_COMMAND`. Tệp này không chứa mật khẩu nào.
2. Chạy thử một lần bằng tay dưới `vkcrm-saoluu`: `bash office-pull.sh; echo $?`, rồi đọc
   `office-pull.log` cạnh script. Lượt đầu chép toàn bộ Kho, có thể mất nhiều giờ, hay nhiều đêm; khoá
   của script (thư mục `office-pull.lock` trong `STATE_DIR`) ngăn hai lượt chồng nhau, và lượt sau
   tiếp từ chỗ lượt trước dừng.
3. Lịch:
   - **Windows** (Task Scheduler), hai tác vụ, "Run whether user is logged on or not", dưới
     `vkcrm-saoluu`. Chương trình `C:\Program Files\Git\bin\bash.exe`; đối số
     `-lc "bash /d/VKCRM-saoluu/office-pull.sh"` mỗi ngày lúc 01:00, và
     `-lc "bash /d/VKCRM-saoluu/office-pull.sh --check-monthly"` ngày 1 hằng tháng lúc 04:00.
   - **Linux** (crontab của `vkcrm-saoluu`):
     ```
     0 1 * * * /bin/bash /srv/vkcrm-saoluu/office-pull.sh
     0 4 1 * * /bin/bash /srv/vkcrm-saoluu/office-pull.sh --check-monthly
     ```

Mỗi đêm script:

1. chép Kho về `vkoffice:kho` với `--immutable`: tệp đã có ở văn phòng mà bị đổi trên Kho thì báo
   lỗi, **không ghi đè** — đó là tín hiệu giả mạo, và nó đi tới CRM qua số lỗi của biên nhận;
2. lấy danh sách tệp trên Kho **chưa có biên nhận** (trừ đi `receipted.txt` trong `STATE_DIR`), bỏ
   các **tên trùng** (một tên có từ hai tệp trên Kho: biên nhận chỉ mang tên, nên không chứng minh được
   bản ở văn phòng là bản nào — mỗi đêm là một lỗi trong nhật ký và trong số lỗi của biên nhận, cho tới
   khi người cài đặt xử lý theo `php artisan vkcrm:storage:orphans`), và chỉ giữ tối đa
   `MAX_RECEIPT_FILES` tệp (mặc định 100 000, để biên nhận nằm dưới trần 32 MiB của CRM; phần còn lại vào
   lượt sau, nên lượt đầu trên một Kho lớn mất nhiều đêm);
3. `rclone cryptcheck --one-way` đúng các tệp đó: so nội dung đã mã hoá ở văn phòng với tệp trên Kho,
   từng tệp;
4. dựng biên nhận `receipt-<giờ UTC>.json` cho đúng các tệp khớp (tên mờ, md5 của Drive, cỡ; mã
   Shared Drive và thư mục gốc đọc từ cấu hình `vkkho`; giờ chạy; số lỗi), gửi lên
   `vkbackups:VK-CRM-backups/office-receipts/<ENV_FOLDER>/`, và **chỉ sau khi gửi được** mới ghi các
   tệp đó vào `receipted.txt`. Đêm không có tệp mới vẫn gửi một biên nhận rỗng, để CRM biết máy văn
   phòng còn sống;
5. kéo các archive CSDL (đã mã hoá AES-256) về `ARCHIVE_DIR`, cũng với `--immutable`.

Script **không bao giờ** xoá, di chuyển hay đồng bộ gì, ở Kho, ở "VK-CRM Backups" hay ở văn phòng:
bản ở văn phòng chỉ được thêm vào. Có test cấu trúc giữ điều đó
(`tests/Feature/Storage/OfficeCopyStructureTest.php`). Mã thoát: 0 không lỗi; 1 có lỗi (xem nhật
ký); 2 cấu hình sai; 75 một lượt khác đang chạy.

CRM đọc biên nhận lúc 07:00 hằng ngày (`vkcrm:storage:office-receipts`, qua remote `gdrive` của
Bước 3) và chỉ đánh dấu một tệp "đã có bản ở văn phòng" khi tên, thế hệ, md5 và cỡ khớp đúng một
dòng chỉ mục SỐNG của Kho đang cấu hình. Biên nhận của Kho khác, sai khuôn, quá 32 MiB hay ghi giờ ở
tương lai thì bị từ chối cả tệp, và lý do hiện ở dòng `document_office_copy` cùng thư cảnh báo kho.
Biên nhận có **tên** mang giờ ở tương lai quá 5 phút (đồng hồ máy văn phòng chạy nhanh) thì CRM chưa
đọc và không đi qua nó, để các biên nhận đúng giờ đến sau vẫn được nhập; câu "biên nhận mang tên giờ ở
tương lai" hiện mỗi ngày cho tới khi được gỡ. Cả hai trường hợp cần người gỡ: mục D.9.
Biên nhận báo lỗi phía văn phòng thì các tệp khớp vẫn được ghi nhận, và câu "máy văn phòng báo N lỗi"
hiện ở cùng chỗ. Lỗi `rclone` phía máy chủ đi theo đúng đường thư báo lỗi sao lưu
(`rclone:office-receipts`).

### D.8 — Trên máy chủ web

```
DOCUMENT_OFFICE_RECEIPTS_PATH=gdrive:VK-CRM-backups/office-receipts/vk-crm-production
```

(`gdrive` là remote của Bước 3; phần cuối là `ENV_FOLDER` của máy văn phòng.) Rồi
`php artisan optimize` và `php artisan vkcrm:storage:office-receipts`: lệnh in số biên nhận đã nhập
và số tệp được đánh dấu. Trống biến này thì lệnh và mục lịch không làm gì, và vùng đệm không bao giờ
được dọn.

### D.9 — Gỡ biên nhận bị từ chối, đang chờ, hay bị bỏ qua

Máy văn phòng ghi các tệp của một biên nhận vào `receipted.txt` (trong `STATE_DIR`) **ngay khi gửi
được** biên nhận đó, và từ đó không kiểm hay gửi biên nhận cho chúng nữa. Vì vậy một biên nhận mà CRM
không nhập là các tệp của nó **không bao giờ** có biên nhận: vùng đệm trên máy chủ web giữ chúng mãi
(an toàn, nhưng ổ đĩa cứ đầy dần). Ba dấu hiệu:

- dòng `document_office_copy` hay thư cảnh báo kho có câu "Biên nhận … bị từ chối" (Kho khác, sai
  khuôn, quá 32 MiB, giờ bắt đầu ở tương lai);
- câu "… biên nhận mang tên giờ ở tương lai …": đồng hồ máy văn phòng chạy nhanh (pin CMOS hết, mất
  đồng bộ giờ). CRM chưa đọc biên nhận đó và không đi qua nó; biên nhận đúng giờ đến sau vẫn được nhập;
- không câu lỗi nào, nhưng số "media trên kho chưa có biên nhận" ở trang "Kho tài liệu" không giảm qua
  nhiều đêm. Từ M14 Task 6, CRM giữ sổ các biên nhận đã đọc (bảng `office_receipt_imports`) và đọc cả
  biên nhận ĐẾN MUỘN mang tên nhỏ hơn biên nhận đã nhập (ví dụ hai lượt kéo chạy chồng): lệnh
  `vkcrm:storage:office-receipts` in số "biên nhận đến muộn". Dấu hiệu này vì thế chỉ còn ở biên nhận có
  tên cũ hơn biên nhận ĐẦU TIÊN CRM từng đọc, hay khi máy văn phòng không gửi được biên nhận (xem
  `office-pull.log`).

Cách gỡ, theo thứ tự:

1. **Sửa nguyên nhân.** Đồng hồ: trên Windows `w32tm /resync` (cửa sổ dòng lệnh quyền quản trị), trên
   Linux `timedatectl set-ntp true`; kiểm lại bằng `date -u` trong Git Bash. Kho khác: sửa remote
   `vkkho` theo D.5. Biên nhận quá 32 MiB: báo người cài đặt.
2. **Biên nhận tên ở tương lai**: người quản lý "VK-CRM Backups" mở thư mục
   `VK-CRM-backups/office-receipts/<ENV_FOLDER>/` trên Google Drive và xoá các tệp `receipt-….json` có
   ngày sau hôm nay. Tài khoản `van-phong-kho@…` không xoá được (vai Người đóng góp).
3. **Trên máy văn phòng**, dưới `vkcrm-saoluu`, lúc không có lượt kéo nào đang chạy (không có thư mục
   `office-pull.lock` trong `STATE_DIR`): đổi tên `receipted.txt` thành `receipted-<ngày>.txt`. Không
   xoá tệp cũ. Lượt đêm sau kiểm lại mọi tệp (tải lại toàn bộ Kho một lần) và gửi một biên nhận cho tất
   cả; CRM đánh dấu những tệp chưa có biên nhận, và đếm những tệp đã có là "đã có biên nhận từ trước".
   Kho lớn hơn `MAX_RECEIPT_FILES` tệp (mặc định 100 000) thì việc này trải ra nhiều đêm, mỗi đêm một
   biên nhận dưới trần 32 MiB của CRM.
4. **Sáng hôm sau**, sau 07:00, xem dòng `document_office_copy`, hoặc người cài đặt chạy
   `php artisan vkcrm:storage:office-receipts` và đọc số đếm. Mã thoát 1 nghĩa là còn biên nhận bị từ
   chối hay đang chờ, lệnh `rclone` hỏng, hoặc một lượt nhập khác đang chạy: lệnh in lý do.

Mốc biên nhận đã nhập của CRM (cursor, `storage.office_receipt_cursor`) không cần sửa tay: CRM không
bao giờ cho nó vượt giờ máy chủ cộng 5 phút. Nếu nó đã ở tương lai (đồng hồ máy chủ web từng chạy
nhanh), lượt nhập kế tiếp tự đặt lại, đọc lại mọi biên nhận trong thư mục (tệp đã có biên nhận không bị
ghi lại), và câu "Mốc biên nhận đã nhập … đã đặt lại" hiện ở dòng `document_office_copy` một lần. Khi
thấy câu đó, kiểm đồng hồ của máy chủ web.

### Những điều cần biết

- **Biên nhận là lời của máy văn phòng.** Một máy văn phòng bị chiếm có thể gửi biên nhận giả và làm
  vùng đệm bị dọn sớm. Kho vẫn còn bản (CRM không bao giờ xoá gì trên Kho vì một biên nhận), và lượt
  `--check-monthly` cùng `vkcrm:storage:verify` là lưới. Giữ máy văn phòng như giữ két sắt.
- **Token `vkbackups` ghi đè được archive.** Vai Người đóng góp trên "VK-CRM Backups" cho sửa tệp, và
  token `vkbackups` có `scope` `drive`. Một máy văn phòng bị chiếm vì thế tải được phiên bản mới (rác,
  hay bản đã bị mã độc tống tiền mã hoá) đè lên mọi archive CSDL và mọi biên nhận trên "VK-CRM
  Backups". Nó không xoá được tệp nào, nhưng nội dung gốc khi đó chỉ còn trong lịch sử phiên bản của
  Drive, mà lịch sử phiên bản không phải sao lưu. Bản đối chứng là chính `ARCHIVE_DIR` ở văn phòng:
  script chỉ chép thêm vào đó, với `--immutable`, nên archive đã có ở văn phòng không bao giờ bị bản
  trên Drive ghi đè. Archive bị đổi trên Drive (khác cỡ hay giờ sửa) làm lượt kéo báo lỗi, và lỗi đó
  tới CRM qua số lỗi của biên nhận kế tiếp ("máy văn phòng báo N lỗi"). Thấy câu đó thì đừng xoá
  archive nào ở `ARCHIVE_DIR`, và báo người cài đặt so hai bản. Kẻ đã chiếm được máy văn phòng thì
  sửa được cả `ARCHIVE_DIR`: đó là lý do thêm để giữ máy này như két sắt.
- **Archive ở văn phòng không tự hết.** Script chỉ chép thêm, nên `ARCHIVE_DIR` giữ mọi archive đã
  từng có. Người giữ máy văn phòng tự xoá bằng tay các archive cũ, giữ ít nhất 30 bản mới nhất. Khi
  huỷ tệp của hồ sơ hết hạn lưu (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`, "Huỷ tệp của hồ sơ đã quá hạn
  lưu"), archive cũ ở văn phòng cũng là một nơi còn tệp.
- **Biên nhận tích luỹ** trên "VK-CRM Backups" (một tệp nhỏ mỗi đêm, khoảng 365 tệp mỗi năm). CRM chỉ
  đọc biên nhận mới hơn biên nhận đã nhập gần nhất, cộng biên nhận đến muộn chưa từng đọc (sổ
  `office_receipt_imports`). Người quản lý "VK-CRM Backups" có thể xoá bằng tay các biên nhận cũ hơn
  một năm.
- **Đừng xoá `receipted.txt`** trừ khi được dặn (diễn tập "mất kho", bước 6; gỡ biên nhận, mục D.9 —
  ở cả hai chỗ là đổi tên, không xoá). Mất nó thì mọi tệp được
  kiểm và ghi biên nhận lại: an toàn, nhưng tốn một lượt tải lại toàn bộ.
- **Máy văn phòng hỏng**: dựng máy mới theo phụ lục này, với **cùng** mật khẩu `crypt` và salt nếu
  còn ổ cũ; ổ mất thì một remote `crypt` mới và một `receipted.txt` trống — lượt đầu kéo lại toàn bộ
  Kho.
