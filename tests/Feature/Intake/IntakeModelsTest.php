<?php

use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Audit;
use App\Support\Normalizer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * M10 Task 1 — bảng `intake_requests` / `intake_parties`, enum, model, alias morph, factory.
 * Không Action, không màn hình: quyền và policy ở `IntakeRequestPolicyTest`, ranh giới MCP ở
 * `IntakeMcpBoundaryTest`.
 */
it('stores the exact column kinds and lengths the data model lists, so form maxLength can equal them', function () {
    $columns = collect(Schema::getColumns('intake_requests'))->keyBy('name');

    // Độ dài chuỗi: form (Task 3) đặt maxLength bằng đúng các số này — MariaDB strict, vượt là 500.
    foreach ([
        'code' => 30, 'contact_name' => 200, 'contact_name_normalized' => 200, 'contact_phone' => 20,
        'contact_phone_normalized' => 20, 'contact_email' => 150, 'contact_id_number_hash' => 64,
        'contact_role' => 20, 'source' => 20, 'referred_by' => 200, 'conflict_level' => 10,
        'privacy_notice_version' => 20, 'status' => 20,
    ] as $name => $length) {
        expect($columns->get($name))->not->toBeNull("thiếu cột {$name}")
            ->and(intakeColumnHasLength($columns[$name], $length))->toBeTrue("cột {$name} phải dài {$length}");
    }

    foreach (['contact_name', 'contact_phone', 'contact_email', 'contact_id_number_hash', 'contact_role', 'referred_by',
        'matter_type_id', 'summary', 'quoted_amount', 'conflict_level', 'conflict_checked_at', 'conflict_result',
        'conflict_acknowledged_by', 'conflict_acknowledged_at', 'conflict_overridden_by', 'conflict_override_reason',
        'privacy_notice_version', 'privacy_notice_acknowledged_at', 'privacy_notice_recorded_by', 'assigned_to',
        'first_response_at', 'decline_reason', 'client_id', 'matter_id', 'merged_into_id', 'retention_until',
        'anonymised_at', 'anonymised_by', 'anonymised_reason', 'deleted_at'] as $nullable) {
        expect($columns[$nullable]['nullable'])->toBeTrue("{$nullable} phải cho phép null (ẩn danh xoá về null)");
    }

    expect($columns['code']['nullable'])->toBeFalse()
        ->and($columns['source']['nullable'])->toBeFalse()
        ->and($columns['status']['nullable'])->toBeFalse()
        ->and($columns['received_at']['nullable'])->toBeFalse()
        ->and($columns['decline_reason_is_conflict']['nullable'])->toBeFalse();

    // Số CCCD thô của người liên hệ KHÔNG có cột nào (R7): chỉ dấu băm.
    expect($columns->has('contact_id_number'))->toBeFalse();
});

it('stores the exact columns of intake_parties, with no raw phone or id number', function () {
    $columns = collect(Schema::getColumns('intake_parties'))->keyBy('name');

    foreach (['role' => 20, 'name' => 200, 'name_normalized' => 200, 'phone_normalized' => 20, 'id_number_hash' => 64] as $name => $length) {
        expect($columns->get($name))->not->toBeNull("thiếu cột {$name}")
            ->and(intakeColumnHasLength($columns[$name], $length))->toBeTrue("cột {$name} phải dài {$length}");
    }

    expect($columns['intake_request_id']['nullable'])->toBeFalse()
        ->and($columns['name']['nullable'])->toBeTrue()
        ->and($columns['name_normalized']['nullable'])->toBeTrue()
        ->and($columns['phone_normalized']['nullable'])->toBeTrue()
        ->and($columns['id_number_hash']['nullable'])->toBeTrue()
        ->and($columns->keys()->all())->toBe(
            ['id', 'intake_request_id', 'role', 'name', 'name_normalized', 'phone_normalized', 'id_number_hash', 'created_at', 'updated_at'],
        );
});

