{{--
    Trang "Kết nối AI" của quản trị (M11 Task 15; làn ai 2026-10-15) — luật ở docblock
    `App\Filament\Admin\Pages\AiConnections` và các Action nó gọi; tệp này chỉ vẽ ra.

    Thứ tự (làn ai, như trang cài đặt AI của các CRM quốc tế): thẻ trạng thái tổng → các bước để bắt
    đầu dùng → mục pháp lý thu gọn → công tắc → bảng nhân sự → chi tiết một người → hoạt động của trợ
    lý AI → nhật ký MCP.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament, cùng
    thành ngữ `bulk-reassign.blade.php`. Không script nội tuyến (CSP của M8). Hai `<details>` mang
    `wire:ignore.self` để một lần vẽ lại của Livewire (lọc bảng, chọn kỳ) không đóng/mở thay người
    dùng; `wire:key` của mục pháp lý đổi theo trạng thái nên ghi hay xoá ngày vẫn đổi mặc định mở/đóng.
--}}
@php
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $warning = 'border-radius:0.75rem;padding:1rem;border:1px solid var(--warning-500);background-color:color-mix(in srgb, var(--warning-500) 12%, transparent);';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $heading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.5rem;';
    $subheading = 'font-size:1rem;font-weight:600;margin:1rem 0 0.5rem;';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $secondaryButton = 'min-height:44px;display:inline-flex;align-items:center;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);background:transparent;color:inherit;cursor:pointer;';
    $link = 'color:var(--primary-600);font-weight:600;text-decoration:underline;white-space:nowrap;';
    $table = 'width:100%;border-collapse:collapse;font-size:0.875rem;';
    $th = 'text-align:left;padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);font-weight:600;';
    $td = 'padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 15%, transparent);vertical-align:top;';
    $num = 'text-align:right;font-variant-numeric:tabular-nums;';

    // Thẻ trạng thái: xám khi tắt, xanh khi chỉ đọc, xanh đậm khi đọc và ghi; viền vàng khi thiếu hồ sơ.
    $status = $summary->status;
    $tone = match ($status) {
        \App\Enums\AiOfficeStatus::Off => 'gray',
        \App\Enums\AiOfficeStatus::ReadOnly => 'success',
        \App\Enums\AiOfficeStatus::ReadWrite => 'success',
    };
    $statusCard = 'border-radius:0.75rem;padding:1.25rem;border:1px solid '.($summary->needsAssessment() ? 'var(--warning-500)' : 'color-mix(in srgb, var(--'.$tone.'-500) 45%, transparent)')
        .';background-color:color-mix(in srgb, var(--'.$tone.'-500) '.($status === \App\Enums\AiOfficeStatus::ReadWrite ? '22' : '10').'%, transparent);';
    $dot = 'display:inline-block;width:0.75rem;height:0.75rem;border-radius:9999px;margin-right:0.5rem;vertical-align:middle;background-color:var(--'.$tone.'-'.($status === \App\Enums\AiOfficeStatus::ReadWrite ? '700' : '500').');';
    $stat = 'flex:1 1 9rem;border-radius:0.5rem;padding:0.75rem;background-color:color-mix(in srgb, var(--gray-500) 8%, transparent);';
    $statValue = 'display:block;font-size:1.5rem;font-weight:700;font-variant-numeric:tabular-nums;';

    $ratio = fn (int $done): string => $done.'/'.$summary->staffEnabled;
    $stepProgress = [
        'legal' => $filedOn === null ? null : __('ai_connections.setup.progress.filed', ['date' => $filedOn]),
        'staff_enabled' => __('ai_connections.setup.progress.people', ['count' => $summary->staffEnabled]),
        'staff_acknowledged' => __('ai_connections.setup.progress.ratio', ['done' => $summary->staffAcknowledged, 'total' => $summary->staffEnabled]),
        'staff_connected' => __('ai_connections.setup.progress.ratio', ['done' => $summary->staffConnected, 'total' => $summary->staffEnabled]),
        'matters' => __('ai_connections.setup.progress.matters', ['count' => $summary->mattersAllowed]),
    ];
    $stepLinks = [
        'legal' => '#viec-phap-ly',
        'server' => '#cong-tac',
        'staff_enabled' => '#nhan-su',
        'matters' => $mattersUrl,
    ];
