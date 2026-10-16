<?php

/**
 * Danh sách nền SQL thô đã được rà soát (M15 Task 1). Khoá: "<đường dẫn>:<đối số đầu đã chuẩn hoá>";
 * giá trị: lý do an toàn. Đối số đầu KHÔNG phải chuỗi hằng thì phải có mặt ở đây, và chỉ khi không byte
 * nào của nó đến từ request/dữ liệu người dùng gõ/cấu hình người dùng sửa. Nếu có thì sửa sang binding,
 * KHÔNG thêm vào đây. Xem tests/Feature/Security/RawSqlInterpolationTest.php.
 *
 * @return array<string, string>
 */
return [
    'app/Actions/Document/ChecklistProgress.php:"SUM(CASE WHEN matter_checklist_items.status IN ({$placeholders}) THEN 1 ELSE 0 END) as settled"' => 'chỉ chèn danh sách dấu ? do code dựng từ hằng SETTLED_STATUSES; giá trị đi qua binding $settled',
    'app/Actions/Document/ChecklistProgress.php:self::missingSinceSql().\' < ?\'' => 'missingSinceSql() trả chuỗi hằng COALESCE(cột, cột); giá trị so sánh đi qua binding',
    'app/Actions/Performance/BuildTeamWorkload.php:"{$person} as person_id"' => 'chỉ chèn tên cột do code dựng; mọi nơi gọi countPer() truyền hằng chữ (matters.lead_lawyer_id, tasks.assignee_id...), không có đầu vào người dùng',
    'app/Actions/Performance/BuildTeamWorkload.php:$person' => 'DB::raw($person) — $person là tên cột hằng do mọi nơi gọi countPer() truyền (matters.lead_lawyer_id, tasks.assignee_id...), cùng nguồn với dòng select',
    'app/Actions/Performance/BuildTeamWorkload.php:\'MAX(\'.$rows->qualifyColumn(\'created_at\').\') as last_at\'' => 'chỉ nối tên cột đã qualify do model dựng',
    'app/Actions/Schedule/RemindClientsOfUpcomingInstalments.php:\'instalments.amount > \'.BillingSummary::collectedExpression(\'instalments.id\')' => 'biểu thức tổng hợp cố định của BillingSummary với tên cột hằng',
    'app/Actions/Search/SearchMatters.php:"CASE WHEN {$column} LIKE ? ESCAPE \'".self::ESCAPE."\' THEN 1 ELSE 0 END AS ".self::hitAlias($source)' => 'tên cột là hằng của lớp (matters.code/title/case_number), bí danh dựng từ enum; chuỗi tìm đi qua binding ? đã escape LIKE',
    'app/Actions/Search/SearchMatters.php:"{$column} LIKE ? ESCAPE \'".self::ESCAPE."\'"' => 'tên cột là hằng của lớp hoặc qualifyColumn(); chuỗi tìm đi qua binding ? đã escape LIKE',
    'app/Actions/Storage/MeasureDocumentStore.php:\'drive_objects.object_key = \'.$this->mediaKeyExpression()' => 'biểu thức CONCAT cố định theo tên driver CSDL, không có giá trị người dùng',
    'app/Filament/Admin/Resources/Matters/Tables/MattersTable.php:\'matters.*, \'.BillingSummary::outstandingPerMatterExpression().\' as outstanding_balance_amount\'' => 'biểu thức công nợ cố định của BillingSummary (giá trị enum ContractStatus/InstalmentStatus do code), không có đầu vào người dùng',
    'app/Filament/Admin/Widgets/MattersMissingDocumentsWidget.php:ChecklistProgress::missingSinceSql()' => 'chuỗi hằng COALESCE(cột, cột) do code dựng',
    'app/Filament/Admin/Widgets/Revenue/ClosedWithBalanceWidget.php:\'(\'.BillingSummary::outstandingPerMatterExpression().\') > 0\'' => 'biểu thức công nợ cố định của BillingSummary, không có đầu vào người dùng',
    'app/Filament/Admin/Widgets/Revenue/ClosedWithBalanceWidget.php:\'matters.*, (\'.BillingSummary::outstandingPerMatterExpression().\') as outstanding_amount\'' => 'biểu thức công nợ cố định của BillingSummary, không có đầu vào người dùng',
    'app/Filament/Admin/Widgets/Revenue/ReceivablesDonutWidget.php:\'coalesce(sum(amount - (\'.BillingSummary::collectedExpression().\')), 0) as agg\'' => 'biểu thức tổng hợp cố định của BillingSummary với cột mặc định',
    'app/Models/ClientRequest.php:self::holderIdSql().\' = ?\'' => 'holderIdSql() là chuỗi hằng; id người đi qua binding ?',
    'app/Models/ClientRequest.php:self::holderIdSql().\' as holder_id\'' => 'holderIdSql() trả chuỗi hằng COALESCE(cột, cột)',
    'app/Models/Instalment.php:$this->qualifyColumn(\'amount\').\' > \'.BillingSummary::collectedExpression($this->qualifyColumn(\'id\'))' => 'chỉ nối tên cột qualify do model dựng vào biểu thức tổng hợp cố định',
    'app/Models/Task.php:\'CASE WHEN tasks.status IN (\'.implode(\', \',array_fill(0,count($open),\'?\')).\') THEN 0 ELSE 1 END\'' => 'chỉ chèn danh sách dấu ? theo số trạng thái mở của enum; giá trị đi qua binding $open',
    'app/Support/Billing/BillingSummary.php:self::collectedExpression().\' as collected_amount\'' => 'biểu thức tổng hợp cố định, cột mặc định instalments.id',
    'app/Support/Billing/BillingSummary.php:self::outstandingExpression(\'instalments.amount\',\'instalments.id\').\' as outstanding_amount\'' => 'biểu thức công nợ cố định với tên cột hằng',
];
