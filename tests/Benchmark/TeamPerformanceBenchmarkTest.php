<?php

use App\Actions\Performance\BuildPerformanceReport;
use App\Actions\Performance\BuildPerformanceTrend;
use App\Actions\Performance\BuildTeamWorkload;
use App\Actions\Schedule\CapturePerformanceSnapshots;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Filament\Admin\Widgets\Performance\OverdueTrendWidget;
use App\Filament\Admin\Widgets\Performance\StaleTrendWidget;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PerformanceSnapshot;
use App\Models\User;
use App\Support\BusinessHours;
use App\Support\Performance\DeadlineHolderAtDue;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\RequestHolderAt;
use App\Support\Performance\TeamRoster;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
 * Task 5 đo trang một người qua Livewire (≤ 200 ms; luật sư xem chính mình, trưởng phòng xem một luật
 * sư và một trợ lý — kèm số truy vấn và các truy vấn chậm nhất). Task 7 thêm ảnh chụp hai năm (30 người,
 * cả hai loại dòng), tác vụ chụp (≤ 10 giây), trang một người CÓ biểu đồ (trang + hai widget xu hướng,
 * ≤ 200 ms) và cột P8 của trang hiệu suất, kèm `EXPLAIN` của hai truy vấn ảnh chụp. Phép đo "Hiệu suất
 * theo kỳ", một quý (≤ 500 ms), là của Task 8 (phán quyết controller cho Task 7–8).
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
            // Task 8: 30% đã kết thúc là `i % 10` ∈ {1, 2, 3}, không phải {0, 1, 2} — vụ `restricted`
            // (`i % 20 === 0`) nay còn mở, nên tác vụ chụp ghi cả dòng `restricted` (rà soát Task 7, m6).
            'closed_at' => $i % 10 >= 1 && $i % 10 <= 3 ? $day(-($i % 300)) : null,
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
            // Task 8: văn phòng trả lời 0–95 giờ SAU khi khách gửi (trước đây hai cột rải độc lập, nên có luồng
            // "trả lời" 300 ngày sau hoặc trước cả lúc gửi — thời gian phản hồi giờ làm việc đếm từng ngày).
            $sentAgo = (($k + $r) % 300) * 1440 + 600;
            $requests[] = [
                'matter_id' => $matter->id, 'client_user_id' => $clientUserIds[$k % count($clientUserIds)], 'subject' => "Yêu cầu {$r}",
                'content' => 'Nội dung đo', 'status' => $status, 'assigned_to' => $k % 10 === 0 ? $assistant : null,
                'answered_at' => in_array($status, ['answered', 'closed'], true) ? $ago(max(0, $sentAgo - (($k * 7 + $r) % 96) * 60)) : null,
                'last_activity_at' => $stamp, 'created_at' => $ago($sentAgo), 'updated_at' => $stamp,
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

    // Task 7 — ảnh chụp hai năm (730 ngày tới hôm qua) cho 30 người: dòng `normal` cho mọi người mỗi ngày,
    // dòng `restricted` cho 20 luật sư (5% vụ là restricted, nên luật sư nào cũng có) — 36.500 dòng.
    $snapshots = [];
    $lawyerIds = $lawyers->pluck('id')->flip();
    foreach (range(1, 730) as $back) {
        $capturedOn = PerformanceSnapshot::storedDay(today()->subDays($back));
        foreach ($staff as $s => $person) {
            $levels = $lawyerIds->has($person->id) ? ['normal', 'restricted'] : ['normal'];
            foreach ($levels as $level) {
                $snapshots[] = [
                    'captured_on' => $capturedOn, 'user_id' => $person->id, 'confidentiality' => $level,
                    'open_lead_matters' => ($s + $back) % 40, 'stale_matters' => ($s * 3 + $back) % 9, 'overdue_deadlines' => ($s + $back * 7) % 12,
                    'checklist_settled' => ($s + $back) % 50, 'checklist_total' => 50 + $s, 'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
        }
        if (count($snapshots) >= 2000) {
            $insert('performance_snapshots', $snapshots, 2000);
            $snapshots = [];
        }
    }
    $insert('performance_snapshots', $snapshots, 2000);

    $mariadb = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    DB::statement($mariadb
        ? 'ANALYZE TABLE matters, matter_user, deadlines, stage_logs, client_requests, client_request_replies, matter_checklist_items, documents, contracts, instalments, payments, activity_log, performance_snapshots'
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
    // Rà soát cuối làn, I1 — bộ nhớ tạm R11 (`PerformanceCache`) trên ĐÚNG kho của production (`database`,
    // TTL 300 giây). "Lạnh" xoá kho trước MỖI lần (ngoài phép đo): lần mở đầu tiên của một người xem trong
    // 5 phút. "Ấm" là các lần sau, trong TTL. Hai con số in cạnh nhau (Ghi chú M13).
    config(['cache.default' => 'database', 'vkcrm.performance.cache_seconds' => 300]);
    $medianCold = function (callable $run): float {
        $times = [];
        foreach (range(1, 5) as $_) {
            Cache::flush();
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

    // Task 5 — trang của một người, qua Livewire: mount (cổng người, nhật ký `performance_viewed` khi
    // xem người khác) + lần vẽ đầu (dòng BuildTeamWorkload có N11, cơ cấu lĩnh vực, ba danh sách, trang
    // đầu của bảng "Vụ việc"). Ngân sách 200 ms (R11). Kèm số truy vấn mỗi lần vẽ và sáu truy vấn chậm
    // nhất (trung vị 5 lần).
    $memberSlow = [];
    $memberPages = [
        'luật sư xem chính mình' => [$lawyers[3], $lawyers[3]],
        'trưởng phòng xem một luật sư' => [$manager, $lawyers[3]],
        'admin xem một luật sư' => [$admin, $lawyers[3]],
        'trưởng phòng xem một trợ lý' => [$manager, $assistants[0]],
    ];
    foreach ($memberPages as $label => [$viewer, $subject]) {
        $this->actingAs($viewer->fresh(), 'web');
        Livewire::test(TeamMember::class, ['user' => $subject->getKey()]); // làm nóng

        $ms = $median(fn () => Livewire::test(TeamMember::class, ['user' => $subject->getKey()]));
        $coldMs = $medianCold(fn () => Livewire::test(TeamMember::class, ['user' => $subject->getKey()]));
        $report .= sprintf("  Trang của một người (Livewire, %s): lạnh %.1f ms, ấm %.1f ms — ngân sách 200 ms\n", $label, $coldMs, $ms);
        expect($coldMs)->toBeLessThan(3000.0);

        // Truy vấn của lần mở LẠNH (kho tạm trống): đúng các truy vấn gộp mà EXPLAIN bên dưới cần.
        $runs = [];
        foreach (range(1, 5) as $_) {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(TeamMember::class, ['user' => $subject->getKey()]);
            $runs[] = DB::getQueryLog();
            DB::disableQueryLog();
        }
        $slowest = [];
        foreach (array_keys($runs[0]) as $q) {
            $times = array_map(fn (array $log): float => (float) ($log[$q]['time'] ?? 0), $runs);
            sort($times);
            $slowest[$q] = $times[2];
        }
        arsort($slowest);
        $report .= sprintf("    %d truy vấn mỗi lần mở trang, tổng %.1f ms; chậm nhất:\n", count($runs[0]), array_sum($slowest));
        foreach (array_slice($slowest, 0, 6, true) as $q => $queryMs) {
            $report .= sprintf("      #%-2d %7.1f ms  %s\n", $q, $queryMs, mb_strimwidth(preg_replace('/\s+/', ' ', $runs[0][$q]['query']), 0, 110, '…'));
            $memberSlow[$label][$q] = ['sql' => $runs[0][$q]['query'], 'bindings' => $runs[0][$q]['bindings'], 'ms' => $queryMs];
        }
    }

    // Task 7 — trang một người CÓ biểu đồ: hai widget xu hướng là component Livewire riêng (tải lười, mỗi
    // widget một request), nên đo trang, từng widget, và tổng "trang + hai widget". Ngân sách 200 ms (R11).
    $trendWidgets = [StaleTrendWidget::class, OverdueTrendWidget::class];
    $trendQueries = [];
    foreach (['trưởng phòng xem một luật sư' => [$manager, $lawyers[3]], 'luật sư xem chính mình' => [$lawyers[3], $lawyers[3]]] as $label => [$viewer, $subject]) {
        $this->actingAs($viewer->fresh(), 'web');
        foreach ($trendWidgets as $widget) {
            Livewire::test($widget, ['subjectId' => $subject->getKey()]); // làm nóng
        }

        $widgetMs = array_map(fn (string $widget): float => $median(fn () => Livewire::test($widget, ['subjectId' => $subject->getKey()])), $trendWidgets);
        $wholeRun = function () use ($subject, $trendWidgets): void {
            Livewire::test(TeamMember::class, ['user' => $subject->getKey()]);
            foreach ($trendWidgets as $widget) {
                Livewire::test($widget, ['subjectId' => $subject->getKey()]);
            }
        };
        $whole = $median($wholeRun);
        $wholeCold = $medianCold($wholeRun);
        $report .= sprintf("  Trang của một người CÓ biểu đồ (%s): trang + hai widget lạnh %.1f ms, ấm %.1f ms — ngân sách 200 ms; StaleTrendWidget %.1f ms, OverdueTrendWidget %.1f ms\n", $label, $wholeCold, $whole, $widgetMs[0], $widgetMs[1]);
        expect($wholeCold)->toBeLessThan(3000.0);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BuildPerformanceTrend::class)->handle($viewer->fresh(), $subject->fresh(), PerformancePeriod::trailingDays(BuildPerformanceTrend::MEMBER_PAGE_DAYS));
        $trendQueries["xu hướng 90 ngày ({$label})"] = collect(DB::getQueryLog())->last(fn (array $query): bool => str_contains($query['query'], 'performance_snapshots'));
        DB::disableQueryLog();
    }

    // Task 7 — cột P8 của "Hiệu suất theo kỳ": đầu kỳ → cuối kỳ, MỘT truy vấn cho cả trang (quý trước).
    $quarter = PerformancePeriod::fromFilters(['period' => 'last_quarter']);
    $viewer = $manager->fresh();
    $quarterSubjects = TeamRoster::subjectsForPeriod($viewer, $quarter);
    app(BuildPerformanceTrend::class)->endpoints($viewer, $quarterSubjects, $quarter); // làm nóng
    $endpointsMs = $median(fn () => app(BuildPerformanceTrend::class)->endpoints($viewer, $quarterSubjects, $quarter));
    $report .= sprintf("  Cột P8 (BuildPerformanceTrend::endpoints, quý trước, trưởng phòng, %d người): %.1f ms\n", $quarterSubjects->count(), $endpointsMs);
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(BuildPerformanceTrend::class)->endpoints($viewer, $quarterSubjects, $quarter);
    $trendQueries['P8 cả trang'] = collect(DB::getQueryLog())->last(fn (array $query): bool => str_contains($query['query'], 'performance_snapshots'));
    DB::disableQueryLog();

    // Task 7 — tác vụ chụp trên 3.000 vụ (ngân sách 10 giây, R11): chạy lại trong ngày là upsert.
    $capture = app(CapturePerformanceSnapshots::class);
    $written = $capture->handle(); // làm nóng
    $captureMs = $median(fn () => $capture->handle());
    $report .= sprintf("  Tác vụ chụp (CapturePerformanceSnapshots, %d dòng, %d dòng ảnh chụp trong bảng): %.1f ms — ngân sách 10 giây\n", $written, DB::table('performance_snapshots')->count(), $captureMs);
    expect($captureMs)->toBeLessThan(30000.0);

    // Task 8 — "Hiệu suất theo kỳ", MỘT QUÝ (quý trước), ngân sách 500 ms (R11), cho ba loại người xem.
    // (a) `BuildPerformanceReport::handle()` riêng; (b) cả request đổi kỳ của trang qua Livewire: trang mở ở
    // kỳ mặc định (tháng trước) NGOÀI phép đo, rồi đo đúng request "chọn Quý trước → Áp dụng" (hydrate,
    // ghi `performance_viewed`, dựng báo cáo quý, P8, vẽ bảng). Kèm số truy vấn của báo cáo quý và tập việc
    // đã nạp (mốc, yêu cầu của kỳ) để thấy phần PHP tính trên bao nhiêu dòng.
    $reportQueries = [];
    foreach (['admin' => $admin, 'trưởng phòng' => $manager, 'luật sư (dòng của mình)' => $lawyers[3]] as $label => $viewer) {
        $viewer = $viewer->fresh();
        $subjects = TeamRoster::subjectsForPeriod($viewer, $quarter);
        app(BuildPerformanceReport::class)->handle($viewer, $subjects, $quarter); // làm nóng

        $reportMs = $median(fn () => app(BuildPerformanceReport::class)->handle($viewer, $subjects, $quarter));

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BuildPerformanceReport::class)->handle($viewer, $subjects, $quarter);
        $reportQueries[$label] = DB::getQueryLog();
        DB::disableQueryLog();

        $this->actingAs($viewer, 'web');
        $quarterRequest = function (bool $cold): array {
            $times = [];
            foreach (range(1, 5) as $_) {
                // Mở trang và chọn "Quý trước" ở ô kỳ (mỗi việc một request, ngoài phép đo); đo request "Xem số liệu".
                // Lạnh: kho tạm xoá ngay trước request đo; ấm: báo cáo quý đã có trong kho từ lần trước.
                $page = Livewire::test(Performance::class)->fillForm(['period' => 'last_quarter']);
                if ($cold) {
                    Cache::flush();
                }
                $start = hrtime(true);
                $page->call('applyPeriod');
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            expect($page->instance()->period()->key)->toBe('last_quarter');

            return $times;
        };
        $times = $quarterRequest(true);
        $warmTimes = $quarterRequest(false);

        // Lần mở trang ở kỳ MẶC ĐỊNH (tháng trước): mount + ghi nhật ký + báo cáo một tháng + vẽ bảng.
        Livewire::test(Performance::class); // làm nóng
        $monthMs = $median(fn () => Livewire::test(Performance::class));
        $monthCold = $medianCold(fn () => Livewire::test(Performance::class));

        $report .= sprintf("  \"Hiệu suất theo kỳ\", quý trước (%s, %d người): BuildPerformanceReport %.1f ms, %d truy vấn; request đổi kỳ (Livewire) lạnh %.1f ms, ấm %.1f ms — ngân sách 500 ms; mở trang ở kỳ mặc định (tháng trước) lạnh %.1f ms, ấm %.1f ms\n",
            $label, $subjects->count(), $reportMs, count($reportQueries[$label]), $times[2], $warmTimes[2], $monthCold, $monthMs);
        expect($times[2])->toBeLessThan(60000.0);
    }
    $lastMonthBounds = PerformancePeriod::fromFilters(['period' => 'last_month'])->bounds();
    $report .= sprintf("    tập của tháng trước: %d mốc đến hạn, %d yêu cầu khách gửi (cả văn phòng)\n",
        DB::table('deadlines')->whereBetween('due_date', $lastMonthBounds)->count(),
        DB::table('client_requests')->whereBetween('created_at', $lastMonthBounds)->count());
    // Phân rã phần PHP của báo cáo quý (trưởng phòng): cùng các scope và bộ dựng lịch sử mà
    // `BuildPerformanceReport` gọi, đo từng khúc — để biết thời gian nằm ở SQL hay ở PHP.
    $viewer = $manager->fresh();
    $visible = fn ($matters) => $matters->listableBy($viewer);
    $parts = [
        'nạp mốc của quý (+ vụ)' => fn () => Deadline::query()->dueBetween($quarter->bounds())->whereHas('matter', $visible)->with('matter:id,closed_at,deleted_at')->get(),
        'nạp yêu cầu của quý (+ vụ)' => fn () => ClientRequest::query()->createdBetween($quarter->bounds())->whereHas('matter', $visible)->with('matter:id,lead_lawyer_id')->get(),
    ];
    $loaded = [];
    foreach ($parts as $label => $run) {
        $loaded[$label] = $run();
        $report .= sprintf("    %-30s %7.1f ms (%d dòng)\n", $label, $median($run), $loaded[$label]->count());
    }
    $quarterDeadlines = $loaded['nạp mốc của quý (+ vụ)'];
    $quarterRequests = $loaded['nạp yêu cầu của quý (+ vụ)'];
    $cutoff = $quarter->cutoff();
    $hoursOf = BusinessHours::fromConfig();
    foreach ([
        'DeadlineHolderAtDue::resolve' => fn () => DeadlineHolderAtDue::resolve($quarterDeadlines),
        'Deadline::outcomeAt (mọi mốc)' => fn () => $quarterDeadlines->each(fn (Deadline $d) => $d->outcomeAt($cutoff)),
        'RequestHolderAt::resolve' => fn () => RequestHolderAt::resolve($quarterRequests, fn (ClientRequest $r) => $r->answeredBy($cutoff) ? $r->answered_at : $cutoff),
        'BusinessHours (mọi luồng đã trả lời)' => fn () => $quarterRequests->each(fn (ClientRequest $r) => $r->answeredBy($cutoff) ? $hoursOf->minutesBetween($r->created_at, $r->answered_at) : null),
    ] as $label => $run) {
        $report .= sprintf("    %-30s %7.1f ms\n", $label, $median($run));
    }
    $report .= sprintf("    tập của quý: %d mốc đến hạn, %d yêu cầu khách gửi (cả văn phòng, trước listableBy)\n",
        DB::table('deadlines')->whereBetween('due_date', $quarter->bounds())->count(),
        DB::table('client_requests')->whereBetween('created_at', $quarter->bounds())->count());

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

        foreach ($trendQueries as $label => $query) {
            $report .= sprintf("\n    EXPLAIN %s:\n%s", $label, $explain($query['query'], $query['bindings']));
        }

        // Task 8 — EXPLAIN của mọi truy vấn đọc của báo cáo quý (trưởng phòng) và của sáu truy vấn chậm
        // nhất trên trang một người (trưởng phòng xem một luật sư): bằng chứng cho quyết định index (R11).
        foreach ($reportQueries['trưởng phòng'] as $q => $query) {
            if (! str_starts_with(strtolower(ltrim($query['query'])), 'select')) {
                continue;
            }
            $report .= sprintf("\n    EXPLAIN báo cáo quý #%d (%.1f ms, %s):\n%s", $q, $query['time'], mb_strimwidth(preg_replace('/\s+/', ' ', $query['query']), 0, 90, '…'), $explain($query['query'], $query['bindings']));
        }
        foreach ($memberSlow['trưởng phòng xem một luật sư'] as $q => $query) {
            if (! str_starts_with(strtolower(ltrim($query['sql'])), 'select')) {
                continue;
            }
            $report .= sprintf("\n    EXPLAIN trang một người #%d (%.1f ms, %s):\n%s", $q, $query['ms'], mb_strimwidth(preg_replace('/\s+/', ' ', $query['sql']), 0, 90, '…'), $explain($query['sql'], $query['bindings']));
        }

        $report .= "\n";
    }

    fwrite(STDERR, $report);
});
