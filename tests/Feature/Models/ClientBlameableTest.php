<?php

use App\Models\Client;
use App\Models\User;

it('records who created and updated a client when a staff user is logged in', function () {
    $creator = User::factory()->create();
    $editor = User::factory()->create();

    $this->actingAs($creator, 'web');
    $client = Client::factory()->create();

    expect($client->created_by)->toBe($creator->id)
        ->and($client->updated_by)->toBe($creator->id)
        ->and($client->creator->is($creator))->toBeTrue();

    $this->actingAs($editor, 'web');
    $client->update(['note' => 'đã gọi lại']);

    expect($client->fresh()->updated_by)->toBe($editor->id)
        ->and($client->fresh()->created_by)->toBe($creator->id);
});

it('leaves blame columns null when nobody is logged in', function () {
    $client = Client::factory()->create();

    expect($client->created_by)->toBeNull()->and($client->updated_by)->toBeNull();
});

/**
 * I-1 (fix round 4), ở đúng tầng sinh ra khiếm khuyết. Hai test Action bên cạnh chứng minh hậu quả;
 * test này khoá chính HỢP ĐỒNG: `blameOn()` thắng phiên ambient trong MỌI trường hợp, kể cả trường
 * hợp mà bản trước bỏ lọt — cột đã mang sẵn đúng id của actor, nên `isDirty('updated_by')` là false
 * và cửa nhường cũ không bao giờ mở.
 */
it('lets an explicit blameOn beat the ambient session even when the column already holds that id', function () {
    $actor = User::factory()->create();
    $sessionUser = User::factory()->create();

    $this->actingAs($sessionUser, 'web');

    // Tạo: `blameOn()` đứng trước phiên, nên cả hai cột là actor chứ không phải người đang đăng nhập.
    $client = Client::factory()->make();
    $client->blameOn($actor)->save();

    expect($client->fresh()->created_by)->toBe($actor->id)
        ->and($client->fresh()->updated_by)->toBe($actor->id);

    // Sửa: giá trị lưu sẵn ĐÃ BẰNG actor — đúng hình dạng mà `isDirty` không nhận ra.
    $client->fresh()->blameOn($actor)->update(['note' => 'đã gọi lại']);

    expect($client->fresh()->updated_by)->toBe($actor->id);
});
