<?php

/**
 * Danh sách nền SQL thô có chèn biến đã được rà soát (M15 Task 1). Khoá: "<đường dẫn>:<đối số đầu
 * đã chuẩn hoá khoảng trắng>"; giá trị: lý do an toàn. Chỗ nào chèn giá trị người dùng thì sửa sang
 * binding, KHÔNG thêm vào đây. Xem tests/Feature/Security/RawSqlInterpolationTest.php.
 *
 * @return array<string, string>
 */
return [
    'app/Actions/Document/ChecklistProgress.php:"SUM(CASE WHEN matter_checklist_items.status IN ({$placeholders}) THEN 1 ELSE 0 END) as settled"' => 'chỉ chèn danh sách dấu ? do code dựng từ hằng SETTLED_STATUSES; giá trị đi qua binding $settled',
    'app/Actions/Performance/BuildTeamWorkload.php:"{$person} as person_id"' => 'chỉ chèn tên cột do code dựng; mọi nơi gọi countPer() truyền hằng chữ (matters.lead_lawyer_id, tasks.assignee_id...), không có đầu vào người dùng',
    'app/Actions/Performance/BuildTeamWorkload.php:\'MAX(\'.$rows->qualifyColumn(\'created_at\').\') as last_at\'' => 'chỉ nối tên cột đã qualify do model dựng',
    'app/Actions/Search/SearchMatters.php:"CASE WHEN {$column} LIKE ? ESCAPE \'".self::ESCAPE."\' THEN 1 ELSE 0 END AS ".self::hitAlias($source)' => 'tên cột là hằng của lớp (matters.code/title/case_number), bí danh dựng từ enum; chuỗi tìm đi qua binding ? đã escape LIKE',
    'app/Actions/Search/SearchMatters.php:"{$column} LIKE ? ESCAPE \'".self::ESCAPE."\'"' => 'tên cột là hằng của lớp hoặc qualifyColumn(); chuỗi tìm đi qua binding ? đã escape LIKE',
    'app/Actions/Storage/MeasureDocumentStore.php:\'drive_objects.object_key = \'.$this->mediaKeyExpression()' => 'biểu thức CONCAT cố định theo tên driver CSDL, không có giá trị người dùng',
    'app/Models/Instalment.php:$this->qualifyColumn(\'amount\').\' > \'.BillingSummary::collectedExpression($this->qualifyColumn(\'id\'))' => 'chỉ nối tên cột qualify do model dựng vào biểu thức tổng hợp cố định',
    'app/Models/Task.php:\'CASE WHEN tasks.status IN (\'.implode(\', \',array_fill(0,count($open),\'?\')).\') THEN 0 ELSE 1 END\'' => 'chỉ chèn danh sách dấu ? theo số trạng thái mở của enum; giá trị đi qua binding $open',
];
