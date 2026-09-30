<?php

use Illuminate\Support\Facades\DB;

/**
 * M9 Task 1: migration DỮ LIỆU `2026_09_30_000001_rename_matter_types_to_office_names.php`.
 *
 * Migration đã chạy xong (trên bảng rỗng) trước khi test dựng dữ liệu, nên test gọi LẠI `up()` /
 * `down()` của đúng tệp đó sau khi dựng các dòng `matter_types` bằng `DB::table` (không qua model:
 * hook `saving` và `HasBlameable` không liên quan tới việc đang đo).
 *
 * **Collation.** Trên MariaDB `utf8mb4_unicode_ci` so `=` KHÔNG phân biệt hoa/thường và dấu, nên
 * "LAO ĐỘNG" hay "Lao dong" khớp `where name = 'Lao động'`, và (PAD SPACE) cả "Lao động " có dấu cách
 * cuối. SQLite so `=` từng byte, nên các ca chỉ-khác-hoa-thường / chỉ-khác-dấu / dấu-cách-cuối chỉ có
 * nghĩa trên `bin/dev test:mariadb`; trên SQLite chúng xanh ngay cả với một bản cài dùng
 * `where('name', $old)` (đã đo: SQLite 13 xanh, MariaDB 4 đỏ với bản cài đó; xem báo cáo).
 */
function renameMigration(): object
{
    return require database_path('migrations/2026_09_30_000001_rename_matter_types_to_office_names.php');
}

function seedOldTypeRow(string $code, string $name, bool $trashed = false): int
{
    return DB::table('matter_types')->insertGetId([
        'code' => $code,
        'name' => $name,
        'is_active' => true,
        'sort_order' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'deleted_at' => $trashed ? '2026-02-01 00:00:00' : null,
    ]);
}

function renameTestTypeName(int $id): string
{
    return DB::table('matter_types')->where('id', $id)->value('name');
}

beforeEach(function () {
    DB::table('matter_types')->delete();
});

it('renames the four old seed names to the office names and never touches a code', function () {
    $ids = [
        'DD' => seedOldTypeRow('DD', 'Tranh chấp đất đai'),
        'DN' => seedOldTypeRow('DN', 'Doanh nghiệp'),
        'DS' => seedOldTypeRow('DS', 'Tranh chấp dân sự'),
        'LD' => seedOldTypeRow('LD', 'Lao động'),
    ];

    renameMigration()->up();

    expect(renameTestTypeName($ids['DD']))->toBe('Đất đai và bất động sản')
        ->and(renameTestTypeName($ids['DN']))->toBe('Đầu tư và doanh nghiệp')
        ->and(renameTestTypeName($ids['DS']))->toBe('Giải quyết tranh chấp')
        ->and(renameTestTypeName($ids['LD']))->toBe('Lao động và nhân sự')
        ->and(DB::table('matter_types')->orderBy('code')->pluck('code')->all())->toBe(['DD', 'DN', 'DS', 'LD']);
});

it('leaves HS and HN and every other code alone', function () {
    $hs = seedOldTypeRow('HS', 'Hình sự');
    $hn = seedOldTypeRow('HN', 'Hôn nhân và gia đình');
    $xx = seedOldTypeRow('XX', 'Doanh nghiệp');

    renameMigration()->up();

    expect(renameTestTypeName($hs))->toBe('Hình sự')
        ->and(renameTestTypeName($hn))->toBe('Hôn nhân và gia đình')
        ->and(renameTestTypeName($xx))->toBe('Doanh nghiệp');
});

it('does not touch a type the admin already renamed to something else', function () {
    $id = seedOldTypeRow('DD', 'Đất đai (tên văn phòng tự đặt)');

    renameMigration()->up();

    expect(renameTestTypeName($id))->toBe('Đất đai (tên văn phòng tự đặt)');
});

/**
 * Ca "chỉ khác hoa/thường": dưới `utf8mb4_unicode_ci` `where name = 'Lao động'` khớp cả "LAO ĐỘNG"
 * hay "Lao Động", và migration sẽ "sửa" lại đúng thứ admin cố ý gõ. Migration so bằng `===` trong PHP.
 * Chỉ có sức ép thật trên MariaDB.
 */
