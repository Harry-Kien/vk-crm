# Kết nối AI của bạn với hệ thống hồ sơ

Hướng dẫn cho nhân sự. Ảnh chụp màn hình từng bước được thêm sau lượt chạy thử thật với Claude và
ChatGPT. Đọc `docs/CHINH-SACH-AI.md` trước: bạn phải cam kết với chính sách đó thì mới kết nối được.

> Ký hiệu nguồn: `[PL:n]` là dòng n của `docs/research/2026-09-24-mcp-phap-ly-goi.md`, `[DC:n]` là
> dòng n của `docs/research/2026-09-24-doi-chieu-ung-dung-mcp.md`. Giới hạn của từng gói AI dưới
> đây ghi đúng như tra cứu ghi ở ngày 2026-09-24, kể cả chỗ "chưa kiểm được". Nhà cung cấp AI đổi
> giao diện và gói thường xuyên: thấy khác thì báo quản trị viên để sửa tài liệu này.

## Trước khi bắt đầu

1. **Quản trị viên đã bật quyền AI cho bạn** ("Chỉ đọc" hay "Đọc và ghi") trên trang "Kết nối AI", và
   văn phòng đã bật máy chủ AI. Chưa thì mọi bước dưới đây kết thúc bằng lỗi.
2. **Bạn đã đọc và tích cam kết** trên trang "Kết nối AI của tôi" trong `/admin`. Khi chính sách đổi
   phiên bản, bạn phải cam kết lại; tới lúc đó kết nối cũ ngừng chạy.
3. **Bạn đã tắt việc dùng hội thoại để huấn luyện AI** trên tài khoản AI của mình
   (`docs/CHINH-SACH-AI.md`, mục 7).
4. **Địa chỉ máy chủ AI (URL MCP)** của văn phòng: `https://khachhang.luatvukhang.com/mcp`. Nếu văn
   phòng tách tên miền quản trị riêng, URL nằm trên **tên miền quản trị** (cùng tên miền với
   `/admin`): dán URL trên tên miền cổng khách thì kết nối không bao giờ thành. Trang "Kết nối AI của
   tôi" hiện đúng URL cần dán.

**Màn hình đồng ý.** Ở mọi ứng dụng, bước cuối là đăng nhập `/admin` của văn phòng (kèm mã xác thực
hai lớp) rồi một trang hỏi bạn có đồng ý cho ứng dụng AI hành động với danh nghĩa và quyền của bạn
không. Trang đó ghi **tên miền mà mã đăng nhập sẽ được gửi về** (ví dụ `claude.ai`, `chatgpt.com`,
hay `localhost` cho ứng dụng chạy trên máy bạn), không ghi cái tên mà ứng dụng tự khai. Tên miền
không đúng ứng dụng bạn đang kết nối thì bấm "Từ chối" và báo quản trị viên.

## Claude (web, ứng dụng máy tính, điện thoại)

1. Trong Claude: **Customize → Connectors → Add custom connector** [DC:716], [PL:109].
2. Dán URL MCP của văn phòng. Để Claude tự đăng ký (không điền client ID hay secret).
3. Claude mở trang đăng nhập `/admin`: đăng nhập, nhập mã hai lớp, bấm "Đồng ý".
4. Đặt bốn chức năng ghi ở **"Needs approval"** (bắt buộc, `docs/CHINH-SACH-AI.md` mục 7).

Giới hạn theo gói, theo tra cứu:

- **Free**: chỉ được 1 custom connector. **Pro, Max**: tự thêm. **Team, Enterprise**: chỉ Owner của
  tổ chức thêm connector (Organization settings → Connectors), sau đó từng người tự bấm Connect và
  đăng nhập bằng tài khoản `/admin` của chính mình [DC:359], [DC:601], [DC:716].
- Muốn đổi cách xác thực của một connector thì phải xoá connector rồi thêm lại [DC:716].
- Kết quả của mỗi lần gọi tối đa khoảng 150.000 ký tự; mỗi lần gọi chờ tối đa 240 giây [PL:182].
- Claude gọi từ máy chủ của Anthropic, không từ máy bạn [PL:177].

## Claude Code

1. Trong terminal:

   ```bash
   claude mcp add --transport http vkcrm https://khachhang.luatvukhang.com/mcp
   ```

   (thay URL bằng URL MCP của văn phòng) [PL:186].
