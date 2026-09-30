<?php

use App\Models\ChecklistTemplate;
use App\Models\MatterType;
use App\Support\StagePresets;
use Database\Factories\MatterTypeFactory;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MatterTypeSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * M9 Task 1 — mười hai lĩnh vực hành nghề của văn phòng (luatvukhang.com), không phải sáu.
 *
 * Biểu đồ cơ cấu vụ việc của trang doanh thu chạy trên mọi loại đang hoạt động; một bộ seed chỉ
 * có sáu loại kể cho chủ văn phòng một câu chuyện sai. Bốn loại cũ đổi TÊN (không bao giờ đổi
 * `code`, vì `matters.code` nhúng mã loại), sáu loại mới có bộ giai đoạn TẠM chung.
 */
const NEW_TYPE_CODES = ['HC', 'TM', 'NH', 'SH', 'TC', 'XD'];
const ALL_TYPE_CODES = ['DD', 'DN', 'DS', 'HC', 'HN', 'HS', 'LD', 'NH', 'SH', 'TC', 'TM', 'XD'];
const CONTRACT_ITEM_NAME = 'Hợp đồng dịch vụ pháp lý và giấy uỷ quyền';

it('seeds exactly twelve active matter types after the reference seeders run', function () {
    $this->seed(ReferenceDataSeeder::class);

    $active = MatterType::query()->where('is_active', true)->pluck('code')->sort()->values()->all();

    expect(MatterType::query()->count())->toBe(12)
        ->and($active)->toBe(ALL_TYPE_CODES)
        ->and(array_column(MatterTypeSeeder::types(), 'code'))->toHaveCount(12);
});

it('names the twelve types after the office fields and keeps the four renamed codes', function () {
    $this->seed(ReferenceDataSeeder::class);

    $names = MatterType::query()->pluck('name', 'code')->all();

    expect($names)->toBe([
        'DD' => 'Đất đai và bất động sản',
        'DS' => 'Giải quyết tranh chấp',
        'HS' => 'Hình sự',
        'DN' => 'Đầu tư và doanh nghiệp',
        'LD' => 'Lao động và nhân sự',
        'HN' => 'Hôn nhân và gia đình',
        'HC' => 'Hành chính và giấy phép',
        'TM' => 'Hợp đồng và thương mại',
        'NH' => 'Ngân hàng và tín dụng',
        'SH' => 'Sở hữu trí tuệ và công nghệ',
        'TC' => 'Thuế và tài chính',
        'XD' => 'Xây dựng và hạ tầng',
    ]);
});

it('appends the six new types after the six old ones without renumbering sort_order', function () {
    $this->seed(ReferenceDataSeeder::class);

    $order = MatterType::query()->orderBy('sort_order')->pluck('code')->all();

    expect($order)->toBe(['DD', 'DS', 'HS', 'DN', 'LD', 'HN', 'HC', 'TM', 'NH', 'SH', 'TC', 'XD']);
});

it('gives the six new types the five-stage provisional set, not the civil litigation one', function () {
    $this->seed(ReferenceDataSeeder::class);

    foreach (NEW_TYPE_CODES as $code) {
        $keys = MatterType::query()->where('code', $code)->firstOrFail()->stages()->pluck('key')->all();

        expect($keys)->toBe(['intake', 'collecting_documents', 'drafting', 'in_progress', 'closed'], "Loại {$code}");
        expect($keys)->not->toContain('filed', 'court_accepted', 'appeal');
    }
});

it('marks the provisional stage set as provisional in the description of each new type', function () {
    $this->seed(ReferenceDataSeeder::class);

    foreach (NEW_TYPE_CODES as $code) {
        expect(MatterType::query()->where('code', $code)->firstOrFail()->description)
            ->toContain('TẠM');
    }

    foreach (['DD', 'DS', 'HS', 'DN', 'LD', 'HN'] as $code) {
        expect(MatterType::query()->where('code', $code)->firstOrFail()->description)->toBeNull();
    }
});

it('maps HC TM NH SH TC XD to the provisional preset explicitly and keeps the civil default for unknown codes', function () {
    foreach (NEW_TYPE_CODES as $code) {
        expect(StagePresets::for($code))->toBe(StagePresets::provisional(), "Loại {$code}")
            ->and(StagePresets::for($code))->not->toBe(StagePresets::civil());
    }

    expect(StagePresets::for('ZZ'))->toBe(StagePresets::civil())
        ->and(StagePresets::for('DS'))->toBe(StagePresets::civil())
        ->and(StagePresets::for('HS'))->toBe(StagePresets::criminal())
        ->and(StagePresets::for('DN'))->toBe(StagePresets::corporate());
});

it('shapes the provisional preset as a straight five-step line ending in one terminal stage', function () {
    $stages = StagePresets::provisional();

    expect(array_column($stages, 'key'))->toBe(['intake', 'collecting_documents', 'drafting', 'in_progress', 'closed'])
        ->and(collect($stages)->where('is_terminal', true)->pluck('key')->all())->toBe(['closed']);

    foreach ($stages as $index => $stage) {
        expect($stage['allowed_next'])->toBe(isset($stages[$index + 1]) ? [$stages[$index + 1]['key']] : []);
    }
});

