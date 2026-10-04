<?php

use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Performance\TeamRoster;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * M13 — ĐO các trang theo dõi đội ngũ trên dữ liệu nhân lên (kế hoạch M13, R11). Không phải một test
 * hành vi, và KHÔNG chạy trong bộ thường: `tests/Benchmark` không nằm trong testsuite nào của
 * `phpunit.xml`. Chạy tay, trên MariaDB thật (SQLite trong bộ nhớ không nói gì về máy chủ), tuần tự:
 *
 *     /d/vkwt/m13-dev test:mariadb tests/Benchmark/TeamPerformanceBenchmarkTest.php
 *
 * Dữ liệu (R11) chèn thẳng bằng `DB::table()->insert()` theo lô, như `SearchMattersBenchmarkTest`:
 * 3.000 vụ (5% `restricted`, 30% đã kết thúc), 30 nhân sự (20 luật sư, 9 trợ lý, 1 quản lý), 15.000
 * mốc, 45.000 dòng tiến độ, 6.000 yêu cầu cùng 15.000 trả lời, 30.000 đầu mục, 20.000 tài liệu nhóm
 * A, 150.000 dòng nhật ký, 9.000 khoản thu.
 *
 * Task 4 đo "Theo dõi đội ngũ" (ngân sách: trung vị 5 lần ≤ 300 ms với trưởng phòng) và riêng truy
 * vấn gộp N11 (quá 150 ms thì cột N11 rời trang tổng quan, chỉ còn trên trang của một người — phán
 * quyết ghi PROGRESS). Lần đo đầu (2026-10-04) cho N11 gộp 297,6 ms, nên phán quyết N11 áp dụng: trang
 * tổng quan không hỏi N11, trang của một người hỏi N11 cho một người — tệp này đo cả ba hình dạng.
 * Task 5 thêm trang một người (≤ 200 ms), Task 6/7 (sau khi gộp làn m13b) thêm
 * "Hiệu suất theo kỳ", một quý (≤ 500 ms), Task 7 thêm ảnh chụp hai năm và tác vụ chụp (≤ 10 giây).
 *
 * Kết quả và `EXPLAIN` của MỌI truy vấn mà `BuildTeamWorkload` chạy (bắt qua query log) in ra STDERR;
 * số đo ghi vào "Ghi chú M13" của PROGRESS. Khẳng định duy nhất là một trần rộng để lần chạy báo lỗi
 * nếu có gì hỏng hẳn, không để đo hiệu năng tinh.
 */
