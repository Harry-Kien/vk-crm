{{--
    Trang "Kết nối AI" của quản trị (M11 Task 15) — luật ở docblock `App\Filament\Admin\Pages\AiConnections`
    và các Action nó gọi; tệp này chỉ vẽ ra.

    Không có bước dựng CSS trong dự án (CLAUDE.md) — style nội tuyến trên biến CSS của Filament, cùng
    thành ngữ `bulk-reassign.blade.php`. Không script nội tuyến (CSP của M8).
--}}
@php
    $card = 'border-radius:0.75rem;padding:1rem;border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);';
    $warning = 'border-radius:0.75rem;padding:1rem;border:1px solid var(--warning-500);background-color:color-mix(in srgb, var(--warning-500) 12%, transparent);';
    $muted = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $heading = 'font-size:1.125rem;font-weight:700;margin-bottom:0.5rem;';
    $button = 'min-height:44px;display:inline-flex;align-items:center;gap:0.375rem;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:none;color:var(--primary-50);background-color:var(--primary-600);cursor:pointer;';
    $secondaryButton = 'min-height:44px;display:inline-flex;align-items:center;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;border:1px solid color-mix(in srgb, var(--gray-500) 40%, transparent);background:transparent;color:inherit;cursor:pointer;';
    $table = 'width:100%;border-collapse:collapse;font-size:0.875rem;';
    $th = 'text-align:left;padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);font-weight:600;';
    $td = 'padding:0.5rem;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 15%, transparent);vertical-align:top;';
@endphp

<x-filament-panels::page>
    <p style="max-width:48rem;">{{ __('ai_connections.admin.intro') }}</p>

    {{-- 1. R12 mục 3: dải cảnh báo tới khi quản trị ghi ngày đã nộp hồ sơ. --}}
    <section style="{{ $filedOn === null ? $warning : $card }}" data-transfer-assessment>
        @if ($filedOn === null)
            <h2 style="{{ $heading }}">{{ __('ai_connections.assessment.banner_title') }}</h2>
            <p>{{ __('ai_connections.assessment.banner_intro') }}</p>
            <ol style="margin:0.5rem 0 0.5rem 1.25rem;list-style:decimal;">
                @foreach (['transfer_assessment', 'client_consent', 'protection_officer', 'incident_procedure'] as $item)
                    <li style="margin-top:0.25rem;">{{ __('ai_connections.assessment.items.'.$item) }}</li>
                @endforeach
            </ol>
            <p style="{{ $muted }}">{{ __('ai_connections.assessment.banner_hint') }}</p>
        @else
            <p>{{ __('ai_connections.assessment.filed_note', ['date' => $filedOn]) }}</p>
        @endif

        <form wire:submit="recordTransferAssessment" style="margin-top:0.75rem;max-width:24rem;">
            {{ $this->assessmentForm }}

            <button type="submit" style="{{ $secondaryButton }}margin-top:0.75rem;" wire:loading.attr="disabled" wire:target="recordTransferAssessment">
                {{ __('ai_connections.assessment.save') }}
            </button>
        </form>
    </section>

    {{-- 2. Hai công tắc toàn hệ thống. --}}
    <section style="{{ $card }}">
        <h2 style="{{ $heading }}">{{ __('ai_connections.admin.switches.heading') }}</h2>
        <p style="{{ $muted }}margin-bottom:0.75rem;">{{ __('ai_connections.admin.switches.description') }}</p>

        <form wire:submit="saveSwitches">
            {{ $this->switchesForm }}

            <button type="submit" style="{{ $button }}margin-top:1rem;" wire:loading.attr="disabled" wire:target="saveSwitches">
                {{ __('ai_connections.admin.switches.save') }}
            </button>
        </form>
    </section>

    {{-- 3. Bảng nhân sự. --}}
    {{ $this->table }}

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
