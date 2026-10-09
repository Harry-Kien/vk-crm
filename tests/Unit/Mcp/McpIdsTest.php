<?php

use App\Support\Mcp\McpIds;

/*
| M11 bộ tool (Task 9): id có tiền tố — `matter_123`, `deadline_9`, `request_7`, `doc_88` [DC:30-39].
| Đọc ngược CHẶT: một chuỗi lệch dạng cho `null`, và tool trả "Không tìm thấy" y như id không tồn
| tại (R3) — không có thông điệp "id sai định dạng" để dò.
*/

it('encodes ids with their prefix', function () {
    expect(McpIds::encode(McpIds::MATTER, 123))->toBe('matter_123')
        ->and(McpIds::encode(McpIds::DEADLINE, 9))->toBe('deadline_9')
        ->and(McpIds::encode(McpIds::REQUEST, 7))->toBe('request_7')
        ->and(McpIds::encode(McpIds::DOCUMENT, 88))->toBe('doc_88')
        ->and(McpIds::encode(McpIds::UPDATE, 5))->toBe('update_5')
        ->and(McpIds::encode(McpIds::CHECKLIST_ITEM, 4))->toBe('item_4')
        ->and(McpIds::encode(McpIds::REPLY, 3))->toBe('reply_3')
        ->and(McpIds::encode(McpIds::USER, 2))->toBe('user_2');
});

it('decodes an id of the expected type and round-trips', function () {
    expect(McpIds::decode('matter_123', McpIds::MATTER))->toBe(123)
        ->and(McpIds::decode(McpIds::encode(McpIds::DOCUMENT, 88), McpIds::DOCUMENT))->toBe(88);
});

it('answers null for anything that is not exactly <prefix>_<positive integer>', function (?string $value) {
    expect(McpIds::decode($value, McpIds::MATTER))->toBeNull();
})->with([
    'wrong prefix' => 'request_123',
    'no prefix' => '123',
    'empty' => '',
    'null' => null,
    'zero' => 'matter_0',
    'leading zero' => 'matter_0123',
    'negative' => 'matter_-1',
    'decimal' => 'matter_1.5',
    'exponent' => 'matter_1e3',
    'trailing newline' => "matter_1\n",
    'leading space' => ' matter_1',
    'upper case' => 'MATTER_1',
    'unicode digit' => 'matter_١٢٣',
    'two ids' => 'matter_1_2',
    'sql' => 'matter_1 OR 1=1',
    'too long for an int' => 'matter_'.str_repeat('9', 19),
]);

it('parses an id against a list of accepted types (fetch accepts a matter or a request)', function () {
    expect(McpIds::parse('request_7', McpIds::MATTER, McpIds::REQUEST))->toBe(['type' => McpIds::REQUEST, 'id' => 7])
        ->and(McpIds::parse('matter_1', McpIds::MATTER, McpIds::REQUEST))->toBe(['type' => McpIds::MATTER, 'id' => 1])
        ->and(McpIds::parse('doc_88', McpIds::MATTER, McpIds::REQUEST))->toBeNull()
        ->and(McpIds::parse('nonsense', McpIds::MATTER, McpIds::REQUEST))->toBeNull();
});

it('refuses to encode an unknown type or a non-positive id (programming errors, not user input)', function (string $type, int $id) {
    McpIds::encode($type, $id);
})->with([
    ['client', 1],
    ['matter', 0],
    ['matter', -4],
])->throws(InvalidArgumentException::class);

it('refuses to decode against an unknown type', function () {
    McpIds::decode('client_1', 'client');
})->throws(InvalidArgumentException::class);