it('indexes what the conflict check and the dashboards will look up', function () {
    $indexed = fn (string $table): array => collect(Schema::getIndexes($table))
        ->map(fn (array $index) => implode(',', $index['columns']))->all();

    expect($indexed('intake_requests'))->toContain(
        'code', 'contact_phone_normalized', 'contact_id_number_hash', 'contact_name_normalized',
        'status,received_at', 'assigned_to', 'retention_until', 'matter_id',
    )->and($indexed('intake_parties'))->toContain('name_normalized', 'phone_normalized', 'id_number_hash');

    $unique = fn (string $column): bool => collect(Schema::getIndexes('intake_requests'))
        ->contains(fn (array $index) => $index['columns'] === [$column] && $index['unique']);

    expect($unique('code'))->toBeTrue()
        ->and($unique('matter_id'))->toBeTrue();
});

it('has the backed-string enums with vietnamese labels', function () {
    expect(array_map(fn ($c) => $c->value, IntakeStatus::cases()))
        ->toBe(['new', 'contacted', 'consulting', 'quoted', 'won', 'declined', 'lost', 'merged'])
        ->and(array_map(fn ($c) => $c->value, IntakeSource::cases()))
        ->toBe(['phone', 'zalo', 'walk_in', 'referral', 'website_form', 'other']);

    foreach ([...IntakeStatus::cases(), ...IntakeSource::cases()] as $case) {
        expect($case->label())->not->toStartWith('enums.');
    }
});

it('generates TN-YYYY-0001 codes that never repeat', function () {
    $first = IntakeRequest::factory()->create();
    $second = IntakeRequest::factory()->create();

    $year = now()->format('Y');

    expect($first->code)->toBe("TN-{$year}-0001")
        ->and($second->code)->toBe("TN-{$year}-0002")
        ->and(IntakeRequest::nextCode())->toBe("TN-{$year}-0003");
});

