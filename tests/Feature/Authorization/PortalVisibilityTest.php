<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;

beforeEach(function () {
    $this->clientA = Client::factory()->create();
    $this->clientB = Client::factory()->create();
    $this->userA = ClientUser::factory()->create(['client_id' => $this->clientA->id]);

    $this->matterA = Matter::factory()->for($this->clientA)->create();
    $this->matterB = Matter::factory()->for($this->clientB)->create();

    $this->publishedLog = StageLog::factory()->for($this->matterA)->published()->create();
    $this->internalLog = StageLog::factory()->for($this->matterA)->internalOnly()->create();
    $this->foreignLog = StageLog::factory()->for($this->matterB)->published()->create();

    $this->visibleDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)
        ->create(['status' => DocumentStatus::Published, 'client_can_view' => true]);
    $this->hiddenDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)
        ->create(['status' => DocumentStatus::Published, 'client_can_view' => false]);
    // Đã ký và nộp toà nhưng CHƯA công bố: SPEC §4.11 nói đúng bước cuối mới ra tới khách,
    // nên `client_can_view` bật sẵn ở đây không được đủ.
    $this->unpublishedDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Issued)
        ->create(['status' => DocumentStatus::SignedFiled, 'client_can_view' => true]);
    $this->internalDoc = Document::factory()->for($this->matterA)->group(DocumentGroup::Internal)
        ->create(['status' => DocumentStatus::Published, 'client_can_view' => true]);
    $this->foreignDoc = Document::factory()->for($this->matterB)->group(DocumentGroup::Issued)
        ->create(['status' => DocumentStatus::Published, 'client_can_view' => true]);
});

it('shows only published stage logs of the own client', function () {
    $this->actingAs($this->userA, 'client');

    expect(StageLog::pluck('id')->all())->toBe([$this->publishedLog->id]);
});

it('shows only published viewable non internal documents of the own client', function () {
    $this->actingAs($this->userA, 'client');

    expect(Document::pluck('id')->all())->toBe([$this->visibleDoc->id])
        ->and(Document::find($this->hiddenDoc->id))->toBeNull()
        ->and(Document::find($this->unpublishedDoc->id))->toBeNull()
        ->and(Document::find($this->internalDoc->id))->toBeNull()
        ->and(Document::find($this->foreignDoc->id))->toBeNull();
});

it('shows only published deadlines of the own client', function () {
    $mine = Deadline::factory()->for($this->matterA)->published()->create();
    Deadline::factory()->for($this->matterA)->create(['is_published' => false]);
    Deadline::factory()->for($this->matterB)->published()->create();

    $this->actingAs($this->userA, 'client');

    expect(Deadline::pluck('id')->all())->toBe([$mine->id]);
});

it('shows checklist items of the own matters only', function () {
    $mine = MatterChecklistItem::factory()->for($this->matterA)->create();
    MatterChecklistItem::factory()->for($this->matterB)->create();

    $this->actingAs($this->userA, 'client');

    expect(MatterChecklistItem::pluck('id')->all())->toBe([$mine->id]);
});

it('shows client requests and replies of the own matters only', function () {
    $mine = ClientRequest::factory()->for($this->matterA)->create(['client_user_id' => $this->userA->id]);
    $foreign = ClientRequest::factory()->for($this->matterB)->create();
    $mineReply = ClientRequestReply::factory()->create(['request_id' => $mine->id]);
    ClientRequestReply::factory()->create(['request_id' => $foreign->id]);

    $this->actingAs($this->userA, 'client');

    expect(ClientRequest::pluck('id')->all())->toBe([$mine->id])
        ->and(ClientRequestReply::pluck('id')->all())->toBe([$mineReply->id]);
});

it('shows only communication logs marked visible to the client', function () {
    $visible = CommunicationLog::factory()->for($this->matterA)->create(['is_visible_to_client' => true]);
    CommunicationLog::factory()->for($this->matterA)->create(['is_visible_to_client' => false]);

    $this->actingAs($this->userA, 'client');

    expect(CommunicationLog::pluck('id')->all())->toBe([$visible->id]);
});

it('shows only the own client record', function () {
    $this->actingAs($this->userA, 'client');

    expect(Client::pluck('id')->all())->toBe([$this->clientA->id]);
});

it('shows stage log view receipts of the own client', function () {
    $mine = StageLogView::factory()->create([
        'stage_log_id' => $this->publishedLog->id, 'client_user_id' => $this->userA->id,
    ]);
    StageLogView::factory()->create(['stage_log_id' => $this->foreignLog->id]);

    $this->actingAs($this->userA, 'client');

    expect(StageLogView::pluck('id')->all())->toBe([$mine->id]);
});

it('never shows tables the portal has no business reading', function () {
    MatterParty::factory()->for($this->matterA)->create();
    DocumentDownload::factory()->create(['document_id' => $this->visibleDoc->id]);
    OutboundMessage::factory()->create();
    MatterArchive::factory()->for($this->matterA)->create();
    $template = ChecklistTemplate::factory()->withItems(2)->create();

    $this->actingAs($this->userA, 'client');

    expect(MatterParty::count())->toBe(0)
        ->and(DocumentDownload::count())->toBe(0)
        ->and(OutboundMessage::count())->toBe(0)
        ->and(MatterArchive::count())->toBe(0)
        ->and(ChecklistTemplate::count())->toBe(0)
        ->and($template->items()->count())->toBe(0);
});

it('leaves stage configuration readable because the portal renders it', function () {
    $this->actingAs($this->userA, 'client');

    expect($this->matterA->matterType->stages()->count())->toBeGreaterThan(0)
        ->and($this->matterA->currentStage())->not->toBeNull();
});

it('restricts nothing for staff', function () {
    MatterParty::factory()->for($this->matterA)->create();
    ChecklistTemplate::factory()->withItems(2)->create();
    OutboundMessage::factory()->create();

    $this->actingAs(User::factory()->create(), 'web');

    expect(StageLog::count())->toBe(3)
        ->and(Document::count())->toBe(5)
        ->and(Client::count())->toBe(2)
        ->and(MatterParty::count())->toBe(1)
        ->and(ChecklistTemplate::count())->toBe(1)
        ->and(OutboundMessage::count())->toBe(1);
});

it('cannot be escaped by an orWhere at the top level of the caller query', function () {
    $this->actingAs($this->userA, 'client');

    expect(Matter::where('id', $this->matterB->id)->orWhere('id', $this->matterA->id)->pluck('id')->all())
        ->toBe([$this->matterA->id])
        ->and(StageLog::where('id', $this->foreignLog->id)->orWhere('id', $this->internalLog->id)->count())
        ->toBe(0)
        ->and(MatterParty::where('id', 1)->orWhere('id', 2)->count())->toBe(0);
});
