<?php

use App\Enums\CreatedVia;
use App\Exceptions\McpDraftDiscardIncomplete;
use App\Exceptions\McpDraftNotDestroyable;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\McpConfirmation;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| M11 R5/R6 (Task 7) — bảng nháp, bảng mã xác nhận, và dấu "tạo qua AI"
|--------------------------------------------------------------------------
|
| Nháp (`stage_log_drafts`, `client_request_reply_drafts`) là chỗ DUY NHẤT hai tool nháp của MCP
| ghi vào. Chúng không phải `stage_logs` hay `client_request_replies`, nên về cấu trúc không thể tới
| cổng khách; một người trong `/admin` mở nháp, sửa và bấm nút của luồng web đã có (Task 12).
|
| Nháp không xoá được, chỉ BỎ, kèm người bỏ và lý do (M6.5 R14: sửa không xoá lịch sử). Action
| "Bỏ nháp" (kèm audit) là của Task 12; ở đây model giữ hai điều không Action nào được làm khác:
| không xoá, và không có lần bỏ nào thiếu người hay thiếu lý do.
*/

/** @return array<string, array{0: Closure(): Model}> */
function mcpDraftKinds(): array
{
    return [
        'nháp cập nhật tiến độ' => [fn () => StageLogDraft::factory()->create()],
        'nháp trả lời yêu cầu' => [fn () => ClientRequestReplyDraft::factory()->create()],
    ];
}

it('never deletes a draft', function (Closure $make) {
    $draft = $make();

    expect(fn () => $draft->delete())->toThrow(McpDraftNotDestroyable::class)
        ->and($draft::query()->whereKey($draft->getKey())->exists())->toBeTrue();
})->with(mcpDraftKinds());

it('refuses a discard that names no reason', function (Closure $make, ?string $reason) {
    $draft = $make();
    $staff = User::factory()->create();

    $draft->forceFill(['discarded_at' => now(), 'discarded_by' => $staff->id, 'discard_reason' => $reason]);

    expect(fn () => $draft->save())->toThrow(McpDraftDiscardIncomplete::class)
        ->and($draft->fresh()->discarded_at)->toBeNull();
})->with(mcpDraftKinds())->with([
    'không có' => [null],
    'rỗng' => [''],
    'chỉ khoảng trắng' => ["  \n "],
]);

it('refuses a discard that names nobody', function (Closure $make) {
    $draft = $make();

    $draft->forceFill(['discarded_at' => now(), 'discarded_by' => null, 'discard_reason' => 'Trùng với cập nhật đã gửi.']);

    expect(fn () => $draft->save())->toThrow(McpDraftDiscardIncomplete::class)
        ->and($draft->fresh()->discarded_at)->toBeNull();
})->with(mcpDraftKinds());

it('keeps a discarded draft, with who discarded it and why', function (Closure $make) {
    $draft = $make();
    $staff = User::factory()->create();

    $draft->forceFill(['discarded_at' => now(), 'discarded_by' => $staff->id, 'discard_reason' => 'Trùng với cập nhật đã gửi.'])->save();

    $fresh = $draft->fresh();

    expect($fresh->discarded_at)->not->toBeNull()
        ->and($fresh->discarder->is($staff))->toBeTrue()
        ->and($fresh->discard_reason)->toBe('Trùng với cập nhật đã gửi.');
})->with(mcpDraftKinds());

it('lists as pending only the stage log drafts that are neither used nor discarded', function () {
    $matter = Matter::factory()->create();
    $staff = User::factory()->create();

    $pending = StageLogDraft::factory()->for($matter)->create();
    StageLogDraft::factory()->for($matter)->usedFor(StageLog::factory()->for($matter)->create())->create();
    StageLogDraft::factory()->for($matter)->discarded($staff, 'Không còn đúng.')->create();

    expect(StageLogDraft::query()->pending()->pluck('id')->all())->toBe([$pending->id])
        ->and($matter->stageLogDrafts()->pending()->pluck('id')->all())->toBe([$pending->id])
        ->and($matter->stageLogDrafts()->count())->toBe(3);
});

it('lists as pending only the reply drafts that are neither used nor discarded', function () {
    $request = ClientRequest::factory()->create();
    $staff = User::factory()->create();

    $pending = ClientRequestReplyDraft::factory()->for($request, 'request')->create();
    ClientRequestReplyDraft::factory()->for($request, 'request')
        ->usedFor(ClientRequestReply::factory()->create(['request_id' => $request->id]))
        ->create();
    ClientRequestReplyDraft::factory()->for($request, 'request')->discarded($staff, 'Khách đã được gọi điện trả lời.')->create();

    expect(ClientRequestReplyDraft::query()->pending()->pluck('id')->all())->toBe([$pending->id])
        ->and($request->replyDrafts()->pending()->pluck('id')->all())->toBe([$pending->id])
        ->and($request->replyDrafts()->count())->toBe(3);
});

