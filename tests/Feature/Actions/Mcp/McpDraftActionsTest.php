<?php

use App\Actions\Deadline\ConfirmAiDeadline;
use App\Actions\Mcp\DiscardDraft;
use App\Actions\Mcp\UseReplyDraft;
use App\Actions\Mcp\UseStageLogDraft;
use App\Enums\ClientRequestStatus;
use App\Enums\CreatedVia;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ClientRequestNotOpen;
use App\Exceptions\DeadlineNotCreatedViaAi;
use App\Exceptions\MatterStageChanged;
use App\Exceptions\McpDraftNotPending;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M11 Task 12 — các Action của bước "người trong /admin" với nháp và mốc tạo qua AI
|--------------------------------------------------------------------------
|
| Màn hình đo ở tests/Feature/Filament/{StageLogDrafts,ReplyDrafts,AiCreatedRecords}Test.php. Tệp
| này đo những nhánh màn hình không với tới được: thứ tự từ chối (quyền TRƯỚC trạng thái nháp),
| nháp không thuộc vụ/cuộc trao đổi, giai đoạn đã trôi, cuộc trao đổi đã đóng, tài khoản đã vô hiệu
| hoá, lý do quá dài, và luật "nháp đã xong thì không ghi thêm gì" ở tầng model.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
});

function mdaStageDraft(Matter $matter, array $attributes = []): StageLogDraft
{
    return StageLogDraft::factory()->create([
        'matter_id' => $matter->id,
        'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.',
        ...$attributes,
    ]);
}

function mdaUseStage(StageLogDraft $draft, Matter $matter, User $actor): StageLog
{
    return app(UseStageLogDraft::class)->handle(
        draft: $draft,
        matter: $matter,
        actor: $actor,
        occurredAt: today(),
        internalNote: null,
        publicContent: $draft->public_content,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );
}

/** @return array{0: ClientRequest, 1: ClientRequestReplyDraft} */
function mdaReplyDraft(Matter $matter, array $requestAttributes = []): array
{
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $request = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $clientUser->id,
        'status' => ClientRequestStatus::New,
        ...$requestAttributes,
    ]);

    return [$request, ClientRequestReplyDraft::factory()->create(['request_id' => $request->id])];
}

// ----------------------------------------------------------------------------------------------
// UseStageLogDraft
// ----------------------------------------------------------------------------------------------

it('refuses a person without transitionStage with the shared sentence, before saying anything about the draft', function () {
    $draft = mdaStageDraft($this->matter);
    $draft->forceFill(['used_stage_log_id' => StageLog::factory()->create(['matter_id' => $this->matter->id])->id])->save();

    expect(fn () => mdaUseStage($draft, $this->matter, $this->outsider))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'));

    // Cặp dương: người có quyền nghe đúng câu "đã dùng".
    expect(fn () => mdaUseStage($draft, $this->matter, $this->lawyer))
        ->toThrow(McpDraftNotPending::class, __('ai_drafts.not_pending'));
});

it('refuses a draft that belongs to another matter, and writes nothing', function () {
    $other = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $this->lawyer->id]);
    $draft = mdaStageDraft($other);

    expect(fn () => mdaUseStage($draft, $this->matter, $this->lawyer))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'))
        ->and(StageLog::query()->exists())->toBeFalse()
        ->and($draft->refresh()->isPending())->toBeTrue();

    // Cặp dương: đúng vụ của nó thì gửi được.
    expect(mdaUseStage($draft, $other, $this->lawyer)->matter_id)->toBe($other->id);
});

it('refuses to send from a draft for a deactivated or soft-deleted account with the shared sentence, and writes nothing (M11 Task 13, Task 12 m2)', function (string $how) {
    $draft = mdaStageDraft($this->matter);

    $how === 'deactivated'
        ? $this->lawyer->forceFill(['is_active' => false])->save()
        : $this->lawyer->delete();

    expect(fn () => mdaUseStage($draft, $this->matter, $this->lawyer))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'))
        ->and(StageLog::query()->exists())->toBeFalse()
        ->and($draft->refresh()->isPending())->toBeTrue();

    // Cặp dương: tài khoản hoạt động lại thì gửi được.
    $how === 'deactivated'
        ? $this->lawyer->forceFill(['is_active' => true])->save()
        : $this->lawyer->restore();

    expect(mdaUseStage($draft, $this->matter, $this->lawyer->fresh())->matter_id)->toBe($this->matter->id);
})->with(['deactivated', 'soft-deleted']);

