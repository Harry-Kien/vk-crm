<?php

use App\Filament\Portal\Pages\SubmitDocument;
use App\Http\Middleware\ThrottleUploadedFiles;
use App\Models\ClientUser;
use App\Models\Document;
use App\Support\UploadThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `config/livewire.php` — tệp cấu hình mà M5 Task 5 buộc phải publish, và hai lý do publish nó.
 *
 * 1. **Trần dung lượng.** `FileUploadConfiguration::rules()` trả `['required','file','max:12288']`
 *    khi khoá `temporary_file_upload.rules` trống — 12 MB, trong khi SPEC §6.6 bước 4 và §8.4 nói
 *    20 MB và màn hình nộp giấy tờ in con số 20 ra cho khách đọc.
 * 2. **Bộ đếm request.** `FileUploadConfiguration::middleware()` trả `'throttle:60,1'` khi khoá
 *    `temporary_file_upload.middleware` trống — 3600 tệp/giờ, gấp 180 lần mức 20 tệp/giờ của
 *    SPEC §10.3, trên một endpoint mà một URL đã ký còn hạn dùng lại được.
 *
 * # Vì sao tệp này phải là BẢN ĐẦY ĐỦ của nhà cung cấp, và vì sao có test canh điều đó
 *
 * `LivewireServiceProvider` gộp cấu hình bằng `mergeConfigFrom()`, thứ gộp **NÔNG**: nó chạy
 * `array_merge($vendor, $app)` đúng một tầng. Một tệp chỉ viết `['temporary_file_upload' => [...]]`
 * vì vậy **THAY THẾ TOÀN BỘ** mảng con ấy — `preview_mimes`, `max_upload_time`, `cleanup`, `disk`,
 * `directory` biến mất lặng lẽ, và `preview_mimes` rỗng nghĩa là mọi ảnh xem trước của mọi ô chọn
 * tệp trong cả hai panel ngừng hiện. Không có lời cảnh báo nào; chỉ có một tính năng thôi chạy.
 *
 * Nên tệp được sinh bằng `artisan vendor:publish --tag=livewire:config` và chỉ sửa đúng hai dòng.
 * Test dưới đây là thứ giữ lời hứa đó: nó đối chiếu **từng khoá** với chính tệp của nhà cung cấp
 * còn nằm trong `vendor/`, và chỉ tha đúng hai khoá mà task này cố ý đổi.
 */
$vendorConfig = fn (): array => require base_path('vendor/livewire/livewire/config/livewire.php');

it('keeps every top level key of the vendor file, because the merge is shallow', function () use ($vendorConfig) {
    $vendor = $vendorConfig();
    $published = require base_path('config/livewire.php');

    expect(array_keys($published))->toBe(array_keys($vendor));
});

/**
 * Mọi khoá NGOÀI hai khoá đã đổi phải còn nguyên giá trị mặc định — kể cả những khoá lồng bên
 * trong `temporary_file_upload`, đúng những khoá mà một tệp viết tay sẽ đánh rơi.
 */
it('changes exactly two keys and leaves every other default alone', function () use ($vendorConfig) {
    $vendor = $vendorConfig();
    $published = require base_path('config/livewire.php');

    $changed = ['rules', 'middleware'];

    foreach ($vendor as $key => $value) {
        if ($key !== 'temporary_file_upload') {
            expect($published[$key])->toEqual($value, 'livewire.'.$key);

            continue;
        }

        expect(array_keys($published[$key]))->toBe(array_keys($value));

        foreach ($value as $subKey => $subValue) {
            if (in_array($subKey, $changed, true)) {
                expect($published[$key][$subKey])->not->toEqual($subValue, 'livewire.temporary_file_upload.'.$subKey);

                continue;
            }

            expect($published[$key][$subKey])->toEqual($subValue, 'livewire.temporary_file_upload.'.$subKey);
        }
    }

    // Vế dương của "chỉ hai khoá": hai khoá ấy THẬT SỰ đổi, nếu không vòng lặp trên xanh vì
    // không có gì đổi cả.
    expect($published['temporary_file_upload']['rules'])->not->toBeNull()
        ->and($published['temporary_file_upload']['middleware'])->not->toBeNull();
});

