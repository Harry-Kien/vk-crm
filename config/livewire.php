<?php

use App\Http\Middleware\ThrottleUploadedFiles;

/*
|-------------------------------------------------------------------------------------------
| BẢN PUBLISH CỦA NHÀ CUNG CẤP — ĐÃ SỬA ĐÚNG HAI DÒNG
|-------------------------------------------------------------------------------------------
|
| Sinh bằng `bin/dev artisan vendor:publish --tag=livewire:config`, rồi đổi đúng hai khoá:
| `temporary_file_upload.rules` và `temporary_file_upload.middleware`. Mọi khoá khác giữ
| nguyên mặc định, và `tests/Feature/Config/LivewireUploadConfigTest.php` đối chiếu từng khoá
| một với chính tệp còn nằm trong `vendor/` để chuyện đó đúng mãi.
|
| **Vì sao phải là BẢN ĐẦY ĐỦ, không phải một tệp chỉ có hai dòng cần đổi.**
| `LivewireServiceProvider` gộp cấu hình bằng `mergeConfigFrom()`, thứ chạy `array_merge()`
| đúng MỘT tầng. Một tệp chỉ viết `['temporary_file_upload' => ['rules' => ...]]` vì vậy THAY
| THẾ TOÀN BỘ mảng con ấy: `preview_mimes`, `max_upload_time`, `cleanup`, `disk`, `directory`
| biến mất lặng lẽ, và `preview_mimes` rỗng nghĩa là ảnh xem trước ngừng hiện ở mọi ô chọn tệp
| của cả hai panel. Không có cảnh báo nào; chỉ có một tính năng thôi chạy.
|
| **Ai sửa tệp này đọc `App\Filament\Portal\Pages\SubmitDocument` trước.** Hai dòng dưới đây
| là hai trong ba chỗ cùng nói một con số, và chỗ thứ ba là màn hình khách đọc.
*/

/*
 * SPEC §6.6 bước 4 và §8.4: trần 20 MB mỗi tệp, cấu hình qua `.env`.
 *
 * Đọc thẳng `UPLOAD_MAX_MB` chứ không qua `config('vkcrm.upload_max_mb')`: `LoadConfiguration`
 * nạp các tệp cấu hình theo thứ tự chữ cái, nên lúc tệp này được đánh giá thì `vkcrm` chưa tồn
 * tại. Cùng biến môi trường, cùng bước lùi khi giá trị thiếu hoặc không phải số dương — cùng
 * lý lẽ đã ghi ở `SubmitDocument::maxMegabytes()`: `(int) null === 0` thì fail-closed nhưng vô
 * nghĩa, vì mọi tệp đều "vượt quá 0 MB" và không khách nào hiểu nổi câu đó.
 */
$uploadMaxMb = (int) env('UPLOAD_MAX_MB', 20);
$uploadMaxMb = $uploadMaxMb > 0 ? $uploadMaxMb : 20;