it('casts status, source, role, conflict level and the json snapshot', function () {
    $intake = IntakeRequest::factory()->create([
        'contact_role' => PartyRole::Defendant,
        'conflict_level' => ConflictLevel::Yellow,
        'conflict_result' => ['level' => 'yellow', 'matches' => []],
        'quoted_amount' => 15_000_000,
        'retention_until' => '2028-09-30',
    ])->fresh();

    expect($intake->status)->toBe(IntakeStatus::New)
        ->and($intake->source)->toBe(IntakeSource::Phone)
        ->and($intake->contact_role)->toBe(PartyRole::Defendant)
        ->and($intake->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($intake->conflict_result)->toBe(['level' => 'yellow', 'matches' => []])
        ->and($intake->quoted_amount)->toBe(15_000_000)
        ->and($intake->retention_until->toDateString())->toBe('2028-09-30')
        ->and($intake->received_at)->not->toBeNull()
        ->and($intake->decline_reason_is_conflict)->toBeFalse();
});

/**
 * `identify()` là đường ghi DUY NHẤT của dấu băm CCCD và SĐT chuẩn hoá (cùng khuôn `MatterParty`):
 * số thô không bao giờ được lưu, và `fill()` chặn cả hai cột để một `create([...])` cẩu thả không
 * ghi lệch khỏi `Normalizer`.
 */
it('derives phone and id-number hash only through identify(), never storing the raw id number', function () {
    $intake = IntakeRequest::factory()->create([
        'contact_phone' => '(+84) 832 270 898',
        'contact_phone_normalized' => 'khong-duoc-ghi',
        'contact_id_number_hash' => 'khong-duoc-ghi',
    ]);

    expect($intake->contact_phone_normalized)->toBeNull()
        ->and($intake->contact_id_number_hash)->toBeNull();

    $intake->identify('012345678901', '0832270898')->save();

    $fresh = $intake->fresh();

    expect($fresh->contact_phone_normalized)->toBe(Normalizer::phone('+84832270898'))
        ->and($fresh->contact_id_number_hash)->toBe(Normalizer::idNumberHash('012345678901'))
        ->and(json_encode($fresh->getAttributes()))->not->toContain('012345678901');
});

it('normalises the contact name on every save with the shared Normalizer', function () {
    $intake = IntakeRequest::factory()->create(['contact_name' => 'Nguyễn Văn  Đức']);

    expect($intake->contact_name_normalized)->toBe(Normalizer::name('Nguyễn Văn  Đức'));

    $intake->update(['contact_name' => 'Trần Thị Bình']);

    expect($intake->fresh()->contact_name_normalized)->toBe(Normalizer::name('Trần Thị Bình'));
});

it('lets an anonymised row keep null name and identity without tripping the normaliser', function () {
    $intake = IntakeRequest::factory()->create();
    $intake->contact_name = null;
    $intake->save();

    expect($intake->fresh()->contact_name_normalized)->toBeNull();
});

it('relates a request to its parties, type, client, matter, assignee and merge target, both ways', function () {
    $type = MatterType::factory()->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create();
    $assignee = User::factory()->create();
    $target = IntakeRequest::factory()->create();

    $intake = IntakeRequest::factory()->create([
        'matter_type_id' => $type->id, 'client_id' => $client->id, 'matter_id' => $matter->id,
        'assigned_to' => $assignee->id, 'merged_into_id' => $target->id,
    ]);
    $party = IntakeParty::factory()->for($intake, 'intakeRequest')->create(['role' => PartyRole::Defendant]);

    expect($intake->parties->pluck('id')->all())->toBe([$party->id])
        ->and($party->intakeRequest->is($intake))->toBeTrue()
        ->and($party->role)->toBe(PartyRole::Defendant)
        ->and($intake->matterType->is($type))->toBeTrue()
        ->and($intake->client->is($client))->toBeTrue()
        ->and($intake->matter->is($matter))->toBeTrue()
        ->and($intake->assignee->is($assignee))->toBeTrue()
        ->and($intake->mergedInto->is($target))->toBeTrue()
        ->and($target->mergedFrom->pluck('id')->all())->toBe([$intake->id]);
});

it('refuses two requests converted into the same matter', function () {
    $matter = Matter::factory()->create();
    IntakeRequest::factory()->create(['matter_id' => $matter->id]);

    expect(fn () => IntakeRequest::factory()->create(['matter_id' => $matter->id]))
        ->toThrow(QueryException::class);
});

it('identifies and normalises an opposing party the same way as a matter party', function () {
    $party = IntakeParty::factory()->create(['name' => 'Công ty TNHH Đông Á']);
    $party->identify('0123456789', '0912 345 678')->save();

    $fresh = $party->fresh();

    expect($fresh->name_normalized)->toBe(Normalizer::name('Công ty TNHH Đông Á'))
        ->and($fresh->phone_normalized)->toBe(Normalizer::phone('0912345678'))
        ->and($fresh->id_number_hash)->toBe(Normalizer::idNumberHash('0123456789'));

    // Cả khi đi qua `Model::unguarded()` (factory): `fill()` vẫn chặn hai cột chỉ `identify()` ghi được.
    $forged = IntakeParty::factory()->create(['phone_normalized' => 'gia-mao', 'id_number_hash' => 'gia-mao']);

    expect($forged->fresh()->phone_normalized)->toBeNull()
        ->and($forged->fresh()->id_number_hash)->toBeNull();
});

it('records who wrote it through HasBlameable', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');

    $intake = IntakeRequest::factory()->create();

    expect($intake->created_by)->toBe($author->id)
        ->and($intake->creator->is($author))->toBeTrue();
});

it('has the strict morph aliases intake_request and intake_party', function () {
    expect((new IntakeRequest)->getMorphClass())->toBe('intake_request')
        ->and((new IntakeParty)->getMorphClass())->toBe('intake_party')
        ->and(Relation::getMorphedModel('intake_request'))->toBe(IntakeRequest::class)
        ->and(Relation::getMorphedModel('intake_party'))->toBe(IntakeParty::class);
});

