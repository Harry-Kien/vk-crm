<?php

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
