<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\MatterOverview;
use App\Models\Deadline;

/**
 * Đầu ra của tool `get_matter` (kế hoạch M11, bảng tool 5 [DC:51]): {@see MatterPresenter::detail()}
 * — mã, loại, giai đoạn (nhãn nội bộ), đội ngũ, khách (số điện thoại đã che), các bên theo R10, toà,
 * số thụ lý, cờ `has_internal_note` — cộng ba khoá ghép:
 *
 *  - `next_deadlines`: tối đa năm mốc chưa hoàn thành gần nhất ({@see DeadlinePresenter});
 *  - `checklist_progress`: `{submitted, total, label}` — "Đã nộp X/Y" của `ChecklistProgress`;
 *  - `open_client_request_count`: số yêu cầu từ khách chưa đóng, chưa rút.
 *
 * Không `description_internal`, `summary_for_client`, email khách (bảng tool: loại trừ riêng).
 */
final class MatterOverviewPresenter
{
    public const FIELDS = [...MatterPresenter::DETAIL_FIELDS, 'next_deadlines', 'checklist_progress', 'open_client_request_count'];

    /**
     * Cần nạp sẵn: như {@see MatterPresenter::detail()}; mỗi mốc như {@see DeadlinePresenter::present()}.
     *
     * @return array<string, mixed>
     */
    public static function present(MatterOverview $overview): array
    {
        return [
            ...MatterPresenter::detail($overview->matter),
            'next_deadlines' => array_map(fn (Deadline $deadline): array => DeadlinePresenter::present($deadline), $overview->nextDeadlines),
            'checklist_progress' => [
                'submitted' => $overview->checklistSubmitted,
                'total' => $overview->checklistTotal,
                'label' => __('mcp.get_matter.checklist_progress', [
                    'submitted' => $overview->checklistSubmitted,
                    'total' => $overview->checklistTotal,
                ]),
            ],
            'open_client_request_count' => $overview->openClientRequestCount,
        ];
    }
}
