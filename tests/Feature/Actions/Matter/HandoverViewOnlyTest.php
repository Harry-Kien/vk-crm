<?php

use App\Actions\Matter\CollectHandoverEntries;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Support\Handover\HandoverEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — gói bàn giao không trao bản sao của một tài liệu
 * văn phòng đã công bố "chỉ xem, không cho tải". Hàm toàn cục mang tiền tố `hvo…`.
 */
beforeEach(function () {
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

function hvoDocument(Matter $matter, DocumentStatus $status, bool $download): Document
{
    $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create([
        'status' => $status,
        'client_can_view' => $status === DocumentStatus::Published,
        'client_can_download' => $download,
        'published_at' => $status === DocumentStatus::Published ? now() : null,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('van-ban.pdf', '%PDF-1.4 noi dung'))->toMediaCollection('file');

    return $document;
}

it('leaves out of the handover a document published as view only, and keeps the downloadable and the filed ones', function () {
    $matter = Matter::factory()->create();
    $viewOnly = hvoDocument($matter, DocumentStatus::Published, false);
    $downloadable = hvoDocument($matter, DocumentStatus::Published, true);
    $filed = hvoDocument($matter, DocumentStatus::SignedFiled, false);

    $ids = app(CollectHandoverEntries::class)->handle($matter, new MatterArchive)
        ->map(fn (HandoverEntry $entry): int => $entry->documentId)
        ->all();

    expect($ids)->toContain($downloadable->id, $filed->id)
        ->not->toContain($viewOnly->id);
});