it('links a stage log draft to its matter, its author and the stage log it became', function () {
    $matter = Matter::factory()->create();
    $author = User::factory()->create();
    $log = StageLog::factory()->for($matter)->create();

    $draft = StageLogDraft::factory()->for($matter)->usedFor($log)->create(['created_by' => $author->id]);

    expect($draft->matter->is($matter))->toBeTrue()
        ->and($draft->creator->is($author))->toBeTrue()
        ->and($draft->usedStageLog->is($log))->toBeTrue();
});

it('links a reply draft to its request, its author and the reply it became', function () {
    $request = ClientRequest::factory()->create();
    $author = User::factory()->create();
    $reply = ClientRequestReply::factory()->create(['request_id' => $request->id]);

    $draft = ClientRequestReplyDraft::factory()->for($request, 'request')->usedFor($reply)->create(['created_by' => $author->id]);

    expect($draft->request->is($request))->toBeTrue()
        ->and($draft->creator->is($author))->toBeTrue()
        ->and($draft->usedReply->is($reply))->toBeTrue();
});

/** R6: `idempotency_key` unique THEO NGƯỜI — gọi lại cùng khoá trả nháp đã có (Task 13). */
it('refuses a second draft with the same idempotency key from the same person', function (string $model) {
    $author = User::factory()->create();
    $model::factory()->create(['created_by' => $author->id, 'idempotency_key' => 'khoa-lap-lai-01']);

    expect(fn () => $model::factory()->create(['created_by' => $author->id, 'idempotency_key' => 'khoa-lap-lai-01']))
        ->toThrow(QueryException::class);
})->with([
    'nháp cập nhật tiến độ' => [StageLogDraft::class],
    'nháp trả lời yêu cầu' => [ClientRequestReplyDraft::class],
]);

it('accepts the same idempotency key from two different people', function (string $model) {
    $model::factory()->create(['idempotency_key' => 'khoa-lap-lai-01']);
    $model::factory()->create(['idempotency_key' => 'khoa-lap-lai-01']);

    expect($model::query()->where('idempotency_key', 'khoa-lap-lai-01')->count())->toBe(2);
})->with([
    'nháp cập nhật tiến độ' => [StageLogDraft::class],
    'nháp trả lời yêu cầu' => [ClientRequestReplyDraft::class],
]);

it('keeps the draft columns as long as the columns of the record the draft becomes', function () {
    $long = str_repeat('Đ', 20000);

    $draft = StageLogDraft::factory()->create([
        'public_content' => $long,
        'next_step' => $long,
        'client_action' => $long,
        'internal_note' => $long,
        'expected_next_update_at' => '2026-11-02',
    ]);
    $reply = ClientRequestReplyDraft::factory()->create(['content' => $long]);

    $fresh = $draft->fresh();

    expect($fresh->public_content)->toBe($long)
        ->and($fresh->internal_note)->toBe($long)
        ->and($fresh->expected_next_update_at->toDateString())->toBe('2026-11-02')
        ->and($reply->fresh()->content)->toBe($long);
});

/** R6: `jti` unique — gọi lại cùng mã xác nhận trả đúng bản ghi đã tạo, không tạo bản thứ hai. */
it('refuses a second confirmation row for the same jti', function () {
    $confirmation = McpConfirmation::factory()->create();

    expect(fn () => McpConfirmation::factory()->create(['jti' => $confirmation->jti]))
        ->toThrow(QueryException::class);
});

it('points a confirmation at the record it created, through the morph map', function () {
    $deadline = Deadline::factory()->create();
    $user = User::factory()->create();

    $confirmation = McpConfirmation::factory()->create([
        'user_id' => $user->id,
        'tool' => 'create_deadline',
        'result_type' => $deadline->getMorphClass(),
        'result_id' => $deadline->id,
    ]);

    $fresh = $confirmation->fresh();

    expect($fresh->result_type)->toBe('deadline')
        ->and($fresh->result->is($deadline))->toBeTrue()
        ->and($fresh->user->is($user))->toBeTrue()
        ->and($fresh->created_at)->not->toBeNull()
        ->and(array_key_exists('updated_at', $fresh->getAttributes()))->toBeFalse();
});

