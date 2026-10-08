<?php

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\GetMatterTool;
use App\Support\Mcp\PhoneMask;

/**
 * Các luật kiến trúc của dự án, viết thành test để chúng không còn là lời khuyên.
 *
 * Mỗi luật ở đây tương ứng một sai sót đã xảy ra thật trên dự án này, không phải một quy tắc
 * chung chung chép từ đâu về. Chỗ nào chưa xảy ra nhưng chắc chắn sẽ xảy ra thì cũng ghi, và
 * nói rõ là như vậy.
 *
 * `pestphp/pest-plugin-arch` đã nằm sẵn trong vendor từ đầu dự án mà chưa ai dùng. Phát hiện
 * ngày 2026-09-22 khi rà soát công cụ.
 */

// ---------------------------------------------------------------------------------------------
// Nghiệp vụ không được biết gì về giao diện
// ---------------------------------------------------------------------------------------------

/**
 * CLAUDE.md: "Nghiệp vụ nằm trong app/Actions/; Filament resource/controller/job chỉ gọi Action."
 * Luật này là thứ giữ cho hệ thống sau này gắn được một lớp API cho ứng dụng di động mà không
 * phải viết lại luật nào — đúng câu trả lời tôi đã đưa cho chủ văn phòng. Nếu một Action biết
 * tới Filament thì lời hứa đó vỡ, và nó vỡ âm thầm.
 */
arch('nghiệp vụ không phụ thuộc vào Filament hay Livewire')
    ->expect('App\Actions')
    ->not->toUse(['Filament', 'Livewire']);

/**
 * Hai model xác thực là ngoại lệ có lý do, không phải ngoại lệ cho tiện: Filament đòi model
 * đăng nhập cài `FilamentUser` để trả lời `canAccessPanel(Panel $panel)`, và `ClientUser` còn
 * cài `HasEmailAuthentication` cho mã một lần. Đó là cách Filament hỏi model "người này vào
 * được panel nào", không phải giao diện lọt vào tầng dữ liệu. Mọi model khác thì tuyệt đối không.
 */
arch('model không phụ thuộc vào Filament hay Livewire')
    ->expect('App\Models')
    ->not->toUse(['Filament', 'Livewire'])
    ->ignoring(['App\Models\User', 'App\Models\ClientUser']);

arch('policy không phụ thuộc vào Filament hay Livewire')
    ->expect('App\Policies')
    ->not->toUse(['Filament', 'Livewire']);

/**
 * Tầng phân quyền phải trả lời được câu hỏi của nó mà không cần một phiên đăng nhập nào đang mở.
 * M5 đã mất một vòng vì đúng chuyện này: một cổng đọc `Filament::getCurrentPanel()` nên câu trả
 * lời về quyền phụ thuộc vào panel nào tình cờ đang hiện hành.
 */
arch('policy không đọc phiên đăng nhập hiện hành')
    ->expect('App\Policies')
    ->not->toUse(['Illuminate\Support\Facades\Auth', 'auth']);

// ---------------------------------------------------------------------------------------------
// Không để lại dấu vết gỡ lỗi
// ---------------------------------------------------------------------------------------------

arch('không còn hàm gỡ lỗi nào trong mã nguồn')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

/**
 * **`toBeUsed()` chỉ nhìn thấy những gì Composer nạp được**, tức các lớp PHP trong `app/`. Một
 * `dump()` trong một view Blade, một tệp route hay một tệp cấu hình thì vô hình với luật trên —
 * và đó chính là những chỗ một lần gỡ lỗi vội hay bị bỏ quên nhất, vì chúng không có test đơn vị
 * nào chạy qua.
 *
 * Nên luật được NỚI RỘNG chứ không được chú thích: test này quét thẳng các tệp bằng văn bản.
 * Nói rõ nó không bắt được gì, để nó không bị tin quá mức: một `dump()` gọi gián tiếp
 * (`$helper()`, `call_user_func('dd', …)`) thì không có dạng văn bản nào để nhận ra, và luật
 * `toBeUsed()` ở trên cũng không thấy. Hai lớp cộng lại vẫn không phải một lời bảo đảm; chúng
 * bắt đúng một thứ: dấu vết gỡ lỗi viết thẳng, thứ đã lọt ra production ở nhiều dự án hơn mọi
 * kỹ thuật tinh vi.
 *
 * Chú thích và docblock bị gỡ bỏ trước khi so, vì chính tệp này nhắc tên các hàm ấy trong văn
 * xuôi; với Blade thì gỡ `{{-- … --}}`.
 */
