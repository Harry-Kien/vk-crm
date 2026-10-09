<?php

use Illuminate\Support\Facades\Storage;

/**
 * M14 Task 1 — nhân chứng của hook `Storage::fake('documents_remote')` trong khối `Unit` của
 * `tests/Pest.php` (bản `Feature` có nhân chứng riêng ở `tests/Feature/Storage/
 * DocumentsRemoteDiskTest.php`). Tệp này không tự gọi `Storage::fake()`: gỡ dòng của khối `Unit`
 * thì đĩa ở đây là adapter kho thật, `path()` không nằm dưới gốc đĩa giả, và test đỏ.
 */
it('đĩa documents_remote trong test Unit luôn là đĩa giả', function () {
    expect(Storage::disk('documents_remote')->path('18/ho-so.pdf'))
        ->toStartWith(storage_path('framework/testing/disks'));
});
