<?php

use App\Enums\DocumentGroup;
use App\Enums\PartyRole;

it('gives every enum case a vietnamese label', function () {
    $files = glob(app_path('Enums/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Enums\\'.pathinfo($file, PATHINFO_FILENAME);
        expect(enum_exists($class))->toBeTrue("{$class} phải là enum");

        foreach ($class::cases() as $case) {
            $label = $case->label();
            expect($label)->toBeString()->not->toBeEmpty()
                ->and($label)->not->toStartWith('enums.', "{$class}::{$case->name} thiếu nhãn trong lang/vi/enums.php");
        }
    }
});

it('has the enums the data model requires', function () {
    foreach ([
        'MatterRole', 'Confidentiality', 'ChecklistItemStatus', 'DocumentGroup', 'DocumentStatus',
        'DeadlineSeverity', 'ClientRequestStatus', 'OutboundChannel', 'OutboundStatus', 'PartyRole', 'CommunicationType',
    ] as $name) {
        expect(enum_exists('App\\Enums\\'.$name))->toBeTrue("Thiếu enum {$name}");
    }

    expect(DocumentGroup::Internal->value)->toBe('D')
        ->and(PartyRole::OpposingCounsel->value)->toBe('opposing_counsel');
});