it('marks deadlines and communication logs as entered on the web unless said otherwise', function () {
    $deadline = Deadline::factory()->create();
    $log = CommunicationLog::factory()->create();

    expect($deadline->created_via)->toBe(CreatedVia::Web)
        ->and($deadline->fresh()->created_via)->toBe(CreatedVia::Web)
        ->and($log->created_via)->toBe(CreatedVia::Web)
        ->and($log->fresh()->created_via)->toBe(CreatedVia::Web);
});

it('stores web for a deadline or communication log row that does not name the column', function () {
    $deadline = Deadline::factory()->create();
    $log = CommunicationLog::factory()->create();

    $deadlineRow = collect((array) DB::table('deadlines')->where('id', $deadline->id)->first())->except(['id', 'created_via'])->all();
    $logRow = collect((array) DB::table('communication_logs')->where('id', $log->id)->first())->except(['id', 'created_via'])->all();

    $deadlineId = DB::table('deadlines')->insertGetId($deadlineRow);
    $logId = DB::table('communication_logs')->insertGetId($logRow);

    expect(DB::table('deadlines')->where('id', $deadlineId)->value('created_via'))->toBe('web')
        ->and(DB::table('communication_logs')->where('id', $logId)->value('created_via'))->toBe('web');
});

it('records who confirmed a deadline created through AI, and when', function () {
    $staff = User::factory()->create();
    $deadline = Deadline::factory()->create();

    $deadline->forceFill(['created_via' => CreatedVia::Mcp, 'confirmed_at' => '2026-10-03 10:15:00', 'confirmed_by' => $staff->id])->save();

    $fresh = $deadline->fresh();

    expect($fresh->created_via)->toBe(CreatedVia::Mcp)
        ->and($fresh->confirmed_at->format('Y-m-d H:i'))->toBe('2026-10-03 10:15')
        ->and($fresh->confirmer->is($staff))->toBeTrue();
});

/**
 * Chỉ Action ghi được ba cột này (Task 12, 13): một form web điền `created_via`, `confirmed_at`
 * hay `confirmed_by` qua `fill()` là tự dán nhãn "đã xác nhận" hay "tạo qua AI" cho một mốc.
 */
it('never lets mass assignment set created_via or the confirmation of a deadline or communication log', function () {
    $staff = User::factory()->create();
    $deadline = Deadline::factory()->create();
    $log = CommunicationLog::factory()->create();

    $deadline->update(['created_via' => 'mcp', 'confirmed_at' => now(), 'confirmed_by' => $staff->id]);
    $log->update(['created_via' => 'mcp']);

    $deadline = $deadline->fresh();

    expect($deadline->created_via)->toBe(CreatedVia::Web)
        ->and($deadline->confirmed_at)->toBeNull()
        ->and($deadline->confirmed_by)->toBeNull()
        ->and($log->fresh()->created_via)->toBe(CreatedVia::Web);
});

/*
 * M11 Task 13 (rà soát Task 7, m2 và m3): nháp đã dùng phải luôn trỏ được tới thứ nó đã thành, và
 * một lần xoá CỨNG cha (dưới tầng model, nơi hook `deleting` của nháp không chạy) không được lặng lẽ
 * xoá nháp hay đưa một nháp đã dùng về "đang chờ" để gửi lần hai. Bốn khoá ngoại là `restrict`.
 */
it('refuses a hard delete of the stage log or reply a used draft became, and keeps the draft used', function (string $kind) {
    if ($kind === 'stage') {
        $draft = StageLogDraft::factory()->create();
        $target = StageLog::factory()->create(['matter_id' => $draft->matter_id]);
        $draft->forceFill(['used_stage_log_id' => $target->id])->save();
        $table = 'stage_logs';
    } else {
        $draft = ClientRequestReplyDraft::factory()->create();
        $target = ClientRequestReply::factory()->create(['request_id' => $draft->request_id]);
        $draft->forceFill(['used_reply_id' => $target->id])->save();
        $table = 'client_request_replies';
    }

    expect(fn () => DB::table($table)->where('id', $target->id)->delete())->toThrow(QueryException::class)
        ->and($draft->fresh()->isPending())->toBeFalse();
})->with(['stage', 'reply']);

it('refuses a hard delete of the matter or request a draft belongs to, and keeps the draft', function (string $kind) {
    $draft = $kind === 'stage' ? StageLogDraft::factory()->create() : ClientRequestReplyDraft::factory()->create();
    [$table, $id] = $kind === 'stage' ? ['matters', $draft->matter_id] : ['client_requests', $draft->request_id];

    expect(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class)
        ->and($draft::query()->withoutGlobalScopes()->whereKey($draft->getKey())->exists())->toBeTrue();
})->with(['stage', 'reply']);