it('đo trang "Theo dõi đội ngũ" trên 3.000 vụ', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Admin Đo']);
    $manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Đo']);
    $lawyers = User::factory()->count(20)->withRole(Role::Lawyer)->create()->values();
    $assistants = User::factory()->count(9)->withRole(Role::Assistant)->create()->values();
    $types = MatterType::factory()->count(5)->withStages()->create()->load('stages');

    $now = now();
    $stamp = $now->toDateTimeString();
    $insert = function (string $table, array $rows, int $chunk = 1000): void {
        foreach (array_chunk($rows, $chunk) as $part) {
            DB::table($table)->insert($part);
        }
    };
    $ago = fn (int $minutes): string => $now->copy()->subMinutes($minutes)->toDateTimeString();
    $day = fn (int $days): string => $now->copy()->addDays($days)->toDateString();

    // Khách và tài khoản cổng (yêu cầu của khách cần client_user_id).
    $clients = [];
    foreach (range(1, 1500) as $i) {
        $clients[] = ['code' => sprintf('KHD-%05d', $i), 'type' => 'individual', 'name' => "Khách đo {$i}", 'created_at' => $stamp, 'updated_at' => $stamp];
    }
    $insert('clients', $clients);
    $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();

    $clientUsers = [];
    foreach ($clientIds as $k => $clientId) {
        $clientUsers[] = ['client_id' => $clientId, 'name' => "Tài khoản đo {$k}", 'email' => "kh{$k}@do.test", 'password' => 'x', 'activated_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp];
    }
    $insert('client_users', $clientUsers);
    $clientUserIds = DB::table('client_users')->orderBy('id')->pluck('id')->all();

    // 3.000 vụ: 5% restricted, 30% đã kết thúc, 80% đã bật cổng, cập nhật cho khách rải 0–40 ngày.
    $matters = [];
    foreach (range(1, 3000) as $i) {
        $type = $types[$i % 5];
        $matters[] = [
            'code' => sprintf('VK-2026-%s-%04d', $type->code, $i),
            'client_id' => $clientIds[$i % count($clientIds)],
            'matter_type_id' => $type->id,
            'title' => "Vụ đo {$i}",
            'stage' => $type->stages->first()->key,
            'stage_entered_at' => $ago(($i % 60) * 1440),
            'last_client_update_at' => $i % 7 === 0 ? null : $ago(($i % 40) * 1440),
            'lead_lawyer_id' => $lawyers[$i % 20]->id,
            'opened_at' => $day(-400 + $i % 300),
            'closed_at' => $i % 10 < 3 ? $day(-($i % 300)) : null,
            'is_published_to_portal' => $i % 5 !== 0,
            'confidentiality' => $i % 20 === 0 ? 'restricted' : 'normal',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }
    $insert('matters', $matters, 500);
    $matterRows = DB::table('matters')->orderBy('id')->get(['id', 'lead_lawyer_id']);

    $team = $deadlines = $stageLogs = $requests = $items = [];
    foreach ($matterRows as $k => $matter) {
        $associate = $lawyers[($k + 7) % 20]->id;
        $assistant = $assistants[$k % 9]->id;
        $team[] = ['matter_id' => $matter->id, 'user_id' => $matter->lead_lawyer_id, 'role_in_matter' => 'lead', 'created_at' => $stamp, 'updated_at' => $stamp];
        if ($associate !== $matter->lead_lawyer_id) {
            $team[] = ['matter_id' => $matter->id, 'user_id' => $associate, 'role_in_matter' => $k % 4 === 0 ? 'observer' : 'associate', 'created_at' => $stamp, 'updated_at' => $stamp];
        }
        $team[] = ['matter_id' => $matter->id, 'user_id' => $assistant, 'role_in_matter' => 'assistant', 'created_at' => $stamp, 'updated_at' => $stamp];

        foreach (range(0, 4) as $d) {
            $done = ($k + $d) % 5 < 3;
            $deadlines[] = [
                'matter_id' => $matter->id, 'name' => "Mốc {$d}", 'due_date' => $day((($k * 7 + $d * 13) % 120) - 60),
                'severity' => $d === 0 ? 'critical' : 'normal', 'responsible_user_id' => $d === 4 ? $assistant : $matter->lead_lawyer_id,
                'is_completed' => $done, 'completed_at' => $done ? $ago((($k + $d) % 90) * 1440) : null,
                'is_published' => false, 'reminders_sent' => '[]', 'created_at' => $ago(200 * 1440), 'updated_at' => $stamp,
            ];
        }

        foreach (range(0, 14) as $s) {
            $stageLogs[] = [
                'matter_id' => $matter->id, 'from_stage' => null, 'to_stage' => null, 'occurred_at' => $ago((($k + $s * 11) % 365) * 1440),
                'internal_note' => 'Ghi chú đo', 'is_published' => $s % 3 === 0, 'created_by' => $matter->lead_lawyer_id,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ];
        }

        foreach (range(0, 1) as $r) {
            $status = ['new', 'in_progress', 'answered', 'closed'][($k + $r) % 4];
            $requests[] = [
                'matter_id' => $matter->id, 'client_user_id' => $clientUserIds[$k % count($clientUserIds)], 'subject' => "Yêu cầu {$r}",
                'content' => 'Nội dung đo', 'status' => $status, 'assigned_to' => $k % 10 === 0 ? $assistant : null,
                'answered_at' => in_array($status, ['answered', 'closed'], true) ? $ago((($k + $r) % 200) * 1440) : null,
                'last_activity_at' => $stamp, 'created_at' => $ago((($k + $r) % 300) * 1440 + 600), 'updated_at' => $stamp,
            ];
        }

        foreach (range(0, 9) as $c) {
            $items[] = [
                'matter_id' => $matter->id, 'name' => "Giấy tờ {$c}", 'is_required' => $c % 10 < 7, 'sort_order' => $c,
                'status' => ['missing', 'pending_review', 'accepted', 'rejected', 'not_applicable', 'accepted', 'missing', 'accepted', 'pending_review', 'accepted'][($k + $c) % 10],
                'rejection_reason' => ($k + $c) % 10 === 3 ? 'Ảnh chụp mờ, đề nghị nộp lại' : null,
                'created_at' => $ago((($k + $c) % 60) * 1440), 'updated_at' => $stamp,
            ];
        }
    }
    $insert('matter_user', $team);
    $insert('deadlines', $deadlines);
    $insert('stage_logs', $stageLogs);
    $insert('client_requests', $requests);
    $insert('matter_checklist_items', $items);

    $requestRows = DB::table('client_requests')->orderBy('id')->get(['id', 'matter_id']);
    $replies = [];
    foreach ($requestRows as $k => $request) {
        foreach (range(0, $k % 2 === 0 ? 2 : 1) as $_) {
            $replies[] = ['request_id' => $request->id, 'author_type' => 'user', 'author_id' => $lawyers[$k % 20]->id, 'content' => 'Trả lời đo', 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    $insert('client_request_replies', array_slice($replies, 0, 15000));

    // 20.000 tài liệu nhóm A, gắn vào đầu mục (hai phần ba số đầu mục).
    $itemRows = DB::table('matter_checklist_items')->orderBy('id')->get(['id', 'matter_id']);
    $documents = [];
    foreach ($itemRows as $k => $item) {
        if ($k % 3 !== 2) {
            $documents[] = ['matter_id' => $item->matter_id, 'matter_checklist_item_id' => $item->id, 'group' => 'A', 'title' => "Tài liệu khách {$k}", 'status' => 'internal_draft', 'version' => 1, 'uploader_type' => 'user', 'uploader_id' => $admin->id, 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    $insert('documents', array_slice($documents, 0, 20000));

    // Tiền: 1.500 hợp đồng, 4.500 đợt, 9.000 khoản thu.
    $contracts = [];
    foreach ($matterRows->take(1500) as $k => $matter) {
        $contracts[] = ['matter_id' => $matter->id, 'code' => sprintf('HD-%05d', $k), 'status' => 'active', 'billing_model' => 'fixed_fee', 'total_amount' => 30_000_000, 'signed_at' => $day(-300), 'created_at' => $stamp, 'updated_at' => $stamp];
    }
    $insert('contracts', $contracts);
    $contractRows = DB::table('contracts')->orderBy('id')->get(['id', 'matter_id']);
    $leadOf = $matterRows->pluck('lead_lawyer_id', 'id');
    $instalments = [];
    foreach ($contractRows as $contract) {
        foreach (range(1, 3) as $n) {
            $instalments[] = ['contract_id' => $contract->id, 'sequence' => $n, 'name' => "Đợt {$n}", 'amount' => 10_000_000, 'trigger_type' => 'due_date', 'due_date' => $day(-200 + $n * 30), 'status' => 'pending', 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    $insert('instalments', $instalments);
    $payments = [];
    foreach (DB::table('instalments')->join('contracts', 'contracts.id', '=', 'instalments.contract_id')->orderBy('instalments.id')->get(['instalments.id', 'contracts.matter_id']) as $k => $instalment) {
        foreach (range(0, 1) as $p) {
            $payments[] = ['instalment_id' => $instalment->id, 'amount' => 5_000_000, 'paid_on' => $day(-(($k + $p * 17) % 300)), 'method' => 'bank_transfer', 'attributed_lawyer_id' => $leadOf[$instalment->matter_id], 'created_at' => $stamp, 'updated_at' => $stamp];
        }
    }
    $insert('payments', $payments);

    // 150.000 dòng nhật ký: vụ, mốc, đầu mục, yêu cầu, khoản thu, tài liệu, và dòng đăng nhập không thuộc vụ nào.
    $staff = $lawyers->concat($assistants)->push($manager)->values();
    $deadlineIds = DB::table('deadlines')->orderBy('id')->pluck('id')->all();
    $itemIds = $itemRows->pluck('id')->all();
    $requestIds = $requestRows->pluck('id')->all();
    $paymentIds = DB::table('payments')->orderBy('id')->pluck('id')->all();
    $documentIds = DB::table('documents')->orderBy('id')->pluck('id')->all();
    $matterIds = $matterRows->pluck('id')->all();
    $activity = [];
    foreach (range(0, 149_999) as $a) {
        [$type, $ids, $event] = match ($a % 7) {
            0 => ['matter', $matterIds, 'matter_reassigned'],
            1 => ['deadline', $deadlineIds, 'deadline_added'],
            2 => ['matter_checklist_item', $itemIds, 'checklist_item_reviewed'],
            3 => ['client_request', $requestIds, 'client_request_assigned'],
            4 => ['payment', $paymentIds, 'payment_recorded'],
            5 => ['document', $documentIds, 'document_uploaded'],
            default => [null, null, 'login_success'],
        };
        $activity[] = [
            'log_name' => 'default', 'description' => $event, 'event' => $event,
            'subject_type' => $type, 'subject_id' => $ids === null ? null : $ids[$a % count($ids)],
            'causer_type' => 'user', 'causer_id' => $staff[$a % $staff->count()]->id,
            'properties' => '{}', 'created_at' => $ago(($a * 7) % (365 * 1440)), 'updated_at' => $stamp,
        ];
        if (count($activity) === 2000) {
            $insert('activity_log', $activity, 2000);
            $activity = [];
        }
    }
    $insert('activity_log', $activity, 2000);

    $mariadb = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    DB::statement($mariadb
        ? 'ANALYZE TABLE matters, matter_user, deadlines, stage_logs, client_requests, client_request_replies, matter_checklist_items, documents, contracts, instalments, payments, activity_log'
        : 'ANALYZE');

    $build = app(BuildTeamWorkload::class);
    $median = function (callable $run): float {
        $times = [];
        foreach (range(1, 5) as $_) {
            $start = hrtime(true);
            $run();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);

        return $times[2];
    };

    $report = sprintf("\nBuildTeamWorkload trên %s: %d vụ, %d nhân sự theo dõi, %d mốc, %d dòng tiến độ, %d yêu cầu, %d trả lời, %d đầu mục, %d tài liệu, %d dòng nhật ký, %d khoản thu (trung vị 5 lần, ms)\n",
        DB::connection()->getDriverName(), Matter::query()->count(), TeamRoster::members()->count(), DB::table('deadlines')->count(),
        DB::table('stage_logs')->count(), DB::table('client_requests')->count(), DB::table('client_request_replies')->count(),
        DB::table('matter_checklist_items')->count(), DB::table('documents')->count(), DB::table('activity_log')->count(), DB::table('payments')->count());

    // Năm hình dạng gọi: trang tổng quan (trưởng phòng, admin — không N11, phán quyết N11 của Task 4),
    // N11 gộp cho mọi người (hình dạng đã bị loại, đo để ghi lại vì sao), và trang của một người
    // (Task 5: luật sư xem chính mình, trưởng phòng xem một luật sư — có N11, giới hạn ở người đó).
    $one = new EloquentCollection([$lawyers[3]]);
    $scenarios = [
        'tổng quan, trưởng phòng' => [$manager, null, false],
        'tổng quan, admin' => [$admin, null, false],
        'N11 gộp mọi người (loại)' => [$manager, null, true],
        'một người, luật sư + N11' => [$lawyers[3], $one, true],
        'một người, TP + N11' => [$manager, $one, true],
    ];
    $isN11 = fn (array $query): bool => str_contains($query['sql'], 'activity_log') && str_contains(strtolower($query['sql']), 'max(');
    $perQuery = [];
    foreach ($scenarios as $label => [$viewer, $subjects, $withN11]) {
        $viewer = $viewer->fresh();
        $subjects ??= TeamRoster::subjectsFor($viewer);
        $build->handle($viewer, $subjects, $withN11); // làm nóng: nạp quyền, bộ nhớ đệm câu lệnh

        $ms = $median(fn () => $build->handle($viewer, $subjects, $withN11));
        $report .= sprintf("  BuildTeamWorkload  %-26s %7.1f ms  [%d người]\n", $label, $ms, $subjects->count());
        expect($ms)->toBeLessThan(3000.0);

        // Thời gian từng truy vấn (query log), trung vị 5 lần.
        $runs = [];
        foreach (range(1, 5) as $_) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $build->handle($viewer, $subjects, $withN11);
            $runs[] = DB::getQueryLog();
            DB::disableQueryLog();
        }
        foreach (array_keys($runs[0]) as $q) {
            $times = array_map(fn (array $log): float => (float) ($log[$q]['time'] ?? 0), $runs);
            sort($times);
            $perQuery[$label][$q] = ['sql' => $runs[0][$q]['query'], 'bindings' => $runs[0][$q]['bindings'], 'ms' => $times[2]];
        }
    }

    foreach ($perQuery as $label => $queries) {
        $report .= "\n  Từng truy vấn ({$label}):\n";
        foreach ($queries as $q => $query) {
            $report .= sprintf("    #%-2d %7.1f ms  %s%s\n", $q, $query['ms'], $isN11($query) ? '[N11] ' : '', mb_strimwidth(preg_replace('/\s+/', ' ', $query['sql']), 0, 110, '…'));
        }
    }

    $grouped = collect($perQuery['N11 gộp mọi người (loại)'])->first($isN11);
    $single = collect($perQuery['một người, TP + N11'])->first($isN11);
    $report .= sprintf("\n  N11 gộp cho mọi người (trưởng phòng): %.1f ms — ngưỡng 150 ms: %s\n", $grouped['ms'], $grouped['ms'] > 150 ? 'VƯỢT — N11 rời trang tổng quan (phán quyết N11)' : 'trong ngưỡng');
    $report .= sprintf("  N11 cho một người (trưởng phòng xem một luật sư): %.1f ms\n", $single['ms']);
    expect(collect($perQuery['tổng quan, trưởng phòng'])->first($isN11))->toBeNull();

    // Cả trang, qua Livewire, với trưởng phòng: mount (nhật ký performance_viewed) + dựng bảng.
    $this->actingAs($manager->fresh(), 'web');
    Livewire::test(TeamOverview::class);
    $page = $median(fn () => Livewire::test(TeamOverview::class));
    $report .= sprintf("  Trang \"Theo dõi đội ngũ\" (Livewire, trưởng phòng): %.1f ms — ngân sách 300 ms\n", $page);
    expect($page)->toBeLessThan(3000.0);

    if ($mariadb) {
        $explain = fn (string $sql, array $bindings): string => collect(DB::select('EXPLAIN '.$sql, $bindings))
            ->map(fn ($row) => sprintf('      %s: type=%s key=%s rows=%s%s', $row->table, $row->type, $row->key ?? '-', $row->rows, isset($row->Extra) && $row->Extra !== '' ? " ({$row->Extra})" : ''))
            ->implode("\n");

        foreach ($perQuery['tổng quan, trưởng phòng'] as $q => $query) {
            if (! str_starts_with(strtolower(ltrim($query['sql'])), 'select')) {
                continue;
            }
            $report .= sprintf("\n    EXPLAIN tổng quan #%d:\n%s", $q, $explain($query['sql'], $query['bindings']));
        }

        $report .= sprintf("\n    EXPLAIN N11 cho một người:\n%s", $explain($single['sql'], $single['bindings']));
        $report .= "\n";
    }

    fwrite(STDERR, $report);
});