2. Trong Claude Code gõ `/mcp`, chọn `vkcrm`, đăng nhập. Trình duyệt mở trang `/admin`; sau khi bấm
   "Đồng ý", trình duyệt quay về một địa chỉ `http://localhost:…/callback` hay
   `http://127.0.0.1:…/callback` trên chính máy bạn — đó là đúng [DC:739].

Giới hạn theo tra cứu: Claude Code cảnh báo khi kết quả một lần gọi vượt 10.000 token và cắt ở
25.000 token [DC:642]. Kết nối đi từ máy bạn, nên nếu văn phòng giới hạn IP vào `/admin`, bạn chỉ
đăng nhập được khi đang ở trong mạng văn phòng.

## ChatGPT (developer mode)

1. **Settings → Security and login → Developer mode**: bật [DC:692]. (Một nguồn khác ghi đường
   Settings → Apps & Connectors [PL:109]; giao diện ChatGPT đổi thường xuyên.)
2. Tạo app ở `chatgpt.com/plugins`, dán URL MCP, chọn xác thực OAuth [DC:692]. App nằm trong mục
   Drafts.
3. ChatGPT mở trang đăng nhập `/admin`: đăng nhập, nhập mã hai lớp, bấm "Đồng ý".
4. Khi ChatGPT hỏi duyệt một chức năng ghi, **không** chọn ghi nhớ lựa chọn (`docs/CHINH-SACH-AI.md`
   mục 7) [DC:695].

Giới hạn theo gói, theo tra cứu:

- Developer mode có trên web cho **Plus, Pro, Business, Enterprise, Edu**; **không có cho Free**
  [DC:692], [PL:22].
- **Giới hạn ghi.** Theo trung tâm trợ giúp của OpenAI, kết nối MCP đầy đủ, gồm cả chức năng ghi,
  chỉ có ở **Business, Enterprise, Edu**. **Pro chỉ đọc**. Với **Plus**, các nguồn nói khác nhau
  [PL:136], [PL:166], [PL:247-248]. Ở **Business**, chỉ admin hay owner của tổ chức dùng được developer
  mode [PL:166].
- Hệ quả thẳng: **nếu bạn dùng ChatGPT cá nhân, coi như chỉ đọc**, kể cả khi văn phòng đã bật "Đọc và
  ghi" cho bạn. Chưa có lượt thử thật nào trả lời chắc cho gói của văn phòng.
- ChatGPT giữ danh sách chức năng đã tải. Sau khi quản trị viên đổi quyền AI của bạn, vào trang chi
  tiết của app và bấm **Refresh** để tải lại [DC:87], [DC:692].

## Ứng dụng MCP khác (VS Code / GitHub Copilot, Cursor, Antigravity)

- Chọn kiểu kết nối **HTTP** (Streamable HTTP). VS Code thử kiểu này trước rồi mới lùi về kiểu cũ
  [PL:200].
- Để ứng dụng **tự đăng ký** (đăng ký client động). Đừng điền client ID hay secret.
- Ứng dụng chỉ đăng nhập được khi địa chỉ quay về sau bước "Đồng ý" (redirect URI) nằm trong danh sách
  văn phòng cho phép. Danh sách hôm nay, so khớp ĐÚNG từng ký tự (riêng `http://localhost` và
  `http://127.0.0.1` không xét cổng; `{callback_id}` là một đoạn do ChatGPT cấp):

  | Ứng dụng | Redirect URI |
  |---|---|
  | Claude (web, máy tính, điện thoại) | `https://claude.ai/api/mcp/auth_callback` |
  | ChatGPT | `https://chatgpt.com/connector_platform_oauth_redirect`, `https://chatgpt.com/connector/oauth/{callback_id}` |
  | Claude Code và ứng dụng dòng lệnh | `http://localhost/callback`, `http://127.0.0.1/callback` |
  | VS Code | `https://vscode.dev/redirect`, `http://127.0.0.1:33418/` |
  | Cursor (tra cứu xếp mức "có lẽ đúng" [PL:199]) | `https://www.cursor.com/agents/mcp/oauth/callback`, `http://localhost:8787/callback` |
  | Antigravity | `https://antigravity.google/oauth-callback` |

  Ứng dụng khác cần một redirect URI khác: **nhờ quản trị viên** thêm đúng URI đó (biến
  `MCP_EXTRA_REDIRECT_URIS` của máy chủ). Không dùng được `http://[::1]:…`.