it('không còn hàm gỡ lỗi nào trong view, route, cấu hình hay seeder', function () {
    $roots = [
        resource_path('views'),
        base_path('routes'),
        config_path(),
        base_path('bootstrap'),
        database_path(),
    ];

    /** @var list<string> $files */
    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }

    // Tiền đề: có tệp để quét. Một danh sách rỗng làm test này xanh mãi mãi mà không đo gì.
    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $isBlade = str_ends_with($file, '.blade.php');

        // Bỏ chú thích Blade trước, rồi bỏ chú thích PHP: cả hai đều nhắc tên các hàm này.
        if ($isBlade) {
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
            $source = (string) preg_replace('/\/\*.*?\*\//s', '', $source);
        } else {
            $kept = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $kept .= is_array($token) ? $token[1] : $token;
            }
            $source = $kept;
        }

        $pattern = $isBlade
            ? '/(?:@(?:dd|dump)\b)|(?<![\w$>-])(?:dd|dump|ray|var_dump|print_r)\s*\(/'
            : '/(?<![\w$>-])(?:dd|dump|ray|var_dump|print_r)\s*\(/';

        if (preg_match($pattern, $source) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offenders)->toBe([], 'dấu vết gỡ lỗi còn sót ở: '.implode(', ', $offenders));
});

// ---------------------------------------------------------------------------------------------
// Enum trạng thái phải có nhãn tiếng Việt
// ---------------------------------------------------------------------------------------------

/**
 * CLAUDE.md: "Enum backed string cho mọi cột trạng thái, có label()." Một enum thiếu label()
 * sẽ hiện ra định danh mã tiếng Anh trên màn hình khách hàng.
 */
