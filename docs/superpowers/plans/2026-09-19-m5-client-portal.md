# VK-CRM M5 — Kế hoạch cổng khách hàng

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Khách hàng của văn phòng đăng nhập `/portal` bằng email + mật khẩu + mã OTP gửi qua email, trên điện thoại xem được vụ việc của mình đang ở giai đoạn nào, biết còn thiếu giấy tờ gì, chụp ảnh nộp lên, đọc được lý do khi bị từ chối và hỏi lại được — và hệ thống ghi lại bằng chứng khách đã đọc từng dòng tiến độ. Tiêu chí nghiệm thu SPEC §14 mục 3, 4 và 5 đạt; SPEC §13 dòng M5 ("khách demo đăng nhập và đi hết luồng được trên điện thoại") đạt.

Đây là milestone làm cho M0–M4 có ý nghĩa. Trước M5, khách hàng **không dùng được hệ thống**: `/portal` hôm nay chỉ có một màn hình đăng nhập.

**Architecture:** Panel `portal` đã tồn tại (`PortalPanelProvider`, guard `client`, path `/portal`, màu thương hiệu, 404 cho mọi từ chối). M5 chỉ thêm trang và Action; **không thêm một điều kiện phân quyền nào ở tầng màn hình**. Ba lớp bảo vệ dựng từ M2 giữ nguyên vai trò và giữ nguyên tính độc lập:

1. **Truy vấn** — `ClientPortalScope` + `Model::applyClientPortalConstraints()`. Không màn hình nào được viết `where('client_id', ...)`.
2. **Hành động** — Policy, qua `ChecksPortalVisibility::visibleToPortal()`, áp lại chính điều kiện đó một cách độc lập.
3. **Serialize** — `HidesInternalAttributesFromPortal`, vì một dòng tiến độ đã công bố vẫn mang `internal_note` trong cùng bản ghi.

Nghiệp vụ mới nằm ở `app/Actions/Portal/`: `RecordStageLogView` (SPEC §4.18), `OpenClientRequest` và `ReplyToClientRequest` (SPEC §4.14). Nộp tài liệu **không** có Action mới — `App\Actions\Document\SubmitClientDocument` đã là của M4; M5 chỉ dựng màn hình gọi nó.

Đăng nhập OTP dùng bộ MFA có sẵn của Filament 5 (`Filament\Auth\MultiFactor\Email\EmailAuthentication`), **không cài gói ngoài** và **không thêm bảng** — xem "Kết luận về OTP" bên dưới.

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint. Không gói mới bắt buộc. Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §3 (hai panel, cột "Xác thực" của panel `portal`), §4.3 (`client_users`), §4.10 (`matter_checklist_items`, công thức `X/Y`), §4.14 (`client_requests`, `client_request_replies`), §4.18 (`stage_log_views`), §5 phần **Portal** (bảy điều kiện cố định, cài bằng global scope + policy, không spatie), §6.6 và §6.7 (Action đã có ở M4), §7.2 (tab "Yêu cầu từ khách", nhãn "Khách đã xem"), §7.1 mục 5 (widget "Khách chưa xem cập nhật"), **§8 toàn bộ** (§8.1 đăng nhập, §8.2 danh sách, §8.3 chi tiết, §8.4 nộp tài liệu — và câu mở đầu §8 về chữ to, điện thoại, không thuật ngữ), §9 (mẫu `client.otp`), §10.3, §10.6, §10.7, §10.9, §10.10, §11 phần "Cách ly dữ liệu giữa khách hàng", "Tài liệu nội bộ", "Ghi chú nội bộ", §12 (dữ liệu mẫu), §13 dòng M5, §14 mục 3, 4, 5. `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md` §2 (M5), §4 (nguyên tắc trải nghiệm 375px), §6 (quy trình mỗi milestone — `security-review` **bắt buộc** ở M5).

---

## Ràng buộc toàn cục

