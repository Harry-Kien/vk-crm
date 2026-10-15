<?php

use App\Models\Setting;
use App\Support\Billing\ClientPaymentSettings;
use Database\Seeders\ClientPaymentSettingsSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Làn nt Task 1 — dữ liệu mẫu: thông tin chuyển khoản rõ là giả, công tắc "Nhắc lịch thanh toán cho
| khách" vẫn TẮT sau khi seed (mặc định chủ văn phòng chọn), kể cả qua `DatabaseSeeder` đầy đủ.
*/

it('seeds fake bank details and leaves the client payment switch OFF', function () {
    $this->seed(ClientPaymentSettingsSeeder::class);

    $settings = ClientPaymentSettings::current();

    expect(ClientPaymentSettings::enabled())->toBeFalse()
        ->and(Setting::query()->where('key', ClientPaymentSettings::ENABLED)->value('value'))->toBe(ClientPaymentSettings::OFF)
        ->and($settings->transferAccount())->not->toBeNull()
        ->and($settings->transferAccount()['account_holder'])->toContain('THU NGHIEM')
        ->and($settings->daysBeforeDue())->toBe(3);
});

it('keeps the switch OFF after the full development seed, with the demo bank details in place', function () {
    Storage::fake('private');

    $this->seed(DatabaseSeeder::class);

    expect(ClientPaymentSettings::enabled())->toBeFalse()
        ->and(ClientPaymentSettings::current()->stored('account_number'))->toBe(ClientPaymentSettingsSeeder::DEMO_ACCOUNT_NUMBER);
});

/*
| Vòng sửa 1 của rà soát cuối làn (phát hiện 1): `DemoDataSeeder` chạy được trên máy chủ thật bằng
| `db:seed --class=DemoDataSeeder` (demo cho khách trước khi dùng thật). Trước vòng sửa này seeder GHI
| ĐÈ vô điều kiện thông tin chuyển khoản thật bằng tài khoản giả và tắt công tắc. Nay seeder chỉ ghi khi
| chưa có gì được cấu hình — có bất kỳ giá trị nào đã lưu thì không chạm gì.
|
| Mutation probe: bỏ lần hỏi "đã có cấu hình chưa" trong seeder → cả ba hàng ĐỎ.
*/
it('never overwrites what the office already configured', function (array $configured) {
    app()->detectEnvironment(fn () => 'production');

    foreach ($configured as $key => $value) {
        Setting::query()->create(['key' => $key, 'value' => $value]);
    }

    // Đúng lệnh trên máy chủ thật (production hỏi xác nhận, `--force` trả lời thay).
    $this->artisan('db:seed', ['--class' => ClientPaymentSettingsSeeder::class, '--force' => true])->assertSuccessful();

    expect(Setting::query()->pluck('value', 'key')->all())->toEqual($configured);
})->with([
    'real account, switch ON' => [[
        ClientPaymentSettings::ENABLED => ClientPaymentSettings::ON,
        ClientPaymentSettings::BANK_BIN => '970415',
        ClientPaymentSettings::ACCOUNT_NUMBER => '1029384756',
        ClientPaymentSettings::ACCOUNT_HOLDER => 'CONG TY LUAT VU KHANG',
    ]],
    'only the lead time' => [[ClientPaymentSettings::DAYS_BEFORE_DUE => '5']],
    'only the switch, OFF' => [[ClientPaymentSettings::ENABLED => ClientPaymentSettings::OFF]],
]);

it('writes the demo details on a fresh install, and running it again changes nothing', function () {
    $this->seed(ClientPaymentSettingsSeeder::class);
    $first = Setting::query()->orderBy('key')->get(['key', 'value', 'updated_at'])->toArray();

    $this->travel(1)->hours();
    $this->seed(ClientPaymentSettingsSeeder::class);

    expect(Setting::query()->orderBy('key')->get(['key', 'value', 'updated_at'])->toArray())->toBe($first)
        ->and(ClientPaymentSettings::current()->stored('account_number'))->toBe(ClientPaymentSettings::DEMO_ACCOUNT_NUMBER);
});
