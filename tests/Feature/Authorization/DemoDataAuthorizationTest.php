<?php

use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // `MatterSeeder` nay nộp tệp thật qua `UploadStaffDocument`/`SubmitClientDocument`,
    // nên nó GHI RA ĐĨA. Không có dòng này, mỗi lần chạy bộ test lại bỏ vài chục tệp PDF
    // vào `storage/app/private` thật của máy dev.
    Storage::fake('private');

    $this->seed(DatabaseSeeder::class);
});

it('gives each role the reach the spec describes on the seeded office', function () {
    $admin = User::where('email', 'admin@luatvukhang.com')->firstOrFail();
    $lawyer = User::where('email', 'luatsu1@luatvukhang.com')->firstOrFail();
    $accountant = User::where('email', 'ketoan@luatvukhang.com')->firstOrFail();

    $all = Matter::count();
    $lawyerMatters = Matter::query()->listableBy($lawyer)->count();

    /**
     * MatterSeeder::run() xoay vòng 3 luật sư theo thứ tự email (luatsu1 ở chỉ số 0):
     * lead_lawyer_id dùng $lawyers[($i - 1) % 3], nên luatsu1 làm chủ trì ở vụ i mà
     * ($i - 1) % 3 === 0, tức i thuộc {1,4,7,10,13,16,19} — 7 vụ trên 20 vụ.
     * Ngoài ra addTeamMember() thêm luật sư $lawyers[$i % 3] làm Associate khi $i % 4 === 0;
     * $i % 3 === 0 cùng lúc $i % 4 === 0 chỉ xảy ra ở i = 12 trong khoảng 1..20, và 12 không
     * nằm trong tập chủ trì ở trên. Vậy luatsu1 có mặt trong đội ngũ đúng 7 + 1 = 8 vụ trong
     * 20 vụ đánh số.
     *
     * Task 10 thêm đúng MỘT vụ `restricted` ngoài 20 vụ đó (MatterSeeder::restrictedMatter()),
     * với lead_lawyer_id cố định là luatsu1 — nên tổng tăng lên 21 và luatsu1 tăng lên 8 + 1 = 9.
     *
     * M7 Task 3 thêm đúng MỘT vụ ĐÃ KẾT THÚC ngoài 21 vụ đó (MatterSeeder::closedMatter()), với
     * lead_lawyer_id cố định là luatsu3 (`$lawyers->last()`, cố tình KHÁC luatsu1 — xem docblock
     * `closedMatter()` — để con số 9 của luatsu1 ngay trên không đổi) — nên tổng ($all) tăng lên
     * 22. Vụ này KHÔNG `restricted`, nên nó cũng rơi vào nhánh "vụ THƯỜNG" của scopeListableBy();
     * kế toán (chỉ `matter.viewAny`, không `matter.view`) thấy được nhánh đó không điều kiện gì
     * thêm, nên con số của họ tăng THEO cùng $all ở vế "vụ thường" — 20 (không mật) + 1 (đã kết
     * thúc, không mật) = 21 — trong khi vẫn dừng lại TRƯỚC vụ mật (nhánh restricted, bị loại vì
     * thiếu `matter.view`), nên 21 chứ không phải 22.
     *
     * Admin luôn thấy $all (bypass mọi nhánh của scopeListableBy qua vai trò admin). Numbers xác
     * nhận bằng `bin/dev artisan tinker` trên chính bộ seeder này (xem task-10-report.md, cập
     * nhật M7 Task 3).
     */
    expect($all)->toBe(22)
        ->and(Matter::query()->listableBy($admin)->count())->toBe($all)
        ->and(Matter::query()->listableBy($accountant)->count())->toBe(21)
        ->and($lawyerMatters)->toBe(9)
        ->and($accountant->can('view', Matter::first()))->toBeFalse();

    Matter::query()->listableBy($lawyer)->get()
        ->each(fn (Matter $matter) => expect($lawyer->can('view', $matter))->toBeTrue());

    $onTeam = Matter::whereHas('team', fn ($q) => $q->whereKey($lawyer->id))->orderBy('id')->pluck('id')->all();

    expect(Matter::query()->listableBy($lawyer)->orderBy('id')->pluck('id')->all())->toBe($onTeam);
});

/**
 * Carry-forward M2 (task 10): nhánh `restricted` của Matter::scopeListableBy() không có bằng
 * chứng trên dữ liệu mẫu thật trước đây. MatterSeeder::restrictedMatter() thêm đúng một vụ, lead
 * là luatsu1@luatvukhang.com — kiểm tra ba vai đại diện đúng luật SPEC §5: lead lawyer và admin
 * thấy được, quản lý (có matter.view nhưng không phải admin, không đứng tên vụ này) thì không.
 */
it('shows the seeded restricted matter to its lead lawyer and the admin but not to the manager', function () {
    $admin = User::where('email', 'admin@luatvukhang.com')->firstOrFail();
    $lawyer = User::where('email', 'luatsu1@luatvukhang.com')->firstOrFail();
    $manager = User::where('email', 'quanly@luatvukhang.com')->firstOrFail();

    $restricted = Matter::where('confidentiality', 'restricted')->firstOrFail();

    expect($restricted->lead_lawyer_id)->toBe($lawyer->id)
        ->and(Matter::query()->listableBy($lawyer)->whereKey($restricted->id)->exists())->toBeTrue()
        ->and(Matter::query()->listableBy($admin)->whereKey($restricted->id)->exists())->toBeTrue()
        ->and(Matter::query()->listableBy($manager)->whereKey($restricted->id)->exists())->toBeFalse()
        ->and($lawyer->can('view', $restricted))->toBeTrue()
        ->and($admin->can('view', $restricted))->toBeTrue()
        ->and($manager->can('view', $restricted))->toBeFalse();
});

it('shows a seeded client only their own matters and nothing internal', function () {
    $clientUser = ClientUser::where('email', 'khach1@example.com')->firstOrFail();
    $this->actingAs($clientUser, 'client');

    $matters = Matter::get();

    expect($matters)->not->toBeEmpty()
        ->and($matters->pluck('client_id')->unique()->all())->toBe([$clientUser->client_id])
        ->and(StageLog::where('is_published', false)->count())->toBe(0)
        ->and(Document::where('group', 'D')->count())->toBe(0);
});

it('never leaks an internal note into what the portal serializes', function () {
    $clientUser = ClientUser::where('email', 'khach1@example.com')->firstOrFail();
    $notes = StageLog::withoutGlobalScopes()->whereNotNull('internal_note')->pluck('internal_note');

    $this->actingAs($clientUser, 'client');
    $payload = json_encode(Matter::with(['stageLogs', 'client', 'checklistItems'])->get()->toArray(), JSON_UNESCAPED_UNICODE);

    expect($notes)->not->toBeEmpty();
    $notes->each(fn (string $note) => expect($payload)->not->toContain($note));
});