it('accepts an audit row whose subject is an intake request or party without throwing', function () {
    $actor = User::factory()->create();
    $intake = IntakeRequest::factory()->create();
    $party = IntakeParty::factory()->for($intake, 'intakeRequest')->create();

    $forIntake = Audit::record('conflict_check_run', $intake, ['level' => 'green'], $actor);
    $forParty = Audit::record('conflict_check_run', $party, ['level' => 'green'], $actor);

    expect($forIntake)->not->toBeNull()
        ->and($forIntake->subject_type)->toBe('intake_request')
        ->and($forParty->subject_type)->toBe('intake_party');
});

/**
 * R7 đòi ẩn danh phủ cả `activity_log`, R8 giới hạn lý do xung đột, và `ActivityOwningMatter` cho
 * MỌI người có `auditLog.view` đọc các dòng chủ thể `intake_request`. Nên nhật ký tự động của
 * model chỉ mang cột KHÔNG cá nhân và KHÔNG nhạy cảm.
 */
it('never writes personal or sensitive columns to the automatic activity log', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor, 'web');

    $intake = IntakeRequest::factory()->create([
        'contact_name' => 'Người Gọi Bí Mật',
        'contact_phone' => '0912345678',
        'contact_email' => 'bi.mat@example.test',
        'referred_by' => 'Ông Giới Thiệu',
        'summary' => 'Câu chuyện rất nhạy cảm',
    ]);
    $intake->update([
        'summary' => 'Câu chuyện đã đổi',
        'decline_reason' => 'Lý do xung đột với khách X',
        'conflict_override_reason' => 'Lý do ghi đè bí mật',
        'status' => IntakeStatus::Declined,
        'decline_reason_is_conflict' => true,
    ]);

    $rows = Activity::query()->where('subject_type', 'intake_request')->get();

    expect($rows)->not->toBeEmpty();

    $dump = $rows->map(fn (Activity $a) => json_encode($a->properties, JSON_UNESCAPED_UNICODE))->implode(' ');

    foreach (['Người Gọi', '0912345678', 'bi.mat', 'Giới Thiệu', 'Câu chuyện', 'xung đột', 'ghi đè', 'contact_', 'summary', 'decline_reason', 'referred_by'] as $secret) {
        expect($dump)->not->toContain($secret);
    }

    // ...nhưng vẫn ghi cái không nhạy cảm: chuyển trạng thái để lại dấu vết.
    expect($dump)->toContain('declined');
});

/**
 * Bên đối lập không có nhật ký tự động: tên, SĐT chuẩn hoá và dấu băm CCCD của BÊN THỨ BA không có
 * lý do gì nằm trong `activity_log` (R7) — nhật ký của bản ghi cha đã đủ để biết ai sửa gì.
 */
it('writes no automatic activity rows for intake parties', function () {
    $this->actingAs(User::factory()->create(), 'web');

    IntakeParty::factory()->create(['name' => 'Bên Đối Lập Bí Mật'])->identify('0123456789', '0912345678')->save();

    expect(Activity::query()->where('subject_type', 'intake_party')->count())->toBe(0);
});

it('soft-deletes requests', function () {
    expect(class_uses_recursive(IntakeRequest::class))->toContain(SoftDeletes::class);

    $intake = IntakeRequest::factory()->create();
    $intake->delete();

    expect(IntakeRequest::query()->count())->toBe(0)
        ->and(IntakeRequest::withTrashed()->count())->toBe(1);
});

/**
 * SQLite không báo độ dài của `varchar` (mọi chuỗi đều là TEXT với nó), nên trên SQLite phép so này
 * luôn đúng và nhân chứng THẬT của độ dài cột là lượt chạy `test:mariadb` (`varchar(30)`, …) — đó
 * cũng là lý do độ dài phải khớp `maxLength` của form: MariaDB strict, vượt là 500.
 *
 * @param  array{type: string}  $column
 */
function intakeColumnHasLength(array $column, int $length): bool
{
    return DB::connection()->getDriverName() === 'sqlite' || str_contains($column['type'], "({$length})");
}
