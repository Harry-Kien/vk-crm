<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Mail\Client\DocumentPublished;
use App\Mail\Client\DocumentRejected;
use App\Mail\Client\MissingDocuments;
use App\Mail\Client\RequestAnswered;
use App\Mail\Client\StageUpdate;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use App\Models\User;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn portal — khách có NHIỀU hồ sơ.
 *
 * Năm thư báo khách về MỘT hồ sơ cụ thể ("Mở hồ sơ để xem đầy đủ", "Mở hồ sơ để nộp lại", "Mở hồ sơ
 * để xem câu trả lời"…) đều trỏ về `/portal` trần — trang danh sách. Khách có một hồ sơ được chuyển
 * thẳng tới hồ sơ đó, nhưng khách có ba hồ sơ (doanh nghiệp, người theo nhiều vụ) bấm "Mở hồ sơ" và
 * phải tự đi tìm đúng thẻ. Thông báo đẩy của cùng sự kiện đã mở đúng trang (`PushTopic::url()`); thư
 * nay trỏ cùng chỗ, dựng từ cấu hình như `PortalUrl::base()` (không theo host của request).
 */
beforeEach(function () {
    config(['vkcrm.admin_domain' => 'quantri.luatvukhang.test', 'vkcrm.portal_domain' => 'khachhang.luatvukhang.test']);

    $this->scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';
    $this->client = Client::factory()->create();
    $this->recipient = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
    $this->root = $this->scheme.'://khachhang.luatvukhang.test/portal';
});

it('opens the matter page from a stage update email', function () {
    $log = StageLog::factory()->for($this->matter)->create(['is_published' => true]);

    expect((new StageUpdate($log, $this->recipient))->render())
        ->toContain('href="'.$this->root.'/ho-so/'.$this->matter->id.'"');
});

it('opens the documents block of the matter from a document published email', function () {
    $document = Document::factory()->for($this->matter)->create([
        'group' => DocumentGroup::Authority,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    expect((new DocumentPublished($document, $this->recipient))->render())
        ->toContain('href="'.$this->root.'/ho-so/'.$this->matter->id.'#tai-lieu"');
});

it('opens the checklist block of the matter from a rejection and a missing-documents email', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->create([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ nên không đọc được số. Nhờ anh/chị chụp lại giúp em.',
        'reviewed_at' => now(),
    ]);

    $target = 'href="'.$this->root.'/ho-so/'.$this->matter->id.'#ho-so-giay-to"';

    expect((new DocumentRejected($item, $this->recipient))->render())->toContain($target)
        ->and((new MissingDocuments($this->matter, $this->recipient, collect([$item])))->render())->toContain($target);
});

it('opens the requests page of the matter from a request answered email', function () {
    $request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->recipient->id]);
    $reply = ClientRequestReply::factory()->create([
        'request_id' => $request->id,
        'author_type' => (new User)->getMorphClass(),
        'author_id' => User::factory()->create()->id,
    ]);

    expect((new RequestAnswered($reply, $this->recipient))->render())
        ->toContain('href="'.$this->root.'/yeu-cau/'.$this->matter->id.'"');
});