- **Gemini CLI đã ngừng từ 18/06/2026** với người dùng miễn phí và Google AI Pro/Ultra; thay bằng
  **Antigravity CLI** [PL:195]. Ứng dụng Gemini cá nhân (trên web) chỉ cho thêm app tuỳ chỉnh ở Mỹ
  [PL:193], nên không dùng được ở văn phòng.
- Các ứng dụng này gọi từ chính máy bạn. Trên máy cá nhân còn có rủi ro từ các máy chủ MCP khác cùng
  chạy trong ứng dụng; chỉ cài những máy chủ MCP bạn biết rõ [PL:212].

## AI làm được gì qua kết nối này

Mười một chức năng đọc, cho mọi người được bật quyền AI:

| Chức năng | Dùng để |
|---|---|
| `whoami` | Tôi là ai trong hệ thống, chế độ của tôi, tôi thấy bao nhiêu vụ qua AI |
| `search`, `fetch` | Tìm và mở vụ việc, yêu cầu của khách (ChatGPT dùng hai chức năng này) |
| `search_matters`, `get_matter` | Tìm vụ việc theo chữ, loại, giai đoạn, "vụ tôi phụ trách"; xem tổng quan một vụ |
| `list_matter_updates` | Dòng tiến độ của một vụ |
| `list_deadlines` | Mốc thời hạn; mặc định là mốc của tôi từ hôm nay tới hết 7 ngày, quá hạn lên đầu |
| `get_checklist` | Danh mục hồ sơ của một vụ: đã nộp mấy mục, mục nào bị từ chối |
| `list_documents` | Danh sách tài liệu nhóm A, B, C (không nội dung tệp) |
| `list_client_requests`, `get_client_request` | Yêu cầu của khách và cả cuộc trao đổi |

Bốn chức năng ghi, chỉ khi bạn được bật "Đọc và ghi" **và** văn phòng bật cho phép ghi:
`draft_progress_update` (nháp dòng tiến độ), `draft_request_reply` (nháp trả lời khách),
`create_deadline` (thêm mốc nội bộ), `log_communication` (ghi nhật ký liên lạc nội bộ). Nháp không
bao giờ tự tới khách: bạn mở nháp trên trang vụ việc trong `/admin`, sửa, rồi tự bấm gửi. Thêm mốc và
ghi nhật ký cần hai lần gọi (xem trước, rồi xác nhận). Chi tiết và những gì AI không bao giờ thấy:
`docs/CHINH-SACH-AI.md`, mục 3, 5, 6.

## Không kết nối được?

- **Vừa được bật quyền, hay quản trị viên vừa đổi gì, mà ứng dụng vẫn như cũ**: Claude giữ thông tin
  máy chủ khoảng 5 phút [DC:715]; ChatGPT giữ danh sách chức năng cho tới khi bấm Refresh [DC:87].
  Chờ vài phút, bấm Refresh, hay ngắt rồi kết nối lại.
- **Lỗi 401 lặp lại** (ứng dụng đòi đăng nhập mãi): kiểm với quản trị viên (1) quyền AI của bạn còn
  bật không, (2) máy chủ AI của văn phòng còn bật không, và tự kiểm (3) bạn đã cam kết đúng phiên bản
  chính sách hiện hành trên trang "Kết nối AI của tôi" chưa. Đổi mật khẩu, đặt lại xác thực hai lớp
  cũng thu hồi mọi kết nối: kết nối lại từ đầu.
- **Lỗi 429** (quá nhiều yêu cầu): chờ đúng số giây ứng dụng báo (`Retry-After`) rồi thử lại. Mỗi
  người có giới hạn số lần gọi mỗi phút, chức năng ghi có thêm giới hạn mỗi ngày. Khi cả văn phòng
  kết nối trong cùng một giờ, bước đăng ký có thể báo 429 cho người tới sau: thử lại sang giờ sau.
- **"Không tìm thấy" cho một vụ bạn thấy trên web**: vụ đó là vụ hạn chế, hay chưa được bật "cho phép
  AI" (khách chưa đồng ý bằng văn bản). Đó là cố ý (`docs/CHINH-SACH-AI.md`, mục 3, 4).
- **Không thấy chức năng ghi**: bạn đang ở "Chỉ đọc", văn phòng chưa bật cho phép ghi, hay ứng dụng
  chưa tải lại danh sách (bấm Refresh). Với ChatGPT cá nhân, xem mục "Giới hạn ghi" ở trên.
