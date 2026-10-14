<?php

use App\Actions\Document\DeleteDocument;
use App\Actions\Document\KeepAcceptedChecklistVersion;
use App\Actions\Document\ReopenChecklistItem;
use App\Actions\Document\UpdateDocumentDetails;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\DocumentCorrectionRefused;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * Làn fa (A4–A6) — luật của các Action sửa sai mà màn hình chặn trước (nút ẩn, ô chọn chỉ mời giá trị
 * hợp lệ, `required` của form), nên một test màn hình không chạm tới. Action là cổng thật cho mọi lối
 * vào khác. Hành vi màn hình ở `tests/Feature/Filament/{DocumentCorrections,ChecklistCorrections,
 * ChecklistVersions}Test.php`. Hàm toàn cục mang tiền tố `dca…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->matter->team()->attach($this->assistant->id, ['role_in_matter' => MatterRole::Assistant->value]);
});

function dcaDocument(Matter $matter, array $attributes = []): Document
{
    return Document::factory()->for($matter)->group(DocumentGroup::Authority)->create([
        'title' => 'Thông báo thụ lý',
        'status' => DocumentStatus::InternalDraft,
        ...$attributes,
    ]);
}

it('refuses an assistant who edits a document the client already sees, whatever the screen showed', function () {
    $published = dcaDocument($this->matter, ['status' => DocumentStatus::Published, 'client_can_view' => true]);

    expect(fn () => app(UpdateDocumentDetails::class)->handle($published, $this->assistant, 'Đổi tên', null, null))
        ->toThrow(AuthorizationException::class)
        ->and($published->fresh()->title)->toBe('Thông báo thụ lý');
});

it('refuses a checklist item of another matter, and a title of white space only', function () {
    $document = dcaDocument($this->matter);
    $foreign = MatterChecklistItem::factory()->for(Matter::factory())->create();

    $errors = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($errors(fn () => app(UpdateDocumentDetails::class)->handle($document, $this->lawyer, 'Thông báo thụ lý', null, $foreign->id)))
        ->toBe(['matter_checklist_item_id'])
        ->and($errors(fn () => app(UpdateDocumentDetails::class)->handle($document, $this->lawyer, "\u{00A0} \u{200B}", null, null)))
        ->toBe(['title'])
        ->and($document->fresh()->matter_checklist_item_id)->toBeNull();
});

it('refuses to delete for someone the policy refuses, and a document the client sees', function () {
    $draft = dcaDocument($this->matter);
    $published = dcaDocument($this->matter, ['status' => DocumentStatus::Published, 'client_can_view' => true]);

    expect(fn () => app(DeleteDocument::class)->handle($draft, $this->assistant, 'Tải nhầm vào vụ khác'))
        ->toThrow(DocumentCorrectionRefused::class)
        ->and(fn () => app(DeleteDocument::class)->handle($published, $this->lawyer, 'Tải nhầm vào vụ khác'))
        ->toThrow(DocumentCorrectionRefused::class)
        ->and($draft->fresh()->trashed())->toBeFalse()
        ->and($published->fresh()->trashed())->toBeFalse();
});

it('refuses to reopen an item that is missing, rejected or awaiting review', function (ChecklistItemStatus $status) {
    $item = MatterChecklistItem::factory()->for($this->matter)->create(['status' => $status]);

    expect(fn () => app(ReopenChecklistItem::class)->handle($item, $this->lawyer))
        ->toThrow(DocumentCorrectionRefused::class)
        ->and($item->fresh()->status)->toBe($status);
})->with([ChecklistItemStatus::Missing, ChecklistItemStatus::Rejected, ChecklistItemStatus::PendingReview]);

it('refuses to keep an accepted version that does not exist or is no longer on the portal', function () {
    $item = MatterChecklistItem::factory()->for($this->matter)->create(['status' => ChecklistItemStatus::PendingReview]);
    $newer = Document::factory()->for($this->matter)->pendingReview()->create(['matter_checklist_item_id' => $item->id, 'version' => 2]);
    $keep = fn () => app(KeepAcceptedChecklistVersion::class)->handle($item->fresh(), $this->lawyer, 'Ảnh gửi thêm bị mờ, giữ bản cũ đã nhận');

    // Chưa từng có bản đã nhận.
    expect($keep)->toThrow(DocumentCorrectionRefused::class);

    // Có số version đã nhận, nhưng bản đó đã bị rút khỏi cổng khách.
    $item->forceFill(['accepted_version' => 1])->save();
    Document::factory()->for($this->matter)->pendingReview()->create([
        'matter_checklist_item_id' => $item->id, 'version' => 1, 'status' => DocumentStatus::Retracted, 'client_can_view' => false,
    ]);

    expect($keep)->toThrow(DocumentCorrectionRefused::class)
        ->and($item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($newer->fresh()->status)->toBe(DocumentStatus::Published);
});
