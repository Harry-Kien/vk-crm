<?php

use App\Enums\PartyRole;
use App\Models\MatterParty;
use App\Support\Mcp\PartyLabel;

/*
| M11 R10 (Task 9): bên không phải khách của văn phòng không thể đồng ý, nên mặc định
| (`MCP_PARTY_NAMES=pseudonym`) họ ra khỏi hệ thống dưới dạng vai + số thứ tự ("Bị đơn 1"); bên là
| khách của văn phòng ra bằng tên. `full` trả tên thật — chủ văn phòng quyết (câu hỏi mở 3).
*/

function partyForLabel(int $id, PartyRole $role, ?bool $ourClient, string $name, int $matterId = 1): MatterParty
{
    return (new MatterParty)->forceFill([
        'id' => $id,
        'matter_id' => $matterId,
        'role' => $role,
        'is_our_client' => $ourClient,
        'name' => $name,
    ]);
}

/** @return list<string> */
function partyLabels(iterable $parties): array
{
    return array_map(fn (array $row) => $row['label'], PartyLabel::assign($parties));
}

beforeEach(function () {
    config(['vkcrm.mcp.party_names' => 'pseudonym']);
});

it('defaults to pseudonym mode', function () {
    expect(config('vkcrm.mcp.party_names'))->toBe('pseudonym')
        ->and(PartyLabel::mode())->toBe(PartyLabel::PSEUDONYM);
});

it('labels a party who is not our client "Bị đơn 1" in pseudonym mode', function () {
    $rows = PartyLabel::assign([partyForLabel(7, PartyRole::Defendant, false, 'Trần Văn Bị')]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('Bị đơn 1')
        ->and($rows[0]['pseudonym'])->toBeTrue()
        ->and($rows[0]['party']->getKey())->toBe(7);
});

it('numbers two defendants 1 and 2 by creation order, stable between calls and input orders', function () {
    $first = partyForLabel(3, PartyRole::Defendant, false, 'Công ty X');
    $second = partyForLabel(9, PartyRole::Defendant, false, 'Ông Y');

    $a = PartyLabel::assign([$second, $first]);
    $b = PartyLabel::assign([$first, $second]);

    expect(array_map(fn ($r) => [$r['party']->getKey(), $r['label']], $a))
        ->toBe([[3, 'Bị đơn 1'], [9, 'Bị đơn 2']])
        ->and(array_map(fn ($r) => [$r['party']->getKey(), $r['label']], $b))
        ->toBe([[3, 'Bị đơn 1'], [9, 'Bị đơn 2']]);
});

it('gives our own client its real name and numbers each role on its own', function () {
    $labels = partyLabels([
        partyForLabel(1, PartyRole::Plaintiff, true, 'Nguyễn Thị Khách'),
        partyForLabel(2, PartyRole::Defendant, false, 'Bị Đơn Thật'),
        partyForLabel(3, PartyRole::Related, false, 'Người Liên Quan Thật'),
        partyForLabel(4, PartyRole::Plaintiff, false, 'Đồng Nguyên Đơn'),
        partyForLabel(5, PartyRole::Related, false, 'Người Thứ Hai'),
    ]);

    expect($labels)->toBe([
        'Nguyễn Thị Khách',
        PartyRole::Defendant->label().' 1',
        PartyRole::Related->label().' 1',
        PartyRole::Plaintiff->label().' 1',
        PartyRole::Related->label().' 2',
    ]);
});

it('never lets the real name of a third party through in pseudonym mode', function () {
    $rows = PartyLabel::assign([
        partyForLabel(1, PartyRole::Defendant, false, 'Lê Văn Bí Mật'),
        partyForLabel(2, PartyRole::OpposingCounsel, false, 'Luật sư Đối Phương'),
        partyForLabel(3, PartyRole::ThirdParty, false, 'Bên Ba Tên Thật'),
    ]);

    foreach ($rows as $row) {
        expect($row['label'])->not->toContain('Bí Mật')
            ->and($row['label'])->not->toContain('Đối Phương')
            ->and($row['label'])->not->toContain('Tên Thật')
            ->and($row['pseudonym'])->toBeTrue();
    }
});

it('treats an unknown is_our_client as "not our client" (fails closed)', function () {
    expect(partyLabels([partyForLabel(1, PartyRole::Defendant, null, 'Không Rõ')]))->toBe(['Bị đơn 1']);
});

it('returns real names in full mode', function () {
    config(['vkcrm.mcp.party_names' => 'full']);

    $rows = PartyLabel::assign([
        partyForLabel(1, PartyRole::Plaintiff, true, 'Nguyễn Thị Khách'),
        partyForLabel(2, PartyRole::Defendant, false, 'Trần Văn Bị'),
    ]);

    expect(PartyLabel::mode())->toBe(PartyLabel::FULL)
        ->and(array_map(fn ($r) => [$r['label'], $r['pseudonym']], $rows))
        ->toBe([['Nguyễn Thị Khách', false], ['Trần Văn Bị', false]]);
});

it('falls back to pseudonym for any value that is not exactly "full"', function (mixed $value) {
    config(['vkcrm.mcp.party_names' => $value]);

    expect(PartyLabel::mode())->toBe(PartyLabel::PSEUDONYM)
        ->and(partyLabels([partyForLabel(1, PartyRole::Defendant, false, 'Trần Văn Bị')]))->toBe(['Bị đơn 1']);
})->with(['FULL', 'Full', ' full', 'true', '1', '', null, true]);

it('refuses parties of more than one matter: numbering only means something inside one matter', function () {
    PartyLabel::assign([
        partyForLabel(1, PartyRole::Defendant, false, 'A', matterId: 1),
        partyForLabel(2, PartyRole::Defendant, false, 'B', matterId: 2),
    ]);
})->throws(LogicException::class);

it('refuses a party without an id: the order would not be stable', function () {
    PartyLabel::assign([(new MatterParty)->forceFill(['matter_id' => 1, 'role' => PartyRole::Defendant, 'is_our_client' => false, 'name' => 'A'])]);
})->throws(LogicException::class);