it('mọi enum đều là backed string và có nhãn tiếng Việt', function () {
    $missing = [];

    foreach (glob(app_path('Enums/*.php')) as $file) {
        $class = 'App\\Enums\\'.basename($file, '.php');

        if (! enum_exists($class)) {
            continue;
        }

        $reflection = new ReflectionEnum($class);

        if ((string) $reflection->getBackingType() !== 'string') {
            $missing[] = $class.' — không phải backed string';

            continue;
        }

        if (! method_exists($class, 'label')) {
            $missing[] = $class.' — thiếu label()';
        }
    }

    expect($missing)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Ranh giới đã được đặt tên ở M5: không màn hình khách nào được nhắc tới ghi chú nội bộ
// ---------------------------------------------------------------------------------------------

/**
 * Phán quyết M5, ghi trong sổ tiến độ: `internal_note` chỉ được chặn ở tầng serialize, nên
 * `$log->internal_note` trong một Blade view VẪN trả về giá trị thật. Luật thay thế là tuyệt
 * đối và dễ kiểm: **không màn hình nào của cổng khách được nhắc tới nó, bằng bất cứ cách nào.**
 *
 * Test này quét chữ, không quét kiểu — đúng thứ cần ở đây, vì cái hở nằm ở tầng chữ.
 *
 * NHƯNG phải bỏ chú thích ra trước khi quét, và đó không phải một chi tiết kỹ thuật: bản đầu
 * của test này đỏ lên vì chính những docblock đang GIẢI THÍCH luật — mười tệp bị tố, không tệp
 * nào vi phạm. Một test coi lời giải thích về luật là hành vi phá luật thì sẽ bị người ta tắt đi.
 */
it('không tệp nào của cổng khách nhắc tới ghi chú nội bộ', function () {
    /** Bỏ chú thích PHP bằng token_get_all, bỏ chú thích Blade bằng biểu thức của chính nó. */
    $stripComments = function (string $path, string $contents): string {
        if (str_ends_with($path, '.blade.php')) {
            return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);
        }

        $kept = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    };

    $roots = [
        app_path('Filament/Portal'),
        resource_path('views/filament/portal'),
    ];

    $offenders = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $contents = $stripComments($file->getPathname(), file_get_contents($file->getPathname()));

            foreach (['internal_note', 'description_internal'] as $forbidden) {
                if (str_contains($contents, $forbidden)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' — '.$forbidden;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 11 (R2) — thư luôn qua hàng đợi, không bao giờ nằm trong transaction
// ---------------------------------------------------------------------------------------------

/**
 * `deadlines/F1`, `notify/notify-2`, `e2e/F3`: trước Task 11, `CheckDeadlines` gọi
 * `Mail::to()->send()` ngay bên trong `DB::transaction()` của từng mốc — một transport hỏng ném
 * lỗi xuyên transaction, rollback xoá cả dòng `outbound_messages` vừa ghi. Luật thay thế: KHÔNG
 * Action nào trong `app/Actions` được gọi `Mail::` bên trong một `DB::transaction()`. Việc gửi
 * thư luôn được giao cho một job/listener hàng đợi, dispatch SAU khi transaction đã commit.
 *
 * Quét bằng token (`token_get_all`), bỏ comment trước khi so — cùng lý do đã ghi ở luật "không
 * còn hàm gỡ lỗi" phía trên: rất nhiều docblock của chính `CheckDeadlines` nhắc tới
 * `Mail::to()->send()` trong VĂN XUÔI để giải thích lịch sử, và một luật đọc chữ thô sẽ tự tố
 * chính lời giải thích của nó.
 *
 * # Vòng sửa 1 (Minor): mở rộng sang thông báo trong ứng dụng
 *
 * Cùng một rủi ro với `Mail::`: `Notification::send()`/`Notification::route()` (facade
 * `Illuminate\Support\Facades\Notification`) và `$model->notify()` (trait `Notifiable`) đều có
 * thể chạy một kênh MẠNG THẬT (kênh `mail`, `broadcast`, `slack`, ...) tuỳ theo lớp
 * `Illuminate\Notifications\Notification` được truyền vào — cùng hình dạng rủi ro đã buộc `Mail::`
 * ra khỏi transaction. Luật quét thêm HAI mẫu này, CHỈ khi chúng thật sự là lời GỌI (`::send`/
 * `::route`/`->notify(`), không phải mọi chữ "Notification" xuất hiện.
 *
 * **CỐ Ý không chặn `Notification::make()`.** Đó là `Filament\Notifications\Notification` (đã
 * dùng ở `SyncClientPartyIdentities.php` qua `->sendToDatabase()`) — một câu GHI CSDL đơn thuần
 * vào bảng `notifications` của chính ứng dụng, không mở kết nối mạng nào, nên không mang cùng
 * rủi ro với `Mail::`/`Notification::send()`. Phân biệt bằng TÊN PHƯƠNG THỨC đứng sau `::`, không
 * phải bằng tên lớp — token hoá không biết `Notification::make()` ở một tệp là lớp nào trong hai
 * lớp cùng tên "Notification" đó, và cũng không cần biết, miễn phương thức được gọi đúng là an toàn.
 */
it('không có Mail::/Notification::send/route/->notify( nào chạy bên trong DB::transaction ở app/Actions', function () {
    $root = app_path('Actions');
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file->getPathname())),
            fn ($token) => ! (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)),
        ));

        $text = fn ($token): string => is_array($token) ? $token[1] : $token;
        $isWhitespace = fn ($token): bool => is_array($token) && $token[0] === T_WHITESPACE;

        /** Token tiếp theo, bỏ qua khoảng trắng — dùng để nhận `::send`/`::route`/`->notify(`. */
        $nextNonWs = function (array $tokens, int $i) use ($isWhitespace) {
            $j = $i + 1;

            while (isset($tokens[$j]) && $isWhitespace($tokens[$j])) {
                $j++;
            }

            return $tokens[$j] ?? null;
        };

        /** Token đứng trước, bỏ qua khoảng trắng — dùng để nhận `->` ngay trước `notify`. */
        $prevNonWs = function (array $tokens, int $i) use ($isWhitespace) {
            $j = $i - 1;

            while ($j >= 0 && $isWhitespace($tokens[$j])) {
                $j--;
            }

            return $tokens[$j] ?? null;
        };

        // `$armed`: đã thấy "DB" "::" "transaction", đang đợi đúng dấu "(" mở đầu lời gọi — TÁCH
        // RIÊNG khỏi việc đếm độ sâu ngoặc, để dấu "(" đó chỉ được đếm ĐÚNG MỘT LẦN (bởi nhánh
        // đếm chung bên dưới). Gộp hai việc vào một nhánh từng làm ngoặc đó bị đếm hai lần — một
        // lần gán tay `$depth = 1`, một lần nữa khi vòng lặp tự nhiên đi tới đúng token đó — nên
        // độ sâu không bao giờ trở lại 0 ở đúng ngoặc đóng, và luật này không bắt được gì cả.
        $armed = false;
        $depth = 0; // > 0: đang ở trong dấu ngoặc của một lời gọi DB::transaction(...).
        $sawOffense = false;

        foreach ($tokens as $i => $token) {
            if ($depth === 0 && ! $armed) {
                if (is_array($token) && $token[0] === T_STRING && $token[1] === 'transaction'
                    && $text($tokens[$i - 1] ?? null) === '::'
                    && $text($tokens[$i - 2] ?? null) === 'DB'
                ) {
                    $armed = true;
                }

                continue;
            }

            if ($armed && $depth === 0) {
                if ($token === '(') {
                    $depth = 1;
                    $sawOffense = false;
                    $armed = false;
                } elseif (! (is_array($token) && $token[0] === T_WHITESPACE)) {
                    // Không có gì khác hơn khoảng trắng đứng giữa "transaction" và "(" trong PHP
                    // hợp lệ — nhánh này chỉ để không kẹt mãi ở trạng thái "armed" nếu có.
                    $armed = false;
                }

                continue;
            }

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;

                if ($depth === 0 && $sawOffense) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            } elseif (is_array($token) && $token[0] === T_STRING) {
                $name = $token[1];

                if ($name === 'Mail') {
                    $sawOffense = true;
                } elseif ($name === 'Notification' && $text($nextNonWs($tokens, $i)) === '::') {
                    // Tên phương thức ngay sau '::' — CHỈ send()/route() bị chặn. make() (đọc
                    // docblock ở trên) cố ý không chặn.
                    $j = $i + 1;

                    while (isset($tokens[$j]) && ($isWhitespace($tokens[$j]) || $text($tokens[$j]) === '::')) {
                        $j++;
                    }

                    $method = $tokens[$j] ?? null;

                    if (is_array($method) && $method[0] === T_STRING && in_array($method[1], ['send', 'route'], true)) {
                        $sawOffense = true;
                    }
                } elseif ($name === 'notify'
                    && $text($prevNonWs($tokens, $i)) === '->'
                    && $text($nextNonWs($tokens, $i)) === '('
                ) {
                    $sawOffense = true;
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Mail::/Notification::send/route/->notify( chạy bên trong DB::transaction ở: '.implode(', ', $offenders),
    );
});

// ---------------------------------------------------------------------------------------------
// Hai sự thật về CSS mà dự án đã trả giá để biết
// ---------------------------------------------------------------------------------------------

/**
 * M4 Task 7 phát hiện: dự án KHÔNG có bước build CSS, Filament phục vụ một tệp theme biên dịch
 * sẵn, nên một class Tailwind viết tay trong Blade **render ra không có gì**. Hai tính năng đã
 * xuất xưởng ở trạng thái vô hình trước khi ai đó nhận ra (nền xám của ghi chú nội bộ từ M3, và
 * nền đỏ nhạt của tài liệu nhóm D từ M4).
 *
 * Luật: view của cổng khách dùng inline style trên biến CSS của Filament. Test này chặn đúng
 * những tiền tố Tailwind tiện tay nhất, không chặn toàn bộ chữ `class=` — vì class của chính
 * Filament thì dùng được bình thường.
 */
it('view của cổng khách không dùng class Tailwind viết tay', function () {
    $root = resource_path('views/filament/portal');

    if (! is_dir($root)) {
        expect(true)->toBeTrue();

        return;
    }

    // Những tiền tố hay bị dùng theo phản xạ. Danh sách này cố ý ngắn và cụ thể: mục đích là
    // bắt thói quen, không phải dựng một bộ phân tích CSS.
    $tailwindish = '/\bclass="[^"]*\b(?:flex|grid|gap-\d|p[xytblr]?-\d|m[xytblr]?-\d|text-(?:xs|sm|base|lg|xl|\dxl)|bg-(?:gray|red|green|blue|amber|zinc|slate)-\d|rounded-(?:sm|md|lg|xl|full)|w-\d|h-\d|border-\d)\b/';

    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (preg_match($tailwindish, file_get_contents($file->getPathname()), $m)) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' — '.$m[0];
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// M11 Task 14 — máy chủ MCP: luật cấu trúc
// ---------------------------------------------------------------------------------------------

/**
 * Review Focus 4 của kế hoạch M11 ("ngữ cảnh ambient sai guard"): trong một request `/mcp`,
 * `auth('web')` và `auth('client')` đều rỗng (hoặc mang một người KHÁC, khi cùng tiến trình còn một
 * phiên cổng khách), còn `auth()` mặc định là guard nào thì tuỳ middleware nào chạy trước. Một chỗ
 * đọc chúng cho ra kết quả SAI mà không ném lỗi (causer `null`, scope cổng khách bật nhầm). Luật: tầng
 * giao thức `App\Mcp` không đọc phiên đăng nhập bằng facade hay hàm trợ giúp — người dùng đọc từ
 * `$request->user('mcp')` của request MCP đang chạy, tường minh — và không viết truy vấn thô (`DB`):
 * mọi truy vấn nằm ở Action (`App\Actions\Mcp`).
 */
arch('tầng giao thức MCP không đọc phiên đăng nhập ambient, không truy vấn thô')
    ->expect('App\Mcp')
    ->not->toUse(['Illuminate\Support\Facades\Auth', 'auth', 'Illuminate\Support\Facades\DB']);

/**
 * Action không biết giao thức (kế hoạch M11, "Ba tầng mới, mỏng"): `App\Actions\Mcp` trả DTO, chỉ tool
 * ở `App\Mcp\Tools` biết `Request`/`Response` của laravel/mcp. Một Action phụ thuộc gói giao thức thì
 * màn hình web (nút "Dùng nháp", trang "Kết nối AI") không gọi lại được nó mà không kéo theo gói đó.
 */
arch('Action của MCP không phụ thuộc gói giao thức laravel/mcp')
    ->expect('App\Actions\Mcp')
    ->not->toUse('Laravel\Mcp');

/**
 * Tệp PHP dưới một thư mục (đệ quy), theo thứ tự tên.
 *
 * @return list<string>
 */
function architecturePhpFiles(string $root): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** Mã PHP đã bỏ chú thích và docblock (cùng lý do với luật "không còn hàm gỡ lỗi" ở trên). */
function architectureCodeOnly(string $source): string
{
    $kept = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $kept .= is_array($token) ? $token[1] : $token;
    }

    return $kept;
}

/** `app/Mcp/Tools/Foo.php` → `App\Mcp\Tools\Foo` (PSR-4 của `composer.json`). */
function architectureClassOf(string $path): string
{
    $relative = substr($path, strlen(app_path()) + 1, -strlen('.php'));

    return 'App\\'.str_replace('/', '\\', str_replace('\\', '/', $relative));
}

/**
 * Lời GHI Eloquent viết thẳng trong một đoạn mã: `->save(`, `::create(`, `->update(`, `->delete(` (kế
 * hoạch M11 Task 14), cộng những anh em cùng họ (`saveQuietly`, `forceDelete`, `insert`, `upsert`,
 * `increment`, `firstOrCreate`, `updateOrCreate`…).
 *
 * @return list<string>
 */
function architectureWriteCalls(string $code): array
{
    preg_match_all(
        '/->\s*(?:save|saveQuietly|update|updateQuietly|delete|deleteQuietly|forceDelete|insert|insertOrIgnore|upsert|increment|decrement|restore)\s*\(|::\s*(?:create|forceCreate|createQuietly|insert|upsert|updateOrCreate|firstOrCreate|updateOrInsert|destroy)\s*\(/',
        $code,
        $matches,
    );

    return array_values(array_unique(array_map(fn (string $match): string => (string) preg_replace('/\s+/', '', $match), $matches[0])));
}

/**
 * Kế hoạch M11 ("Ràng buộc toàn cục"): "Tool MCP gọi Action, không bao giờ ghi Eloquent trực tiếp."
 * `toUse()` không thấy một lời gọi phương thức trên model, nên quét văn bản (đã bỏ chú thích), như luật
 * gỡ lỗi. Quét cả `App\Mcp` (bước gọi tool, server), không chỉ `App\Mcp\Tools`: cả tầng giao thức không
 * có lý do nào để ghi.
 */
it('tầng giao thức MCP không ghi Eloquent trực tiếp (save/create/update/delete)', function () {
    $files = architecturePhpFiles(app_path('Mcp'));

    // Tiền đề: có tệp để quét, gồm đủ các tool của CrmServer.
    expect(count(glob(app_path('Mcp/Tools/*Tool.php'))))->toBeGreaterThanOrEqual(15)
        ->and(count($files))->toBeGreaterThan(15);

    $offenders = [];

    foreach ($files as $file) {
        foreach (architectureWriteCalls(architectureCodeOnly((string) file_get_contents($file))) as $call) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' — '.$call;
        }
    }

    expect($offenders)->toBe([], 'App\Mcp ghi Eloquent trực tiếp ở: '.implode(', ', $offenders));
});

it('máy dò lời ghi bắt đúng các dạng gọi, và bỏ qua chú thích (cặp dương/âm)', function () {
    $code = architectureCodeOnly(implode("\n", [
        '<?php',
        '// $matter->save(); trong chú thích không tính',
        '/** Deadline::create([...]) trong docblock cũng không */',
        '$a->save();',
        'Deadline::create([]);',
        '$b -> update ([1]);',
        '$c->delete();',
        '$d->saveQuietly();',
        '$schema->string()->required();',
        '$e->updated_at;',
    ]));

    expect(architectureWriteCalls($code))->toBe(['->save(', '::create(', '->update(', '->delete(', '->saveQuietly(']);
});

/**
 * DC:120, DC:226 (singleton giữ trạng thái qua request — CVE-2026-48529 của GitHub): trên PHP-FPM mỗi
 * request là một tiến trình mới, nhưng trong một tiến trình sống lâu (worker hàng đợi, bộ test, Octane
 * nếu ai đó bật sau này) một thuộc tính `static` mang dữ liệu của người A sang request của người B.
 * Luật: không lớp nào trong `App\Mcp` và `App\Support\Mcp` KHAI thuộc tính `static` (đọc bằng
 * reflection), và không hàm nào khai biến `static $x` (quét token). Hằng (`const`) và phương thức
 * `static` không giữ trạng thái nên được phép. Trạng thái của một request nằm trong đối tượng gắn
 * container của request đó (`ToolCallContext`) hoặc thuộc tính của request.
 */
it('App\Mcp và App\Support\Mcp không khai thuộc tính static hay biến static giữ trạng thái', function () {
    $files = [...architecturePhpFiles(app_path('Mcp')), ...architecturePhpFiles(app_path('Support/Mcp'))];

    // Tiền đề: hai thư mục có tệp, và mọi tệp đọc ra được một lớp/trait (reflection không bị bỏ qua).
    expect(count($files))->toBeGreaterThan(30);

    $offenders = [];
    $unreadable = [];

    foreach ($files as $file) {
        $class = architectureClassOf($file);

        if (class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class)) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class) {
                    $offenders[] = $class.'::$'.$property->getName();
                }
            }
        } else {
            $unreadable[] = $class;
        }

        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            fn ($token): bool => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
        ));

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_STATIC && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_VARIABLE) {
                $offenders[] = $class.' — static '.$tokens[$i + 1][1];
            }
        }
    }

    expect($unreadable)->toBe([])
        ->and(array_values(array_unique($offenders)))->toBe([], 'trạng thái static trong mã MCP: '.implode(', ', $offenders));
});

