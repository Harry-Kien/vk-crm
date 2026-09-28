<?php

use App\Enums\DocumentGroup;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\DocumentReferencedByBillingRecord;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Gộp M6.5 + M9, xung đột 5: một tệp đang được bản ghi tiền trỏ tới — bản scan phụ lục
 * (`contract_amendments.document_id`) hay biên lai (`payments.receipt_document_id`) — không xoá
 * được. Hai khoá ngoại ấy là `nullOnDelete`, và phụ lục lẫn khoản thu là bản ghi KHÔNG sửa/xoá
 * được: xoá tệp là lặng lẽ cắt bằng chứng khỏi một bản ghi tiền bất biến.
 *
 * Hai tầng, mỗi tầng một câu hỏi: `DocumentPolicy::delete` trả lời "nút xoá có bấm được không" kèm
 * lý do đọc được; hook `Document::deleting` chặn MỌI đường xoá (mềm lẫn cứng, kể cả một đường
 * tương lai không hỏi policy). `RetractDocument` (M7 Task 7) chưa tồn tại — khi nó có, nó phải hỏi
 * cùng {@see Document::isReferencedByBillingRecord()} (việc mang sang, xem báo cáo gộp M9).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
    ]);
    $this->scan = Document::factory()->group(DocumentGroup::Internal)->create(['matter_id' => $this->matter->id]);
});

/** Hai tầng cùng từ chối, và tệp còn nguyên — cả khi xoá mềm lẫn khi xoá cứng. */
function expectScanUndeletable(Document $scan, User $lead): void
{
    $verdict = Gate::forUser($lead)->inspect('delete', $scan);

    expect($verdict->denied())->toBeTrue()
        ->and($verdict->message())->toBe(__('documents.delete_blocked_billing_reference'));

    expect(fn () => $scan->delete())->toThrow(
        DocumentReferencedByBillingRecord::class,
        __('documents.delete_blocked_billing_reference'),
    );
    expect(fn () => $scan->forceDelete())->toThrow(DocumentReferencedByBillingRecord::class);

    $fresh = Document::withTrashed()->find($scan->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->trashed())->toBeFalse();
}

it('refuses to delete the scan a contract amendment points at', function () {
    $amendment = ContractAmendment::factory()->for($this->contract)->create(['document_id' => $this->scan->id]);

    expectScanUndeletable($this->scan, $this->lead);

    expect($amendment->fresh()->document_id)->toBe($this->scan->id);

    // "Không thấy" không phải "không có": dưới guard khách, `ClientPortalScope` trả `1 = 0` cho
    // mọi model tiền, nhưng câu trả lời của điều kiện này không được đổi theo người đang đăng nhập.
    $clientUser = ClientUser::factory()->create(['client_id' => $this->matter->client_id]);

    expect(ClientPortalScope::actingAs($clientUser, fn (): bool => $this->scan->isReferencedByBillingRecord()))
        ->toBeTrue();
});

/** Khoản thu đã huỷ vẫn là bản ghi được giữ lại — biên lai của nó cũng phải còn. */
it('refuses to delete a payment receipt, even once that payment has been voided', function () {
    $payment = Payment::factory()->for($this->instalment)->create([
        'amount' => 4_000_000,
        'receipt_document_id' => $this->scan->id,
        'attributed_lawyer_id' => $this->lead->id,
        'voided_at' => now(),
        'voided_by' => $this->lead->id,
        'void_reason' => str_repeat('a', 20),
    ]);

    expectScanUndeletable($this->scan, $this->lead);

    expect($payment->fresh()->receipt_document_id)->toBe($this->scan->id);
});

/** Cặp dương: một tệp không bản ghi tiền nào trỏ tới vẫn đi qua cả hai tầng như trước. */
it('still lets a document no money record points at through both layers', function () {
    expect($this->scan->isReferencedByBillingRecord())->toBeFalse()
        ->and(Gate::forUser($this->lead)->allows('delete', $this->scan))->toBeTrue();

    $this->scan->delete();

    expect(Document::withTrashed()->find($this->scan->id)->trashed())->toBeTrue();
});