it('gives every seeded type at least one stage and exactly one terminal stage', function () {
    $this->seed(ReferenceDataSeeder::class);

    foreach (MatterType::query()->get() as $type) {
        expect($type->stages()->count())->toBeGreaterThanOrEqual(1, "Loại {$type->code}")
            ->and($type->stages()->where('is_terminal', true)->count())->toBe(1, "Loại {$type->code}");
    }
});

it('gives every seeded stage of every type a client_description of at least thirty characters', function () {
    $this->seed(ReferenceDataSeeder::class);

    $rows = DB::table('matter_type_stages')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(mb_strlen($row->client_description ?? ''))->toBeGreaterThanOrEqual(30, "Giai đoạn {$row->key} của loại {$row->matter_type_id}");
    }
});

it('gives every seeded type at least one checklist template with the mandatory service-contract item', function () {
    $this->seed(ReferenceDataSeeder::class);

    foreach (MatterType::query()->get() as $type) {
        $templates = ChecklistTemplate::query()->where('matter_type_id', $type->id)->with('items')->get();

        expect($templates->count())->toBeGreaterThanOrEqual(1, "Loại {$type->code} không có danh mục hồ sơ mẫu.");

        foreach ($templates as $template) {
            $contract = $template->items->firstWhere('name', CONTRACT_ITEM_NAME);

            expect(mb_strlen($template->name))->toBeLessThanOrEqual(150)
                ->and($template->is_active)->toBeTrue()
                ->and($contract)->not->toBeNull("Loại {$type->code}: thiếu đầu mục hợp đồng dịch vụ.")
                ->and($contract->is_required)->toBeTrue()
                ->and($template->items->contains(fn ($item) => str_starts_with($item->name, 'Giấy tờ tuỳ thân')))->toBeTrue();

            foreach ($template->items as $item) {
                expect(mb_strlen($item->name))->toBeLessThanOrEqual(200);
            }
        }
    }
});

it('never restores a matter type name an admin changed when the seeder runs again', function () {
    $this->seed(ReferenceDataSeeder::class);

    MatterType::query()->where('code', 'DD')->firstOrFail()->update(['name' => 'Đất đai (tên văn phòng tự đặt)']);
    MatterType::query()->where('code', 'TM')->firstOrFail()->update(['name' => 'Thương mại (tự đặt)', 'description' => null]);

    $this->seed(ReferenceDataSeeder::class);

    expect(MatterType::query()->where('code', 'DD')->firstOrFail()->name)->toBe('Đất đai (tên văn phòng tự đặt)')
        ->and(MatterType::query()->where('code', 'TM')->firstOrFail()->name)->toBe('Thương mại (tự đặt)')
        ->and(MatterType::query()->where('code', 'TM')->firstOrFail()->description)->toBeNull()
        ->and(MatterType::query()->count())->toBe(12);
});

it('adds only the missing new types on a database that already holds the six old ones', function () {
    $this->seed(ReferenceDataSeeder::class);
    MatterType::query()->whereIn('code', NEW_TYPE_CODES)->get()->each->forceDelete();
    MatterType::query()->where('code', 'DS')->firstOrFail()->update(['name' => 'Tranh chấp dân sự']);

    expect(MatterType::query()->count())->toBe(6);

    $this->seed(ReferenceDataSeeder::class);

    expect(MatterType::query()->count())->toBe(12)
        ->and(MatterType::query()->where('code', 'DS')->firstOrFail()->name)->toBe('Tranh chấp dân sự')
        ->and(MatterType::query()->where('code', 'XD')->firstOrFail()->stages()->count())->toBe(5);
});

it('publishes no matter of a new type to the client portal in the demo data', function () {
    $this->seed(DatabaseSeeder::class);

    $newIds = MatterType::query()->whereIn('code', NEW_TYPE_CODES)->pluck('id');

    expect($newIds)->toHaveCount(6)
        ->and(DB::table('matters')->whereIn('matter_type_id', $newIds)->where('is_published_to_portal', true)->count())->toBe(0)
        // Cặp dương: bảng vụ việc mẫu CÓ vụ đã công bố, nên vế trên không xanh vì bảng rỗng.
        ->and(DB::table('matters')->where('is_published_to_portal', true)->count())->toBeGreaterThan(0);
});

/**
 * Bẫy factory: `lexify('??')` sinh mã hai chữ ngẫu nhiên. Với mười hai mã seed (12/676 ≈ 1,8%) một
 * test tạo loại bằng factory sẽ trùng mã với loại đã seed (`DuplicateMatterTypeCode`), hoặc lặng
 * lẽ nhận bộ giai đoạn của loại đó qua `withStages()`.
 */
it('never lets the matter type factory pick a code the reference seeder owns', function () {
    $seeded = array_column(MatterTypeSeeder::types(), 'code');

    for ($i = 0; $i < 300; $i++) {
        expect($seeded)->not->toContain(MatterTypeFactory::new()->make()->code);
    }
});

it('keeps the checklist seeder re-runnable without duplicating templates', function () {
    $this->seed(ReferenceDataSeeder::class);
    $before = ChecklistTemplate::query()->count();

    $this->seed(ChecklistTemplateSeeder::class);

    expect(ChecklistTemplate::query()->count())->toBe($before)
        ->and($before)->toBeGreaterThanOrEqual(12);
});
