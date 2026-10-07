<?php

use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M11 Task 12 — nháp trả lời yêu cầu của khách do AI soạn, trên tab "Yêu cầu từ khách"
|--------------------------------------------------------------------------
|
| Khối "Nháp trả lời từ AI (n)" đứng trên hộp thư. "Mở nháp" mở form trả lời, điền sẵn nội dung
| nháp, kèm nguyên cuộc trao đổi; bấm Gửi thì câu trả lời thật sinh ra qua `ReplyToClientRequest`
| dưới tên NGƯỜI BẤM, và nháp trỏ `used_reply_id` tới nó. "Bỏ nháp" đòi lý do.
|
| Mọi test ở đây đi qua màn hình (Livewire, relation manager của trang `ViewMatter`).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->client = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id, 'name' => 'Nguyễn Văn An']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->author = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Người Soạn Qua AI']);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->matter->addTeamMember($this->author, MatterRole::Associate);

    $this->request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'subject' => 'Xin hỏi về ngày hoà giải',
        'content' => 'Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.',
        'status' => ClientRequestStatus::New,
    ]);
});

function rdDraft(ClientRequest $request, User $author, array $attributes = []): ClientRequestReplyDraft
{
    return ClientRequestReplyDraft::factory()->create([
        'request_id' => $request->id,
        'created_by' => $author->id,
        'content' => 'Chào anh An, toà đã hẹn hoà giải lúc 9 giờ ngày 15/10.',
        ...$attributes,
    ]);
}