/**
 * **Một con số, ba chỗ đọc nó.** Dòng hướng dẫn dưới ô chọn tệp, cổng của
 * {@see SubmitDocument::_startUpload()} và luật của endpoint tải lên phải nói cùng một con số;
 * lệch nhau là đúng cái khe 13–20 MB mà rà soát đã đo, nơi khách đọc một câu của framework ngay
 * dưới một dòng hứa hẹn một con số khác.
 */
it('caps the upload endpoint at exactly the number the screen promises', function () {
    $rules = config('livewire.temporary_file_upload.rules');

    expect($rules)->toBeArray()
        ->and($rules)->toContain('max:'.(config('vkcrm.upload_max_mb') * 1024))
        ->and($rules)->toContain('required')
        ->and($rules)->toContain('file');

    // Cùng con số ấy là con số trang in ra cho khách.
    expect(__('portal_submit.steps.file.help', ['max' => config('vkcrm.upload_max_mb')]))
        ->toContain('tối đa 20 MB');
});

/**
 * SPEC §10.3: 20 tệp / giờ / **tài khoản** — M8 Task 3 đổi CÁCH đếm.
 *
 * Bản trước cắm bộ đếm có tên `throttle:livewire-upload` của framework, thứ tăng MỘT đơn vị cho
 * mỗi REQUEST. Sau M6.5 R10 một request mang được nhiều tệp, nên trần "20 TỆP" thành 20 REQUEST.
 * Nay chỗ cắm là {@see ThrottleUploadedFiles}, đếm TỆP; hành vi thật (một request nhiều tệp, hai
 * tài khoản cùng địa chỉ, hai trần khách/nhân sự) được đo qua HTTP ở
 * `tests/Feature/Http/UploadFileCountThrottleTest.php` và `tests/Feature/Portal/SubmitDocumentTest.php`.
 * Test này chỉ ghim chỗ cắm và hai hằng số, và khoá theo tài khoản (không theo địa chỉ) ở mức
 * đơn vị.
 */
it('plugs the file counting middleware into the upload endpoint', function () {
    expect(config('livewire.temporary_file_upload.middleware'))->toBe(ThrottleUploadedFiles::class);

    // Không còn một bộ đếm có tên nào của framework đứng cạnh nó: hai bộ đếm cho cùng một luật là
    // cách chắc chắn để chúng lệch nhau.
    expect(RateLimiter::limiter(UploadThrottle::NAME))->toBeNull();

    expect(UploadThrottle::FILES_PER_HOUR)->toBe(SubmitDocument::FILES_PER_HOUR)
        ->and(UploadThrottle::STAFF_FILES_PER_HOUR)->toBeGreaterThan(UploadThrottle::FILES_PER_HOUR);
});

it('keys the upload counter per account and only falls back to the address for nobody', function () {
    $first = ClientUser::factory()->create();
    $second = ClientUser::factory()->create(['client_id' => $first->client_id]);

    $request = Request::create('/livewire/upload-file', 'POST');

    auth('client')->setUser($first);
    $one = UploadThrottle::keyFor($request);

    auth('client')->setUser($second);
    $two = UploadThrottle::keyFor($request);

    expect($one)->not->toBe($two)
        // Hai người của CÙNG một khách hàng trên cùng một đường truyền: hai rổ đếm khác nhau,
        // và cả hai khoá nói về tài khoản chứ không về địa chỉ.
        ->and($one)->toBe(Document::recipientToken($first))
        ->and($two)->toBe(Document::recipientToken($second));

    // Vế còn lại của luật: không có ai đăng nhập thì mới rơi về địa chỉ.
    auth('client')->logout();
    expect(UploadThrottle::keyFor($request))->toStartWith('ip:');
});
