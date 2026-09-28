<?php

use App\Support\ConcurrentChange;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Final review wave 2 (phụ lục): ERROR 1020/1213 của InnoDB thành một lỗi kiểm tra tiếng Việt;
 * mọi lỗi CSDL khác đi ra nguyên vẹn.
 */
function fakeQueryException(int $driverCode): QueryException
{
    $pdo = new PDOException('SQLSTATE[HY000]: General error: '.$driverCode);
    $pdo->errorInfo = ['HY000', $driverCode, 'mô phỏng'];

    return new QueryException('mariadb', 'update x set y = 1', [], $pdo);
}

it('turns ERROR 1020 and 1213 into a Vietnamese validation error on the given field', function (int $code) {
    try {
        ConcurrentChange::guard('client', fn () => throw fakeQueryException($code));
        test()->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['client' => [__('actions.concurrent_change_retry')]])
            ->and(__('actions.concurrent_change_retry'))->not->toBe('actions.concurrent_change_retry');
    }
})->with([1020, 1213]);

it('lets any other database error through untouched', function () {
    expect(fn () => ConcurrentChange::guard('client', fn () => throw fakeQueryException(1062)))
        ->toThrow(QueryException::class);
});

it('returns the callback result when nothing goes wrong', function () {
    expect(ConcurrentChange::guard('client', fn () => 42))->toBe(42);
});
