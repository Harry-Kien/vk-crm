<?php

use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Carry-forward từ Task 3, sửa ở M9 Task 5: `PaymentPolicy::canRecordPaymentOn()` bản trước gọi
 * `matterForBillingGate()` (nạp lại vụ việc, KHÔNG khoá `ClientPortalScope` —
 * {@see ChecksBillingAccess::matterForBillingGate()}) TRƯỚC khi biết
 * `$user` có phải nhân sự hay không. Với một vụ nạp thiếu cột, một `ClientUser` gọi
 * `create`/`void` kích hoạt một lần nạp lại KHÔNG bị cắt theo phiên cổng khách của chính nó, trước
 * khi bị từ chối — không đổi kết quả cuối, nhưng một khách hàng không nên khiến cổng tiền phải làm
 * việc thêm chỉ để rồi bị từ chối. Fix: `$user instanceof User` đứng ĐẦU thân hàm.
 *
 * Test này chứng minh phần "trước khi bị từ chối" bằng cách đếm truy vấn: một `ClientUser` hỏi
 * `create`/`void` trên một vụ việc nạp THIẾU CỘT (`select('id')`, đúng hình dạng
 * `matterForBillingGate()` phải xử lý) không được sinh thêm truy vấn `matters` nào — nếu fix bị
 * gỡ, `matterForBillingGate()` chạy MỘT truy vấn `Matter::query()->find()` trước khi
 * `canSeeBilling()` kịp từ chối ở nhánh `! $user instanceof User`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->clientUser = ClientUser::factory()->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

it('refuses a client user before ever reloading a partially loaded matter for the payment gate', function () {
    $partiallyLoaded = Matter::query()->select('id')->whereKey($this->matter->id)->firstOrFail();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'matters')) {
            $queries[] = $query->sql;
        }
    });

    $create = $this->clientUser->can('create', [Payment::class, $partiallyLoaded]);

    expect($create)->toBeFalse()
        ->and($queries)->toBe([]);
});

/**
 * Cặp dương: trên CÙNG vụ nạp thiếu cột, một nhân sự đủ quyền vẫn qua được (và ở đó truy vấn nạp
 * lại là được phép, có chủ đích). Kế toán, không phải luật sư: `payment.record` trên một vụ
 * THƯỜNG (không `restricted`) đòi quyền `payment.record` mà kế toán có và luật sư thì không (xem
 * `PaymentPolicy::canRecordPaymentOn()`) — luật sư ghi được CHỈ trên vụ `restricted` của chính
 * mình, một nhánh khác không liên quan tới điều đang thử ở đây (thứ tự kiểm tra).
 */
it('still lets an authorised staff member through on the same partially loaded matter', function () {
    $partiallyLoaded = Matter::query()->select('id')->whereKey($this->matter->id)->firstOrFail();

    expect($this->accountant->can('create', [Payment::class, $partiallyLoaded]))->toBeTrue();
});
