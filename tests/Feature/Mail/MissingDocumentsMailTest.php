<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\OutboundStatus;
use App\Jobs\SendMissingDocumentsMail;
use App\Mail\Client\MissingDocuments;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Support\PortalUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Mẫu `client.missing_documents` (SPEC §6.9, §9): nội dung thư (R7), ranh giới nội dung (R6) và
 * dòng nhật ký thư. Chuyện "gửi cho ai, khi nào" nằm ở `RemindMissingDocumentsTest` /
 * `SendMissingDocumentsMailTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{0: Matter, 1: ClientUser} */
function missingDocsMailFixture(): array
{
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'name' => 'Nguyễn Thị Lan']);
    $matter = Matter::factory()->create(['client_id' => $client->id]);

    return [$matter, $account];
}

/** @param  array<string, mixed>  $attributes */
function outstandingItem(Matter $matter, string $name, ChecklistItemStatus $status = ChecklistItemStatus::Missing, array $attributes = []): MatterChecklistItem
{
    return MatterChecklistItem::factory()->for($matter)->status($status)->create(array_merge([
        'name' => $name,
        'is_required' => true,
    ], $attributes));
}

/** @return array{subject: string, html: string, text: string} */
function renderMissingDocuments(Matter $matter, ClientUser $account, Collection $items): array
{
    $mail = new MissingDocuments($matter, $account, $items);

    return [
        'subject' => $mail->envelope()->subject,
        'html' => $mail->render(),
        'text' => view($mail->content()->text, $mail->content()->with)->render(),
    ];
}

/**
 * R7: liệt kê ĐÚNG những gì còn thiếu bằng tên người thường đọc được, kèm liên kết vào cổng, trong
 * cả bản HTML lẫn bản văn bản thuần; tiêu đề có mã hồ sơ.
 */
it('lists exactly the items that are missing, with a portal link, in both bodies', function () {
    [$matter, $account] = missingDocsMailFixture();
    $one = outstandingItem($matter, 'Chứng minh nhân dân');
    $two = outstandingItem($matter, 'Sổ hộ khẩu');

    $parts = renderMissingDocuments($matter, $account, collect([$one, $two]));

    expect($parts['subject'])->toContain($matter->code)
        ->and($parts['html'])->toContain('Chứng minh nhân dân')->toContain('Sổ hộ khẩu')
        ->toContain('Nguyễn Thị Lan')->toContain(PortalUrl::base())
        ->and($parts['text'])->toContain('- Chứng minh nhân dân')->toContain('- Sổ hộ khẩu')
        ->toContain(PortalUrl::base());
});

/**
 * Bản văn bản thuần đọc được như một danh sách (nghiệm thu M6 Task 10, thư thật trong log trên dữ
 * liệu seed): mỗi đầu mục một dòng, KHÔNG có dấu cách thừa cuối dòng, và một dòng trống trước liên
 * kết vào cổng. Trước bản sửa, `@endif` cuối vòng lặp nuốt mất dòng trống nên liên kết dính ngay
 * dưới đầu mục cuối, và dấu cách giữa hai khối `@if` để lại một khoảng trắng cuối mỗi dòng.
 *
 * Mutation probe: bỏ dòng trống sau `@endforeach` trong `missing-documents-text.blade.php` → ĐỎ.
 */
it('keeps the plain-text list readable: one item per line, no trailing space, a blank line before the portal link', function () {
    [$matter, $account] = missingDocsMailFixture();
    $one = outstandingItem($matter, 'Chứng minh nhân dân');
    $two = outstandingItem($matter, 'Sổ hộ khẩu', ChecklistItemStatus::Rejected, [
        'rejection_reason' => 'Ảnh mờ.',
        'reviewed_at' => now(),
    ]);

    $text = renderMissingDocuments($matter, $account, collect([$one, $two]))['text'];

    expect($text)->toContain(__('portal.email.missing_documents.line', ['code' => $matter->code])."\n\n- Chứng minh nhân dân\n- Sổ hộ khẩu — ")
        ->toContain('Ảnh mờ.')
        ->toContain(")\n\n".__('portal.email.missing_documents.open').' '.PortalUrl::base());
});

/** Đúng những gì được TRUYỀN vào: một đầu mục không có trong danh sách thì không có trong thư. */
it('does not mention an item that is not in the list it was given', function () {
    [$matter, $account] = missingDocsMailFixture();
    $listed = outstandingItem($matter, 'Chứng minh nhân dân');
    outstandingItem($matter, 'Giấy khai sinh');

    $parts = renderMissingDocuments($matter, $account, collect([$listed]));

    expect($parts['html'])->not->toContain('Giấy khai sinh')
        ->and($parts['text'])->not->toContain('Giấy khai sinh');
});

/**
 * Tiêu đề không nêu tên giấy tờ (dòng nhật ký thư hiện cho mọi người xem được vụ, kể cả trợ lý)
 * — chỉ mã hồ sơ và một câu chung.
 */
it('never puts an item name in the subject', function () {
    [$matter, $account] = missingDocsMailFixture();
    $item = outstandingItem($matter, 'Giấy chứng tử DAU-HIEU-TEN');

    $parts = renderMissingDocuments($matter, $account, collect([$item]));

    expect($parts['subject'])->not->toContain('DAU-HIEU-TEN')
        ->and($parts['html'])->toContain('DAU-HIEU-TEN');
});

/** Đầu mục bị từ chối kèm đúng lý do văn phòng đã viết cho khách đọc; đầu mục chưa nộp thì không có lý do. */
it('shows the rejection reason for a rejected item and none for a missing one', function () {
    [$matter, $account] = missingDocsMailFixture();
    $rejected = outstandingItem($matter, 'Ảnh chân dung', ChecklistItemStatus::Rejected, [
        'rejection_reason' => 'Ảnh bị mờ LY-DO-TU-CHOI, vui lòng chụp lại.',
    ]);
    $missing = outstandingItem($matter, 'Sổ hộ khẩu');

    $parts = renderMissingDocuments($matter, $account, collect([$rejected, $missing]));

    expect($parts['html'])->toContain('LY-DO-TU-CHOI')->toContain('cần nộp lại')
        ->and($parts['text'])->toContain('LY-DO-TU-CHOI')->toContain('cần nộp lại');
});

/**
 * Lý do từ chối còn sót lại trên một đầu mục đã trở về `missing` KHÔNG phải thứ khách được đọc
 * (cổng chỉ hiện lý do khi đầu mục đang `rejected`) — thư cũng vậy.
 *
 * Mutation probe: bỏ điều kiện `$item->status === ChecklistItemStatus::Rejected` khỏi `reason` ở
 * `MissingDocuments::content()` — test này ĐỎ.
 */
it('does not show a stale rejection reason on an item that is missing again', function () {
    [$matter, $account] = missingDocsMailFixture();
    $item = outstandingItem($matter, 'Sổ hộ khẩu', ChecklistItemStatus::Missing, [
        'rejection_reason' => 'LY-DO-CU-CON-SOT lần trước.',
    ]);

    $parts = renderMissingDocuments($matter, $account, collect([$item]));

    expect($parts['html'])->not->toContain('LY-DO-CU-CON-SOT')
        ->and($parts['text'])->not->toContain('LY-DO-CU-CON-SOT')
        ->and($parts['html'])->not->toContain('cần nộp lại');
});

/**
 * R6, ranh giới nội dung: một cột nội bộ của hồ sơ không bao giờ vào hộp thư của khách — soi ở
 * cả tiêu đề, HTML và văn bản thuần, bằng chuỗi đánh dấu chứ không bằng mắt. Cặp dương: nội dung ĐÃ
 * CÔNG BỐ (tên đầu mục) vẫn phải có mặt.
 */
it('never carries the matters internal description into the client mailbox', function () {
    [$matter, $account] = missingDocsMailFixture();
    $marker = 'DAU-HIEU-NOI-BO-'.uniqid();
    $matter->update(['description_internal' => $marker]);
    $item = outstandingItem($matter, 'Sổ hộ khẩu');

    $parts = renderMissingDocuments($matter->fresh(), $account, collect([$item]));

    expect($parts['subject'])->not->toContain($marker)
        ->and($parts['html'])->not->toContain($marker)
        ->and($parts['text'])->not->toContain($marker)
        ->and($parts['html'])->toContain('Sổ hộ khẩu');
});

/** Tên đầu mục do nhân sự gõ: bản HTML phải thoát nó (thư không được mang một liên kết lừa đảo). */
it('escapes a staff-typed item name in the HTML body', function () {
    [$matter, $account] = missingDocsMailFixture();
    $item = outstandingItem($matter, 'Bản sao <a href="https://x.test">bấm vào</a>');

    $parts = renderMissingDocuments($matter, $account, collect([$item]));

    expect($parts['html'])->not->toContain('<a href="https://x.test">')
        ->and($parts['html'])->toContain('&lt;a href');
});

/**
 * Đi qua cánh cửa thật (không Mail::fake): dòng `outbound_messages` mang đúng mẫu, đúng hồ sơ, và
 * payload KHÔNG mang tên giấy tờ (tiêu đề không có, và ledger chỉ lưu tiêu đề).
 */
it('writes one ledger row with the template and the matter as its related record', function () {
    [$matter, $account] = missingDocsMailFixture();
    $item = outstandingItem($matter, 'Giấy chứng tử DAU-HIEU-TEN');

    (new SendMissingDocumentsMail($matter->id))->handle();

    $row = OutboundMessage::query()->withoutGlobalScopes()->where('recipient', $account->email)->sole();

    expect($row->template)->toBe('client.missing_documents')
        ->and($row->related_type)->toBe($matter->getMorphClass())
        ->and($row->related_id)->toBe($matter->getKey())
        ->and($row->status)->toBe(OutboundStatus::Sent)
        ->and(json_encode($row->payload, JSON_UNESCAPED_UNICODE))->not->toContain('DAU-HIEU-TEN');
});

it('addresses the mail to the account and names the matter in the subject', function () {
    Mail::fake();
    [$matter, $account] = missingDocsMailFixture();
    outstandingItem($matter, 'Sổ hộ khẩu');

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertSent(MissingDocuments::class, fn (MissingDocuments $mail) => $mail->hasTo($account->email)
        && str_contains($mail->envelope()->subject, $matter->code));
});
