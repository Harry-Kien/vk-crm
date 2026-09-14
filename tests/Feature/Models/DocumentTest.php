<?php

use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\Matter;
use App\Models\User;

it('records a polymorphic uploader for staff and clients', function () {
    $matter = Matter::factory()->create();
    $staff = User::factory()->create();
    $client = ClientUser::factory()->create(['client_id' => $matter->client_id]);

    $byStaff = Document::factory()->for($matter)->uploadedBy($staff)->create();
    $byClient = Document::factory()->for($matter)->uploadedBy($client)->create();

    expect($byStaff->uploader->is($staff))->toBeTrue()
        ->and($byClient->uploader->is($client))->toBeTrue()
        ->and($matter->documents)->toHaveCount(2);
});

it('links versions through parent_document_id', function () {
    $v1 = Document::factory()->create(['version' => 1]);
    $v2 = Document::factory()->for($v1->matter)->create(['version' => 2, 'parent_document_id' => $v1->id]);

    expect($v2->parent->is($v1))->toBeTrue()
        ->and($v1->newerVersions->first()->is($v2))->toBeTrue();
});

it('never exposes group D through the client visible scope', function () {
    $matter = Matter::factory()->create();
    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create(['client_can_view' => true]);
    Document::factory()->for($matter)->group(DocumentGroup::Issued)->create(['client_can_view' => false]);
    $visible = Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create(['client_can_view' => true]);

    expect(Document::query()->clientVisible()->pluck('id')->all())->toBe([$visible->id])
        ->and(Document::where('group', 'D')->first()->isInternal())->toBeTrue();
});

it('stores morph aliases instead of class names', function () {
    $doc = Document::factory()->uploadedBy(User::factory()->create())->create();

    expect($doc->getRawOriginal('uploader_type'))->toBe('user');
});

it('casts group and status to enums', function () {
    $doc = Document::factory()->pendingReview()->create();

    expect($doc->group)->toBe(DocumentGroup::ClientProvided)
        ->and($doc->status)->toBe(DocumentStatus::Published)
        ->and($doc->checklistItem)->not->toBeNull();
});

it('logs every download with the downloader', function () {
    $doc = Document::factory()->create();
    $viewer = ClientUser::factory()->create();

    DocumentDownload::factory()->create(['document_id' => $doc->id, 'downloader_type' => $viewer->getMorphClass(), 'downloader_id' => $viewer->id]);

    expect($doc->downloads)->toHaveCount(1)
        ->and($doc->downloads->first()->downloader->is($viewer))->toBeTrue();
});