return [

    /*
    |---------------------------------------------------------------------------
    | Component Locations
    |---------------------------------------------------------------------------
    |
    | This value sets the root directories that'll be used to resolve view-based
    | components like single and multi-file components. The make command will
    | use the first directory in this array to add new component files to.
    |
    */

    'component_locations' => [
        resource_path('views/components'),
        resource_path('views/livewire'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Component Namespaces
    |---------------------------------------------------------------------------
    |
    | This value sets default namespaces that will be used to resolve view-based
    | components like single-file and multi-file components. These folders'll
    | also be referenced when creating new components via the make command.
    |
    */

    'component_namespaces' => [
        'layouts' => resource_path('views/layouts'),
        'pages' => resource_path('views/pages'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Page Layout
    |---------------------------------------------------------------------------
    | The view that will be used as the layout when rendering a single component as
    | an entire page via `Route::livewire('/post/create', 'pages::create-post')`.
    | In this case, the content of pages::create-post will render into $slot.
    |
    */

    'component_layout' => 'layouts::app',

    /*
    |---------------------------------------------------------------------------
    | Lazy Loading Placeholder
    |---------------------------------------------------------------------------
    | Livewire allows you to lazy load components that would otherwise slow down
    | the initial page load. Every component can have a custom placeholder or
    | you can define the default placeholder view for all components below.
    |
    */

    'component_placeholder' => null, // Example: 'placeholders::skeleton'

    /*
    |---------------------------------------------------------------------------
    | Make Command
    |---------------------------------------------------------------------------
    | This value determines the default configuration for the artisan make command
    | You can configure the component type (sfc, mfc, class) and whether to use
    | the high-voltage (⚡) emoji as a prefix in the sfc|mfc component names.
    |
    */

    'make_command' => [
        'type' => 'sfc', // Options: 'sfc', 'mfc', 'class'
        'emoji' => true, // Options: true, false
        'with' => [
            'js' => false,
            'css' => false,
            'test' => false,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Class Namespace
    |---------------------------------------------------------------------------
    |
    | This value sets the root class namespace for Livewire component classes in
    | your application. This value will change where component auto-discovery
    | finds components. It's also referenced by the file creation commands.
    |
    */

    'class_namespace' => 'App\\Livewire',

    /*
    |---------------------------------------------------------------------------
    | Class Path
    |---------------------------------------------------------------------------
    |
    | This value is used to specify the path where Livewire component class files
    | are created when running creation commands like `artisan make:livewire`.
    | This path is customizable to match your projects directory structure.
    |
    */

    'class_path' => app_path('Livewire'),

    /*
    |---------------------------------------------------------------------------
    | View Path
    |---------------------------------------------------------------------------
    |
    | This value is used to specify where Livewire component Blade templates are
    | stored when running file creation commands like `artisan make:livewire`.
    | It is also used if you choose to omit a component's render() method.
    |
    */

    'view_path' => resource_path('views/livewire'),

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | Livewire handles file uploads by storing uploads in a temporary directory
    | before the file is stored permanently. All file uploads are directed to
    | a global endpoint for temporary storage. You may configure this below:
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'), // Example: 'local', 's3'             | Default: 'default'
        /*
         * SPEC §6.6 bước 4 + §8.4: 20 MB. Mặc định của Livewire là `max:12288` (12 MB) và nó
         * đứng ở ENDPOINT TẢI LÊN của chính Livewire — nơi byte thật sự rơi xuống đĩa, và nơi
         * màn hình nộp giấy tờ không có mặt. Để trống nghĩa là dòng chữ hứa 20 MB dưới ô chọn
         * tệp nói dối cả dải 13–20 MB: một khách chụp sổ đỏ bằng điện thoại đời nay ra khoảng
         * 15 MB, và câu trả lời họ nhận được là một câu của framework có tên thuộc tính và số
         * kilobyte trong đó (đo được: "data.file không được lớn hơn 12288 kilobyte").
         */
        'rules' => ['required', 'file', 'max:'.($uploadMaxMb * 1024)],
        'directory' => null,                                  // Example: 'tmp'                     | Default: 'livewire-tmp'
        /*
         * SPEC §10.3: 20 tệp / giờ / **tài khoản**. Mức và cách khoá đều ở
         * `App\Support\UploadThrottle`; ở đây chỉ có chỗ cắm vào framework, cùng thành ngữ với
         * `throttle:document-download` ở `routes/web.php`.
         *
         * Mặc định của Livewire là `throttle:60,1`, tức 3600 tệp/giờ — gấp 180 lần mức SPEC cho
         * phép. Bộ đếm trong `SubmitDocument::_startUpload()` chặn việc CẤP một URL đã ký; nó
         * không chặn việc DÙNG một URL đã cấp, và một URL còn hạn năm phút gửi lại được bao nhiêu
         * lần tuỳ ý (đo được: 25/25 lần POST trả 200, 50 tệp tạm trên đĩa, `VirusScanner` không
         * được hỏi lần nào). Đây là cửa cuối cùng của dải đó.
         *
         * **ĐÂY LÀ MỘT BỘ ĐẾM CÓ TÊN, và bản trước là `throttle:20,60` trần — khác biệt đó là một
         * lỗi đã đo được trên người thật.** `ThrottleRequests` không nhận tham số về guard: nó hỏi
         * `$request->user()`, tức guard MẶC ĐỊNH (`web`). Cổng khách hàng xác thực trên guard
         * `client`, nên giá trị đó là `null` và nó rơi về ĐỊA CHỈ. Hệ quả đo được qua HTTP thật:
         * tài khoản A gửi 20 tệp rồi bị chặn ở 21 — đúng; tài khoản B, người thứ hai của cùng
         * khách hàng trên cùng đường truyền, bị chặn ngay ở tệp ĐẦU TIÊN với 429 và
         * `Retry-After: 3600`. SPEC §4.3 nêu đích danh hai tài khoản cho một khách hàng — hai vợ
         * chồng — làm trường hợp được thiết kế, và hai vợ chồng thì dùng chung một wifi.
         *
         * **Câu cũ ở đây nói đúng cơ chế rồi kết luận sai**, và lời đính chính được để lại đúng
         * chỗ nó đứng: câu ấy viết rằng "bộ đếm theo tài khoản vẫn phải đứng ở `_startUpload()`,
         * và nó đứng ở đó" — như thể cửa theo tài khoản che được cho cửa theo địa chỉ. Nó không
         * che được, vì cửa theo địa chỉ là cửa CHẶT HƠN và nó phạt nhầm người: vợ hết suất vì
         * chồng đã gửi. Hai cửa của chính trang vẫn không thừa, nhưng vì một lý do khác — chúng
         * chặn TRƯỚC khi một URL đã ký được cấp, tức trước khi một byte nào rời khỏi điện thoại.
         *
         * **Phạm vi, nói cho đủ:** endpoint này dùng CHUNG cho cả hai panel. SPEC §10.3 viết
         * "nộp tài liệu 20 tệp / giờ / tài khoản" — luật nộp tài liệu của KHÁCH (SPEC §6.6, §8.4).
         *
         * **M8 Task 3 đổi hai điều ở đây.** (1) Nó là `App\Http\Middleware\ThrottleUploadedFiles`
         * chứ không còn `throttle:livewire-upload`: bộ đếm có tên của framework tăng MỘT đơn vị
         * cho mỗi REQUEST, trong khi một request `files[]` có thể mang nhiều tệp (M6.5 R10) — nên
         * SPEC "20 TỆP / giờ" bị đếm thành 20 REQUEST / giờ. Middleware này đếm số tệp thật và
         * từ chối cả request nếu vượt trần. (2) Nhân sự (guard `web`) có trần riêng
         * `UploadThrottle::STAFF_FILES_PER_HOUR` = 200 — mức 20 của SPEC áp lên luật sư tải bộ hồ
         * sơ toà 30 trang là chạm giới hạn của khách. Không bỏ trần cho nhân sự: mỗi POST vẫn ghi
         * đĩa. Lý lẽ đầy đủ và "giá nếu sai": docblock `App\Support\UploadThrottle`.
         */
        'middleware' => ThrottleUploadedFiles::class,
        'preview_mimes' => [                                  // Supported file types for temporary pre-signed file URLs...
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5, // Max duration (in minutes) before an upload is invalidated...
        'cleanup' => true, // Should cleanup temporary uploads older than 24 hrs...
    ],

    /*
    |---------------------------------------------------------------------------
    | Render On Redirect
    |---------------------------------------------------------------------------
    |
    | This value determines if Livewire will run a component's `render()` method
    | after a redirect has been triggered using something like `redirect(...)`
    | Setting this to true will render the view once more before redirecting
    |
    */

    'render_on_redirect' => false,

    /*
    |---------------------------------------------------------------------------
    | Eloquent Model Binding
    |---------------------------------------------------------------------------
    |
    | Previous versions of Livewire supported binding directly to eloquent model
    | properties using wire:model by default. However, this behavior has been
    | deemed too "magical" and has therefore been put under a feature flag.
    |
    */

    'legacy_model_binding' => false,

    /*
    |---------------------------------------------------------------------------
    | Auto-inject Frontend Assets
    |---------------------------------------------------------------------------
    |
    | By default, Livewire automatically injects its JavaScript and CSS into the
    | <head> and <body> of pages containing Livewire components. By disabling
    | this behavior, you need to use @livewireStyles and @livewireScripts.
    |
    */

    'inject_assets' => true,

    /*
    |---------------------------------------------------------------------------
    | Navigate (SPA mode)
    |---------------------------------------------------------------------------
    |
    | By adding `wire:navigate` to links in your Livewire application, Livewire
    | will prevent the default link handling and instead request those pages
    | via AJAX, creating an SPA-like effect. Configure this behavior here.
    |
    */

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],

    /*
    |---------------------------------------------------------------------------
    | HTML Morph Markers
    |---------------------------------------------------------------------------
    |
    | Livewire intelligently "morphs" existing HTML into the newly rendered HTML
    | after each update. To make this process more reliable, Livewire injects
    | "markers" into the rendered Blade surrounding @if, @class & @foreach.
    |
    */

    'inject_morph_markers' => true,

    /*
    |---------------------------------------------------------------------------
    | Smart Wire Keys
    |---------------------------------------------------------------------------
    |
    | Livewire uses loops and keys used within loops to generate smart keys that
    | are applied to nested components that don't have them. This makes using
    | nested components more reliable by ensuring that they all have keys.
    |
    */

    'smart_wire_keys' => true,

    /*
    |---------------------------------------------------------------------------
    | Pagination Theme
    |---------------------------------------------------------------------------
    |
    | When enabling Livewire's pagination feature by using the `WithPagination`
    | trait, Livewire will use Tailwind templates to render pagination views
    | on the page. If you want Bootstrap CSS, you can specify: "bootstrap"
    |
    */

    'pagination_theme' => 'tailwind',

    /*
    |---------------------------------------------------------------------------
    | Release Token
    |---------------------------------------------------------------------------
    |
    | This token is stored client-side and sent along with each request to check
    | a users session to see if a new release has invalidated it. If there is
    | a mismatch it will throw an error and prompt for a browser refresh.
    |
    */

    'release_token' => 'a',

    /*
    |---------------------------------------------------------------------------
    | CSP Safe
    |---------------------------------------------------------------------------
    |
    | This config is used to determine if Livewire will use the CSP-safe version
    | of Alpine in its bundle. This is useful for applications that are using
    | strict Content Security Policy (CSP) to protect against XSS attacks.
    |
    */

    'csp_safe' => false,

    /*
    |---------------------------------------------------------------------------
    | Payload Guards
    |---------------------------------------------------------------------------
    |
    | These settings protect against malicious or oversized payloads that could
    | cause denial of service. The default values should feel reasonable for
    | most web applications. Each can be set to null to disable the limit.
    |
    */

    'payload' => [
        'max_size' => 1024 * 1024,   // 1MB - maximum request payload size in bytes
        'max_nesting_depth' => 10,   // Maximum depth of dot-notation property paths
        'max_calls' => 50,           // Maximum method calls per request
        'max_components' => 200,     // Maximum components per batch request
    ],
];