function rdInbox(Matter $matter): Testable
{
    return test()->livewire(ClientRequestsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

function rdAction(string $name, ClientRequestReplyDraft $draft): TestAction
{
    $name = ['useDraft' => 'useReplyDraft', 'discardDraft' => 'discardReplyDraft'][$name];

    return TestAction::make($name)->arguments(['draft' => $draft->id]);
}

/**
 * `ReportsActionFailures` gửi lời từ chối của Action với tiêu đề chung và câu thật ở thân — đọc cả
 * hai hàng đợi thông báo của session (cùng cách `DeadlinesRelationManagerTest` làm).
 */
function rdAssertRefusalShown(string $message): void
{
    $sent = collect([
        ...session()->get('filament.notifications', []),
        ...session()->get('filament.claimed_notifications', []),
    ]);

    expect([...$sent->pluck('title')->all(), ...$sent->pluck('body')->all()])->toContain($message);
}

function rdReload(ClientRequest $request): ClientRequest
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail($request->getKey());
}

it('shows the pending reply drafts above the inbox with their count, the request they answer, and the draft text', function () {
    rdDraft($this->request, $this->author);

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->assertSee(__('ai_drafts.reply.heading', ['count' => 1]))
        ->assertSee('Người Soạn Qua AI')
        ->assertSee('Xin hỏi về ngày hoà giải')
        ->assertSee('Chào anh An, toà đã hẹn hoà giải lúc 9 giờ ngày 15/10.');
});

it('leaves used and discarded reply drafts, and drafts on another matter, out of the block', function () {
    $reply = ClientRequestReply::factory()->create(['request_id' => $this->request->id]);
    rdDraft($this->request, $this->author, ['content' => 'Nháp đã dùng rồi.'])->forceFill(['used_reply_id' => $reply->id])->save();
    ClientRequestReplyDraft::factory()->discarded($this->lawyer, 'Sai ngày')->create([
        'request_id' => $this->request->id, 'created_by' => $this->author->id, 'content' => 'Nháp đã bỏ rồi.',
    ]);
    $foreign = ClientRequest::factory()->for(Matter::factory()->create())->create();
    rdDraft($foreign, $this->author, ['content' => 'Nháp của vụ khác.']);

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->assertDontSee('Nháp đã dùng rồi.')
        ->assertDontSee('Nháp đã bỏ rồi.')
        ->assertDontSee('Nháp của vụ khác.');
});

it('opens a reply draft into the reply form, sends the edited text under the name of the person who clicked, and marks the draft used', function () {
    $draft = rdDraft($this->request, $this->author);

    $this->actingAs($this->lawyer, 'web');

    $page = rdInbox($this->matter)
        ->mountAction(rdAction('useDraft', $draft))
        ->assertSchemaStateSet(['content' => 'Chào anh An, toà đã hẹn hoà giải lúc 9 giờ ngày 15/10.']);

    // Cả cuộc trao đổi nằm trên ô nhập, như modal "Trả lời".
    expect((string) $page->instance()->getMountedAction()->getModalDescription())
        ->toContain('Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.');

    $page->fillForm(['content' => 'Chào anh An, toà đã hẹn hoà giải lúc 10 giờ ngày 15/10.'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $reply = ClientRequestReply::query()->where('request_id', $this->request->id)->sole();

    expect($reply->content)->toBe('Chào anh An, toà đã hẹn hoà giải lúc 10 giờ ngày 15/10.')
        ->and($reply->author_type)->toBe($this->lawyer->getMorphClass())
        ->and($reply->author_id)->toBe($this->lawyer->id)
        ->and($draft->refresh()->used_reply_id)->toBe($reply->id)
        ->and(rdReload($this->request)->status)->toBe(ClientRequestStatus::Answered);

    $audit = Activity::query()->where('event', 'mcp_draft_used')->sole();

    expect($audit->causer?->is($this->lawyer))->toBeTrue()
        ->and($audit->subject?->is($reply))->toBeTrue()
        ->and($audit->properties->get('draft_type'))->toBe('client_request_reply_draft')
        ->and($audit->properties->get('draft_id'))->toBe($draft->id)
        ->and($audit->properties->get('draft_created_by'))->toBe($this->author->id)
        ->and($audit->properties->get('client_request_id'))->toBe($this->request->id)
        ->and($audit->properties->get('matter_id'))->toBe($this->matter->id);
});

it('refuses an empty reply from a draft and keeps the draft pending', function () {
    $draft = rdDraft($this->request, $this->author);

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->callAction(rdAction('useDraft', $draft), data: ['content' => ''])
        ->assertHasFormErrors(['content' => 'required']);

    expect(ClientRequestReply::query()->where('request_id', $this->request->id)->exists())->toBeFalse()
        ->and($draft->refresh()->used_reply_id)->toBeNull();
});

it('hides open and discard from a person who cannot reply to the request, and shows them to the lead lawyer', function () {
    $draft = rdDraft($this->request, $this->author);
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $this->matter->addTeamMember($viewerOnly, MatterRole::Observer);

    $this->actingAs($viewerOnly, 'web');

    rdInbox($this->matter)
        ->assertSee('Chào anh An, toà đã hẹn hoà giải lúc 9 giờ ngày 15/10.')
        ->assertActionHidden(rdAction('useDraft', $draft))
        ->assertActionHidden(rdAction('discardDraft', $draft));

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->assertActionVisible(rdAction('useDraft', $draft))
        ->assertActionVisible(rdAction('discardDraft', $draft));
});

it('hides open on a closed request, where no reply can be written, but still allows discarding the draft', function () {
    $draft = rdDraft($this->request, $this->author);
    $this->request->forceFill(['status' => ClientRequestStatus::Closed])->save();

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->assertActionHidden(rdAction('useDraft', $draft))
        ->assertActionVisible(rdAction('discardDraft', $draft));
});

it('does not let a used reply draft be opened again', function () {
    $draft = rdDraft($this->request, $this->author);

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)->callAction(rdAction('useDraft', $draft))->assertHasNoFormErrors();

    rdInbox($this->matter)
        ->assertDontSee(__('ai_drafts.actions.open'))
        ->mountAction(rdAction('useDraft', $draft))
        ->assertNotified(__('ai_drafts.not_pending'))
        ->assertActionNotMounted(rdAction('useDraft', $draft));

    rdInbox($this->matter)
        ->callAction(rdAction('useDraft', $draft))
        ->assertNotified(__('ai_drafts.not_pending'));

    expect(ClientRequestReply::query()->where('request_id', $this->request->id)->count())->toBe(1);
});

it('does not open, on this matter page, a reply draft of a request on another matter', function () {
    $otherMatter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    $foreignRequest = ClientRequest::factory()->for($otherMatter)->create(['client_user_id' => $this->clientUser->id]);
    $foreign = rdDraft($foreignRequest, $this->lawyer);

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->assertActionHidden(rdAction('useDraft', $foreign))
        ->assertActionHidden(rdAction('discardDraft', $foreign))
        // Một request Livewire tự dựng, bỏ qua nút: hai action vẫn không chạy.
        ->call('mountAction', 'useReplyDraft', ['draft' => $foreign->id])
        ->call('callMountedAction')
        ->call('mountAction', 'discardReplyDraft', ['draft' => $foreign->id])
        ->call('callMountedAction');

    expect(ClientRequestReply::query()->exists())->toBeFalse()
        ->and($foreign->refresh()->isPending())->toBeTrue();

    // Cặp dương: trên trang của chính vụ đó, nút hiện.
    rdInbox($otherMatter)->assertActionVisible(rdAction('useDraft', $foreign));
});

it('refuses to send a reply draft that was discarded in another tab after the form was opened', function () {
    $draft = rdDraft($this->request, $this->author);

    $this->actingAs($this->lawyer, 'web');

    $page = rdInbox($this->matter)->mountAction(rdAction('useDraft', $draft));

    $draft->forceFill(['discarded_at' => now(), 'discarded_by' => $this->lawyer->id, 'discard_reason' => 'Sai ngày'])->save();

    $page->callMountedAction();

    rdAssertRefusalShown(__('ai_drafts.not_pending'));
    expect(ClientRequestReply::query()->where('request_id', $this->request->id)->exists())->toBeFalse()
        ->and($draft->refresh()->used_reply_id)->toBeNull();
});

it('requires a reason to discard a reply draft, then records who and why', function () {
    $draft = rdDraft($this->request, $this->author);

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)
        ->callAction(rdAction('discardDraft', $draft), data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    expect($draft->refresh()->discarded_at)->toBeNull();

    rdInbox($this->matter)
        ->callAction(rdAction('discardDraft', $draft), data: ['reason' => 'Ngày hoà giải chưa chốt.'])
        ->assertHasNoFormErrors();

    $draft->refresh();

    expect($draft->discarded_by)->toBe($this->lawyer->id)
        ->and($draft->discard_reason)->toBe('Ngày hoà giải chưa chốt.')
        ->and(ClientRequestReply::query()->where('request_id', $this->request->id)->exists())->toBeFalse();

    $audit = Activity::query()->where('event', 'mcp_draft_discarded')->sole();

    expect($audit->causer?->is($this->lawyer))->toBeTrue()
        ->and($audit->subject?->is($this->request))->toBeTrue()
        ->and($audit->properties->get('draft_type'))->toBe('client_request_reply_draft')
        ->and($audit->properties->get('draft_id'))->toBe($draft->id)
        ->and($audit->properties->get('client_request_id'))->toBe($this->request->id)
        ->and($audit->properties->get('matter_id'))->toBe($this->matter->id);
});

it('never shows the reply drafts to a person who cannot see the matter content', function () {
    rdDraft($this->request, $this->author);
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    rdInbox($this->matter)
        ->assertDontSee(__('ai_drafts.reply.heading', ['count' => 1]))
        ->assertDontSee('Chào anh An, toà đã hẹn hoà giải lúc 9 giờ ngày 15/10.');

    $this->actingAs($this->lawyer, 'web');

    rdInbox($this->matter)->assertSee('Chào anh An, toà đã hẹn hoà giải lúc 9 giờ ngày 15/10.');
});
