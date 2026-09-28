<?php

use App\Enums\PaymentMethod;
use App\Exceptions\PaymentNotDestroyable;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;

it('records the actor, the instalment, and the attributed lawyer', function () {
    $author = User::factory()->create();
    $this->actingAs($author, 'web');
    $instalment = Instalment::factory()->create();
    $lawyer = User::factory()->create();

    $payment = Payment::factory()->for($instalment)->create([
        'attributed_lawyer_id' => $lawyer->id,
        'method' => PaymentMethod::Cash,
        'amount' => 3_000_000,
    ]);

    expect($payment->created_by)->toBe($author->id)
        ->and($payment->instalment->is($instalment))->toBeTrue()
        ->and($payment->attributedLawyer->is($lawyer))->toBeTrue()
        ->and($payment->amount)->toBe(3_000_000)
        ->and($payment->method)->toBe(PaymentMethod::Cash);
});

it('can never be deleted, regardless of state', function () {
    $payment = Payment::factory()->create();

    expect(fn () => $payment->delete())->toThrow(PaymentNotDestroyable::class)
        ->and(Payment::count())->toBe(1);
});

/**
 * M9 Task 5 ("Test bắt buộc": "khoản thu không xoá được — delete() lẫn forceDelete()"). Không
 * `SoftDeletes`, nên `Model::forceDelete()` mặc định CHỈ gọi thẳng `delete()` — cùng sự kiện
 * `deleting`, cùng hook. Test riêng để mutation probe không lẫn hai đường gọi làm một.
 */
it('can never be force deleted either', function () {
    $payment = Payment::factory()->create();

    expect(fn () => $payment->forceDelete())->toThrow(PaymentNotDestroyable::class)
        ->and(Payment::count())->toBe(1);
});

it('cannot be deleted even after being voided', function () {
    $payment = Payment::factory()->voided()->create();

    expect(fn () => $payment->delete())->toThrow(PaymentNotDestroyable::class)
        ->and(Payment::count())->toBe(1);
});

it('refuses in vietnamese, from the language file', function () {
    $payment = Payment::factory()->create();

    expect(fn () => $payment->delete())
        ->toThrow(PaymentNotDestroyable::class, __('exceptions.payment_not_destroyable'));
});

it('does not have a deleted_at column', function () {
    expect(Payment::factory()->create()->getAttributes())->not->toHaveKey('deleted_at');
});
