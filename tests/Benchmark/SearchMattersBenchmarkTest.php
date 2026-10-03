<?php

use App\Actions\Search\SearchMatters;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * M7 Task 9 — ĐO thời gian tìm kiếm trên vài nghìn hồ sơ (SPEC §6.13 "LIKE … đủ ở quy mô vài
 * nghìn hồ sơ"). Không phải một test hành vi, và KHÔNG chạy trong bộ thường: `tests/Benchmark` không
 * nằm trong testsuite nào của `phpunit.xml`, nên `php artisan test` (CI) và `container-test` không
 * có tham số đều bỏ qua nó. Chạy tay, trên MariaDB thật (SQLite trong bộ nhớ không nói gì về máy
 * chủ):
 *
 *     /d/vkwt/m7b-dev test:mariadb tests/Benchmark/SearchMattersBenchmarkTest.php
 *
 * Dữ liệu chèn thẳng bằng `DB::table()->insert()` theo lô (factory cho 6.000 vụ mất nhiều phút):
 * 3.000 khách, 6.000 vụ (5% restricted, 60% có số thụ lý), 3 bên mỗi vụ, 5 tài liệu mỗi vụ, đội
 * ngũ 2 người mỗi vụ. Kết quả (trung vị của 5 lần, mili giây) và `EXPLAIN` in ra STDERR; số đo ghi
 * vào "Ghi chú M7". Khẳng định duy nhất là một trần rộng (2 giây) để lần chạy báo lỗi nếu có gì
 * hỏng hẳn, không để đo hiệu năng tinh.
 */
it('đo thời gian tìm trên 6.000 hồ sơ', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $lawyers = User::factory()->count(30)->withRole(Role::Lawyer)->create();
    $types = MatterType::factory()->count(5)->withStages()->create()->load('stages');

    $family = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý'];
    $middle = ['Văn', 'Thị', 'Hữu', 'Đức', 'Minh', 'Ngọc', 'Thanh', 'Quốc', 'Thu', 'Kim'];
    $given = ['An', 'Bình', 'Cường', 'Dũng', 'Giang', 'Hà', 'Hải', 'Hạnh', 'Hùng', 'Lan', 'Linh', 'Long', 'Mai', 'Nam', 'Phúc', 'Quang', 'Sơn', 'Tâm', 'Thảo', 'Trang', 'Tuấn', 'Vy', 'Yến', 'Ánh'];
    $topics = ['Tranh chấp hợp đồng mua bán', 'Ly hôn và chia tài sản', 'Tranh chấp quyền sử dụng đất', 'Thừa kế theo di chúc', 'Đòi nợ', 'Lao động — sa thải trái luật', 'Tư vấn thành lập doanh nghiệp', 'Bồi thường thiệt hại ngoài hợp đồng'];
    $documentTitles = ['Đơn khởi kiện', 'Giấy uỷ quyền', 'Hợp đồng dịch vụ pháp lý', 'Biên bản hoà giải', 'Bản án sơ thẩm', 'CCCD của khách', 'Sổ đỏ', 'Ghi chú nội bộ'];
    $name = fn (int $i): string => $family[$i % 16].' '.$middle[intdiv($i, 16) % 10].' '.$given[intdiv($i, 160) % 24];

    $now = now()->toDateTimeString();
    $userMorph = (new User)->getMorphClass();

    $clients = [];
    foreach (range(1, 3000) as $i) {
        $clients[] = ['code' => sprintf('KHB-%05d', $i), 'type' => 'individual', 'name' => $name($i * 7), 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($clients, 500) as $chunk) {
        DB::table('clients')->insert($chunk);
    }
    $clientIds = DB::table('clients')->pluck('id')->all();

    $matters = [];
    foreach (range(1, 6000) as $i) {
        $type = $types[$i % 5];
        $matters[] = [
            'code' => sprintf('VK-2026-%s-%04d', $type->code, $i),
            'client_id' => $clientIds[$i % count($clientIds)],
            'matter_type_id' => $type->id,
            'title' => $topics[$i % 8].' — '.$name($i * 13),
            'stage' => $type->stages->first()->key,
            'stage_entered_at' => $now,
            'lead_lawyer_id' => $lawyers[$i % 30]->id,
            'opened_at' => now()->toDateString(),
            'is_published_to_portal' => true,
            'case_number' => $i % 5 < 3 ? sprintf('%d/2026/TLST-DS', $i) : null,
            'confidentiality' => $i % 20 === 0 ? 'restricted' : 'normal',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($matters, 500) as $chunk) {
        DB::table('matters')->insert($chunk);
    }
    $matterRows = DB::table('matters')->get(['id', 'lead_lawyer_id']);

    $team = $parties = $documents = [];
    foreach ($matterRows as $k => $matter) {
        $associate = $lawyers[($k + 7) % 30]->id;
        $team[] = ['matter_id' => $matter->id, 'user_id' => $matter->lead_lawyer_id, 'role_in_matter' => 'lead', 'created_at' => $now, 'updated_at' => $now];
        if ($associate !== $matter->lead_lawyer_id) {
            $team[] = ['matter_id' => $matter->id, 'user_id' => $associate, 'role_in_matter' => 'associate', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (range(0, 2) as $p) {
            $partyName = $name($k * 3 + $p + 5000);
            $parties[] = ['matter_id' => $matter->id, 'role' => 'related', 'is_our_client' => false, 'name' => $partyName, 'name_normalized' => Normalizer::name($partyName), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (range(0, 4) as $d) {
            $group = ['A', 'B', 'C', 'D', 'B'][$d];
            $documents[] = ['matter_id' => $matter->id, 'group' => $group, 'title' => $documentTitles[($k + $d) % 8].' '.($k + 1), 'status' => 'internal_draft', 'version' => 1, 'uploader_type' => $userMorph, 'uploader_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now];
        }
    }
    foreach ([['matter_user', $team], ['matter_parties', $parties], ['documents', $documents]] as [$table, $rows]) {
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    DB::statement(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'ANALYZE TABLE matters, clients, matter_parties, documents, matter_user' : 'ANALYZE');

    $search = app(SearchMatters::class);
    $actors = ['admin' => $admin, 'luật sư' => $lawyers[3], 'kế toán' => $accountant];
    $terms = ['0147', 'VK-2026', 'Nguyễn Văn', 'nguyen van an', '4711/2026', 'khởi kiện 512', 'Ánh', 'zzqqxx'];

    $report = sprintf("\nSearchMatters trên %s: %d vụ, %d khách, %d bên, %d tài liệu (trung vị 5 lần, ms; [số dòng trả về%s])\n",
        DB::connection()->getDriverName(), Matter::query()->count(), DB::table('clients')->count(),
        DB::table('matter_parties')->count(), DB::table('documents')->count(), ', + = còn nữa');

    foreach ($actors as $label => $actor) {
        foreach ($terms as $term) {
            $times = [];
            foreach (range(1, 5) as $_) {
                $start = hrtime(true);
                $results = $search->handle($actor, $term);
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            $median = $times[2];
            $report .= sprintf("  %-8s %-16s %7.1f ms  [%d%s]\n", $label, $term, $median, count($results->matters), $results->truncated ? '+' : '');

            expect($median)->toBeLessThan(2000.0);
        }
    }

    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $explain = fn (string $sql, array $bindings): string => collect(DB::select('EXPLAIN '.$sql, $bindings))
            ->map(fn ($row) => sprintf('    %s: type=%s key=%s rows=%s', $row->table, $row->type, $row->key ?? '-', $row->rows))
            ->implode("\n");

        $report .= "\n  EXPLAIN số thụ lý (tiền tố) đứng riêng:\n".$explain("SELECT id FROM matters WHERE case_number LIKE ? ESCAPE '!'", ['4711/2026%']);
        $report .= "\n  EXPLAIN tiêu đề (chứa) đứng riêng:\n".$explain("SELECT id FROM matters WHERE title LIKE ? ESCAPE '!'", ['%khởi%']);

        // Câu THẬT của trang: sáu nguồn trong một OR, sau luật hiển thị.
        foreach (['admin' => $admin, 'luật sư' => $lawyers[3]] as $label => $actor) {
            $query = $search->matching($actor, '4711/2026');
            $report .= "\n  EXPLAIN câu sáu nguồn của SearchMatters::matching() ({$label}):\n".$explain($query->toSql(), $query->getBindings());
        }

        $report .= "\n";
    }

    fwrite(STDERR, $report);
});
