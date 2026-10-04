{{--
    "Trang của một người" (M13) — luật ở docblock `App\Filament\Admin\Pages\TeamMember`; tệp này chỉ
    vẽ ra những gì trang đưa qua `getViewData()`: câu phạm vi cố định (R4), đầu trang (tên, chức danh,
    trạng thái, các con số N1–N11, "Không áp dụng" khi rỗng — R6), cơ cấu lĩnh vực, ba danh sách ngắn,
    bảng "Vụ việc", khối "Cách tính các con số". Hai widget xu hướng (Task 7) là footer widget của
    trang, Filament vẽ chúng sau phần này.

    Style nội tuyến trên biến CSS của Filament (dự án không có bước dựng CSS).
--}}
@php
    $cardStyle = 'border:1px solid color-mix(in srgb, var(--gray-500) 30%, transparent);border-radius:0.75rem;padding:1rem;';
    $headingStyle = 'margin:0;font-size:1rem;font-weight:600;';
    $mutedStyle = 'color:color-mix(in srgb, var(--gray-500) 95%, transparent);';
    $tableStyle = 'width:100%;margin-top:0.75rem;border-collapse:collapse;font-size:0.875rem;';
    $cellStyle = 'padding:0.375rem 0.5rem;text-align:left;vertical-align:top;border-bottom:1px solid color-mix(in srgb, var(--gray-500) 20%, transparent);';
    $headCellStyle = $cellStyle.'font-weight:600;'.$mutedStyle;
    $linkStyle = 'color:var(--primary-600);font-weight:600;';