it('refuses a draft on a soft-deleted matter', function () {
    $draft = mdaStageDraft($this->matter);
    $this->matter->delete();

    expect(fn () => mdaUseStage($draft, $this->matter, $this->lawyer))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'))
        ->and($draft->refresh()->isPending())->toBeTrue();
});

it('keeps the draft pending when the stage moved under the person who opened it', function () {
    $draft = mdaStageDraft($this->matter);
    $seen = $this->matter->fresh();

    Matter::query()->whereKey($this->matter->id)->update(['stage' => 'collecting_documents']);

    expect(fn () => mdaUseStage($draft, $seen, $this->lawyer))->toThrow(MatterStageChanged::class)
        ->and(StageLog::query()->exists())->toBeFalse()
        ->and($draft->refresh()->isPending())->toBeTrue()
        ->and(Activity::query()->where('event', 'mcp_draft_used')->exists())->toBeFalse();
});

it('writes the stage log under the name of the person who clicked, never the draft author', function () {
    $author = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($author, MatterRole::Associate);
    $draft = mdaStageDraft($this->matter, ['created_by' => $author->id]);

    $log = mdaUseStage($draft, $this->matter, $this->lawyer);

    expect($log->created_by)->toBe($this->lawyer->id)
        ->and(Activity::query()->where('event', 'matter_stage_transitioned')->sole()->causer?->is($this->lawyer))->toBeTrue()
        ->and(Activity::query()->where('event', 'mcp_draft_used')->sole()->properties->get('draft_created_by'))->toBe($author->id);
});

// ----------------------------------------------------------------------------------------------
// UseReplyDraft
// ----------------------------------------------------------------------------------------------

it('refuses to reply from a draft on a closed request and keeps the draft pending', function () {
    [$request, $draft] = mdaReplyDraft($this->matter, ['status' => ClientRequestStatus::Closed]);

    expect(fn () => app(UseReplyDraft::class)->handle($draft, $request, $this->lawyer, 'Trả lời'))
        ->toThrow(ClientRequestNotOpen::class)
        ->and(ClientRequestReply::query()->exists())->toBeFalse()
        ->and($draft->refresh()->isPending())->toBeTrue();
});

it('refuses a person who cannot reply with the shared sentence, before saying anything about the draft', function () {
    [$request, $draft] = mdaReplyDraft($this->matter);
    $draft->forceFill(['discarded_at' => now(), 'discarded_by' => $this->lawyer->id, 'discard_reason' => 'Sai'])->save();

    expect(fn () => app(UseReplyDraft::class)->handle($draft, $request, $this->outsider, 'Trả lời'))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'));

    expect(fn () => app(UseReplyDraft::class)->handle($draft, $request, $this->lawyer, 'Trả lời'))
        ->toThrow(McpDraftNotPending::class);
});

it('refuses a reply draft that belongs to another request of the same matter', function () {
    [$request] = mdaReplyDraft($this->matter);
    [, $otherDraft] = mdaReplyDraft($this->matter);

    expect(fn () => app(UseReplyDraft::class)->handle($otherDraft, $request, $this->lawyer, 'Trả lời'))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'))
        ->and(ClientRequestReply::query()->exists())->toBeFalse();
});

// ----------------------------------------------------------------------------------------------
// DiscardDraft
// ----------------------------------------------------------------------------------------------

it('refuses a discard reason longer than the limit, and accepts one exactly at it', function () {
    $draft = mdaStageDraft($this->matter);

    expect(fn () => app(DiscardDraft::class)->handle($draft, $this->lawyer, str_repeat('ạ', DiscardDraft::REASON_MAX_LENGTH + 1)))
        ->toThrow(ValidationException::class)
        ->and($draft->refresh()->isPending())->toBeTrue();

    app(DiscardDraft::class)->handle($draft, $this->lawyer, str_repeat('ạ', DiscardDraft::REASON_MAX_LENGTH));

    expect($draft->refresh()->isPending())->toBeFalse();
});

it('trims the discard reason before it is stored', function () {
    $draft = mdaStageDraft($this->matter);

    app(DiscardDraft::class)->handle($draft, $this->lawyer, "  Trùng nội dung.  \n");

    expect($draft->refresh()->discard_reason)->toBe('Trùng nội dung.')
        ->and(Activity::query()->where('event', 'mcp_draft_discarded')->sole()->properties->get('reason'))->toBe('Trùng nội dung.');
});

