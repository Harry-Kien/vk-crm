<?php

use App\Enums\PushTopic;
use App\Support\Security\ContentSecurityPolicy;

/*
|--------------------------------------------------------------------------
| M12 Task 10 — nghiệm thu, đặc tả và hướng dẫn cài app nói đúng điều mã đang làm
|--------------------------------------------------------------------------
|
| Task 10 không thêm mã ứng dụng; sản phẩm của nó là văn bản mà người khác làm theo:
|
|  - danh sách kiểm tra máy thật (`docs/research/2026-10-01-pwa-kiem-tra-may-that.md`) — chủ văn
|    phòng chạy trên iPhone và Android (phán quyết 3 của controller: phần máy thật PENDING OWNER).
|    Kế hoạch đòi "kích hoạt đủ tám chủ đề", "vô hiệu hoá tài khoản thì hết nhận tin" và "các màn
|    hình chính của admin ở bề ngang 390px"; trước Task 10 danh sách chỉ có bốn chủ đề rải rác ở D;
|  - đính chính SPEC (§9 bảng chủ đề đẩy, §10 mục 2 chỉ thị CSP, §13 dòng M12, §15 app gốc);
|  - hướng dẫn cài app cho khách trong `docs/QUY-TRINH.md`;
|  - "Ghi chú M12" của `docs/PROGRESS.md`.
|
| Mỗi test so văn bản với NGUỒN THẬT — `PushTopic::cases()`, câu của `lang/vi/push.php`, nhãn tab và
| nút của `lang/vi`, chỉ thị của `ContentSecurityPolicy::policy()` — để một chủ đề mới, một nhãn nút
| đổi tên hay một chỉ thị CSP đổi làm test đỏ, thay vì để chủ văn phòng đi tìm một cái nút không còn
| tên đó, hay để đặc tả nói khác mã.
*/

function pwaDocsFile(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)));
}

/** Đoạn của `$text` từ dòng bắt đầu bằng `$start` tới trước dòng kế tiếp bắt đầu bằng `$end` (hoặc hết tệp). */
function pwaDocsSection(string $text, string $start, ?string $end): string
{
    $from = strpos($text, "\n".$start);
    expect($from)->not->toBeFalse("thiếu đoạn bắt đầu bằng {$start}");

    $to = $end === null ? false : strpos($text, "\n".$end, $from + 1);

    return $to === false ? substr($text, $from) : substr($text, $from, $to - $from);
}

function pwaDocsFlat(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
}

/** Một dòng bảng Markdown bắt đầu bằng `| $id |`. */
function pwaDocsRow(string $section, string $id): string
{
    $matched = preg_match('/^\| '.preg_quote($id, '/').' \|.*$/m', $section, $match);
    expect($matched)->toBe(1, "thiếu dòng bảng {$id}");

    return pwaDocsFlat($match[0]);
}

function pwaDocsChecklist(): string
{
    return pwaDocsFile('docs/research/2026-10-01-pwa-kiem-tra-may-that.md');
}

/** Tám chủ đề có sự kiện thật (mọi case trừ "Gửi thử"). */
function pwaDocsEventTopics(): array
{
    return array_values(array_filter(PushTopic::cases(), fn (PushTopic $topic): bool => $topic !== PushTopic::Test));
}

/** Câu trên màn hình khoá của một chủ đề — mốc hạn có ba bậc, mỗi bậc một câu. */
function pwaDocsBodies(PushTopic $topic): array
{
    return $topic === PushTopic::StaffDeadlineReminder
        ? [__('push.alerts.staff.deadline.upcoming'), __('push.alerts.staff.deadline.imminent'), __('push.alerts.staff.deadline.overdue')]
        : [__('push.alerts.'.$topic->value)];
}

