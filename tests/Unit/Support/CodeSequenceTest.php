<?php

use App\Support\CodeSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts at one and increments per key', function () {
    expect(CodeSequence::next('client:2026'))->toBe(1)
        ->and(CodeSequence::next('client:2026'))->toBe(2)
        ->and(CodeSequence::next('client:2027'))->toBe(1)
        ->and(CodeSequence::next('matter:2026:DD'))->toBe(1);
});

it('formats with a four digit zero padded number', function () {
    expect(CodeSequence::format('KH-2026-', 7))->toBe('KH-2026-0007')
        ->and(CodeSequence::format('VK-2026-DD-', 147))->toBe('VK-2026-DD-0147')
        ->and(CodeSequence::format('VK-2026-DD-', 12345))->toBe('VK-2026-DD-12345');
});

it('rolls back the counter together with an outer transaction', function () {
    try {
        DB::transaction(function () {
            CodeSequence::next('client:2026');
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(CodeSequence::next('client:2026'))->toBe(1);
});