it('refuses a discard from a deactivated account or from a person without the gate of the draft', function (string $kind) {
    $draft = $kind === 'stage' ? mdaStageDraft($this->matter) : mdaReplyDraft($this->matter)[1];

    expect(fn () => app(DiscardDraft::class)->handle($draft, $this->outsider, 'Không cần'))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'));

    $this->lawyer->forceFill(['is_active' => false])->save();

    expect(fn () => app(DiscardDraft::class)->handle($draft, $this->lawyer->fresh(), 'Không cần'))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'))
        ->and($draft->refresh()->isPending())->toBeTrue();

    $this->lawyer->forceFill(['is_active' => true])->save();

    app(DiscardDraft::class)->handle($draft, $this->lawyer->fresh(), 'Không cần');

    expect($draft->refresh()->isPending())->toBeFalse();
})->with(['stage', 'reply']);

it('lets a reply draft of a closed request be discarded', function () {
    [, $draft] = mdaReplyDraft($this->matter, ['status' => ClientRequestStatus::Closed]);

    app(DiscardDraft::class)->handle($draft, $this->lawyer, 'Yêu cầu đã đóng.');

    expect($draft->refresh()->discarded_by)->toBe($this->lawyer->id);
});

it('sends nothing to anyone when a draft is discarded', function () {
    Mail::fake();
    $draft = mdaStageDraft($this->matter);
    [, $replyDraft] = mdaReplyDraft($this->matter);

    app(DiscardDraft::class)->handle($draft, $this->lawyer, 'Không cần');
    app(DiscardDraft::class)->handle($replyDraft, $this->lawyer, 'Không cần');

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(OutboundMessage::query()->exists())->toBeFalse();
});

// ----------------------------------------------------------------------------------------------
// ConfirmAiDeadline
// ----------------------------------------------------------------------------------------------

it('refuses to confirm a deadline entered on the web', function () {
    $deadline = Deadline::factory()->for($this->matter)->create(['responsible_user_id' => $this->lawyer->id]);

    expect(fn () => app(ConfirmAiDeadline::class)->handle($deadline, $this->lawyer))
        ->toThrow(DeadlineNotCreatedViaAi::class)
        ->and($deadline->refresh()->confirmed_at)->toBeNull();
});

it('keeps the first confirmer when a deadline is confirmed twice', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $this->lawyer->id,
        'created_via' => CreatedVia::Mcp,
    ]);
    $second = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($second, MatterRole::Associate);

    app(ConfirmAiDeadline::class)->handle($deadline, $this->lawyer);
    app(ConfirmAiDeadline::class)->handle($deadline, $second);

    expect($deadline->refresh()->confirmed_by)->toBe($this->lawyer->id)
        ->and(Activity::query()->where('event', 'deadline_ai_confirmed')->count())->toBe(1);
});

it('refuses to confirm for a person who cannot edit the deadline', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $this->lawyer->id,
        'created_via' => CreatedVia::Mcp,
    ]);
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $this->matter->addTeamMember($viewerOnly, MatterRole::Observer);

    expect(fn () => app(ConfirmAiDeadline::class)->handle($deadline, $viewerOnly))
        ->toThrow(AuthorizationException::class)
        ->and($deadline->refresh()->confirmed_at)->toBeNull();
});

// ----------------------------------------------------------------------------------------------
// Model: nháp đã xong thì không ghi thêm gì
// ----------------------------------------------------------------------------------------------

it('refuses any further write on a draft once it is used or discarded', function (string $kind, string $state, Closure $change) {
    $draft = $kind === 'stage' ? mdaStageDraft($this->matter) : mdaReplyDraft($this->matter)[1];
    $usedColumn = $draft::usedColumn();
    $target = $kind === 'stage'
        ? StageLog::factory()->create(['matter_id' => $this->matter->id])
        : ClientRequestReply::factory()->create(['request_id' => $draft->request_id]);

    $state === 'used'
        ? $draft->forceFill([$usedColumn => $target->id])->save()
        : $draft->forceFill(['discarded_at' => now(), 'discarded_by' => $this->lawyer->id, 'discard_reason' => 'Gốc'])->save();

    $before = $draft->fresh()->getAttributes();

    expect(fn () => $change($draft, $usedColumn, $target, $this->lawyer))->toThrow(McpDraftNotPending::class)
        ->and($draft->fresh()->getAttributes())->toBe($before);
})->with(['stage', 'reply'])->with([
    'đã dùng, rồi bỏ' => ['used', fn ($d, $col, $t, $u) => $d->forceFill(['discarded_at' => now(), 'discarded_by' => $u->id, 'discard_reason' => 'Bỏ'])->save()],
    'đã dùng, rồi trỏ sang bản ghi khác' => ['used', fn ($d, $col, $t, $u) => $d->forceFill([$col => null])->save()],
    'đã bỏ, rồi dùng' => ['discarded', fn ($d, $col, $t, $u) => $d->forceFill([$col => $t->id])->save()],
    'đã bỏ, rồi bỏ lại cái bỏ' => ['discarded', fn ($d, $col, $t, $u) => $d->forceFill(['discarded_at' => null, 'discarded_by' => null, 'discard_reason' => null])->save()],
    'đã bỏ, rồi đổi lý do' => ['discarded', fn ($d, $col, $t, $u) => $d->forceFill(['discard_reason' => 'Lý do khác'])->save()],
]);

