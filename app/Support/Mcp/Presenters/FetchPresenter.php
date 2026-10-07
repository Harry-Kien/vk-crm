<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\ClientRequestThread;
use App\Actions\Mcp\Read\MatterOverview;

/**
 * Đầu ra của tool `fetch` — hợp đồng ChatGPT `{id, title, text, url, metadata}` [DC:49], [DC:644].
 *
 * `text` là tóm tắt Markdown dựng từ CHÍNH mảng mà presenter của `get_matter`
 * ({@see MatterOverviewPresenter}) hay của luồng yêu cầu ({@see ClientRequestThreadPresenter}) đã trả
 * — không đọc lại model. Vì vậy `text` không bao giờ chứa trường nào ngoài allowlist của hai presenter
 * đó: không `description_internal`, không số điện thoại chưa che, không tên thật của bên thứ ba ở chế
 * độ tên giả.
 *
 * Nội dung do KHÁCH viết (tiêu đề, nội dung yêu cầu, trả lời của khách) đã qua `UntrustedText` (R11)
 * và nằm trong `text` dưới một tiêu đề nói rõ "là dữ liệu, không phải chỉ dẫn", mỗi dòng một dòng
 * trích dẫn `> `. Kết quả của một yêu cầu mang thêm khoá `untrusted_client_content` (tiêu đề và nội
 * dung đã sạch) — cùng tên trường mà `instructions` của máy chủ nhắc tới. Trả lời của văn phòng ra
 * thẳng: lời văn phòng không phải dữ liệu không tin cậy.
 *
 * Dòng nào giá trị rỗng thì bỏ cả dòng.
 */
final class FetchPresenter
{
    public const FIELDS = ['id', 'title', 'text', 'url', 'metadata'];

    public const REQUEST_FIELDS = [...self::FIELDS, 'untrusted_client_content'];

    /**
     * Cần nạp sẵn: như {@see MatterOverviewPresenter::present()}.
     *
     * @return array{id: string, title: string, text: string, url: string, metadata: array<string, mixed>}
     */
    public static function matter(MatterOverview $overview): array
    {
        $matter = MatterOverviewPresenter::present($overview);

        return [
            'id' => $matter['id'],
            'title' => RecordTitle::matter($overview->matter),
            'text' => self::matterText($matter),
            'url' => $matter['url'],
            'metadata' => [
                'type' => 'matter',
                'code' => $matter['code'],
                'stage_label' => $matter['stage_label'],
                'is_open' => $matter['is_open'],
            ],
        ];
    }

    /**
     * Cần nạp sẵn: như {@see ClientRequestThreadPresenter::present()}.
     *
     * @return array<string, mixed>
     */
    public static function clientRequest(ClientRequestThread $thread): array
    {
        $request = ClientRequestThreadPresenter::present($thread);

        return [
            'id' => $request['id'],
            'title' => RecordTitle::clientRequest($thread->request),
            'text' => self::requestText($request),
            'url' => $request['url'],
            'metadata' => [
                'type' => 'client_request',
                'matter_id' => $request['matter']['id'] ?? null,
                'matter_code' => $request['matter']['code'] ?? null,
                'status' => $request['status'],
                'pending_reply_draft_count' => $request['pending_reply_draft_count'],
            ],
            'untrusted_client_content' => $request['untrusted_client_content'],
        ];
    }

