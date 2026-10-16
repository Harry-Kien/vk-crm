<?php

use App\Enums\AiOfficeStatus;
use App\Enums\MyAiConnectionStatus;

/*
|--------------------------------------------------------------------------
| Làn ai (2026-10-15) — tài liệu gọi đúng tên nút, nhãn của hai trang "Kết nối AI" mới
|--------------------------------------------------------------------------
| Đọc CHÍNH tài liệu rồi so với tệp ngôn ngữ (không chép tay chuỗi nào ở đây): QUY-TRINH mục trợ lý
| AI cho quản trị viên và nhân sự, `docs/KET-NOI-AI.md` cho nhân sự.
*/

function aidocsSection(string $document, string $heading): string
{
    $text = (string) file_get_contents(base_path($document));
    $from = strpos($text, $heading);

    expect($from)->not->toBeFalse("Không thấy mục \"{$heading}\" trong {$document}");

    $next = preg_match('/\n#{2,3} /', $text, $match, PREG_OFFSET_CAPTURE, (int) $from + strlen($heading)) === 1
        ? $match[0][1]
        : strlen($text);

    // Dòng Markdown gãy giữa câu: so trên văn bản đã gộp khoảng trắng.
    return (string) preg_replace('/\s+/u', ' ', substr($text, (int) $from, $next - (int) $from));
}

it('QUY-TRINH có mục trợ lý AI gọi đúng ba câu trạng thái, các công tắc, bộ lọc, nút hàng loạt và tên khối hoạt động', function () {
    $section = aidocsSection('docs/QUY-TRINH.md', '### Trợ lý AI của nhân sự — trang "Kết nối AI"');

    foreach (AiOfficeStatus::cases() as $status) {
        expect($section)->toContain($status->label());
    }

    expect($section)
        ->toContain(__('ai_connections.setup.heading'))
        ->toContain(__('ai_connections.legal.summary'))
        ->toContain(__('ai_connections.admin.switches.enabled'))
        ->toContain(__('ai_connections.admin.switches.write_enabled'))
        ->toContain(__('ai_connections.admin.switches.save'))
        ->toContain(__('ai_connections.admin.switches.technical'))
        ->toContain(__('ai_connections.staff.filters.enabled'))
        ->toContain(__('ai_connections.staff.filters.not_acknowledged'))
        ->toContain(__('ai_connections.staff.filters.connected'))
        ->toContain(__('ai_connections.staff.bulk.label'))
        ->toContain(__('ai_connections.activity.heading'))
        ->toContain(__('ai_connections.guide.copy'))
        ->toContain(__('ai_connections.guide.steps_heading'))
        ->toContain(__('ai_connections.guide.status_heading'));
});

it('docs/KET-NOI-AI.md gọi đúng nhãn công tắc, nút Sao chép, bốn bước, ba thẻ và đầu câu của từng dòng trạng thái', function () {
    $guide = (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(base_path('docs/KET-NOI-AI.md')));
    $page = aidocsSection('docs/KET-NOI-AI.md', '## Trang "Kết nối AI của tôi"');

    expect($guide)->toContain('"'.__('ai_connections.admin.switches.enabled').'"')
        ->and($page)->toContain('**'.__('ai_connections.guide.copy').'**')
        ->and($page)->toContain(__('ai_connections.guide.status_heading'))
        ->and($page)->toContain(__('ai_connections.guide.steps_heading'));

    foreach (__('ai_connections.guide.steps') as $step) {
        expect($page)->toContain($step);
    }

    foreach (__('ai_connections.guide.tabs') as $tab) {
        expect($page)->toContain('**'.$tab.'**');
    }

    foreach (MyAiConnectionStatus::cases() as $status) {
        expect($page)->toContain(trim(explode('—', $status->label())[0]));
    }
});