it('refuses a pending draft becoming used and discarded in one write', function (string $kind) {
    $draft = $kind === 'stage' ? mdaStageDraft($this->matter) : mdaReplyDraft($this->matter)[1];
    $target = $kind === 'stage'
        ? StageLog::factory()->create(['matter_id' => $this->matter->id])
        : ClientRequestReply::factory()->create(['request_id' => $draft->request_id]);

    $draft->forceFill([
        $draft::usedColumn() => $target->id,
        'discarded_at' => now(), 'discarded_by' => $this->lawyer->id, 'discard_reason' => 'Bỏ',
    ]);

    expect(fn () => $draft->save())->toThrow(McpDraftNotPending::class)
        ->and($draft->fresh()->isPending())->toBeTrue();
})->with(['stage', 'reply']);

// ----------------------------------------------------------------------------------------------
// Ba Action tự từ chối nháp đã xong — không dựa vào chốt cuối ở model
// ----------------------------------------------------------------------------------------------

/**
 * Hook `saving` của nháp cũng chặn một nháp đã xong, nên nếu chỉ chạy bình thường thì không phân
 * biệt được Action có tự kiểm hay không. Tắt sự kiện model (`withoutEvents`): Action vẫn phải từ chối,
 * TRƯỚC khi gọi `TransitionMatterStage` / `ReplyToClientRequest` hay ghi gì.
 */
it('refuses a settled draft in the action itself, with the model guard switched off', function (string $case) {
    $stageDraft = mdaStageDraft($this->matter);
    [$request, $replyDraft] = mdaReplyDraft($this->matter);
    $stageDraft->forceFill(['discarded_at' => now(), 'discarded_by' => $this->lawyer->id, 'discard_reason' => 'Gốc'])->save();
    $replyDraft->forceFill(['discarded_at' => now(), 'discarded_by' => $this->lawyer->id, 'discard_reason' => 'Gốc'])->save();

    $call = match ($case) {
        'dùng nháp tiến độ' => fn () => mdaUseStage($stageDraft, $this->matter, $this->lawyer),
        'dùng nháp trả lời' => fn () => app(UseReplyDraft::class)->handle($replyDraft, $request, $this->lawyer, 'Trả lời'),
        'bỏ nháp tiến độ' => fn () => app(DiscardDraft::class)->handle($stageDraft, $this->lawyer, 'Bỏ lần hai'),
        'bỏ nháp trả lời' => fn () => app(DiscardDraft::class)->handle($replyDraft, $this->lawyer, 'Bỏ lần hai'),
    };

    expect(fn () => Model::withoutEvents($call))->toThrow(McpDraftNotPending::class)
        ->and(StageLog::query()->exists())->toBeFalse()
        ->and(ClientRequestReply::query()->exists())->toBeFalse()
        ->and($stageDraft->fresh()->discard_reason)->toBe('Gốc')
        ->and($replyDraft->fresh()->discard_reason)->toBe('Gốc');
})->with(['dùng nháp tiến độ', 'dùng nháp trả lời', 'bỏ nháp tiến độ', 'bỏ nháp trả lời']);

it('refuses a reply draft whose request was soft-deleted', function () {
    [$request, $draft] = mdaReplyDraft($this->matter);
    $request->delete();

    expect(fn () => app(UseReplyDraft::class)->handle($draft, $request, $this->lawyer, 'Trả lời'))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'))
        ->and(fn () => app(DiscardDraft::class)->handle($draft, $this->lawyer, 'Không cần'))
        ->toThrow(AuthorizationException::class, __('ai_drafts.unavailable'));

    expect($draft->refresh()->isPending())->toBeTrue();
});

it('refuses an empty or blank discard reason in the action itself', function (string $reason) {
    $draft = mdaStageDraft($this->matter);

    expect(fn () => app(DiscardDraft::class)->handle($draft, $this->lawyer, $reason))->toThrow(ValidationException::class)
        ->and($draft->refresh()->isPending())->toBeTrue();
})->with(['rỗng' => [''], 'chỉ khoảng trắng' => ["  \n "]]);