it('does not touch a name that differs from the old seed name only by letter case', function () {
    $upper = seedOldTypeRow('LD', 'LAO ĐỘNG');
    $title = seedOldTypeRow('DN', 'doanh nghiệp');

    renameMigration()->up();

    expect(renameTestTypeName($upper))->toBe('LAO ĐỘNG')
        ->and(renameTestTypeName($title))->toBe('doanh nghiệp');
});

it('does not touch a name that differs from the old seed name only by diacritics', function () {
    $plain = seedOldTypeRow('LD', 'Lao dong');
    $noTone = seedOldTypeRow('DS', 'Tranh chap dan su');

    renameMigration()->up();

    expect(renameTestTypeName($plain))->toBe('Lao dong')
        ->and(renameTestTypeName($noTone))->toBe('Tranh chap dan su');
});

it('does not touch a name with surrounding whitespace, which is not the old seed name', function () {
    $id = seedOldTypeRow('LD', 'Lao động ');

    renameMigration()->up();

    expect(renameTestTypeName($id))->toBe('Lao động ');
});

it('renames a soft-deleted type too, by the same exact-name rule, and keeps it soft-deleted', function () {
    $id = seedOldTypeRow('DD', 'Tranh chấp đất đai', trashed: true);
    $other = seedOldTypeRow('DS', 'Tên khác', trashed: true);

    renameMigration()->up();

    expect(renameTestTypeName($id))->toBe('Đất đai và bất động sản')
        ->and(DB::table('matter_types')->where('id', $id)->value('deleted_at'))->not->toBeNull()
        ->and(renameTestTypeName($other))->toBe('Tên khác');
});

it('renames every row that carries the old code and name, live or soft-deleted, not only the first', function () {
    $live = seedOldTypeRow('DD', 'Tranh chấp đất đai');
    $trashed = seedOldTypeRow('DD', 'Tranh chấp đất đai', trashed: true);

    renameMigration()->up();

    expect(renameTestTypeName($live))->toBe('Đất đai và bất động sản')
        ->and(renameTestTypeName($trashed))->toBe('Đất đai và bất động sản');
});

it('leaves updated_at alone so a rename does not look like an admin edit', function () {
    $id = seedOldTypeRow('LD', 'Lao động');

    renameMigration()->up();

    expect((string) DB::table('matter_types')->where('id', $id)->value('updated_at'))->toBe('2026-01-01 00:00:00');
});

it('is idempotent when it runs a second time', function () {
    $id = seedOldTypeRow('LD', 'Lao động');

    renameMigration()->up();
    renameMigration()->up();

    expect(renameTestTypeName($id))->toBe('Lao động và nhân sự');
});

it('does nothing on an empty table', function () {
    renameMigration()->up();

    expect(DB::table('matter_types')->count())->toBe(0);
});

it('reverses only names that still equal the new office name', function () {
    $renamed = seedOldTypeRow('DD', 'Đất đai và bất động sản');
    $custom = seedOldTypeRow('LD', 'Lao động (tự đặt)');
    $upper = seedOldTypeRow('DN', 'ĐẦU TƯ VÀ DOANH NGHIỆP');

    renameMigration()->down();

    expect(renameTestTypeName($renamed))->toBe('Tranh chấp đất đai')
        ->and(renameTestTypeName($custom))->toBe('Lao động (tự đặt)')
        ->and(renameTestTypeName($upper))->toBe('ĐẦU TƯ VÀ DOANH NGHIỆP');
});

it('round-trips up then down back to the old names', function () {
    $ids = [
        seedOldTypeRow('DD', 'Tranh chấp đất đai'),
        seedOldTypeRow('DN', 'Doanh nghiệp'),
        seedOldTypeRow('DS', 'Tranh chấp dân sự'),
        seedOldTypeRow('LD', 'Lao động'),
    ];

    renameMigration()->up();
    renameMigration()->down();

    expect(array_map('renameTestTypeName', $ids))->toBe(['Tranh chấp đất đai', 'Doanh nghiệp', 'Tranh chấp dân sự', 'Lao động']);
});
