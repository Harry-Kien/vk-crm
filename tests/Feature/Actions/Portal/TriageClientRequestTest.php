<?php

use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Hộp thư của văn phòng, phần không phải viết chữ: nhận, giao việc, đổi trạng thái (SPEC §7.2).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->action = app(TriageClientRequest::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);

    $this->request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::New,
    ]);
});

function reloadThread(ClientRequest $request): ClientRequest
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail($request->getKey());
}

// =========================================================================================
// GIAO VIỆC — và "nhận" là cùng một động tác
// =========================================================================================

it('assigns the request and lifts it out of new in one move', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->action->assign($this->request, $this->lawyer, $assistant);

    expect(reloadThread($this->request)->assigned_to)->toBe($assistant->id)
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

/**
 * Chiều ngược lại KHÔNG tự động, và đó là luật chứ không phải một chỗ quên: `new` nghĩa là "chưa
 * ai trong văn phòng nhìn thấy", và một khi đã có người nhìn thì điều đó không thành chưa xảy ra
 * được nữa.
 */
it('never sends a request back to new when the assignee is taken away', function () {
    $this->action->assign($this->request, $this->lawyer, $this->lawyer);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);

    $this->action->assign(reloadThread($this->request), $this->lawyer, null);

    expect(reloadThread($this->request)->assigned_to)->toBeNull()
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

it('leaves a status that is past new alone when assigning', function () {
    $this->request->update(['status' => ClientRequestStatus::Answered]);

    $this->action->assign(reloadThread($this->request), $this->lawyer, $this->lawyer);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::Answered);
});

/**
 * Giao một yêu cầu cho người không mở được hồ sơ là đẩy nó vào một hàng đợi không ai nhìn thấy.
 * Vế dương ngay cạnh: một người TRONG đội ngũ thì nhận được.
 */
it('refuses an assignee who cannot open the matter, and accepts one who can', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    try {
        $this->action->assign($this->request, $this->lawyer, $outsider);
        $this->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('assigned_to')
            ->and($exception->errors()['assigned_to'][0])->toBe(__('requests.validation.assignee_cannot_open'));
    }

    expect(reloadThread($this->request)->assigned_to)->toBeNull();

    $this->action->assign($this->request, $this->lawyer, $this->lawyer);

    expect(reloadThread($this->request)->assigned_to)->toBe($this->lawyer->id);
});

// =========================================================================================
// ĐỔI TRẠNG THÁI
// =========================================================================================

it('stamps answered_at the first time the status lands on answered and never moves it again', function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::Answered);

    $this->travelTo('2026-09-25 10:00:00');
    $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::InProgress);
    $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::Answered);

    expect(reloadThread($this->request)->answered_at->toDateTimeString())->toBe('2026-09-21 10:00:00');
});

it('lets a closed thread be reopened, because there is no transition matrix here', function () {
    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::Closed);
    $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::InProgress);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

// =========================================================================================
// CỔNG QUYỀN
// =========================================================================================

it('refuses a staff member without matter update on both methods, and lets a team lawyer through', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => $this->action->assign($this->request, $accountant, $this->lawyer))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->action->setStatus($this->request, $accountant, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class)
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::New);

    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::Closed);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::Closed);
});

it('refuses a request on a matter the staff member cannot list', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    expect(fn () => $this->action->setStatus($this->request, $outsider, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class);
});

it('refuses a soft deleted matter and a deactivated staff account with the same sentence', function () {
    $deactivated = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $this->matter->addTeamMember($deactivated, MatterRole::Assistant);

    $ghost = ClientRequest::factory()->for($this->matter)->make(['id' => 999999]);

    $messages = collect([
        [$this->request, $deactivated],
        [$ghost, $this->lawyer],
    ])->map(function (array $case): string {
        [$thread, $actor] = $case;

        try {
            $this->action->setStatus($thread, $actor, ClientRequestStatus::Closed);
        } catch (AuthorizationException $exception) {
            return $exception->getMessage();
        }

        return 'KHÔNG TỪ CHỐI';
    })->unique();

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBe(__('requests.unavailable'));
});

/** Tham số đi ra từ URL và không được tin: Action đọc lại hàng thật. */
it('reads the thread back instead of trusting the object it was handed', function () {
    $foreign = ClientRequest::factory()
        ->for(Matter::factory()->create(['is_published_to_portal' => true]))
        ->create();
    $foreign->matter_id = $this->matter->id;

    expect(fn () => $this->action->setStatus($foreign, $this->lawyer, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class);
});

// =========================================================================================
// NHẬT KÝ — causer là NHÂN SỰ, đo với phiên khách đang mở
// =========================================================================================

/**
 * Cảnh phân biệt được: một phiên KHÁCH đang mở trong cùng trình duyệt. `Audit::record()` ưu tiên
 * guard `web`, nên ở đây một lần bỏ `causer:` tường minh vẫn xanh — thứ làm nó ĐỎ là một lời gọi
 * không có phiên nào cả (một job, một lệnh console), nên test này chạy cả hai cảnh.
 */
it('credits the staff member in both a browser session and a session less call', function () {
    $this->actingAs($this->clientUser, 'client');

    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::InProgress);

    $withSession = Activity::query()->where('event', 'client_request_status_changed')->latest('id')->firstOrFail();

    expect($withSession->causer_type)->toBe($this->lawyer->getMorphClass())
        ->and($withSession->causer_id)->toBe($this->lawyer->id)
        ->and($withSession->properties['from'])->toBe('new')
        ->and($withSession->properties['to'])->toBe('in_progress');

    // Không phiên nào cả: nhánh mà một `causer` suy ra từ `auth()` sẽ để trống.
    auth('client')->logout();
    auth('web')->logout();

    $this->action->assign(reloadThread($this->request), $this->lawyer, $this->lawyer);

    $sessionless = Activity::query()->where('event', 'client_request_assigned')->latest('id')->firstOrFail();

    expect($sessionless->causer_type)->toBe($this->lawyer->getMorphClass())
        ->and($sessionless->causer_id)->toBe($this->lawyer->id)
        ->and($sessionless->properties['to'])->toBe($this->lawyer->id);
});

it('writes no log line at all when it refuses', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => $this->action->setStatus($this->request, $accountant, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class)
        ->and(Activity::query()->whereIn('event', [
            'client_request_status_changed',
            'client_request_assigned',
        ])->count())->toBe(0);
});
