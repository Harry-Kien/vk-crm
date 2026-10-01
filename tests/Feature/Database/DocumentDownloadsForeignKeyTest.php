<?php

use App\Models\Document;
use App\Models\DocumentDownload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M7 Task 7, bước 1 (phán quyết controller): `document_downloads.document_id` thôi
 * `cascadeOnDelete` và thành `restrictOnDelete`. Nhật ký tải là BẰNG CHỨNG khách đã nhận tài liệu
 * (SPEC §4.12 "ghi log mọi lượt tải"); một lần xoá cứng tài liệu không được lặng lẽ xoá luôn bằng
 * chứng đó. Không có đường xoá cứng tài liệu hợp lệ nào (R5), nên một lần xoá cứng tài liệu đã có
 * lượt tải phải HỎNG TO.
 *
 * Xoá bằng query builder (`DB::table`) chứ không qua model: đo đúng ràng buộc của CSDL, không đo
 * một hook Eloquent nào.
 */
it('từ chối xoá cứng một tài liệu đã có lượt tải, và dòng tải còn nguyên', function () {
    $document = Document::factory()->create();
    $download = DocumentDownload::factory()->create(['document_id' => $document->id]);

    expect(fn () => DB::table('documents')->where('id', $document->id)->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('documents')->where('id', $document->id)->exists())->toBeTrue()
        ->and(DB::table('document_downloads')->where('id', $download->id)->exists())->toBeTrue();
});

it('vẫn xoá cứng được một tài liệu chưa từng có lượt tải (ràng buộc chỉ giữ bằng chứng tải)', function () {
    $document = Document::factory()->create();

    DB::table('documents')->where('id', $document->id)->delete();

    expect(DB::table('documents')->where('id', $document->id)->exists())->toBeFalse();
});