/**
 * Action mà `handle()` của một tool nhận qua container (`App\Actions\…`).
 *
 * @param  class-string  $tool
 * @return list<class-string>
 */
function architectureToolActions(string $tool): array
{
    if (! method_exists($tool, 'handle')) {
        return [];
    }

    $actions = [];

    foreach ((new ReflectionMethod($tool, 'handle'))->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && str_starts_with($type->getName(), 'App\\Actions\\')) {
            $actions[] = $type->getName();
        }
    }

    return $actions;
}

/**
 * Mã (bỏ chú thích) của một lớp có đi qua tập R3 (`McpMatterScope`) hoặc hỏi quyền đích danh
 * (`Gate::forUser(`) không.
 *
 * @param  class-string  $class
 */
function architectureAsksPolicy(string $class): bool
{
    $code = architectureCodeOnly((string) file_get_contents((string) (new ReflectionClass($class))->getFileName()));

    return str_contains($code, 'McpMatterScope') || preg_match('/Gate\s*::\s*forUser\s*\(/', $code) === 1;
}

/**
 * Lớp tool KHÔNG đi qua `McpMatterScope` hay `Gate::forUser`. Một tool đạt khi tự nó đi qua, hoặc khi
 * nó nhận ít nhất một Action ở `handle()` và MỌI Action đó đều đi qua. Tool không nhận Action nào và tự
 * nó cũng không hỏi thì bị liệt kê; tool có một Action im lặng thì bị liệt kê kèm tên Action đó.
 *
 * @param  list<class-string>  $tools
 * @return list<string>
 */