/** Nơi một cú chạm phải mở, gọi bằng đúng tên trên màn hình. */
function pwaDocsLanding(PushTopic $topic): array
{
    return match ($topic) {
        PushTopic::ClientStageUpdate => [__('portal_progress.blocks.timeline.heading')],
        PushTopic::ClientDocumentPublished => [__('portal_progress.blocks.documents.heading')],
        PushTopic::ClientDocumentRejected => [__('portal_progress.blocks.checklist.heading')],
        PushTopic::ClientRequestAnswered => [__('requests.portal.heading')],
        PushTopic::StaffDeadlineReminder => [__('deadlines.tab.title')],
        PushTopic::StaffNewClientRequest => [__('requests.tab.title')],
        PushTopic::StaffNewClientDocument => [__('matters.tabs.checklist')],
        PushTopic::StaffInstalmentOverdue => [__('billing.receivables.title'), __('billing.tab.title')],
        PushTopic::StaffHandoverReady => [__('matters.tabs.documents')],
        PushTopic::Test => [],
    };
}

it('danh sách kiểm tra có mục G kích hoạt ĐỦ chín chủ đề: mỗi dòng một chủ đề, đúng app, đúng câu màn hình khoá, đúng nơi chạm mở', function (): void {
    $sectionG = pwaDocsSection(pwaDocsChecklist(), '## G.', '## H.');
    $firm = (string) config('vkcrm.brand.short_name');

    // Vòng sửa cuối I5: `staff.handover_ready` nối lúc gộp `main` vào nhánh — dòng G9.
    expect(pwaDocsEventTopics())->toHaveCount(9);

    preg_match_all('/^\| (G\d+) \|/m', $sectionG, $ids);
    expect($ids[1])->toBe(array_map(fn (int $i): string => 'G'.$i, range(1, count(pwaDocsEventTopics()))));

    foreach (pwaDocsEventTopics() as $index => $topic) {
        $row = pwaDocsRow($sectionG, 'G'.($index + 1));
        $app = $topic->panel() === 'portal' ? 'app khách' : 'app nội bộ';

        expect($row)->toContain("`{$topic->value}`")
            ->toContain($app)
            ->toContain("**{$firm}**");

        foreach (pwaDocsBodies($topic) as $body) {
            expect($row)->toContain($body);
        }

        foreach (pwaDocsLanding($topic) as $landing) {
            expect($row)->toContain($landing);
        }
    }

    // Không lộ gì của hồ sơ, và cú chạm khi đã hết phiên: qua đăng nhập (và mã một lần) về đúng trang.
    expect(pwaDocsFlat($sectionG))
        ->toContain('không có tên khách, mã hồ sơ, tên vụ việc, tên tài liệu')
        ->toContain('hết phiên')
        ->toContain('`staff.handover_ready`');
});

it('danh sách kiểm tra gọi đúng tên các nút dùng để kích hoạt từng chủ đề', function (): void {
    $sectionG = pwaDocsFlat(pwaDocsSection(pwaDocsChecklist(), '## G.', '## H.'));

    expect($sectionG)
        ->toContain(__('matters.actions.transition_stage'))
        ->toContain(__('matters.transition_form.publish'))
        ->toContain(__('documents.tab.actions.publish'))
        ->toContain(__('checklist.tab.actions.reject'))
        ->toContain(__('portal_progress.blocks.requests.open'))
        ->toContain(__('deadlines.tab.actions.add'))
        ->toContain('08:00');
});

