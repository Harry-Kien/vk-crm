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