@endphp

<x-filament-panels::page>
    <p style="max-width:48rem;">{{ __('ai_connections.admin.intro') }}</p>

    {{-- Làn ai — thẻ trạng thái tổng. --}}
    <section style="{{ $statusCard }}" data-ai-status="{{ $status->value }}" @if ($summary->needsAssessment()) data-ai-status-warning @endif>
        <p style="{{ $muted }}font-size:0.875rem;font-weight:600;margin:0 0 0.25rem;">{{ __('ai_connections.status.heading') }}</p>
        <h2 style="font-size:1.375rem;font-weight:700;margin:0;"><span style="{{ $dot }}" aria-hidden="true"></span>{{ $status->label() }}</h2>
        <p style="margin:0.25rem 0 0;">{{ $status->hint() }}</p>

        @if ($summary->needsAssessment())
            <p style="{{ $warning }}margin-top:0.75rem;padding:0.625rem 0.75rem;font-weight:600;">
                {{ __('ai_connections.status.assessment_missing') }}
                <a href="#viec-phap-ly" style="{{ $link }}">{{ __('ai_connections.setup.links.legal') }}</a>
            </p>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-top:1rem;">
            @foreach ([
                'enabled' => (string) $summary->staffEnabled,
                'acknowledged' => $ratio($summary->staffAcknowledged),
                'connected' => $ratio($summary->staffConnected),
                'calls' => (string) $summary->callsLastWeek,
            ] as $key => $value)
                <div style="{{ $stat }}">
                    <span style="{{ $statValue }}" data-ai-stat="{{ $key }}">{{ $value }}</span>
                    <span style="{{ $muted }}font-size:0.875rem;">{{ __('ai_connections.status.stats.'.$key) }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Làn ai — các bước để bắt đầu dùng, ✓/○ theo trạng thái thật. --}}
    <section style="{{ $card }}" data-ai-setup>
        <h2 style="{{ $heading }}">{{ __('ai_connections.setup.heading') }}</h2>
        <p style="{{ $muted }}">{{ __('ai_connections.setup.intro') }}</p>

        <ol style="margin:0.75rem 0 0;padding:0;list-style:none;display:grid;gap:0.5rem;">
            @foreach ($steps as $index => $step)
                @php($done = $summary->isDone($step))
                <li data-ai-step="{{ $step->value }}" data-done="{{ $done ? '1' : '0' }}"
                    style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem 0.75rem;padding:0.625rem 0.75rem;border-radius:0.5rem;background-color:color-mix(in srgb, var(--{{ $done ? 'success' : 'gray' }}-500) {{ $done ? '12' : '6' }}%, transparent);">
                    <span aria-hidden="true" style="flex:0 0 1.75rem;height:1.75rem;border-radius:9999px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;{{ $done ? 'background-color:var(--success-600);color:white;' : 'border:2px solid color-mix(in srgb, var(--gray-500) 60%, transparent);' }}">{{ $done ? '✓' : $index + 1 }}</span>
                    <span style="flex:1 1 16rem;">
                        <span style="font-weight:600;">{{ $step->label() }}</span>
                        <span style="{{ $muted }}font-size:0.875rem;">— {{ $done ? __('ai_connections.setup.done') : __('ai_connections.setup.todo') }}</span>
                        @if (isset($stepProgress[$step->value]))
                            <span style="display:block;{{ $muted }}font-size:0.875rem;" data-ai-step-progress="{{ $step->value }}">{{ $stepProgress[$step->value] }}</span>
                        @endif
                    </span>
                    @if (isset($stepLinks[$step->value]))
                        <a href="{{ $stepLinks[$step->value] }}" style="{{ $link }}" data-ai-step-link="{{ $step->value }}">{{ __('ai_connections.setup.links.'.$step->value) }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>

    {{-- 1. R12 mục 3: mục pháp lý thu gọn — mở sẵn tới khi quản trị ghi ngày đã nộp hồ sơ. --}}
    <details id="viec-phap-ly" style="{{ $filedOn === null ? $warning : $card }}"
             data-transfer-assessment="{{ $filedOn === null ? 'pending' : 'filed' }}"
             wire:key="transfer-assessment-{{ $filedOn === null ? 'pending' : 'filed' }}" wire:ignore.self
             @if ($filedOn === null) open @endif>
        <summary style="cursor:pointer;font-size:1.125rem;font-weight:700;">{{ __('ai_connections.legal.summary') }}</summary>

        <div style="margin-top:0.75rem;">
            @if ($filedOn === null)
                <h2 style="{{ $heading }}">{{ __('ai_connections.assessment.banner_title') }}</h2>
            @else
                <p>{{ __('ai_connections.assessment.filed_note', ['date' => $filedOn]) }}</p>
            @endif

            <p style="margin-top:0.5rem;">{{ __('ai_connections.assessment.banner_intro') }}</p>
            <ol style="margin:0.5rem 0 0.5rem 1.25rem;list-style:decimal;">
                @foreach (['transfer_assessment', 'client_consent', 'protection_officer', 'incident_procedure'] as $item)
                    <li style="margin-top:0.25rem;">{{ __('ai_connections.assessment.items.'.$item) }}</li>
                @endforeach
            </ol>
            @if ($filedOn === null)
                <p style="{{ $muted }}">{{ __('ai_connections.assessment.banner_hint') }}</p>
            @endif

            <form wire:submit="recordTransferAssessment" style="margin-top:0.75rem;max-width:24rem;">
                {{ $this->assessmentForm }}

                <button type="submit" style="{{ $secondaryButton }}margin-top:0.75rem;" wire:loading.attr="disabled" wire:target="recordTransferAssessment">
                    {{ __('ai_connections.assessment.save') }}
                </button>
            </form>
        </div>
    </details>

    {{-- 2. Hai công tắc toàn hệ thống — nhãn tiếng thường, hộp xác nhận nói hậu quả. --}}
    <section id="cong-tac" style="{{ $card }}" data-ai-switches>
        <h2 style="{{ $heading }}">{{ __('ai_connections.admin.switches.heading') }}</h2>

        {{ $this->switchesForm }}

        <div style="margin-top:1rem;">
            {{ $this->confirmSwitchesAction }}
        </div>

        <details data-ai-tech-details wire:ignore.self style="margin-top:1rem;{{ $muted }}font-size:0.875rem;">
            <summary style="cursor:pointer;font-weight:600;">{{ __('ai_connections.admin.switches.technical') }}</summary>
            <p style="margin-top:0.5rem;">{{ __('ai_connections.admin.switches.technical_keys', ['enabled' => \App\Support\Mcp\McpSwitches::ENABLED, 'write' => \App\Support\Mcp\McpSwitches::WRITE_ENABLED]) }}</p>
            <p style="margin-top:0.5rem;">{{ __('ai_connections.admin.switches.description') }}</p>
            <p style="margin-top:0.5rem;">{{ __('ai_connections.admin.switches.write_needs_enabled') }}</p>
        </details>
    </section>

    {{-- 3. Bảng nhân sự. --}}
    <div id="nhan-su">
        {{ $this->table }}
    </div>

    {{-- 4. Chi tiết một người. --}}
    @if ($selected !== null)
        <section style="{{ $card }}" data-ai-connections-detail>
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:0.5rem;">
                <h2 style="{{ $heading }}margin-bottom:0;">{{ __('ai_connections.admin.detail.heading', ['name' => $selected->name]) }}</h2>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    {{ $this->revokeAllConnectionsAction }}
                    <button type="button" style="{{ $secondaryButton }}" wire:click="closeConnections">
                        {{ __('ai_connections.admin.detail.close') }}
                    </button>
                </div>
            </div>

            @if ($connections === [])
                <p style="{{ $muted }}margin-top:0.75rem;">{{ __('ai_connections.admin.detail.empty') }}</p>
            @else
                <div style="overflow-x:auto;margin-top:0.75rem;">
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">{{ __('ai_connections.connections.platform') }}</th>
                                <th style="{{ $th }}">{{ __('ai_connections.connections.host') }}</th>
                                <th style="{{ $th }}">{{ __('ai_connections.connections.connected_at') }}</th>
                                <th style="{{ $th }}">{{ __('ai_connections.connections.last_used_at') }}</th>
                                <th style="{{ $th }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($connections as $connection)
                                <tr wire:key="connection-{{ $connection->clientId }}" data-connection="{{ $connection->clientId }}">
                                    <td style="{{ $td }}">{{ $connection->platform->label() }}</td>
                                    <td style="{{ $td }}"><code>{{ $connection->host }}</code></td>
                                    <td style="{{ $td }}">{{ $connection->connectedAt->format('d/m/Y H:i') }}</td>
                                    <td style="{{ $td }}">{{ $connection->lastUsedAt?->format('d/m/Y H:i') ?? __('ai_connections.connections.never_used') }}</td>
                                    <td style="{{ $td }}text-align:right;">{{ ($this->revokeConnectionAction)(['client' => $connection->clientId]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- Làn ai — hoạt động của trợ lý AI (đọc-chỉ, từ nhật ký M11). --}}
    <section style="{{ $card }}" data-ai-activity>
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:0.5rem;">
            <h2 style="{{ $heading }}margin-bottom:0;">{{ __('ai_connections.activity.heading') }}</h2>
            <div style="display:flex;gap:0.5rem;" role="group">
                @foreach (\App\Actions\Mcp\SummarizeMcpActivity::PERIODS as $period)
                    <button type="button" wire:click="showActivity({{ $period }})" data-ai-activity-period="{{ $period }}"
                            aria-pressed="{{ $activity->days === $period ? 'true' : 'false' }}"
                            style="{{ $activity->days === $period ? $button : $secondaryButton }}">
                        {{ __('ai_connections.activity.period', ['days' => $period]) }}
                    </button>
                @endforeach
            </div>
        </div>
        <p style="{{ $muted }}margin-top:0.5rem;">{{ __('ai_connections.activity.intro') }}</p>

        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-top:0.75rem;">
            <div style="{{ $stat }}">
                <span style="{{ $statValue }}" data-ai-activity-total="calls">{{ $activity->calls }}</span>
                <span style="{{ $muted }}font-size:0.875rem;">{{ __('ai_connections.activity.calls') }}</span>
            </div>
            @foreach (\App\Enums\McpToolOutcome::cases() as $outcome)
                <div style="{{ $stat }}">
                    <span style="{{ $statValue }}" data-ai-activity-outcome="{{ $outcome->value }}">{{ $activity->outcomes[$outcome->value] ?? 0 }}</span>
                    <span style="{{ $muted }}font-size:0.875rem;">{{ $outcome->label() }}</span>
                </div>
            @endforeach
            <div style="{{ $stat }}">
                <span style="{{ $statValue }}" data-ai-activity-total="pending_drafts">{{ $activity->pendingDrafts }}</span>
                <span style="{{ $muted }}font-size:0.875rem;">{{ __('ai_connections.activity.pending_drafts') }}</span>
            </div>
        </div>
        <p style="{{ $muted }}font-size:0.875rem;margin-top:0.5rem;">{{ __('ai_connections.activity.refused_hint') }}</p>
        <p style="{{ $muted }}font-size:0.875rem;margin-top:0.25rem;">{{ __('ai_connections.activity.pending_drafts_hint') }}</p>

        @if ($activity->calls === 0)
            <p style="{{ $muted }}margin-top:0.75rem;">{{ __('ai_connections.activity.empty', ['days' => $activity->days]) }}</p>
        @else
            <h3 style="{{ $subheading }}">{{ __('ai_connections.activity.by_day') }}</h3>
            <div style="overflow-x:auto;">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}width:5rem;">{{ __('ai_connections.activity.columns.day') }}</th>
                            <th style="{{ $th }}"></th>
                            <th style="{{ $th }}{{ $num }}width:6rem;">{{ __('ai_connections.activity.columns.calls') }}</th>
                            <th style="{{ $th }}{{ $num }}width:6rem;">{{ __('ai_connections.activity.columns.refused') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activity->byDay as $row)
                            <tr data-ai-activity-day="{{ $row->key }}" data-calls="{{ $row->calls }}" data-refused="{{ $row->refused }}">
                                <td style="{{ $td }}white-space:nowrap;">{{ $row->label }}</td>
                                <td style="{{ $td }}min-width:8rem;">
                                    <span aria-hidden="true" style="display:block;height:0.75rem;border-radius:0.25rem;min-width:{{ $row->calls > 0 ? '2px' : '0' }};width:{{ round($row->calls / $activity->busiestDay() * 100, 1) }}%;background-color:var(--primary-500);"></span>
                                </td>
                                <td style="{{ $td }}{{ $num }}">{{ $row->calls }}</td>
                                <td style="{{ $td }}{{ $num }}">{{ $row->refused }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit, minmax(18rem, 1fr));">
                <div>
                    <h3 style="{{ $subheading }}">{{ __('ai_connections.activity.by_tool') }}</h3>
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">{{ __('ai_connections.activity.columns.tool') }}</th>
                                <th style="{{ $th }}{{ $num }}">{{ __('ai_connections.activity.columns.calls') }}</th>
                                <th style="{{ $th }}{{ $num }}">{{ __('ai_connections.activity.columns.refused') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activity->byTool as $row)
                                <tr data-ai-activity-tool="{{ $row->key }}" data-calls="{{ $row->calls }}" data-refused="{{ $row->refused }}">
                                    <td style="{{ $td }}">
                                        @if ($row->key === '')
                                            {{ __('ai_connections.activity.unknown_tool') }}
                                        @else
                                            <code>{{ $row->label }}</code>
                                        @endif
                                    </td>
                                    <td style="{{ $td }}{{ $num }}">{{ $row->calls }}</td>
                                    <td style="{{ $td }}{{ $num }}">{{ $row->refused }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3 style="{{ $subheading }}">{{ __('ai_connections.activity.by_person') }}</h3>
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">{{ __('ai_connections.activity.columns.person') }}</th>
                                <th style="{{ $th }}{{ $num }}">{{ __('ai_connections.activity.columns.calls') }}</th>
                                <th style="{{ $th }}{{ $num }}">{{ __('ai_connections.activity.columns.refused') }}</th>
                                <th style="{{ $th }}">{{ __('ai_connections.activity.columns.last') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activity->byPerson as $row)
                                <tr data-ai-activity-person="{{ $row->key }}" data-calls="{{ $row->calls }}" data-refused="{{ $row->refused }}">
                                    <td style="{{ $td }}">{{ $row->label }}</td>
                                    <td style="{{ $td }}{{ $num }}">{{ $row->calls }}</td>
                                    <td style="{{ $td }}{{ $num }}">{{ $row->refused }}</td>
                                    <td style="{{ $td }}white-space:nowrap;" title="{{ $row->lastAt?->format('d/m/Y H:i') }}">{{ $row->lastAt?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    {{-- 5. Nhật ký MCP. --}}
    <section style="{{ $card }}" data-mcp-audit>
        <h2 style="{{ $heading }}">{{ __('ai_connections.admin.audit.heading') }}</h2>
        <p style="{{ $muted }}">{{ __('ai_connections.admin.audit.description', ['limit' => \App\Actions\Mcp\ListMcpAuditEntries::LIMIT]) }}</p>
        <p style="{{ $muted }}margin-top:0.25rem;">{{ __('mcp_audit.page.ip_note') }}</p>

        <div style="margin-top:0.75rem;max-width:40rem;">
            {{ $this->auditFiltersForm }}
        </div>

        @if ($entries === [])
            <p style="{{ $muted }}margin-top:0.75rem;">{{ __('ai_connections.admin.audit.empty') }}</p>
        @else
            <div style="overflow-x:auto;margin-top:0.75rem;">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            @foreach (['at', 'person', 'event', 'tool', 'outcome', 'platform', 'ip', 'details'] as $column)
                                <th style="{{ $th }}">{{ __('ai_connections.admin.audit.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr data-mcp-audit-row wire:key="mcp-audit-{{ $entry->id }}">
                                <td style="{{ $td }}white-space:nowrap;">{{ $entry->at->format('d/m/Y H:i:s') }}</td>
                                <td style="{{ $td }}">{{ $entry->person ?? __('ai_connections.admin.audit.system') }}</td>
                                <td style="{{ $td }}">{{ __('activity.events.'.$entry->event) }}</td>
                                <td style="{{ $td }}"><code>{{ $entry->tool }}</code></td>
                                <td style="{{ $td }}">{{ $entry->outcome?->label() }}</td>
                                <td style="{{ $td }}">{{ $entry->platform?->label() }}</td>
                                <td style="{{ $td }}"><code>{{ $entry->ip }}</code></td>
                                <td style="{{ $td }}font-size:0.75rem;"><code style="white-space:pre-wrap;word-break:break-word;">{{ $entry->details === [] ? '' : json_encode($entry->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