it('mục D: "Gửi thử" gọi đúng tên nút, và có bước vô hiệu hoá tài khoản thì hết nhận tin', function (): void {
    $sectionD = pwaDocsSection(pwaDocsChecklist(), '## D.', '## E.');

    expect(pwaDocsRow($sectionD, 'D3'))->toContain('**'.__('push.test.button').'**');

    $deactivate = pwaDocsRow($sectionD, 'D10');
    expect($deactivate)
        ->toContain(__('client_users.label'))
        ->toContain(__('client_users.fields.is_active'))
        ->toContain(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
        ->toContain('**Không** có thông báo nào');
});

it('mục H thử bốn màn hình chính của app nội bộ ở bề ngang iPhone, gọi đúng tên màn hình và nút', function (): void {
    $sectionH = pwaDocsSection(pwaDocsChecklist(), '## H.', '## Bảng kết quả');

    expect(pwaDocsRow($sectionH, 'H1'))->toContain(__('matters.plural_label'))
        ->and(pwaDocsRow($sectionH, 'H2'))->toContain(__('matters.tabs.overview'))
        ->and(pwaDocsRow($sectionH, 'H3'))->toContain(__('matters.tabs.progress'))
        ->and(pwaDocsRow($sectionH, 'H3'))->toContain(__('matters.actions.transition_stage'))
        ->and(pwaDocsRow($sectionH, 'H3'))->toContain(__('filament-actions::modal.actions.submit.label'))
        ->and(pwaDocsRow($sectionH, 'H4'))->toContain(__('deadlines.tab.title'))
        ->and(pwaDocsRow($sectionH, 'H4'))->toContain(__('deadlines.tab.actions.add'));
});

it('bảng kết quả của danh sách kiểm tra có chỗ ghi mọi mục mới', function (): void {
    $results = pwaDocsSection(pwaDocsChecklist(), '## Bảng kết quả', null);

    expect($results)->toContain('| D1–D10 |')
        ->toContain('| G1–G9 |')
        ->not->toContain('| G1–G8 |')
        ->toContain('| H1–H4 |')
        ->not->toContain('| D1–D9 |');
});

it('SPEC §9 có bảng chủ đề đẩy: đúng chín chủ đề của PushTopic, gói bàn giao đã nối, và hai thư nhân sự của M7/M10 nằm trong danh sách cố ý không đẩy', function (): void {
    $sectionNine = pwaDocsSection(pwaDocsFile('docs/SPEC.md'), '## 9. Email', '## 10.');
    $erratum = pwaDocsSection($sectionNine, '**Đính chính 2026-10-04 (M12 Task 10, phán quyết R10', '**Đính chính');

    preg_match_all('/^\| `([a-z_.]+)` \|.*$/m', $erratum, $rows);

    $expected = array_map(fn (PushTopic $topic): string => $topic->value, pwaDocsEventTopics());

    expect($rows[1])->toEqualCanonicalizing($expected)
        ->and($expected)->toContain('staff.handover_ready');

    // Vòng sửa cuối I5: dòng gói bàn giao không còn ghi "mang sang".
    $handover = collect($rows[0])->first(fn (string $row): bool => str_starts_with($row, '| `staff.handover_ready`'));
    expect($handover)->not->toContain('mang sang')
        ->and($handover)->not->toContain('chưa nối');

    // Cố ý không đẩy (R10) — liệt kê để không ai "thêm cho đủ".
    expect(pwaDocsFlat($erratum))
        ->toContain('`client.otp`')
        ->toContain('`client.activation`')
        ->toContain('`client.missing_documents`')
        ->toContain('`staff.stale_matter`')
        ->toContain('`staff.matter_reassigned`')
        ->toContain('`staff.intake_unanswered`')
        ->toContain('chỉ một câu chung');
});

it('SPEC §10 mục 2 ghi các chỉ thị CSP của M12 đúng như ContentSecurityPolicy, và máy chủ push KHÔNG vào connect-src', function (): void {
    $sectionTen = pwaDocsSection(pwaDocsFile('docs/SPEC.md'), '## 10.', '## 11.');
    $erratum = pwaDocsFlat(pwaDocsSection($sectionTen, '   **Đính chính 2026-10-04 (§10.2, M12', '3. '));

    $policy = ContentSecurityPolicy::policy('n');

    foreach (['manifest-src', 'worker-src', 'connect-src', 'img-src'] as $directive) {
        expect(preg_match('/(?:^|; )('.preg_quote($directive, '/').' [^;]+)/', $policy, $match))->toBe(1);
        expect($erratum)->toContain('`'.$match[1].'`');
    }

    expect($erratum)
        ->toContain('`'.ContentSecurityPolicy::WORKER_POLICY.'`')
        ->toContain('KHÔNG thêm vào `connect-src`')
        ->toContain('`public/pwa/register.js`');
});

it('SPEC §13 có dòng M12, §15 trỏ câu "ứng dụng di động" về mục cuối của kế hoạch M12', function (): void {
    $spec = pwaDocsFile('docs/SPEC.md');
    $milestones = pwaDocsSection($spec, '## 13. Milestone', '## 14.');
    $later = pwaDocsFlat(pwaDocsSection($spec, '## 15.', null));
    $plan = pwaDocsFile('docs/superpowers/plans/2026-09-24-m12-pwa.md');

    expect(preg_match('/^\| \*\*M12\*\* \|.+\|$/m', $milestones))->toBe(1);

    $heading = 'Về sau: khi nào mới đáng làm app gốc';
    expect($plan)->toContain('## '.$heading)
        ->and($later)->toContain('`docs/superpowers/plans/2026-09-24-m12-pwa.md`')
        ->and($later)->toContain('"'.$heading.'"');

    // Vòng sửa cuối I4: SPEC là nguồn sự thật — nhánh cắt TRƯỚC khi M10/M11 gộp, không "chạy cuối".
    $erratum13 = pwaDocsFlat(pwaDocsSection($milestones, '**Đính chính 2026-10-04 (M12 Task 10).**', null));
    expect($erratum13)
        ->not->toContain('M12 chạy cuối, sau M9, M10 và M11')
        ->toContain('cắt từ `main` trước khi M10 và M11 gộp')
        ->toContain('`staff.intake_unanswered`')
        ->toContain('`staff.matter_reassigned`');

    // Vòng sửa cuối I1: §15 nói cùng một điều với QUY-TRINH về tài liệu đã tải.
    expect($later)
        ->not->toContain('không lưu hồ sơ trên máy')
        ->toContain('không lưu sẵn hồ sơ trên điện thoại (chỉ tài liệu người dùng chủ động tải về nằm lại trong thư mục tải xuống của máy, đăng xuất không xoá)');

    // Hai đính chính đã có từ Task 4 và Task 7 vẫn đứng.
    expect(pwaDocsFlat(pwaDocsSection($spec, '## 2.', '## 3.')))->toContain('Đính chính 2026-10-03 (M12 Task 4)')
        ->and(pwaDocsFlat(pwaDocsSection($spec, '### 4.15', '### 4.16')))->toContain('`channel` thêm giá trị `push`');
});

it('QUY-TRINH có hướng dẫn cài app cho khách, dùng đúng câu chữ trên màn hình, và không còn hứa app "làm sau"', function (): void {
    $process = pwaDocsFile('docs/QUY-TRINH.md');
    $guide = pwaDocsSection($process, '## Hướng dẫn cài ứng dụng Luật Vũ Khang trên điện thoại', '## ');
    $flat = pwaDocsFlat($guide);
    $firm = (string) config('vkcrm.brand.short_name');

    expect(pwaDocsFlat($process))->not->toContain('Đó là một milestone riêng, làm sau');

    expect($flat)
        ->toContain('### Trên iPhone')
        ->toContain('### Trên điện thoại Android')
        ->toContain(__('pwa.portal.name', ['firm' => $firm]))
        ->toContain(__('pwa.portal.short_name', ['firm' => $firm]))
        // Tên nút in đậm như trên màn hình — so cả cụm `**…**`, không chỉ một chuỗi con của câu văn.
        ->toContain('**'.__('push.devices.menu').'**')
        ->toContain('**'.__('push.devices.enable').'**')
        ->toContain(__('push.devices.state.ios_install', ['app' => __('pwa.portal.short_name', ['firm' => $firm])]))
        ->toContain(__('push.devices.state.ios_install_note'))
        ->toContain(__('push.devices.logout_note'))
        ->toContain(__('pwa.offline.heading'))
        ->toContain('16.4');

    // Ảnh: ảnh mô phỏng nằm thật trong repo; ảnh chỉ có từ máy thật để chỗ trống có chú thích.
    preg_match_all('/!\[([^\]]+)\]\(([^)]+)\)/', $guide, $images, PREG_SET_ORDER);
    expect($images)->not->toBeEmpty();

    foreach ($images as [, $alt, $path]) {
        expect(file_exists(base_path('docs/'.$path)))->toBeTrue("thiếu ảnh docs/{$path}")
            ->and($alt)->toContain('mô phỏng');
    }

    expect($flat)->toContain('ảnh do chủ văn phòng chụp khi chạy danh sách kiểm tra');
});

/*
 * Lời hứa về điện thoại dùng chung (vòng sửa 1 của Task 10, I1). Route tải trả tệp với
 * `Content-Disposition: attachment` (ghim ở `DocumentDownloadAliasTest`), nên một tài liệu khách
 * CHỦ ĐỘNG tải trong app nằm lại trong thư mục tải xuống của máy và đăng xuất không xoá nó. Hướng
 * dẫn cho khách không được hứa "hồ sơ không lưu trên điện thoại" hay "đăng xuất sau khi xem" là đủ:
 * người nhà mở thư mục Tải xuống vẫn đọc được bản án, hợp đồng.
 */
it('hướng dẫn cài app nói thật về tài liệu đã tải: nằm lại trong thư mục tải xuống, đăng xuất không xoá, máy dùng chung thì xoá tay; danh sách kiểm tra F đo đúng điều đó', function (): void {
    $process = pwaDocsFile('docs/QUY-TRINH.md');
    $guide = pwaDocsSection($process, '## Hướng dẫn cài ứng dụng Luật Vũ Khang trên điện thoại', '## ');
    $intro = pwaDocsFlat(pwaDocsSection($guide, 'Ứng dụng **Luật Vũ Khang** chính là', '### Trước khi bắt đầu'));
    $notes = pwaDocsFlat(pwaDocsSection($guide, '### Điều nên biết', '### '));
    $update = pwaDocsFlat(pwaDocsSection($process, '**Cập nhật 2026-10-04 (M12).**', 'Ứng dụng tải từ chợ ứng dụng'));

    // Hai lời hứa sai cũ không còn ở đâu trong tài liệu.
    expect(pwaDocsFlat($process))
        ->not->toContain('không được lưu trên điện thoại')
        ->not->toContain('không có bản sao hồ sơ nào nằm trên điện thoại')
        ->not->toContain('nên đăng xuất sau khi xem');

    expect($intro)->toContain('Riêng tài liệu anh/chị chủ động tải về thì nằm lại trong thư mục tải xuống của máy');

    expect($notes)
        ->toContain(__('push.devices.logout_note').' Tài liệu anh/chị chủ động tải về được điện thoại lưu vào thư mục tải xuống của máy')
        ->toContain('**đăng xuất không xoá chúng**')
        ->toContain('Điện thoại dùng chung với người nhà thì sau khi xem, xoá tay các tài liệu đã tải rồi mới đăng xuất.');

    expect($update)->toContain('chỉ tài liệu người dùng chủ động tải về nằm lại trong thư mục tải xuống của máy, đăng xuất không xoá');

    $sectionF = pwaDocsSection(pwaDocsChecklist(), '## F.', '## G.');

    expect(pwaDocsRow($sectionF, 'F1'))->toContain('Ghi lại tệp vừa tải nằm ở đâu');
    expect(pwaDocsRow($sectionF, 'F2'))
        ->toContain('tệp tải ở F1 (nếu máy đã lưu) **vẫn còn**')
        ->toContain('đăng xuất không xoá chúng')
        ->toContain('**KHÔNG ĐẠT** nếu tệp còn mà hướng dẫn cài cho khách lại hứa khác');
});

it('"Ghi chú M12" có phần nghiệm thu Task 10: R1–R14, câu hỏi chờ trả lời, đánh giá máy chủ push nước ngoài, việc mang sang', function (): void {
    $notes = pwaDocsSection(pwaDocsFile('docs/PROGRESS.md'), '## Ghi chú M12', '## ');
    $task10 = pwaDocsFlat(pwaDocsSection($notes, '### Task 10', '### '));

    foreach (range(1, 14) as $ruling) {
        expect($task10)->toContain("**R{$ruling}");
    }

    expect($task10)
        ->toContain('CHỜ TRẢ LỜI')
        ->toContain('`docs/superpowers/plans/2026-09-21-m8-security-and-launch.md`')
        ->toContain('`docs/research/2026-09-24-mcp-phap-ly-goi.md`')
        ->toContain('M8 Task 6')
        ->toContain('`routes/pwa.php`')
        ->toContain('`staff.handover_ready`')
        ->toContain('PENDING OWNER')
        ->toContain('Giai đoạn 2')
        ->toContain(count(pwaDocsEventTopics()).' chủ đề');
});
