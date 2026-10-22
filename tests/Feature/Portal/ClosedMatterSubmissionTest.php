<?php

use App\Enums\ChecklistItemStatus;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Filament\Facades\Filament;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn portal — vụ ĐÃ KẾT THÚC mà văn phòng còn để trên cổng.
 *
 * Từ M7 Task 3, `SubmitClientDocument` từ chối mọi lần nộp vào vụ đã kết thúc
 * (`MatterClosedForSubmission`) và danh mục của vụ đó là chỉ đọc phía văn phòng. Nhưng cổng vẫn
 * mời khách nộp: khối "Việc anh/chị cần làm" liệt kê giấy tờ "còn chờ", mỗi đầu mục có nút "Gửi
 * giấy tờ này", thẻ hồ sơ ở danh sách mang huy hiệu đỏ "Còn N giấy tờ anh/chị cần nộp", và trang
 * nộp cho chọn đầu mục, chụp ảnh, tải tệp lên — rồi mới báo "vụ việc đã kết thúc" ở bước cuối.
 * Một hành động hiện ra mà luôn thất bại là một ngõ cụt; khách lớn tuổi chụp xong mười trang mới
 * biết không gửi được.
 */
beforeEach(function () {
    Filament::setCurrentPanel('portal');

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'closed_at' => now()->subDay(),
    ]);

    $this->missing = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Giấy chứng nhận quyền sử dụng đất',
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);

    $this->rejected = MatterChecklistItem::factory()->for($this->matter)->create([
        'name' => 'Căn cước công dân',
        'is_required' => true,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số. Nhờ anh/chị chụp lại giúp em.',
    ]);
});

function closedMatterClosedSentence(): string
{
    return __('checklist.submit.matter_closed', ['hotline' => config('vkcrm.brand.hotline')]);
}

it('does not invite the client to submit documents on a closed matter page', function () {
    $html = $this->actingAs($this->clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)
        // Không còn khối "Việc anh/chị cần làm" vì giấy tờ.
        ->not->toContain('data-portal-block="2"')
        // Không còn nút nộp / nộp lại.
        ->not->toContain(e(SubmitDocument::urlForItem($this->missing)))
        ->not->toContain(e(SubmitDocument::urlForItem($this->rejected)))
        ->not->toContain(__('portal_progress.checklist.status.missing'))
        // Vế dương: đầu mục vẫn hiện (lịch sử), cùng câu nói vì sao không gửi được nữa.
        ->toContain(e($this->missing->name))
        ->toContain(e(closedMatterClosedSentence()))
        ->toContain(e(__('portal_progress.checklist.status_closed.missing')));
});

it('keeps the submit invitation on an open matter', function () {
    $this->matter->update(['closed_at' => null]);

    $html = $this->actingAs($this->clientUser, 'client')
        ->get(MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('data-portal-block="2"')
        ->toContain(e(SubmitDocument::urlForItem($this->missing)))
        ->toContain(__('portal_progress.checklist.status.missing'))
        ->not->toContain(e(closedMatterClosedSentence()));
});

it('does not put a red outstanding-documents badge on a closed matter card', function () {
    $cards = $this->actingAs($this->clientUser, 'client')
        ->livewire(MyMatters::class, ['showAll' => true])
        ->instance()
        ->getCards();

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['outstanding'])->toBe(0)
        ->and($cards[0]['tone'])->not->toBe(MyMatters::TONE_OUTSTANDING)
        ->and((string) $cards[0]['status'])->not->toContain('cần nộp');
});

it('tells the client up front on the submit page that a closed matter takes no new documents', function () {
    $component = $this->actingAs($this->clientUser, 'client')
        ->livewire(SubmitDocument::class, ['record' => $this->matter->getKey()]);

    expect($component->html())
        ->toContain(e(closedMatterClosedSentence()))
        ->not->toContain('wire:click="chooseItem(');

    // Một đường dẫn sâu (`?item=`) hay một lời gọi tay cũng không mở được bước chụp ảnh.
    $component->call('chooseItem', $this->missing->getKey())
        ->assertHasErrors('item');

    expect($component->get('item'))->toBeNull();

    // Lối vào thật từ trang hồ sơ / thông báo: `?item=` trên URL của trang.
    $this->actingAs($this->clientUser, 'client')
        ->get(SubmitDocument::urlForItem($this->missing))
        ->assertOk()
        ->assertSee(closedMatterClosedSentence())
        ->assertDontSee(__('portal_submit.steps.file.heading'));
});

/**
 * Cùng họ lỗi: kết thúc vụ khi còn mốc hạn mở là việc được phép (làn fm A1 — hộp thoại cảnh báo rồi
 * cho đóng), và từ lúc đó `CheckDeadlines` thôi nhắc mốc của vụ. Nhưng khối "Mốc thời hạn sắp tới"
 * trên cổng vẫn vẽ mốc ấy, và qua ngày hạn thì vẽ chữ đỏ "Đã quá hạn" — trên một hồ sơ ĐÃ KẾT THÚC.
 * Vụ đã kết thúc không còn mốc nào khách phải nhớ (cùng lý lẽ làn fm B3: không còn "tin tiếp theo").
 */
it('shows no upcoming or overdue deadline on a closed matter', function () {
    Deadline::factory()->for($this->matter)->create([
        'name' => 'Hạn nộp đơn kháng cáo',
        'due_date' => today()->subDays(3),
        'is_published' => true,
        'is_completed' => false,
    ]);

    $url = MatterProgress::getUrl(['record' => $this->matter->getKey()], panel: 'portal');

    $this->actingAs($this->clientUser, 'client')
        ->get($url)
        ->assertOk()
        ->assertDontSee('Hạn nộp đơn kháng cáo')
        ->assertDontSee(__('portal_progress.deadlines.overdue'))
        ->assertSee(__('portal_progress.blocks.deadlines.empty'));

    // Vế dương: cùng mốc trên vụ còn mở vẫn hiện.
    $this->matter->update(['closed_at' => null]);

    $this->actingAs($this->clientUser, 'client')
        ->get($url)
        ->assertOk()
        ->assertSee('Hạn nộp đơn kháng cáo')
        ->assertSee(__('portal_progress.deadlines.overdue'));
});
