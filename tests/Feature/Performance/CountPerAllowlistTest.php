<?php

use App\Actions\Performance\BuildTeamWorkload;
use App\Models\ClientRequest;
use App\Models\Matter;

/**
 * M15 Task 1 — `BuildTeamWorkload::countPer()` chèn `$person` thẳng vào SQL thô (tên cột không bind
 * được). Lý do ở danh sách nền SQL thô chỉ đúng khi hàm CÔNG KHAI này từ chối mọi thứ ngoài danh sách.
 */
it('từ chối biểu thức quy người ngoài danh sách đã duyệt', function (string $person) {
    expect(fn () => BuildTeamWorkload::countPer(Matter::query(), $person))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'tiêm SQL' => ["' OR 1=1 --"],
    'cột lạ' => ['users.password'],
    'biểu thức lạ' => ['(select 1)'],
    'rỗng' => [''],
]);

it('danh sách cho phép gồm biểu thức người giữ của yêu cầu và không có gì ngoài cột/biểu thức hằng', function () {
    expect(BuildTeamWorkload::personExpressions())->toContain(ClientRequest::holderIdSql(), 'matters.lead_lawyer_id');

    foreach (BuildTeamWorkload::personExpressions() as $expression) {
        expect($expression)->toMatch('/^[a-z_.]+$|^COALESCE\([a-z_.]+, [a-z_.]+\)$/');
    }
});

it('vẫn đếm đúng với một biểu thức được phép', function () {
    expect(BuildTeamWorkload::countPer(Matter::query(), 'matters.lead_lawyer_id'))->toBe([]);
});