- **Nhánh:** `m5-client-portal`, cắt từ `main` **sau khi M4 đã merge**. M5 phụ thuộc cứng vào `SubmitClientDocument`, `ReviewChecklistItem` (M4 Task 4) và `DocumentDownloadController` (M4 Task 5); Task 5 của kế hoạch này không bắt đầu được nếu hai thứ đó chưa có. Không bắt đầu M5 trên nhánh `m4-documents`.
- PHP sàn **8.3**, cứng. Không cài gói đòi PHP 8.4+ — xem "Kết luận về kiểm thử trên điện thoại" bên dưới, đây là nơi ràng buộc này cắn thật.
- Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope. Không `storage:link`.
- Định danh mã tiếng Anh. **Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/`** — không hardcode tiếng Việt trong class PHP. Ở M5 quy tắc này nặng hơn mọi milestone trước: người đọc là khách hàng đang lo lắng về vụ việc của mình, và §8 cấm thuật ngữ kỹ thuật lẫn từ viết tắt. Một chuỗi lọt ra tiếng Anh ở đây là một khách hàng không hiểu mình phải làm gì.
- Nghiệp vụ chỉ ở `app/Actions/`. Page, resource, controller chỉ gọi Action và **phải bắt `DomainException`** để đổi thành thông điệp trên form (bài học M3/M4: một exception không được bắt là lỗi 500 trên màn hình khách hàng).
- **Filament 5 khác các bản trước rất nhiều. Không viết mã Filament từ trí nhớ** — đây là phán quyết đã ghi từ kế hoạch M3 và nó vẫn đúng: mã sinh từ trí nhớ cho Filament 3/4 chạy sai trên Filament 5, và M3 đã mất nhiều vòng vì việc đó (`RelationManager::isReadOnly()` mặc định `true` trên trang `ViewRecord`; `disabled()` gọi `saved(false)` nên trường không hề dehydrate; `Filament\Schemas\Schema` chứ không phải `Forms\Form`). Cách làm bắt buộc: chạy `bin/dev artisan make:filament-*` để lấy khung thật, đọc `vendor/filament/` khi cần, hoặc tra context7. **Kế hoạch này mô tả hành vi và luật nghiệp vụ; không có một dòng mã Filament nguyên văn nào là cố ý.**
- **Nghi thức ba tầng (bắt buộc cho mọi task có màn hình).** Mỗi màn hình portal phải chứng minh cách ly **ở từng tầng một cách độc lập**:
  1. *Tầng truy vấn:* dữ liệu của khách khác không có trong kết quả.
  2. *Tầng policy:* thay global scope của model bằng một scope rỗng cùng khoá (đúng hình dạng "ai đó quên một `where`"), khẳng định tầng truy vấn giờ đã thủng, rồi khẳng định policy **vẫn** từ chối. Mẫu có sẵn từ M4 Task 2.
  3. *Tầng serialize:* đặt một chuỗi đánh dấu duy nhất vào `internal_note` (và các cột nội bộ khác), khẳng định nó không xuất hiện ở bất kỳ đâu trong response HTML lẫn JSON.
- **Một test mà fixture của nó đoản mạch trước khi chạm tới điều kiện nó nêu tên thì tệ hơn không có test.** Đây không phải lời khuyên: rà soát M4 tìm ra **hai trong ba** điều kiện của `Document::isReleasedToPortal()` là vô nghĩa vì fixture nhóm D của test lại đồng thời là `internal_draft` và `client_can_view = false`, nên xoá cả hai điều kiện đi bộ test vẫn xanh. Mỗi test cách ly phải kèm **một lần đột biến (mutation probe)**: xoá đúng điều kiện mà test nêu tên, chạy lại, dán bằng chứng test ĐỎ vào báo cáo, rồi khôi phục. Không có bằng chứng đỏ thì coi như chưa có test.
- **Nhóm D và mọi nội dung chưa công bố phải không chạm tới được bằng bất kỳ đường nào portal diễn đạt được**: danh sách, trang chi tiết, quan hệ (cả hai chiều), tải về, thuộc tính đã serialize, tìm kiếm, và **đếm** (`withCount`, `->count()`, `whereHas` dùng làm máy dò tồn tại). Test quét này viết một lần ở Task 2 và **chạy lại sau mỗi task có màn hình**, mở rộng thêm đúng những đường mà màn hình mới vừa tạo ra.
- **SQLite không bao giờ bắt được ràng buộc chỉ số/khoá ngoại của MariaDB** — nó dựng lại cả bảng mỗi khi một index đổi. Dự án đã vỡ vì chuyện này hai lần (lỗi 1553 trên `matter_type_stages` ở M3; migration của medialibrary không có `down()` nên `migrate:reset` im lặng no-op ở M4). **Task nào đụng migration phải chạy trên container MariaDB thật: `migrate:fresh --seed`, rồi một vòng `migrate:reset` → `migrate`, và dán nguyên văn output vào báo cáo.** Theo dự kiến M5 **không cần migration nào** (xem Task 1 và Task 2) — nếu một task thấy mình cần thêm bảng hay thêm cột thì đó là một sai lệch so với kế hoạch, phải báo lại trước khi viết, và khi đó quy tắc này áp dụng đầy đủ.
- TDD với Pest: test đỏ trước. Test màn hình Filament dùng helper `livewire()` của `pestphp/pest-plugin-livewire`.
- Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit **chỉ các tệp của mình theo đường dẫn tường minh** (`git commit -- <path>`), không `git add -A`.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (chép nguyên văn).
- `security-review` là **bắt buộc** ở milestone này (toolchain §6). Người rà soát cuối cùng được brief là **giả định có một lỗi Critical** — đó là cách tìm ra lỗi Critical thứ ba ở M3.

---

## Kết luận về OTP (đã kiểm chứng trên bản đang cài, không phải suy đoán)

SPEC §3 và §8.1: portal xác thực bằng **email + mật khẩu → mã 6 số qua email, hiệu lực 5 phút → nhập mã**; sai quá 5 lần khoá 15 phút theo **cả tài khoản lẫn IP**; lần đầu đăng nhập bắt buộc đổi mật khẩu.

Đã đọc `vendor/filament/filament/src/Auth/MultiFactor/Email/EmailAuthentication.php` và `MultiFactorChallenge.php` trên bản 5.8 đang cài. Kết luận:

- **Filament 5 đã có sẵn đúng luồng này.** `EmailAuthentication` sinh mã 6 số (`str_pad(random_int(0, 999999), 6, '0')`), gửi qua notification, và người dùng nhập vào `Filament\Forms\Components\OneTimeCodeInput`. Luồng đăng nhập của `Filament\Auth\Pages\Login` là: kiểm mật khẩu → kiểm `canAccessPanel` → phát challenge → xác minh mã → **kiểm lại credentials một lần nữa** rồi mới `session()->regenerate()`. Không cần gói `afsakar/filament-otp-login` mà tài liệu bộ công cụ đề xuất dự phòng, và không cần tự viết trang login.
- **Mã lưu trong session, đã băm bằng `Hash::make()`, kèm một khoá hạn dùng riêng — không lưu vào bảng nào.** Vậy nên: **không dùng `client_password_reset_tokens`** (bảng đó là của broker đặt lại mật khẩu, khoá chính là `email`, một mục đích khác), và **không thêm bảng mới**. `SESSION_DRIVER=database` nên mã sống qua nhiều tiến trình PHP, hợp với ràng buộc shared hosting của SPEC §2.
- Hệ quả cần ghi rõ, không phải lỗi: mã gắn với **phiên trình duyệt** đã nhập mật khẩu. Khách xin mã trên điện thoại rồi nhập trên máy tính sẽ không vào được. Đây là hành vi đúng về mặt an toàn, nhưng là điều màn hình phải nói ra bằng tiếng người ("Anh/chị nhập mã trên chính màn hình vừa đăng nhập này").
- Mã dùng một lần: `verifyCode()` xoá khoá session sau khi khớp. Hết hạn kiểm bằng `now()->greaterThan($codeExpiresAt)`. Mặc định `codeExpiryMinutes = 4` — **SPEC nói 5**, phải gọi `->codeExpiryMinutes(5)`.
- **Ba lỗ hổng so với SPEC §10.3 mà bộ có sẵn KHÔNG lấp, phải tự lấp ở Task 1:**
  1. `MultiFactorChallenge::hitRateLimiter()` gọi `RateLimiter::hit($key)` **không truyền decay**, nên mặc định là **60 giây**, không phải 15 phút. Số lần thì đúng (`maxRateLimiterAttempts = 5`).
  2. Khoá của limiter đó là `sha1(guard|class|id)` — **chỉ theo tài khoản, không có chiều IP**. SPEC §10.3 đòi cả hai.
  3. `Login::authenticate()` gọi `$this->rateLimit(5)` của Livewire — cũng **60 giây**, và khoá theo component + IP, **không theo email**. Vậy hôm nay một kẻ thử mật khẩu có thể thử 5 lần mỗi phút mãi mãi.

  Cả ba lấp bằng cách kế thừa trang `Login` và ghi đè `getMultiFactorChallenge()` / `isMultiFactorChallengeRateLimited()` / `authenticate()` — đọc vendor trước khi viết, đừng chép chữ ký hàm từ kế hoạch này.
- `EmailAuthentication` đòi model cài `HasEmailAuthentication` với `hasEmailAuthentication(): bool` và `toggleEmailAuthentication(bool)`. SPEC không cho khách tắt OTP, nên `ClientUser::hasEmailAuthentication()` trả **`true` cứng**, `toggleEmailAuthentication()` ném exception, và panel portal **không** đăng ký trang `EditProfile` (chính trang đó mới hiện `DisableEmailAuthenticationAction`). Có test khẳng định không có đường nào tắt được.
- **Thông điệp cho một khách hàng đang lo.** Mặc định của Filament cho mã sai là một câu chung chung, và cho mã hết hạn là **đúng câu đó** — người dùng không phân biệt được "gõ nhầm" với "mã cũ quá". SPEC §8 cấm kiểu thông điệp này (§8.4 nêu ví dụ "Upload failed" là thứ không được nói). Task 1 phải phân biệt ba tình huống bằng ba câu khác nhau, mỗi câu nói **phải làm gì tiếp**: mã sai (gõ lại, mã có 6 số, xem lại thư mới nhất), mã hết hạn (bấm "Gửi lại mã", mã cũ không dùng được nữa), bị khoá tạm (còn bao nhiêu phút, và số điện thoại văn phòng — người đang không vào được tài khoản cần một con đường không đi qua tài khoản). **Không được để lộ tài khoản có tồn tại hay không** (SPEC §10.10): thông điệp ở bước nhập email + mật khẩu giữ nguyên kiểu chung chung của Filament; ba câu trên chỉ xuất hiện *sau* khi mật khẩu đã đúng.

---

## Kết luận về kiểm thử trên điện thoại (đã kiểm chứng bằng `composer require --dry-run`)

Tài liệu bộ công cụ §"Công cụ chất lượng" đề xuất `pestphp/pest-plugin-browser` cho "test trình duyệt thật cho portal ở khổ điện thoại (M5)". Hiện **chưa cài**. Đã kiểm tra thật trong container:

- `pestphp/pest-plugin-browser` **v5.x đòi `php ^8.4`** → vi phạm sàn PHP 8.3. **Không được cài nhánh 5.**
- **v4.3.1 đòi `php ^8.3` + `ext-sockets`**, và `ext-sockets` **có** trong image. `composer require --dev --dry-run` giải được sạch (20 gói, toàn bộ là họ `amphp`), không có cảnh báo bảo mật. Nghĩa là phần PHP không phải rào cản.
- **Rào cản thật nằm ở chỗ khác:** plugin này lái một trình duyệt thật qua Playwright, tức cần Node. Container `webdevops/php:8.3-alpine` **không có `node`, `npm` hay `npx`** (đã kiểm: `sh: node: not found`). Cài được nó nghĩa là đổi `compose.yaml` — thêm nodejs + npm và `npx playwright install chromium` (~150 MB nhị phân trình duyệt) vào image dev, hoặc thêm một service riêng.

**Quyết định của kế hoạch này: không đặt cổng nghiệm thu của M5 lên `pest-plugin-browser`.** Lý do: SPEC §14 mục 4 và §13 dòng M5 là tiêu chí **về con người** ("khách demo đăng nhập và đi hết luồng được trên điện thoại"), và toolchain §6 bước 5 đã nói "portal trên điện thoại **thật**". Một bộ test Playwright không trả lời được câu hỏi đó, còn đổi image dev giữa milestone thì đụng vào thứ cả ba agent đang dùng chung.

Thay vào đó, khổ điện thoại được kiểm bằng hai đường, cả hai đều bắt buộc:

1. **Test có thể fail, không phải ảnh chụp màn hình.** Mỗi màn hình có test khẳng định *cấu trúc làm nên tính dùng được ở 375px*: bố cục **một cột dọc** không có bảng cuộn ngang; thứ tự bảy khối của §8.3 đúng như SPEC liệt kê; khối "Việc anh/chị cần làm" đứng **trên** dòng thời gian khi có việc và **biến mất hoàn toàn** khi không có (SPEC §8.3 mục 2 "chỉ hiện khi có"); nút bấm và ô nhập có lớp cỡ ≥ 44px theo nguyên tắc ở toolchain §4; không có trạng thái rỗng nào là một bảng rỗng (§4 "không hiện bảng rỗng; trạng thái trống phải kèm hướng dẫn bước tiếp theo").
2. **Một lần đi bộ tay trên điện thoại thật ở Task 7**, có ghi lại từng bước và kết quả, trên dữ liệu sau `migrate:fresh --seed`.

Nếu chủ văn phòng hoặc người vận hành muốn có test trình duyệt thật, **đó là một quyết định về hạ tầng dev** (thêm Node vào image) chứ không phải một task của M5 — ghi lại ở đây để nó là một lựa chọn đã cân nhắc, không phải một thứ bị quên.

---

## Việc bắt buộc mang sang từ rà soát M2/M3/M4

Ghi ở đây để không rơi. Mỗi mục có task phụ trách. Bảng này tồn tại vì chính lý do đó: bỏ sót một mục là kiểu hỏng mà bảng này sinh ra để ngăn.

| Việc | Nguồn | Task |
|---|---|---|
| `ClientRequestReply` và `StageLogView` **đọc được ở tầng scope nhưng bị policy từ chối** (`$user instanceof User`) — hai tầng đang lệch nhau; phải chọn một tầng khi dựng luồng yêu cầu | Rà soát toàn nhánh M2, "Park to M5" | 2 |
| `StageLogView` **chưa có ability `create` nào** — không có cách nào diễn đạt "khách ghi nhận đã đọc" bằng policy; hôm nay nó đi qua bằng cách không ai hỏi | Suy ra từ mục trên, xác nhận bằng `StageLogViewPolicy` hiện tại | 2 |
| Hai trong ba điều kiện của `Document::isReleasedToPortal()` **vô nghĩa** vì fixture đoản mạch ở vế đầu: xoá cả `client_can_view` lẫn điều kiện nhóm D, bộ test vẫn xanh | Rà soát M4 Task 2 (opus), I-1 | 2 |
| Middleware 403→404 **không phủ request cập nhật Livewire**: middleware persistent chạy với một response stub 200 trước khi hydrate (`vendor/.../Utils.php:188`), nên `abort(403)` của `hydrateCanAuthorizeAccess` thoát ra ngoài — ba docblock và một tên test đang nói ngược lại. **Toàn bộ portal là Livewire** | Rà soát M4 Task 2 (opus), I-2 | 2 |
| Nhánh `ClientUser` của `DocumentPolicy::create()` không ngữ cảnh vẫn là `true` vô điều kiện, và mọi lời gọi ngầm của Filament đều không truyền ngữ cảnh — màn hình nộp tệp **phải** hỏi ability kèm đúng `MatterChecklistItem`. **Không có chỗ nào ghi nghĩa vụ tương đương cho `ClientRequest` ở M5** | Rà soát M4 Task 2 (opus), I-3 | 5, 6 |
| `ClientRequest` giới hạn portal theo **Client** chứ không theo **ClientUser**; câu "của chính mình" ở SPEC §5 cần chốt một cách đọc **trước khi** dựng giao diện yêu cầu | Rà soát M2 Task 6 + rà soát M4 Task 2 (minor) | Câu hỏi 1 → 2, 6 |
| `FileGuard::safeName()` **chưa có nơi gọi nào ở production**; M4 Task 3/4 phải gọi nó ở chỗ `addMedia()->usingFileName()`, và M4 Task 5 không được echo tên tệp khách gửi vào `Content-Disposition` chưa mã hoá. Màn hình nộp tệp của M5 phải **xác minh** điều đó đã xảy ra, không được mở lại lỗ | Ledger M4 Task 1, "CARRY FORWARD, must not be lost" | 5 |
| `ClamAvScanner` **chưa từng gặp một clamd thật**; lần đầu bật `CLAMAV_ENABLED=true` trên máy thật cần một lần thử EICAR thủ công. M5 là nơi tệp thật đầu tiên do người ngoài văn phòng gửi lên | Ledger M4 Task 1 | 7 (ghi vào README/PROGRESS, không bật ở M5) |
| `Audit::record()` đã được sửa để rơi về guard `client` khi không có ai ở guard `web` — nhưng **chưa từng có sự kiện nào do portal gây ra được ghi**. SPEC §10.6 đòi ghi đăng nhập thành công/thất bại **cả hai guard**, tải tài liệu, và tạo/vô hiệu tài khoản portal | Rà soát M3 Task 1 | 1, 5, 6 |
| 19 khoá Filament vẫn hiện tiếng Anh trong các tệp chưa ai publish, và `LocalizationTest` **cấu trúc không nhìn thấy** vì nó chỉ duyệt tệp đã có dưới `lang/vendor/`. Đáng kể nhất cho M5: `support/components/input/one-time-code.php` `aria_label` — **chính là ô nhập OTP của khách** — và các câu giới hạn tần suất của `filament/auth/multi-factor/**` | Ledger M3, rà soát localisation | 1 |
| `lang/en/` đang **che** bản `en` của framework nên một lần nâng Laravel sẽ để lọt khoá thô mà test vẫn xanh; phải đối chiếu với `vendor/laravel/framework/.../lang/en/validation.php` | Ledger M3 | 1 (nếu M4 Task 7 chưa làm) |
| Bản `vi` bundled của `filament/infolists` **thiếu `entries.icon.true/false`**, nên mọi `IconEntry` boolean hiện "Yes"/"No" tiếng Anh trong text cho trình đọc màn hình. Trang chi tiết portal sẽ dùng infolist | Ledger M3, mục (d) | 4 |
| **Dòng không đổi giai đoạn nhận biết bằng `from_stage === to_stage`, KHÔNG phải `from_stage === null`** (SPEC §4.8 và §6.3 mâu thuẫn; M3 đi theo §6.3). Dòng thời gian portal đọc sai điều kiện này là đọc sai lịch sử vụ việc | PROGRESS "Ghi chú M3" | 4 |
| Tắt rồi bật lại `is_published_to_portal` có thể **làm lộ lại cả một loạt dòng đã publish cùng lúc**; `MatterNotPublishedToPortal` chỉ chặn ở thời điểm tạo. M6 sở hữu nửa thông báo, nhưng **portal là nơi khách nhìn thấy chúng** | Ledger M3 Task 8, PROGRESS | 4 (ghi nhận), mang tiếp M6 |
| Một `DomainException` mà màn hình không bắt riêng sẽ thành **lỗi 500**. Mọi trang portal gọi Action phải bắt và đổi thành lỗi trên form | Ledger M3 Task 9, PROGRESS | 5, 6 |
| `MatterPolicy::view` chạy một truy vấn EXISTS mỗi lần gọi; danh sách portal gọi policy theo từng dòng | Rà soát M2 Task 5, minor 4 | 3 |
| `ChecksPortalVisibility::visibleToPortal()` phụ thuộc ngữ cảnh guard; đã sửa bằng `ClientPortalScope::actingAs()`. Mọi policy mới của M5 **phải** đi qua đường đó, không tự viết điều kiện | Rà soát toàn nhánh M2, mục (2) | 2 |
| `PortalCoverageTest` là lưới an toàn: mọi model mới ở M3–M8 phải hoặc dùng `RestrictedToClientPortal`, hoặc vào danh sách miễn trừ **kèm lý do** | PROGRESS "Ghi chú M2" | 2 |
| `DocumentPolicy::publish()` chưa đồng bộ với `update`/`delete` (không qua `view()`, không chặn nhóm D, cho qua cả vụ việc đã xoá mềm); xoá tài liệu chưa được ghi nhật ký | Rà soát M4 Task 2, minor | — (M4 sở hữu; nếu M4 merge mà còn thì M5 Task 7 nhặt) |
| `ForceDeleteAction`/`RestoreAction` thừa, `MatterType.code` xoá-mềm-rồi-tạo-lại, `MattersByStageWidget` gộp theo nhãn | Rà soát M3 → kế hoạch M4 Task 7 | — (M4 Task 7 sở hữu; nếu rơi thì M5 Task 7) |
| **Giới hạn 20 tệp/giờ/tài khoản của SPEC §10.3 KHÔNG đặt ở Action được.** Đo được, không phải suy đoán: dưới Filament, **các byte đã nằm trên đĩa TRƯỚC khi bất kỳ form action nào chạy** — endpoint `_startUpload` của Livewire lưu tệp ngay khi người dùng chọn nó. Một giới hạn chỉ đứng ở `SubmitClientDocument` không bảo vệ thứ cần bảo vệ (đĩa, và thời gian của clamd). Nó phải đứng ở **cả** endpoint upload **lẫn** thao tác gửi, và khoá theo **tài khoản** — SPEC §10.3 viết "theo tài khoản", không phải theo IP. Và vì một lần bị từ chối KHÔNG để lại dòng nào trong `documents` hay trong nhật ký, bộ đếm phải tự đếm số LẦN THỬ, không dựng lại được từ dữ liệu đã ghi | Rà soát M4 Task 4 (opus), xác minh bằng cách đọc đường `_startUpload` | 5 |
| **`DocumentPolicy::create()` nhánh khách chưa hỏi `is_active`.** Vòng sửa rà soát M4 Task 4 đã đặt điều kiện SPEC §10.9 vào `SubmitClientDocument` và `ReviewChecklistItem` (xem `App\Actions\Concerns\ChecksAccountActive`), nhưng KHÔNG đụng tới policy vì tệp đó đang có người viết dở. Hệ quả còn lại: nhánh không-ngữ-cảnh trả `true` cho một tài khoản đã bị khoá, nên màn hình nộp tệp M5 vẫn **vẽ** nút "Gửi tệp" cho họ — bấm vào thì Action từ chối. Không sai về an toàn, sai về việc mời người ta bấm vào một lời từ chối | Rà soát M4 Task 4 (opus), I6 | 5 |
| **`MatterPolicy::view` chạy `Matter::query()` KHÔNG gỡ `ClientPortalScope`.** Vì vậy hai Action danh mục hồ sơ không tự làm mình độc lập với guard được: với một phiên portal của khách hàng khác đang mở, chính cổng quyền từ chối dù Action đã đọc đúng. Không với tới được từ panel admin (ở đó `isActive()` trả `false` vì guard `web` cũng đang xác thực), nhưng nó chặn mọi lời gọi từ job/console có phiên khách mở | Rà soát M4 Task 4, phát hiện khi mutation probe SỐNG SÓT | 2 |
| **Không viết mã Filament 5 từ trí nhớ** — phán quyết của kế hoạch M3, giữ nguyên hiệu lực | Kế hoạch M3, "Ràng buộc toàn cục" | mọi task |

---

## Cần chủ văn phòng quyết trước khi bắt đầu task tương ứng

Đây là những quyết định **nghiệp vụ**, không phải kỹ thuật. Kế hoạch này cố ý **không** quyết thay. Hỏi đúng lúc, đừng đoán rồi cài.

| # | Câu hỏi | Vì sao không tự quyết được | Chặn task |
|---|---|---|---|
| 1 | Một vụ việc có hai tài khoản portal (SPEC §4.3 nêu ví dụ hai vợ chồng). **Yêu cầu do người vợ gửi thì người chồng có đọc được không?** Hôm nay scope giới hạn theo `client_id` nên là **có**; câu "Tạo và xem `ClientRequest` của chính mình" ở SPEC §5 đọc được là **không** | Hai cách đọc đều hợp lệ về mặt câu chữ, và đây là một câu hỏi về **sự riêng tư giữa hai người trong cùng một gia đình đang có tranh chấp** — một quyết định của văn phòng, không phải của người viết mã. Đã bị nêu hai lần (M2, M4) và vẫn treo | 2 (chọn tầng), 6 (giao diện) |
| 2 | **Khách có được trả lời tiếp vào một yêu cầu đã gửi không, hay mỗi lần hỏi là một yêu cầu mới?** SPEC §8.3 mục 7 chỉ nói "form đơn giản, xem lại lịch sử trao đổi"; nhưng §4.14 cho `client_request_replies.author_type` là morph, ngụ ý khách cũng viết được | Ảnh hưởng thẳng tới mô hình hội thoại và tới việc trợ lý có phải theo dõi một hộp thư sống hay chỉ trả lời một lần | 6 |
| 3 | **`communication_logs` có hiện trên portal không?** M2 đã ghi: đây là một **bổ sung** vào danh sách portal được liệt kê ở SPEC §5, đang đóng theo mặc định, gated bởi `is_visible_to_client`. SPEC §8.3 **không** liệt kê nó trong bảy khối | Thêm một khối mà SPEC không liệt kê là mở rộng phạm vi dữ liệu khách nhìn thấy — đúng loại việc phải có người ký, không phải suy ra | 4 |
| 4 | **Phông chữ Bunny.** SPEC §3 đã ghi quyết định "giữ Bunny ở giai đoạn này", nhưng cũng ghi rõ cái giá: địa chỉ IP của khách chạm tới một hạ tầng ngoài tầm kiểm soát **ngay ở trang đăng nhập, trước khi ai đăng nhập**. M5 là lúc trang đó có người thật dùng lần đầu | SPEC tự nói đây là quyết định của quan điểm bảo vệ dữ liệu của văn phòng, và nêu sẵn phương án đóng lại (tự host `woff2` + `FontProviders::local()`). Hỏi lại một lần ở đúng thời điểm, không tự đổi | 1 |
| 5 | **Luật sư chưa mở được vụ việc cho một khách hàng hoàn toàn mới** (hệ quả của ma trận quyền SPEC §5: luật sư không có `client.manage`). Câu hỏi tiếp nhận khách mới vẫn treo từ M3 | Nới ô chọn khách hàng là nới đúng chỗ dữ liệu khách đã rò rỉ hai lần trên nhánh M3 | Không chặn M5 (dữ liệu mẫu đã có tài khoản portal). Ghi ở đây để nó không bị coi là đã xử lý |

---

## Cấu trúc tệp (trạng thái cuối M5)

| Đường dẫn | Trách nhiệm |
|---|---|
| `app/Models/ClientUser.php` | Cài `HasEmailAuthentication`; OTP bật cứng, không tắt được |
| `app/Filament/Portal/Pages/Auth/Login.php` | Kế thừa `Filament\Auth\Pages\Login`; giới hạn tần suất theo SPEC §10.3 (5 lần / 15 phút, theo **cả** email lẫn IP); ghi nhật ký đăng nhập thành công và thất bại |
| `app/Filament/Portal/Pages/Auth/ChangePassword.php` | SPEC §8.1 "lần đầu đăng nhập bắt buộc đổi mật khẩu" |
| `app/Http/Middleware/EnsurePortalAccountIsActive.php` | SPEC §10.9: `is_active = false` thì phiên bị vô hiệu **ngay ở request kế tiếp**, và người dùng thấy **màn hình đăng nhập** chứ không phải 404 |
| `app/Http/Middleware/RequirePortalPasswordChange.php` | Ép đổi mật khẩu lần đầu, không cho đi vòng bằng URL |
| `app/Notifications/Client/SendLoginCode.php` | Mã OTP bằng tiếng Việt (mẫu `client.otp` của SPEC §9; layout chung là M6) |
| `app/Actions/Portal/RecordStageLogView.php` | SPEC §4.18: một bản ghi cho mỗi cặp, lần xem đầu tiên, kèm IP |
| `app/Actions/Portal/{OpenClientRequest,ReplyToClientRequest}.php` | SPEC §4.14, §8.3 mục 7 |
| `app/Filament/Portal/Pages/MyMatters.php` | SPEC §8.2 — danh sách thẻ hồ sơ |
| `app/Filament/Portal/Pages/MatterProgress.php` | SPEC §8.3 — bảy khối dọc |
| `app/Filament/Portal/Pages/SubmitDocument.php` | SPEC §8.4 — chọn đầu mục, chụp ảnh, xem trước, gửi |
| `app/Filament/Portal/Pages/MyRequests.php` | SPEC §8.3 mục 7 |
| `app/Filament/Admin/Resources/Matters/RelationManagers/ClientRequestsRelationManager.php` | Đầu kia của cuộc trao đổi, SPEC §7.2 tab "Yêu cầu từ khách" |
| `app/Filament/Admin/Widgets/UnseenUpdatesWidget.php` | SPEC §7.1 mục 5 — giờ mới có dữ liệu |
| `lang/vi/portal.php`, `lang/vi/requests.php` | Chuỗi khách hàng đọc |
| `lang/vendor/filament/vi/**` | Các khoá còn tiếng Anh, gồm `one-time-code` `aria_label` |
| `tests/Feature/Portal/*`, `tests/Feature/Authorization/PortalIsolationSweepTest.php` | |

---

### Task 1: Đăng nhập portal — OTP, đổi mật khẩu lần đầu, giới hạn tần suất, vô hiệu phiên

**Files:** `app/Models/ClientUser.php`, `app/Providers/Filament/PortalPanelProvider.php`, `app/Filament/Portal/Pages/Auth/{Login,ChangePassword}.php`, `app/Http/Middleware/{EnsurePortalAccountIsActive,RequirePortalPasswordChange}.php`, `app/Notifications/Client/SendLoginCode.php`, `lang/vi/portal.php`, `lang/vendor/filament/vi/**`, `tests/Feature/Portal/LoginTest.php`

**Interfaces:** Produces — `ClientUser implements HasEmailAuthentication` (`hasEmailAuthentication()` trả `true` cứng, `toggleEmailAuthentication()` ném `LogicException`); panel portal gọi `->multiFactorAuthentication(EmailAuthentication::make()->codeExpiryMinutes(5))` và `->login(Login::class)`; hai middleware đăng ký trong `authMiddleware` của panel. Consumes — `App\Support\Audit::record()` (M3).

**Đọc trước khi viết:** `vendor/filament/filament/src/Auth/Pages/Login.php`, `.../MultiFactor/MultiFactorChallenge.php`, `.../MultiFactor/Email/EmailAuthentication.php`. Chữ ký hàm trong kế hoạch này có thể sai — bản đang cài là nguồn sự thật.

Việc phải làm, theo SPEC §8.1, §10.3, §10.6, §10.7, §10.9:

1. **OTP bắt buộc, không tắt được.** Panel portal không đăng ký trang `EditProfile` (đó là nơi `DisableEmailAuthenticationAction` sống). Có test khẳng định không đường nào tắt OTP.
2. **Hiệu lực 5 phút**, không phải 4 mặc định. Mã dùng một lần; mã đã dùng rồi nhập lại phải bị từ chối.
3. **Giới hạn tần suất đúng SPEC §10.3: 5 lần / 15 phút, theo cả email lẫn IP** — ba chỗ phải sửa, đã nêu ở mục "Kết luận về OTP". Test phải chứng minh **cả hai chiều**: khoá theo email chặn kẻ đổi IP; khoá theo IP chặn kẻ đổi email. Một test chỉ chứng minh một chiều là một test tự nhận mình làm hai việc.
4. **Ba thông điệp lỗi phân biệt** (sai / hết hạn / bị khoá), tiếng Việt, mỗi câu nói phải làm gì tiếp. **Trước khi mật khẩu đúng thì không câu nào được tiết lộ tài khoản có tồn tại hay không** (SPEC §10.10) — có test riêng cho ranh giới này.
5. **Đổi mật khẩu lần đầu** (`must_change_password`): middleware chặn mọi trang portal khác cho tới khi đổi xong; test phải thử **đi vòng bằng URL trực tiếp**, không chỉ thử bấm nút.
6. **SPEC §10.9 — vô hiệu phiên ngay.** `is_active = false` thì request kế tiếp bị đăng xuất, không đợi hết hạn session. M4 đã nhận một cái giá ở đây và **giao lại cho M5**: hôm nay tài khoản bị vô hiệu nhận **404**, trong khi "câu trả lời nhân đạo cho họ là màn hình đăng nhập". Sửa đúng chỗ đó: middleware này chạy **trước** `Authenticate`, gọi `logout()` + `invalidate()` + `regenerateToken()` rồi chuyển hướng về trang đăng nhập kèm một câu giải thích trung tính (không nói vì sao tài khoản bị khoá — đó là việc của văn phòng nói qua điện thoại).
7. **Nhật ký (SPEC §10.6).** Ghi đăng nhập **thành công và thất bại** của guard `client` qua `Audit::record()`. Đây là lần đầu tiên nhánh guard `client` của helper đó thật sự chạy — test phải khẳng định `causer` là `ClientUser`, không phải `null`. Cập nhật `last_login_at`, `last_login_ip` (SPEC §4.3).
8. **Thư OTP bằng tiếng Việt.** Override notification của Filament. Layout chung theo SPEC §9 và dòng `outbound_messages` là việc của M6 — ghi rõ điều đó trong docblock, đừng để trống mà không nói.
9. **Bản dịch mang sang:** publish và dịch `support/components/input/one-time-code.php` (`aria_label` — chính ô này) và `filament/auth/multi-factor/**`. Mở rộng `LocalizationTest` để nó **nhìn thấy được** các tệp chưa publish (hôm nay nó chỉ duyệt `lang/vendor/`, nên lỗ hổng này cấu trúc là vô hình), và đối chiếu `lang/en/` với `vendor/laravel/framework/.../lang/en/validation.php` thay vì với chính nó — trừ khi M4 Task 7 đã làm, khi đó chỉ xác nhận.

**Test bắt buộc:** OTP bắt buộc và không tắt được; mã hết hạn sau 5 phút; mã dùng lại bị từ chối; mã của tài khoản A không mở được tài khoản B; 5 lần sai khoá 15 phút theo email **và** theo IP; mật khẩu sai không tiết lộ email có tồn tại; `must_change_password` không đi vòng được bằng URL; `is_active = false` → request kế tiếp về màn hình đăng nhập (không phải 404, không phải 403); đăng nhập thành công và thất bại đều sinh một dòng nhật ký có `causer` là `ClientUser`; toàn bộ chuỗi khách đọc đều qua `__()`.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: portal login with a mandatory email one-time code`.

---

### Task 2: Chốt ba tầng cho các model portal còn treo, và `RecordStageLogView`

**Files:** `app/Policies/{StageLogView,ClientRequestReply,ClientRequest}Policy.php`, `app/Models/{StageLogView,ClientRequestReply,ClientRequest}.php`, `app/Actions/Portal/RecordStageLogView.php`, `app/Http/Middleware/AnswerDeniedPanelRequestsWithNotFound.php`, `tests/Feature/Authorization/PortalIsolationSweepTest.php`, `tests/Feature/Actions/Portal/RecordStageLogViewTest.php`

Task này **không có giao diện**. Nó tồn tại để ba task màn hình phía sau không phải vừa dựng màn hình vừa quyết định luật phân quyền — đúng cái bẫy M4 đã rơi vào.

1. **Chọn tầng cho `StageLogView` và `ClientRequestReply`.** Hôm nay scope cho khách đọc, policy từ chối sạch (`$user instanceof User`). Hai tầng lệch nhau là thứ M2 đã ghi "pick a layer when the request thread is built" — giờ là lúc đó. Lời khuyên, không phải mệnh lệnh: **cho khách đọc ở cả hai tầng**, vì SPEC §8.3 mục 7 nói khách "xem lại lịch sử trao đổi" nên trả lời phải đọc được; và biên bản đã xem là dữ liệu **về chính họ**. Dù chọn đường nào, **ghi lý do vào docblock** và có test cho cả hai tầng.
2. **`StageLogView` cần một ability `create`.** Không có nó thì "khách ghi nhận đã đọc" chỉ đi lọt vì không ai hỏi — đúng hình dạng lỗ hổng `DocumentPolicy::create()` trả `true` mà M4 vừa phải vá. Theo quy ước tham số ngữ cảnh tuỳ chọn đã dùng ở `DocumentPolicy::create` / `ClientRequestPolicy::create` (đọc hai tệp đó trước).
3. **`RecordStageLogView` Action.** SPEC §4.18: **một** bản ghi cho mỗi cặp `(stage_log_id, client_user_id)`, **lần xem đầu tiên**, kèm `viewed_at` và `ip`. Bảng đã có `unique(stage_log_id, client_user_id)`, nên đường chạy đua hai tab mở cùng lúc phải xử bằng `firstOrCreate` **và** bắt lỗi trùng khoá, không chỉ bằng một lần `exists()` trước khi insert. **Không cập nhật `viewed_at` ở lần xem thứ hai** — giá trị của bảng này là dấu thời gian của lần đọc đầu, ghi đè là xoá mất bằng chứng. Chỉ ghi cho dòng `is_published = true` thuộc vụ việc khách nhìn thấy được, và đi qua policy chứ không tin vào lời gọi.
4. **Vá lỗ hổng vô nghĩa của `Document::isReleasedToPortal()`** — sửa fixture để nhóm D là `published` + `client_can_view = true` + `client_can_download = true`, rồi **chứng minh bằng đột biến** rằng mỗi trong ba điều kiện bây giờ đều làm test đỏ khi bị xoá. Dán ba lần đỏ vào báo cáo.
5. **Middleware 404 và request cập nhật Livewire.** Rà soát M4 đã chứng minh trong vendor rằng middleware persistent chạy với một response stub 200 trước khi hydrate, nên `abort(403)` từ `hydrateCanAuthorizeAccess` thoát ra ngoài — và ba docblock cùng một tên test đang nói ngược lại. Toàn bộ portal là Livewire, nên đây là chỗ nó cắn. **Sửa hành vi hoặc sửa những câu docblock nói sai — không được để cả hai như cũ.** Nếu Filament 5 không có seam để phủ đường đó thì ghi lại chính xác điều đó và thu hẹp lời hứa của docblock về đúng sự thật.
6. **Test quét cách ly (`PortalIsolationSweepTest`)** — viết một lần ở đây, chạy lại và mở rộng sau mỗi task màn hình. Với khách A và một vụ việc của khách B, khẳng định **không có đường nào** trong danh sách sau trả về dữ liệu của B hay bất kỳ tài liệu nhóm D / nội dung chưa công bố nào: danh sách, trang chi tiết theo id, quan hệ đi xuôi, quan hệ đi ngược, `parent_document_id` và chuỗi version, tải về, thuộc tính đã serialize, ô tìm kiếm, `withCount`, `->count()`, `whereHas` dùng làm máy dò tồn tại, bản ghi đã xoá mềm, và vụ việc có `is_published_to_portal = false`. Mỗi dòng của danh sách này là một dòng test có thể đỏ, không phải một bình luận.
7. **`PortalCoverageTest` vẫn phải xanh** — M5 không thêm model nào theo dự kiến; nếu có thì nó phải dùng `RestrictedToClientPortal` hoặc vào danh sách miễn trừ **kèm lý do viết ra**.

**Phụ thuộc:** cần Câu hỏi 1 (yêu cầu theo Client hay theo ClientUser) đã có câu trả lời trước khi chốt `ClientRequestPolicy`. Nếu chưa có, làm xong mục 2–7 và **dừng lại hỏi**, đừng đoán.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: view receipts and a settled protection layer for portal child records`.

---

### Task 3: Danh sách hồ sơ (SPEC §8.2)

**Files:** `app/Filament/Portal/Pages/MyMatters.php` + view, `lang/vi/portal.php`, `tests/Feature/Portal/MyMattersTest.php`

Màn hình đầu tiên khách thấy sau khi đăng nhập. **Không phải Dashboard mặc định của Filament** — gỡ `Dashboard::class` khỏi panel portal hoặc thay bằng trang này, vì một dashboard trống là đúng thứ toolchain §4 cấm ("không hiện bảng rỗng").

Mỗi hồ sơ là một **thẻ**, không phải một dòng bảng (bảng cuộn ngang trên điện thoại là không dùng được): mã hồ sơ, tiêu đề, **nhãn giai đoạn dễ hiểu** (`matter_type_stages.client_label`, **không bao giờ** `label` nội bộ), ngày cập nhật gần nhất viết theo lối người thường đọc, thanh tiến độ danh mục `X/Y`, và huy hiệu đỏ khi còn giấy tờ cần nộp.

- `X/Y` theo đúng công thức SPEC §4.10: **Y = số item `is_required = true` cộng số item không bắt buộc nhưng đã có tài liệu.** Đây là chỗ dễ tính sai; có test riêng cho từng vế, gồm cả vế "không bắt buộc nhưng đã nộp" mà một cách hiểu ngây thơ sẽ bỏ sót.
- Ba màu có nghĩa xuyên suốt (toolchain §4): xanh xong, vàng chờ khách, đỏ quá hạn. Màu không được là kênh thông tin duy nhất — kèm chữ.
- **Không có hồ sơ nào** thì hiện một câu hướng dẫn bước tiếp theo kèm cách liên hệ văn phòng, không phải một khoảng trắng.
- **Hiệu năng:** `MatterPolicy::view` chạy một EXISTS mỗi lần gọi (mang sang từ M2). Nạp sẵn quan hệ cần dùng và đo: một màn hình 20 thẻ không được sinh hàng trăm truy vấn. Có test đếm truy vấn với một ngưỡng nêu rõ lý do.

**Test bắt buộc:** nghi thức ba tầng đầy đủ; danh sách của khách A **không bao giờ** chứa vụ của khách B, **kể cả khi truyền tham số lọc thủ công** (SPEC §11, chữ "kể cả" đó là một test riêng, không phải một lời hứa); vụ `is_published_to_portal = false` không xuất hiện dù đúng `client_id`; hiện `client_label` chứ không phải `label`; `X/Y` đúng ở cả ba trường hợp; trạng thái rỗng có hướng dẫn; bố cục một cột, không cuộn ngang ở 375px.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: portal matter list, built for a phone`.

---

### Task 4: Chi tiết hồ sơ (SPEC §8.3)

**Files:** `app/Filament/Portal/Pages/MatterProgress.php` + view, `lang/vi/portal.php`, `lang/vendor/filament/vi/infolists/**`, `tests/Feature/Portal/MatterProgressTest.php`

Bảy khối dọc, **đúng thứ tự SPEC §8.3 liệt kê**, và thứ tự đó có test:

1. **Tình trạng hiện tại** — `client_label` cỡ lớn kèm `client_description` giải thích giai đoạn này nghĩa là gì.
2. **Việc anh/chị cần làm** — **chỉ hiện khi có**. Ô nổi bật nhất màn hình: giấy tờ còn thiếu cộng `client_action` của dòng cập nhật mới nhất. Khi không có việc gì thì khối này **biến mất hoàn toàn**, không phải hiện dòng "không có việc" — và có test cho cả hai trạng thái.
3. **Dòng thời gian** — `stage_logs` đã công bố, mới nhất trên cùng, mỗi mục đúng **bốn** phần: chuyện gì đã xảy ra, tiếp theo là gì, anh/chị cần làm gì, dự kiến có tin trước ngày nào. **Nhận biết dòng không đổi giai đoạn bằng `from_stage === to_stage`, không phải `from_stage === null`** — SPEC §4.8 và §6.3 mâu thuẫn và M3 đã đi theo §6.3.
4. **Hồ sơ giấy tờ** — từng đầu mục kèm trạng thái. Mục `rejected` hiện **lý do đầy đủ** (không cắt ngắn, không "..." — lý do đó được viết ra để khách đọc và làm theo) và một nút nộp lại. Mục `missing` có nút nộp.
5. **Tài liệu** — chỉ những gì `client_can_view`; nút tải **chỉ hiện khi** `client_can_download`. Hai cờ độc lập (SPEC §6.5 bước 3).
6. **Mốc thời hạn sắp tới** — chỉ mốc `is_published`.
7. **Gửi yêu cầu** — lối vào Task 6.

Ngoài ra:

- **Ghi nhận đã đọc:** mỗi dòng tiến độ đã công bố mà khách thật sự nhìn thấy sinh một lần gọi `RecordStageLogView` (Task 2). Ghi khi khối hiện ra, không phải khi trang được nạp lần đầu nếu dòng đó nằm ngoài màn hình — nhưng **đừng làm phức tạp hơn mức cần**: ghi khi trang chi tiết render dòng đó là một cách đọc hợp lệ và đơn giản hơn nhiều; chọn một cách, viết lý do, và nói rõ nó nghĩa là gì với nhãn "Khách đã xem" ở panel nội bộ, vì đó là **bằng chứng pháp lý** chứ không phải số liệu thống kê.
- **Bản dịch mang sang:** `filament/infolists` `vi` thiếu `entries.icon.true/false` nên mọi `IconEntry` boolean hiện "Yes"/"No" tiếng Anh. Publish **cả tệp** (override của Laravel thay thế chứ không trộn, nên một override hai khoá sẽ xoá trắng phần còn lại) và quét các khoá thiếu khác cùng lúc.
- **Ghi nhận rủi ro mang tiếp sang M6:** tắt rồi bật lại `is_published_to_portal` làm cả một loạt dòng đã publish hiện ra cùng lúc trên chính màn hình này, với `notified_at` chưa reset. M5 không sửa việc đó (M6 sở hữu), nhưng docblock của trang phải nói ra.
- **Câu hỏi 3** (có hiện `communication_logs` không) chặn việc quyết có khối thứ tám hay không. Mặc định của kế hoạch: **không có**, vì SPEC §8.3 không liệt kê nó.

**Test bắt buộc:** nghi thức ba tầng; gọi thẳng URL chi tiết vụ việc của khách khác → **404** (SPEC §11, §10.10); `internal_note` mang chuỗi đánh dấu duy nhất **không xuất hiện** ở bất kỳ đâu trong HTML lẫn JSON của response (SPEC §11 "Ghi chú nội bộ") — và đây là chỗ `HidesInternalAttributesFromPortal` phải được chứng minh bằng đột biến, không chỉ được tin; dòng chưa công bố không lên timeline; tài liệu nhóm D không lên khối 5 **kể cả khi** `client_can_view` và `client_can_download` đều bật; `client_can_download = false` thì xem được mà không có nút tải; khối 2 biến mất khi không có việc; thứ tự bảy khối đúng; dòng không đổi giai đoạn hiển thị đúng; một lần xem sinh đúng **một** bản ghi `stage_log_views` và lần xem thứ hai **không** đổi `viewed_at`.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: portal matter detail with a progress timeline and view receipts`.

---

### Task 5: Nộp tài liệu (SPEC §8.4)

**Files:** `app/Filament/Portal/Pages/SubmitDocument.php` + view, `lang/vi/portal.php`, `tests/Feature/Portal/SubmitDocumentTest.php`

**Phụ thuộc cứng:** `App\Actions\Document\SubmitClientDocument` và `DocumentDownloadController` của M4 phải đã merge. Task này **không viết nghiệp vụ nộp tệp** — chín bước của SPEC §6.6 đã là của M4. Nó dựng màn hình, và màn hình chỉ gọi Action.

Luồng SPEC §8.4: **chọn đầu mục → tải tệp lên (hỗ trợ chụp ảnh trực tiếp trên điện thoại) → xem trước → gửi**. Sau khi gửi hiện "Đang chờ văn phòng kiểm tra".

- **Chụp ảnh trực tiếp** là yêu cầu, không phải tuỳ chọn: ô tải tệp phải nhận `capture` trên di động. Kiểm bằng tay trên máy thật ở Task 7.
- **Xem trước trước khi gửi** — một khách chụp nhầm trang phải thấy được điều đó trước khi bấm gửi, không phải sau khi bị từ chối ba ngày sau.
- **Thông báo lỗi nói rõ phải làm gì.** SPEC §8.4 cấm kiểu "Upload failed" và cho sẵn một câu mẫu về tệp quá 20 MB. `FileRejected` của M4 đã mang thông điệp tiếng Việt; màn hình **bắt `DomainException` và đổi thành lỗi trên form**, không để thành 500 (bài học M3 Task 9).
- **Luôn truyền `MatterChecklistItem` khi hỏi `DocumentPolicy::create`.** Nhánh không ngữ cảnh của policy đó cố ý chỉ trả lời câu hỏi giao diện ("có hiện nút không") và với `ClientUser` nó vẫn là `true` vô điều kiện — mang sang từ rà soát M4. Màn hình này hỏi sai là màn hình này tự mở lỗ.
- **Xác minh `FileGuard::safeName()` đã được gọi thật** ở chỗ `addMedia()->usingFileName()` (mang sang từ M4 — nó **chưa có nơi gọi nào ở production** khi M4 Task 1 kết thúc). Nếu M4 Task 3/4 đã nối thì chỉ cần một test khẳng định; nếu chưa thì nối ở đây và nói rõ trong báo cáo rằng đó là một việc của M4 rơi lại.
- **Giới hạn tần suất SPEC §10.3: 20 tệp / giờ / tài khoản.** Có test.
- **Nộp lại** một đầu mục đã bị từ chối đi đúng nhánh version của SPEC §6.6 bước 7: bản mới `version + 1`, `parent_document_id` trỏ bản cũ, **không ghi đè**. Bản cũ vẫn còn và **không** hiện lẫn lộn cho khách như một tài liệu riêng.
- **Nhật ký:** lượt nộp của khách sinh một dòng nhật ký có `causer` là `ClientUser` (SPEC §10.6, và đây là nhánh guard `client` của `Audit::record` mang sang từ M3).

**Test bắt quan trọng nhất:** khách A **không** nộp được vào đầu mục của khách B — thử bằng cách sửa tham số, đúng tinh thần SPEC §14 mục 5 — và câu trả lời là **404**, không phải 403. Ngoài ra: `.svg` bị từ chối kèm thông điệp đọc được; tệp > 20 MB bị từ chối kèm đúng lời khuyên của SPEC §8.4; đuôi `.pdf` mà MIME thật là `application/x-dosexec` bị từ chối; nộp lại tạo version 2 và version 1 còn nguyên; quá 20 tệp/giờ bị chặn; sau khi gửi trạng thái đầu mục là `pending_review` và màn hình nói "Đang chờ văn phòng kiểm tra".

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: clients submit paperwork from their phone`.

---

### Task 6: Yêu cầu từ khách — cả hai đầu của cuộc trao đổi

**Files:** `app/Actions/Portal/{OpenClientRequest,ReplyToClientRequest}.php`, `app/Filament/Portal/Pages/MyRequests.php` + view, `app/Filament/Admin/Resources/Matters/RelationManagers/ClientRequestsRelationManager.php`, `app/Filament/Admin/Widgets/UnseenUpdatesWidget.php`, `app/Filament/Admin/Resources/Matters/Pages/ViewMatter.php` (nhãn "Khách đã xem"), `lang/vi/requests.php`, tests

Task này cố ý làm **cả hai đầu**. SPEC §14 mục 4 đòi khách "nhận được phản hồi khi bị từ chối" — một yêu cầu gửi đi mà không ai trong văn phòng nhìn thấy thì tiêu chí đó không chứng minh được.

**Đầu khách (SPEC §8.3 mục 7):** form đơn giản gửi yêu cầu gắn vụ việc, và xem lại lịch sử trao đổi. Trạng thái hiển thị bằng tiếng người, không phải bằng tên enum.

**Đầu văn phòng (SPEC §7.2 tab "Yêu cầu từ khách"):** hộp thư của vụ việc — nhận, đổi trạng thái (`new` → `in_progress` → `answered` → `closed`), gán người xử lý, trả lời. Lọc qua `ScopesToVisibleMatters` như mọi relation manager khác của M3/M4.

Kèm theo, vì giờ mới có dữ liệu:

- **Nhãn "Khách đã xem lúc 21:14 ngày 14/09" / "Khách chưa xem"** trên từng dòng tiến độ đã công bố ở tab Tiến độ của panel nội bộ (SPEC §7.2), tô vàng khi chưa xem quá 5 ngày.
- **Widget SPEC §7.1 mục 5 "Khách chưa xem cập nhật"** — dòng đã công bố quá 5 ngày mà chưa có bản ghi `stage_log_views`. Giới hạn theo `listableBy`, như mọi widget khác.

**Phụ thuộc:** Câu hỏi 1 (người nhà có đọc được yêu cầu của nhau không) và Câu hỏi 2 (khách trả lời tiếp hay mở yêu cầu mới) **phải có câu trả lời trước khi bắt đầu**. Hai câu này quyết định mô hình hội thoại; đoán rồi sửa sau là sửa cả giao diện lẫn policy.

**Test bắt buộc:** nghi thức ba tầng cho `ClientRequest` và `ClientRequestReply`; khách A không đọc, không trả lời, không gán được yêu cầu của khách B (404); khách không sửa được yêu cầu đã gửi; **`ClientRequestPolicy::create` luôn được hỏi kèm `Matter`** (mang sang từ rà soát M4 — nghĩa vụ này trước đây không được ghi ở đâu cả); nhân sự không có `matter.update` (kế toán) không mở và không trả lời được; trả lời sinh nhật ký có `causer` đúng ở cả hai phía; nhãn "Khách đã xem" hiện đúng cả hai trạng thái; widget chỉ đếm dòng thuộc vụ việc người đang xem thấy được.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: client requests, answered from the office`.

---

### Task 7: Đi bộ trên điện thoại thật, dữ liệu mẫu, tài liệu

**Files:** `database/seeders/*`, `docs/PROGRESS.md`, `README.md`, `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md`

1. **`migrate:fresh --seed` trên container MariaDB thật**, rồi kiểm tra dữ liệu mẫu SPEC §12 đủ để demo **toàn bộ** luồng M5: ít nhất một khách có 2 tài khoản portal; vụ việc đang thiếu giấy tờ bắt buộc; một đầu mục `rejected` **kèm lý do thật đọc được** (không phải chuỗi giả của factory — khách demo sẽ đọc câu đó); tài liệu đã công bố có và không có quyền tải; mốc thời hạn `is_published`; dòng tiến độ đã có và chưa có `stage_log_views` (để widget Task 6 có dữ liệu cả hai phía). Bổ sung seeder nếu thiếu.
2. **Đi bộ tay trên điện thoại thật**, ghi lại từng bước và kết quả, đúng kịch bản SPEC §14 mục 4: đăng nhập bằng email + mật khẩu, nhận mã trong hộp thư (Mailpit), nhập mã, bị ép đổi mật khẩu, xem danh sách, mở một hồ sơ, đọc tiến độ, thấy còn thiếu giấy tờ gì, **chụp ảnh trực tiếp** nộp lên, thấy "Đang chờ văn phòng kiểm tra", rồi (ở panel nội bộ) từ chối kèm lý do và quay lại portal đọc lý do đó, nộp lại, gửi một yêu cầu và đọc câu trả lời. **Thử cả các nhánh xấu:** nhập sai mã 5 lần, để mã hết hạn, gửi tệp quá 20 MB, gửi `.svg`, và sửa id trên URL để thử mở hồ sơ của khách khác.
3. **Xác nhận SPEC §14 mục 5 bằng tay**, không chỉ bằng test: không có cách nào, kể cả sửa tham số URL, để khách nhìn thấy dữ liệu khách khác hoặc tài liệu nhóm D.
4. **Ghi lại quyết định về ClamAV:** `NullScanner` vẫn là mặc định, và `ClamAvScanner` **chưa từng gặp một clamd thật** — lần đầu bật `CLAMAV_ENABLED=true` trên máy thật cần một lần thử EICAR thủ công. M5 là milestone đầu tiên có tệp do người ngoài văn phòng gửi lên, nên câu này thuộc về README triển khai, không thuộc về một bình luận trong mã.
5. **Nhặt việc rơi từ M4 Task 7** nếu có (`ForceDeleteAction`/`RestoreAction` thừa, `MatterType.code`, `MattersByStageWidget` gộp theo nhãn, `DocumentPolicy::publish` chưa đồng bộ).
6. **Cập nhật `docs/PROGRESS.md`** dòng M5 và mục "Ghi chú M5": mọi phán quyết, mọi sai lệch so với kế hoạch, mọi việc hoãn, và **trả lời của chủ văn phòng cho năm câu hỏi** ở trên. Cập nhật `README.md` (tài khoản demo portal, luồng OTP) và mục M5 của tài liệu bộ công cụ, gồm cả kết luận về `pest-plugin-browser`.
7. **`security-review` bắt buộc** (toolchain §6). Người rà soát cuối được brief là giả định có một Critical.

- [ ] Test xanh, pint sạch, commit `docs: M5 complete — the client portal`.

---

## Thứ tự và việc chạy song song

Phụ thuộc thật:

```
M4 đã merge
   ├── Task 1 (đăng nhập)  ─┐
   └── Task 2 (ba tầng)    ─┤
                            ├── Task 3 (danh sách)
                            └── Task 4 (chi tiết)  ──┬── Task 5 (nộp tệp)
                                                     └── Task 6 (yêu cầu)
                                                              └── Task 7
```

- **Task 1 và Task 2 chạy song song được** — tệp rời nhau hoàn toàn (một bên xác thực, một bên policy/Action), và test màn hình dùng `actingAs($clientUser, 'client')` nên Task 2 không chờ trang đăng nhập.
- **Task 3 chỉ cần Task 2** (nó gọi policy theo từng thẻ). Chạy song song với Task 1 được.
- **Task 4 cần Task 2** (`RecordStageLogView`).
- **Task 5 và Task 6 chạy song song được** sau Task 4 — Task 5 đụng trang nộp tệp, Task 6 đụng trang yêu cầu và panel nội bộ; chỗ chạm nhau duy nhất là `lang/vi/portal.php`, nên hai người **phải commit theo đường dẫn tường minh** (bài học M3, học bằng cách làm hỏng).
- **Task 7 cuối cùng**, một mình.
- Khuyến nghị giữ nguyên quy trình M3/M4 vì nó vẫn đang tìm ra lỗi: một người cài đặt mới cho mỗi task, rà soát theo phạm vi task, rà soát lại theo phạm vi sau mỗi vòng sửa, rà soát toàn nhánh trước khi merge. **Việc phân quyền của M5 (Task 1, 2, 5) giao cho Opus** — mọi Critical tìm được từ đầu dự án tới giờ đều đến từ một lượt rà soát Opus.

---

## Tự rà soát kế hoạch

**Độ phủ SPEC §13 dòng M5:** đăng nhập OTP → Task 1; danh sách hồ sơ → Task 3; chi tiết hồ sơ → Task 4; nộp tài liệu → Task 5; gửi yêu cầu → Task 6; ghi nhận `stage_log_views` → Task 2 (Action) + Task 4 (nơi gọi) + Task 6 (nơi nhìn thấy ở panel nội bộ). Tiêu chí "khách demo đăng nhập và đi hết luồng được trên điện thoại" → Task 7.

**Độ phủ SPEC §14:** mục 3 (luật sư chuyển giai đoạn một lần thì khách thấy trên portal) → Task 4 — nửa email là của M6, và kế hoạch này không giả vờ ngược lại. Mục 4 (khách trên điện thoại: đăng nhập, xem tiến độ, thấy thiếu gì, nộp ảnh, nhận phản hồi khi bị từ chối) → Task 1, 3, 4, 5, 6, xác nhận ở Task 7. Mục 5 (không cách nào thấy dữ liệu khách khác hoặc nhóm D) → nghi thức ba tầng ở mọi task màn hình, test quét ở Task 2, xác nhận tay ở Task 7.

**Độ phủ §11 phần portal:** khách A gọi URL chi tiết của khách B → 404 (Task 4); khách A tải URL tài liệu của khách B → 404 (M4 Task 5 đã có, Task 2 quét lại); danh sách khách A không chứa vụ khách B kể cả khi truyền tham số lọc thủ công (Task 3); nhóm D không xuất hiện trong bất kỳ truy vấn nào dưới guard `client` (Task 2, mở rộng ở 4 và 5); `internal_note` không có trong response HTML/JSON (Task 4).

**Nhất quán tên gọi:** `RecordStageLogView` sinh ở Task 2, gọi ở Task 4, nhìn thấy ở Task 6. `PortalIsolationSweepTest` viết ở Task 2, mở rộng ở 3, 4, 5, 6. `SubmitClientDocument` và `FileGuard` là của M4, chỉ được **gọi** ở Task 5. `ClientPortalScope::actingAs()` là đường duy nhất policy được dùng để hỏi "khách này có thấy bản ghi kia không".

**Điều dễ sai nhất trong kế hoạch này:** chi tiết Filament 5. Mọi tên lớp và chữ ký hàm ở đây là thứ tôi **đọc được trong `vendor/` hôm nay** (`EmailAuthentication`, `MultiFactorChallenge`, `HasEmailAuthentication`, `OneTimeCodeInput`, `Filament\Auth\Pages\Login`) — nhưng chúng vẫn là mô tả, không phải đặc tả. Người cài đặt đọc lại vendor, đừng chép từ đây. Và **không có một dòng mã Filament nguyên văn nào trong kế hoạch này là cố ý**: M3 đã ghi phán quyết đó sau khi mã sinh từ trí nhớ cho Filament 3/4 chạy sai trên Filament 5 nhiều lần.

**Điều tôi ít chắc nhất.** Ba thứ, theo thứ tự đáng lo:

1. **Việc ghi nhận "khách đã xem" đúng vào lúc nào.** Kế hoạch cho phép chọn giữa "khi trang render dòng đó" và "khi dòng đó thật sự hiện ra trước mắt". Cách thứ nhất đơn giản và bền; cách thứ hai đúng nghĩa hơn với chữ "đã xem". Nhưng bảng này là **bằng chứng đã thông báo cho khách hàng** — nếu có ngày văn phòng phải đưa nó ra để chứng minh mình đã báo, thì sự khác nhau giữa hai cách đọc là sự khác nhau giữa một bằng chứng đứng vững và một bằng chứng bị bẻ. Tôi đã không quyết, và có thể đó là sai: đây có lẽ đáng là **Câu hỏi thứ sáu cho chủ văn phòng**, vì nó là câu hỏi về giá trị pháp lý chứ không phải về kỹ thuật. Người cài Task 4 nên nêu lại.
2. **Câu hỏi 1 chặn hai task và tôi không biết nó mất bao lâu để có câu trả lời.** Nếu chủ văn phòng chưa trả lời, Task 2 làm được 6 trong 7 mục và Task 6 **không bắt đầu được**. Kế hoạch đã cắt Task 2 để chịu được việc đó, nhưng Task 6 thì không — và Task 6 chứa nửa "nhận được phản hồi" của tiêu chí §14 mục 4. Nếu câu trả lời chậm, thứ tự đúng là đẩy Task 5 lên trước và để Task 6 chờ, chứ không phải đoán.
3. **Số lượng task cho khối lượng thật.** Task 4 (bảy khối, dòng thời gian, ghi nhận đã đọc, bản dịch infolist) rõ ràng lớn hơn Task 3. Tôi để nguyên vì cắt đôi trang chi tiết nghĩa là hai người cùng sửa một tệp view và một tệp lang — chính chỗ M3 đã va chạm. Nếu người cài đặt thấy Task 4 vượt một phiên, cách cắt đúng là tách **khối 4–6 (giấy tờ, tài liệu, mốc hạn)** khỏi **khối 1–3 (tình trạng, việc cần làm, dòng thời gian)**, không phải tách theo tầng.

**Cố ý để lại ngoài M5:**

- **Email thông báo** (SPEC §9, mọi mẫu trừ `client.otp`), listener của `StageLogPublished` và `DocumentPublished`, job SLA 14 ngày, nhắc hạn, nhắc bổ sung giấy tờ, nhắc khách chưa xem, heartbeat — **M6**. Hệ quả phải nói thẳng: hết M5, khách vào portal **thấy** cập nhật nhưng **không được báo** là có cập nhật. Tiêu chí §14 mục 3 vì thế chỉ đạt một nửa ở M5, và PROGRESS phải ghi đúng như vậy thay vì tick xanh.
- **Dòng `outbound_messages` cho thư OTP** — M6, cùng lúc với layout email chung.
- **`client_access_until` / `ExpireClientAccess`** — M7. `Matter::applyClientPortalConstraints()` đã để sẵn chỗ kèm bình luận.
- **Tab Mốc thời hạn và Liên lạc ở panel nội bộ, trang bàn giao, tìm kiếm toàn văn** — M7.
- **Header bảo mật, CSP, HSTS, backup** — M8. SPEC §10.1 và §10.2 không thuộc M5 dù M5 là lúc người ngoài đầu tiên dùng hệ thống; nếu triển khai thật trước M8 thì đó là một rủi ro vận hành, cần ghi vào README chứ không lặng lẽ bỏ qua.
- **`pest-plugin-browser`** — không cài, lý do và cái giá đã ghi ở mục "Kết luận về kiểm thử trên điện thoại". Đây là thứ dễ bị hiểu là "quên"; nó không phải.
