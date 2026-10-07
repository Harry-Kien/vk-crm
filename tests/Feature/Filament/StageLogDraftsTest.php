<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| M11 Task 12 — nháp dòng tiến độ do AI soạn, trên tab "Tiến độ"
|--------------------------------------------------------------------------
|
| Khối "Nháp từ AI (n)" đứng trên bảng dòng thời gian. "Mở nháp" mở CHÍNH form "Thêm cập nhật",
| điền sẵn nội dung nháp; bấm lưu thì dòng tiến độ thật sinh ra qua `TransitionMatterStage` (không
| đổi giai đoạn) dưới tên NGƯỜI BẤM, và nháp trỏ `used_stage_log_id` tới nó. "Bỏ nháp" đòi lý do.
|
| Mọi test ở đây đi qua màn hình (Livewire, relation manager của trang `ViewMatter`).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** @return array{0: User, 1: Matter, 2: User} luật sư phụ trách, vụ ở `intake`, người sở hữu token đã soạn nháp */
function sldMatter(array $attributes = []): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id, ...$attributes]);
    $author = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Người Soạn Qua AI']);
    $matter->addTeamMember($author, MatterRole::Associate);

    return [$lawyer, $matter, $author];
}

function sldDraft(Matter $matter, User $author, array $attributes = []): StageLogDraft
{
    return StageLogDraft::factory()->create([
        'matter_id' => $matter->id,
        'created_by' => $author->id,
        'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.',
        'next_step' => 'Chờ toà thụ lý đơn.',
        'client_action' => null,
        'internal_note' => 'Thẩm phán dự kiến: chưa rõ.',
        ...$attributes,
    ]);
}

function sldPage(Matter $matter): Testable
{
    return test()->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

function sldAction(string $name, StageLogDraft $draft): TestAction
{
    $name = ['useDraft' => 'useStageLogDraft', 'discardDraft' => 'discardStageLogDraft'][$name];

    return TestAction::make($name)->arguments(['draft' => $draft->id]);
}

function sldPreviewHtml(Testable $component): string
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    $matches = array_values(array_filter(
        $schema->getFlatComponents(withHidden: true),
        fn ($c) => $c instanceof View,
    ));

    expect($matches)->toHaveCount(1);

    return $matches[0]->toSchemaHtml(true);
}

it('shows the pending AI drafts above the timeline with their count, author and content', function () {
    [$lawyer, $matter, $author] = sldMatter();
    sldDraft($matter, $author);
    sldDraft($matter, $author, ['public_content' => 'Nháp thứ hai về buổi hoà giải.']);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->assertSee(__('ai_drafts.stage_log.heading', ['count' => 2]))
        ->assertSee('Người Soạn Qua AI')
        ->assertSee('Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.')
        ->assertSee('Nháp thứ hai về buổi hoà giải.');
});

it('leaves used and discarded drafts, and drafts of another matter, out of the block', function () {
    [$lawyer, $matter, $author] = sldMatter();
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);
    sldDraft($matter, $author, ['public_content' => 'Nháp đã dùng rồi.'])->forceFill(['used_stage_log_id' => $log->id])->save();
    StageLogDraft::factory()->discarded($lawyer, 'Không cần nữa')->create([
        'matter_id' => $matter->id, 'created_by' => $author->id, 'public_content' => 'Nháp đã bỏ rồi.',
    ]);
    [, $other, $otherAuthor] = sldMatter();
    sldDraft($other, $otherAuthor, ['public_content' => 'Nháp của vụ khác.']);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->assertDontSee(__('ai_drafts.stage_log.heading', ['count' => 0]))
        ->assertDontSee('Nháp đã dùng rồi.')
        ->assertDontSee('Nháp đã bỏ rồi.')
        ->assertDontSee('Nháp của vụ khác.');
});

