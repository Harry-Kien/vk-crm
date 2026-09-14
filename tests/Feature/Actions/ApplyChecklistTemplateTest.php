<?php

use App\Actions\ApplyChecklistTemplate;
use App\Enums\ChecklistItemStatus;
use App\Models\ChecklistTemplate;
use App\Models\Matter;

it('copies template items into the matter as missing items', function () {
    $template = ChecklistTemplate::factory()->withItems(4)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();

    $items = app(ApplyChecklistTemplate::class)->handle($matter, $template);

    expect($items)->toHaveCount(4)
        ->and($matter->checklistItems()->count())->toBe(4)
        ->and($matter->checklistItems->pluck('name')->all())->toBe($template->items->pluck('name')->all())
        ->and($matter->checklistItems->every(fn ($i) => $i->status === ChecklistItemStatus::Missing))->toBeTrue()
        ->and($matter->checklistItems->pluck('is_required')->all())->toBe($template->items->pluck('is_required')->all());
});

it('is a copy, not a reference: editing the template later does not touch the matter', function () {
    $template = ChecklistTemplate::factory()->withItems(2)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();
    app(ApplyChecklistTemplate::class)->handle($matter, $template);

    $template->items->first()->update(['name' => 'Tên đã đổi']);

    expect($matter->checklistItems()->where('name', 'Tên đã đổi')->exists())->toBeFalse();
});

it('does not duplicate items when applied twice', function () {
    $template = ChecklistTemplate::factory()->withItems(3)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();

    app(ApplyChecklistTemplate::class)->handle($matter, $template);
    app(ApplyChecklistTemplate::class)->handle($matter, $template);

    expect($matter->checklistItems()->count())->toBe(3);
});

it('does not resurrect an item that was deliberately removed from the matter', function () {
    $template = ChecklistTemplate::factory()->withItems(3)->create();
    $matter = Matter::factory()->for($template->matterType, 'matterType')->create();
    app(ApplyChecklistTemplate::class)->handle($matter, $template);

    $matter->checklistItems()->first()->delete();
    app(ApplyChecklistTemplate::class)->handle($matter, $template);

    expect($matter->checklistItems()->count())->toBe(2)
        ->and($matter->checklistItems()->withTrashed()->count())->toBe(3);
});
