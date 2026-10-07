<?php

/*
|--------------------------------------------------------------------------
| M14 Task 7 — "đi trọn đường": đẩy → nhập biên nhận → qua biên độ → dọn vùng đệm (kế hoạch R10)
|--------------------------------------------------------------------------
|
| DỜI SANG TASK 6 (phán quyết của controller cho làn m14b): `PushDocumentFileToRemote` và
| `PurgeStagedDocumentCopies` thuộc Task 3 của làn m14 và chưa có trên nhánh `m14-drive-ops`. Lượt
| nhập biên nhận được test với dòng `drive_objects` gieo thẳng (`ImportOfficeReceiptsTest`). Đầu
| Task 6, sau khi gộp m14b vào `m14-drive-storage`, bỏ `markTestSkipped` và viết hai ca:
|
| 1. Media ở `private` → `PushDocumentFileToRemote` (đĩa kho giả) → có dòng chỉ mục sống, md5 khớp
|    → `vkcrm:storage:office-receipts` với biên nhận (`Tests\Support\OfficeReceiptFixtures`) mang
|    đúng tên + md5 + cỡ → `travel` qua `local_purge_after` và `office.purge_margin_hours` →
|    `PurgeStagedDocumentCopies` xoá `private/<media_id>/` và đặt `local_purge_after = NULL`.
| 2. Cùng đường, KHÔNG có biên nhận (hoặc biên nhận của Shared Drive khác) → bản trong vùng đệm còn.
*/

it('đẩy → nhập biên nhận → travel qua biên độ → dọn xoá bản cục bộ; thiếu biên nhận → giữ', function () {
    $this->markTestSkipped('chờ gộp m14: cần PushDocumentFileToRemote + PurgeStagedDocumentCopies (dời sang Task 6)');
});