it('opens a draft into the add-update form, sends the edited text under the name of the person who clicked, and marks the draft used', function () {
    Event::fake([StageLogPublished::class]);
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->mountAction(sldAction('useDraft', $draft))
        ->assertSchemaStateSet([
            'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.',
            'next_step' => 'Chờ toà thụ lý đơn.',
            'internal_note' => 'Thẩm phán dự kiến: chưa rõ.',
            'publish' => true,
        ])
        ->fillForm([
            'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Hoàn Kiếm hôm nay.',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $log = StageLog::query()->where('matter_id', $matter->id)->sole();

    expect($log->public_content)->toBe('Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Hoàn Kiếm hôm nay.')
        ->and($log->next_step)->toBe('Chờ toà thụ lý đơn.')
        ->and($log->internal_note)->toBe('Thẩm phán dự kiến: chưa rõ.')
        ->and($log->from_stage)->toBe('intake')
        ->and($log->to_stage)->toBe('intake')
        ->and($log->is_published)->toBeTrue()
        ->and($log->created_by)->toBe($lawyer->id)
        ->and($draft->refresh()->used_stage_log_id)->toBe($log->id)
        ->and($matter->refresh()->stage)->toBe('intake');

    Event::assertDispatched(StageLogPublished::class, 1);

    $audit = Activity::query()->where('event', 'mcp_draft_used')->sole();

    expect($audit->causer?->is($lawyer))->toBeTrue()
        ->and($audit->subject?->is($log))->toBeTrue()
        ->and($audit->properties->get('draft_type'))->toBe('stage_log_draft')
        ->and($audit->properties->get('draft_id'))->toBe($draft->id)
        ->and($audit->properties->get('draft_created_by'))->toBe($author->id)
        ->and($audit->properties->get('matter_id'))->toBe($matter->id);
});

it('lets the person who clicks turn the publish switch off, so the update stays internal', function () {
    Event::fake([StageLogPublished::class]);
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->callAction(sldAction('useDraft', $draft), data: ['publish' => false])
        ->assertHasNoFormErrors();

    $log = StageLog::query()->where('matter_id', $matter->id)->sole();

    expect($log->is_published)->toBeFalse();
    Event::assertNotDispatched(StageLogPublished::class);
});

it('keeps the SPEC §7.3 rule on a published draft update: under thirty characters is refused and the draft stays pending', function () {
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author, ['public_content' => 'Quá ngắn.']);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->callAction(sldAction('useDraft', $draft), data: ['publish' => true])
        ->assertHasFormErrors(['public_content']);

    expect(StageLog::query()->where('matter_id', $matter->id)->exists())->toBeFalse()
        ->and($draft->refresh()->used_stage_log_id)->toBeNull();
});

it('runs the client preview on the draft content', function () {
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    $html = sldPreviewHtml(sldPage($matter)->mountAction(sldAction('useDraft', $draft)));

    expect($html)->toContain('Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.')
        ->and($html)->toContain('Chờ toà thụ lý đơn.')
        ->and($html)->not->toContain('Thẩm phán dự kiến');
});

it('hides open and discard from a person without transitionStage, and shows them to the lead lawyer', function () {
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    sldPage($matter)
        ->assertSee('Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.')
        ->assertActionHidden(sldAction('useDraft', $draft))
        ->assertActionHidden(sldAction('discardDraft', $draft));

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->assertActionVisible(sldAction('useDraft', $draft))
        ->assertActionVisible(sldAction('discardDraft', $draft));
});

it('does not let a used draft be opened again', function () {
    Event::fake([StageLogPublished::class]);
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)->callAction(sldAction('useDraft', $draft))->assertHasNoFormErrors();

    sldPage($matter)
        ->assertDontSee(__('ai_drafts.actions.open'))
        ->mountAction(sldAction('useDraft', $draft))
        ->assertNotified(__('ai_drafts.not_pending'))
        ->assertActionNotMounted(sldAction('useDraft', $draft));

    sldPage($matter)
        ->callAction(sldAction('useDraft', $draft))
        ->assertNotified(__('ai_drafts.not_pending'));

    expect(StageLog::query()->where('matter_id', $matter->id)->count())->toBe(1);
});

