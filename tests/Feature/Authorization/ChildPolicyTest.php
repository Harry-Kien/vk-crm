<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->log = StageLog::factory()->for($this->matter)->published()->create();
    $this->internalDoc = Document::factory()->for($this->matter)->group(DocumentGroup::Internal)->create();
    $this->clientDoc = Document::factory()->for($this->matter)->group(DocumentGroup::Issued)
        ->create([
            'status' => DocumentStatus::Published,
            'client_can_view' => true,
            'client_can_download' => false,
        ]);
});

it('ties every child record to the visibility of its matter', function () {
    expect($this->lead->can('view', $this->log))->toBeTrue()
        ->and($this->outsider->can('view', $this->log))->toBeFalse()
        ->and($this->outsider->can('view', $this->clientDoc))->toBeFalse()
        ->and($this->outsider->can('view', MatterChecklistItem::factory()->for($this->matter)->create()))->toBeFalse()
        ->and($this->outsider->can('view', Deadline::factory()->for($this->matter)->create()))->toBeFalse();
});

it('limits publishing progress to the roles that may', function () {
    expect($this->lead->can('publish', $this->log))->toBeTrue()
        ->and($this->assistant->can('publish', $this->log))->toBeFalse()
        ->and($this->outsider->can('publish', $this->log))->toBeFalse();
});

it('keeps group D documents away from anyone without document.viewInternal', function () {
    expect($this->lead->can('view', $this->internalDoc))->toBeTrue()
        ->and($this->assistant->can('view', $this->internalDoc))->toBeFalse()
        ->and($this->accountant->can('view', $this->internalDoc))->toBeFalse();
});

it('never lets a client user see a group D document or download what is not downloadable', function () {
    expect($this->clientUser->can('view', $this->internalDoc))->toBeFalse()
        ->and($this->clientUser->can('view', $this->clientDoc))->toBeTrue()
        ->and($this->clientUser->can('download', $this->clientDoc))->toBeFalse();

    $this->clientDoc->update(['client_can_download' => true]);

    expect($this->clientUser->fresh()->can('download', $this->clientDoc->fresh()))->toBeTrue();
});

it('denies a group D document to a client even when both client flags are on', function () {
    $this->internalDoc->update(['client_can_view' => true, 'client_can_download' => true]);

    expect($this->clientUser->can('view', $this->internalDoc->fresh()))->toBeFalse()
        ->and($this->clientUser->can('download', $this->internalDoc->fresh()))->toBeFalse();
});

it('never lets a client user see data of another client', function () {
    $otherMatter = Matter::factory()->create();
    $otherLog = StageLog::factory()->for($otherMatter)->published()->create();

    expect($this->clientUser->can('view', $otherMatter))->toBeFalse()
        ->and($this->clientUser->can('view', $otherLog))->toBeFalse();
});

it('never lets a client user write to internal records', function () {
    expect($this->clientUser->can('update', $this->matter))->toBeFalse()
        ->and($this->clientUser->can('create', StageLog::class))->toBeFalse()
        ->and($this->clientUser->can('view', MatterParty::factory()->for($this->matter)->create()))->toBeFalse()
        ->and($this->clientUser->can('viewAny', MatterParty::class))->toBeFalse();
});

it('lets a client user raise and read their own requests', function () {
    $request = ClientRequest::factory()->for($this->matter)->create(['client_user_id' => $this->clientUser->id]);

    expect($this->clientUser->can('create', ClientRequest::class))->toBeTrue()
        ->and($this->clientUser->can('view', $request))->toBeTrue();
});

it('gates client and settings management by permission', function () {
    expect($this->assistant->can('viewAny', Client::class))->toBeTrue()
        ->and($this->lead->can('viewAny', Client::class))->toBeFalse()
        ->and($this->lead->can('create', ClientUser::class))->toBeTrue()
        ->and($this->admin->can('create', MatterType::class))->toBeTrue()
        ->and($this->lead->can('create', MatterType::class))->toBeFalse();
});

it('answers the create ability the way Laravel actually calls it', function () {
    expect($this->lead->can('create', Deadline::class))->toBeTrue()
        ->and($this->lead->can('create', CommunicationLog::class))->toBeTrue()
        ->and($this->accountant->can('create', Deadline::class))->toBeFalse()
        ->and($this->clientUser->can('create', Deadline::class))->toBeFalse()
        ->and($this->clientUser->can('create', CommunicationLog::class))->toBeFalse();
});

it('lets staff read the client of a matter they can see, but not manage it', function () {
    expect($this->lead->can('view', $this->client))->toBeTrue()
        ->and($this->outsider->can('view', $this->client))->toBeFalse()
        ->and($this->assistant->can('view', Client::factory()->create()))->toBeTrue()
        ->and($this->lead->can('update', $this->client))->toBeFalse()
        ->and($this->lead->can('create', Client::class))->toBeFalse()
        ->and($this->lead->can('delete', $this->client))->toBeFalse();
});

it('lets staff read the client user of a matter they can see, but not manage it', function () {
    // Kế toán không có clientUser.manage; matter.viewAny cho họ thấy mọi vụ việc thường
    // (SPEC §5), nên họ đọc được client user của $this->matter nhưng không quản lý được nó,
    // và không thấy client user không gắn với vụ việc nào cả.
    $unrelatedClientUser = ClientUser::factory()->create();

    expect($this->accountant->can('view', $this->clientUser))->toBeTrue()
        ->and($this->accountant->can('view', $unrelatedClientUser))->toBeFalse()
        ->and($this->accountant->can('update', $this->clientUser))->toBeFalse()
        ->and($this->accountant->can('create', ClientUser::class))->toBeFalse()
        ->and($this->accountant->can('delete', $this->clientUser))->toBeFalse();
});

it('answers portal visibility for a child model even when no guard is open', function () {
    $foreignLog = StageLog::factory()->published()->create();
    $ownLog = StageLog::factory()->for($this->matter)->published()->create();

    expect(ClientPortalScope::isActive())->toBeFalse()
        ->and($this->clientUser->can('view', $foreignLog))->toBeFalse()
        ->and($this->clientUser->can('view', $ownLog))->toBeTrue();
});