function architectureToolsWithoutPolicy(array $tools): array
{
    $missing = [];

    foreach ($tools as $tool) {
        if (architectureAsksPolicy($tool)) {
            continue;
        }

        $actions = architectureToolActions($tool);
        $silent = array_values(array_filter($actions, fn (string $action): bool => ! architectureAsksPolicy($action)));

        if ($actions === []) {
            $missing[] = class_basename($tool).' (không nhận Action nào)';
        } elseif ($silent !== []) {
            $missing[] = class_basename($tool).' → '.implode(', ', array_map(class_basename(...), $silent));
        }
    }

    return $missing;
}

/**
 * Kế hoạch M11 Task 14 ("Mọi tool đều kiểm policy", luật văn bản): R3 — mọi tool kế thừa policy của web
 * qua `McpMatterScope` (định nghĩa duy nhất của tập vụ MCP thấy được) hoặc hỏi `Gate::forUser($actor)`
 * đúng ability của màn hình tương ứng. Tool chỉ đọc tham số và gọi Action, nên luật đọc Action mà
 * `handle()` nhận. Test LIỆT KÊ lớp không làm vậy trong thông điệp lỗi, thay vì chỉ nói "có lớp sai".
 * Đây là lưới văn bản; bằng chứng hành vi là lượt "người không thấy vụ → not_found" của
 * `tests/Feature/Mcp/SensitiveDataSweepTest.php`.
 */
it('mọi tool của CrmServer đi qua McpMatterScope hoặc Gate::forUser (liệt kê lớp không làm vậy)', function () {
    $tools = (new ReflectionClass(CrmServer::class))->getProperty('tools')->getDefaultValue();

    // Tiền đề: luật chạy trên đúng mọi tool có trong thư mục, không một danh sách chép tay.
    expect($tools)->toHaveCount(count(glob(app_path('Mcp/Tools/*Tool.php'))));

    expect(architectureToolsWithoutPolicy($tools))->toBe([]);
});

it('luật policy của tool liệt kê đúng lớp không hỏi gì, và để yên lớp có hỏi (cặp dương/âm)', function () {
    // Âm: một lớp không hỏi policy, không nhận Action nào — bị liệt kê.
    expect(architectureToolsWithoutPolicy([PhoneMask::class]))
        ->toBe(['PhoneMask (không nhận Action nào)']);

    // Dương: tool nhận một Action có đi qua McpMatterScope — không bị liệt kê.
    expect(architectureToolsWithoutPolicy([GetMatterTool::class]))->toBe([]);
});