it('does not open, on this matter page, a draft that belongs to another matter', function () {
    [$lawyer, $matter] = sldMatter();
    [, $other, $otherAuthor] = sldMatter(['lead_lawyer_id' => $lawyer->id]);
    $foreign = sldDraft($other, $otherAuthor);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->assertActionHidden(sldAction('useDraft', $foreign))
        ->assertActionHidden(sldAction('discardDraft', $foreign))
        // Một request Livewire tự dựng, bỏ qua nút: hai action vẫn không chạy.
        ->call('mountAction', 'useStageLogDraft', ['draft' => $foreign->id])
        ->call('callMountedAction')
        ->call('mountAction', 'discardStageLogDraft', ['draft' => $foreign->id])
        ->call('callMountedAction');

    expect(StageLog::query()->exists())->toBeFalse()
        ->and($foreign->refresh()->isPending())->toBeTrue();

    // Cặp dương: trên trang của chính vụ đó, nút hiện.
    sldPage($other)->assertActionVisible(sldAction('useDraft', $foreign));
});

it('refuses to send a draft that was used in another tab after the form was opened, and writes nothing', function () {
    Event::fake([StageLogPublished::class]);
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);

    $this->actingAs($lawyer, 'web');

    $page = sldPage($matter)->mountAction(sldAction('useDraft', $draft));

    $draft->forceFill(['used_stage_log_id' => $log->id])->save();

    $page->callMountedAction()->assertNotified(__('ai_drafts.not_pending'));

    expect(StageLog::query()->where('matter_id', $matter->id)->count())->toBe(1)
        ->and($draft->refresh()->used_stage_log_id)->toBe($log->id);
});

it('requires a reason to discard a draft', function () {
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->callAction(sldAction('discardDraft', $draft), data: ['reason' => '   '])
        ->assertHasFormErrors(['reason' => 'required']);

    expect($draft->refresh()->discarded_at)->toBeNull()
        ->and(Activity::query()->where('event', 'mcp_draft_discarded')->exists())->toBeFalse();
});

it('discards a draft with a reason, records who and why, and takes it off the block', function () {
    [$lawyer, $matter, $author] = sldMatter();
    $draft = sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    sldPage($matter)
        ->callAction(sldAction('discardDraft', $draft), data: ['reason' => 'Thông tin toà án sai.'])
        ->assertHasNoFormErrors();

    $draft->refresh();

    expect($draft->discarded_at)->not->toBeNull()
        ->and($draft->discarded_by)->toBe($lawyer->id)
        ->and($draft->discard_reason)->toBe('Thông tin toà án sai.')
        ->and(StageLog::query()->where('matter_id', $matter->id)->exists())->toBeFalse();

    $audit = Activity::query()->where('event', 'mcp_draft_discarded')->sole();

    expect($audit->causer?->is($lawyer))->toBeTrue()
        ->and($audit->subject?->is($matter))->toBeTrue()
        ->and($audit->properties->get('draft_type'))->toBe('stage_log_draft')
        ->and($audit->properties->get('draft_id'))->toBe($draft->id)
        ->and($audit->properties->get('matter_id'))->toBe($matter->id);

    sldPage($matter)
        ->assertDontSee('Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.')
        ->callAction(sldAction('discardDraft', $draft), data: ['reason' => 'Bỏ lần hai.'])
        ->assertNotified(__('ai_drafts.not_pending'));

    expect($draft->refresh()->discard_reason)->toBe('Thông tin toà án sai.');
});

it('keeps the drafts on the matter page after the matter is withdrawn from AI access', function () {
    [$lawyer, $matter, $author] = sldMatter();
    sldDraft($matter, $author);

    $this->actingAs($lawyer, 'web');

    expect($matter->ai_access->value)->toBe('denied');

    sldPage($matter)->assertSee('Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.');
});

it('never shows the drafts, which may carry an internal note, to a person who cannot see the matter content', function () {
    [$lawyer, $matter, $author] = sldMatter();
    sldDraft($matter, $author);
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    sldPage($matter)
        ->assertDontSee(__('ai_drafts.stage_log.heading', ['count' => 1]))
        ->assertDontSee('Thẩm phán dự kiến: chưa rõ.');

    $this->actingAs($lawyer, 'web');

    sldPage($matter)->assertSee('Thẩm phán dự kiến: chưa rõ.');
});