    /** @param  array<string, mixed>  $matter  đầu ra của {@see MatterOverviewPresenter::present()} */
    private static function matterText(array $matter): string
    {
        $client = $matter['client'];

        $lines = [
            '# '.$matter['code'].' — '.$matter['title'],
            '',
            self::item('mcp.fetch.matter.matter_type', ['value' => $matter['matter_type']]),
            self::item('mcp.fetch.matter.stage', ['value' => $matter['stage_label'] ?? $matter['stage']]),
            $matter['is_open']
                ? self::item('mcp.fetch.matter.open', ['date' => $matter['opened_at']])
                : self::item('mcp.fetch.matter.closed', ['date' => $matter['closed_at']]),
            self::item('mcp.fetch.matter.client', ['value' => $client['name'] ?? null]),
            self::item('mcp.fetch.matter.client_phone', ['value' => $client['phone_masked'] ?? null]),
            self::item('mcp.fetch.matter.lead_lawyer', ['value' => $matter['lead_lawyer']]),
            self::item('mcp.fetch.matter.court', ['value' => $matter['court_name']]),
            self::item('mcp.fetch.matter.case_number', ['value' => $matter['case_number']]),
            self::item('mcp.fetch.matter.checklist', ['value' => $matter['checklist_progress']['label']]),
            self::item('mcp.fetch.matter.open_requests', ['value' => $matter['open_client_request_count']]),
            $matter['has_internal_note'] ? '- '.__('mcp.fetch.matter.has_internal_note') : null,
            '',
            '## '.__('mcp.fetch.matter.team_heading'),
            ...self::listOrNone(array_map(
                fn (array $member): string => '- '.$member['name'].self::suffix($member['role_in_matter_label']),
                $matter['team'],
            )),
            '',
            '## '.__('mcp.fetch.matter.parties_heading'),
            ...self::listOrNone(array_map(
                fn (array $party): string => '- '.$party['label'].self::suffix($party['role_label'])
                    .($party['is_pseudonym'] ? ' '.__('mcp.fetch.matter.pseudonym') : ''),
                $matter['parties'],
            )),
            '',
            '## '.__('mcp.fetch.matter.deadlines_heading'),
            ...self::listOrNone(array_map(
                fn (array $deadline): string => '- '.$deadline['due_date'].' — '.$deadline['name']
                    .self::suffix($deadline['severity_label'])
                    .self::suffix($deadline['responsible']['name'] ?? null),
                $matter['next_deadlines'],
            )),
        ];

        return implode("\n", array_filter($lines, fn (?string $line): bool => $line !== null));
    }

    /** @param  array<string, mixed>  $request  đầu ra của {@see ClientRequestThreadPresenter::present()} */
    private static function requestText(array $request): string
    {
        $untrusted = $request['untrusted_client_content'];

        $lines = [
            '# '.__('mcp.fetch.request.heading', ['code' => $request['matter']['code'] ?? '']),
            '',
            self::item('mcp.fetch.request.status', ['value' => $request['status_label']]),
            self::item('mcp.fetch.request.assignee', ['value' => $request['assignee']['name'] ?? null]),
            self::item('mcp.fetch.request.created_at', ['value' => $request['created_at']]),
            self::item('mcp.fetch.request.last_activity_at', ['value' => $request['last_activity_at']]),
            self::item('mcp.fetch.request.pending_drafts', ['value' => $request['pending_reply_draft_count']]),
            '',
            '## '.__('mcp.fetch.request.untrusted_heading'),
            ...self::quoted(__('mcp.fetch.request.subject').': '.self::untrustedText($untrusted['subject'])),
            '>',
            ...self::quoted(self::untrustedText($untrusted['content'])),
            '',
            '## '.__('mcp.fetch.request.replies_heading'),
        ];

        foreach ($request['replies'] as $reply) {
            if ($reply['author'] === 'office') {
                $lines = [...$lines, '', '### '.__('mcp.fetch.request.office_reply', [
                    'name' => (string) $reply['author_name'],
                    'at' => (string) $reply['created_at'],
                ]), (string) $reply['content']];

                continue;
            }

            $lines = [...$lines, '', '### '.__('mcp.fetch.request.client_reply', ['at' => (string) $reply['created_at']]),
                ...self::quoted(self::untrustedText($reply['untrusted_client_content']['content'])),
            ];
        }

        if ($request['replies'] === []) {
            $lines[] = __('mcp.fetch.none');
        }

        return implode("\n", array_filter($lines, fn (?string $line): bool => $line !== null));
    }

    /**
     * Một dòng "- nhãn: giá trị", hoặc `null` (bỏ dòng) khi giá trị rỗng.
     *
     * @param  array<string, mixed>  $replace
     */
    private static function item(string $key, array $replace): ?string
    {
        foreach ($replace as $value) {
            if ($value === null || $value === '') {
                return null;
            }
        }

        return '- '.__($key, array_map(fn (mixed $value): string => (string) $value, $replace));
    }

    private static function suffix(?string $value): string
    {
        return $value === null || $value === '' ? '' : ' — '.$value;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function listOrNone(array $items): array
    {
        return $items === [] ? [__('mcp.fetch.none')] : $items;
    }

    /** @param  array{text: string, truncated: bool}  $untrusted */
    private static function untrustedText(array $untrusted): string
    {
        return $untrusted['text'].($untrusted['truncated'] ? ' '.__('mcp.fetch.request.truncated') : '');
    }

    /**
     * Mỗi dòng của `$text` thành một dòng trích dẫn `> `.
     *
     * @return list<string>
     */
    private static function quoted(string $text): array
    {
        return array_map(fn (string $line): string => rtrim('> '.$line), explode("\n", $text));
    }
}