@endphp
<x-filament-panels::page>
    @include('filament.admin.pages.performance-scope-note')

    <section data-vk-member-profile style="{{ $cardStyle }}">
        <h2 style="{{ $headingStyle }}font-size:1.125rem;">{{ $member['name'] }}</h2>
        <dl style="display:flex;flex-wrap:wrap;gap:0.5rem 2rem;margin:0.5rem 0 0;">
            <div>
                <dt style="{{ $mutedStyle }}">{{ __('performance.team_member.position') }}</dt>
                <dd style="margin:0;">{{ $member['position'] ?? '—' }}</dd>
            </div>
            <div>
                <dt style="{{ $mutedStyle }}">{{ __('performance.team_member.status') }}</dt>
                <dd style="margin:0;">{{ $member['isActive'] ? __('performance.team_member.active') : __('performance.team_overview.inactive') }}</dd>
            </div>
        </dl>
    </section>

    <section data-vk-member-workload style="{{ $cardStyle }}">
        <h2 style="{{ $headingStyle }}">{{ __('performance.team_member.workload_heading') }}</h2>
        <dl style="display:grid;grid-template-columns:repeat(auto-fill, minmax(12rem, 1fr));gap:0.75rem;margin:0.75rem 0 0;">
            @foreach ($metrics as $metric)
                <div data-vk-metric="{{ $metric['key'] }}" style="padding:0.5rem 0.75rem;border-radius:0.5rem;background:color-mix(in srgb, var(--gray-500) 8%, transparent);">
                    <dt style="font-size:0.875rem;{{ $mutedStyle }}">{{ $metric['label'] }}</dt>
                    <dd style="margin:0.25rem 0 0;font-size:1.125rem;font-weight:600;">{{ $metric['value'] ?? __('performance.not_applicable') }}</dd>
                    @if ($metric['note'] !== null)
                        <dd style="margin:0;font-size:0.75rem;{{ $mutedStyle }}">{{ $metric['note'] }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    </section>

    <section data-vk-member-mix style="{{ $cardStyle }}">
        <h2 style="{{ $headingStyle }}">{{ $mix->byLead ? __('performance.team_member.mix.heading_lead') : __('performance.team_member.mix.heading_supporting') }}</h2>
        @if ($mix->rows === [])
            <p style="margin:0.75rem 0 0;{{ $mutedStyle }}">{{ __('performance.team_member.mix.empty') }}</p>
        @else
            <table style="{{ $tableStyle }}">
                <thead>
                    <tr>
                        <th style="{{ $headCellStyle }}">{{ __('performance.team_member.mix.type') }}</th>
                        <th style="{{ $headCellStyle }}">{{ __('performance.team_member.mix.matters') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mix->rows as $row)
                        <tr data-vk-mix-row="{{ $row['matterTypeId'] }}">
                            <td style="{{ $cellStyle }}">{{ $row['name'] }}</td>
                            <td style="{{ $cellStyle }}">{{ $row['matters'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @isset($lists['deadlines'])
        <section data-vk-member-list="deadlines" style="{{ $cardStyle }}">
            <h2 style="{{ $headingStyle }}">{{ __('performance.team_member.lists.deadlines') }}</h2>
            @if ($lists['deadlines']['rows'] === [])
                <p style="margin:0.75rem 0 0;{{ $mutedStyle }}">{{ __('performance.team_member.lists.empty') }}</p>
            @else
                <table style="{{ $tableStyle }}">
                    <thead>
                        <tr>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.upcoming_deadlines.columns.due_date') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.upcoming_deadlines.columns.code') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.upcoming_deadlines.columns.name') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.upcoming_deadlines.columns.severity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lists['deadlines']['rows'] as $row)
                            <tr data-vk-row="{{ $row['id'] }}">
                                <td style="{{ $cellStyle }}">{{ $row['due'] }}</td>
                                <td style="{{ $cellStyle }}"><a href="{{ $row['url'] }}" style="{{ $linkStyle }}">{{ $row['code'] }}</a></td>
                                <td style="{{ $cellStyle }}">{{ $row['name'] }}</td>
                                <td style="{{ $cellStyle }}">{{ $row['severity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($lists['deadlines']['total'] > count($lists['deadlines']['rows']))
                    <p style="margin:0.5rem 0 0;font-size:0.875rem;{{ $mutedStyle }}">{{ __('performance.team_member.lists.more', ['shown' => count($lists['deadlines']['rows']), 'total' => $lists['deadlines']['total']]) }}</p>
                @endif
            @endif
        </section>
    @endisset

    @isset($lists['requests'])
        <section data-vk-member-list="requests" style="{{ $cardStyle }}">
            <h2 style="{{ $headingStyle }}">{{ __('performance.team_member.lists.requests') }}</h2>
            @if ($lists['requests']['rows'] === [])
                <p style="margin:0.75rem 0 0;{{ $mutedStyle }}">{{ __('performance.team_member.lists.empty') }}</p>
            @else
                <table style="{{ $tableStyle }}">
                    <thead>
                        <tr>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.upcoming_deadlines.columns.code') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('performance.team_member.lists.columns.subject') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('performance.team_member.lists.columns.status') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('performance.team_member.lists.columns.sent_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lists['requests']['rows'] as $row)
                            <tr data-vk-row="{{ $row['id'] }}">
                                <td style="{{ $cellStyle }}"><a href="{{ $row['url'] }}" style="{{ $linkStyle }}">{{ $row['code'] }}</a></td>
                                <td style="{{ $cellStyle }}">{{ $row['subject'] }}</td>
                                <td style="{{ $cellStyle }}">{{ $row['status'] }}</td>
                                <td style="{{ $cellStyle }}">{{ $row['sentAt'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($lists['requests']['total'] > count($lists['requests']['rows']))
                    <p style="margin:0.5rem 0 0;font-size:0.875rem;{{ $mutedStyle }}">{{ __('performance.team_member.lists.more', ['shown' => count($lists['requests']['rows']), 'total' => $lists['requests']['total']]) }}</p>
                @endif
            @endif
        </section>
    @endisset

    @isset($lists['reviews'])
        <section data-vk-member-list="reviews" style="{{ $cardStyle }}">
            <h2 style="{{ $headingStyle }}">{{ __('performance.team_member.lists.reviews') }}</h2>
            @if (! $lists['reviews']['applicable'])
                <p style="margin:0.75rem 0 0;{{ $mutedStyle }}">{{ __('performance.not_applicable') }}</p>
            @elseif ($lists['reviews']['rows'] === [])
                <p style="margin:0.75rem 0 0;{{ $mutedStyle }}">{{ __('performance.team_member.lists.empty') }}</p>
            @else
                <table style="{{ $tableStyle }}">
                    <thead>
                        <tr>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.pending_checklist_reviews.columns.code') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.pending_checklist_reviews.columns.client') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.pending_checklist_reviews.columns.item') }}</th>
                            <th style="{{ $headCellStyle }}">{{ __('widgets.pending_checklist_reviews.columns.submitted_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lists['reviews']['rows'] as $row)
                            <tr data-vk-row="{{ $row['id'] }}">
                                <td style="{{ $cellStyle }}"><a href="{{ $row['url'] }}" style="{{ $linkStyle }}">{{ $row['code'] }}</a></td>
                                <td style="{{ $cellStyle }}">{{ $row['client'] ?? '—' }}</td>
                                <td style="{{ $cellStyle }}">{{ $row['name'] }}</td>
                                <td style="{{ $cellStyle }}">{{ $row['submittedAt'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($lists['reviews']['total'] > count($lists['reviews']['rows']))
                    <p style="margin:0.5rem 0 0;font-size:0.875rem;{{ $mutedStyle }}">{{ __('performance.team_member.lists.more', ['shown' => count($lists['reviews']['rows']), 'total' => $lists['reviews']['total']]) }}</p>
                @endif
            @endif
        </section>
    @endisset

    {{ $this->table }}

    @include('filament.admin.pages.performance-explanations', ['explanations' => $explanations])
</x-filament-panels::page>
