<?php

use App\Enums\ChecklistItemStatus;
use App\Models\ChecklistTemplate;
use App\Models\MatterChecklistItem;

it('orders template items and marks required ones', function () {
    $template = ChecklistTemplate::factory()->withItems(3)->create();

    expect($template->items)->toHaveCount(3)
        ->and($template->items->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and($template->matterType->checklistTemplates->first()->is($template))->toBeTrue();
});

it('defaults a matter checklist item to missing', function () {
    $item = MatterChecklistItem::factory()->create();

    expect($item->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->matter->checklistItems->first()->is($item))->toBeTrue()
        ->and($item->reviewer)->toBeNull();
});
