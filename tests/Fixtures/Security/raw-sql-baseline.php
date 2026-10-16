<?php

/**
 * Danh sách nền SQL thô đã được rà soát (M15 Task 1). Khoá: "<đường dẫn>:<tên hàm>:<đối số đầu đã
 * chuẩn hoá>"; `count` là số lần xuất hiện ĐÚNG đã duyệt (thêm một lần nữa là đỏ, bớt đi cũng đỏ);
 * `reason` là lý do an toàn. Đối số đầu KHÔNG phải chuỗi hằng thì phải có mặt ở đây, và chỉ khi không
 * byte nào của nó đến từ request/dữ liệu người dùng gõ/cấu hình người dùng sửa. Nếu có thì sửa sang
 * binding, KHÔNG thêm vào đây. Xem tests/Feature/Security/RawSqlInterpolationTest.php.
 *
 * @return array<string, array{count: int, reason: string}>
 */
return [
    'app/Actions/Document/ChecklistProgress.php:selectRaw:"SUM(CASE WHEN matter_checklist_items.status IN ({$placeholders}) THEN 1 ELSE 0 END) as settled"' => [
        'count' => 1,
        'reason' => 'chỉ chèn danh sách dấu ? do code dựng từ hằng SETTLED_STATUSES; giá trị đi qua binding $settled',
    ],
    'app/Actions/Document/ChecklistProgress.php:whereRaw:self::missingSinceSql().\' < ?\'' => [
        'count' => 1,
        'reason' => 'missingSinceSql() trả chuỗi hằng COALESCE(cột, cột); giá trị so sánh đi qua binding',
    ],
    'app/Actions/Performance/BuildTeamWorkload.php:raw:"{$person} as person_id"' => [
        'count' => 1,
        'reason' => 'chỉ chèn $person — cùng danh sách cho phép personExpressions() như DB::raw($person); có test ném lỗi ngoài danh sách',
    ],
    'app/Actions/Performance/BuildTeamWorkload.php:raw:$person' => [
        'count' => 1,
        'reason' => 'DB::raw($person) — countPer() chỉ nhận $person nằm trong BuildTeamWorkload::personExpressions() (tên cột hằng và ClientRequest::holderIdSql(), một biểu thức COALESCE hằng), nếu không thì ném InvalidArgumentException; có test',
    ],
    'app/Actions/Performance/BuildTeamWorkload.php:selectRaw:\'MAX(\'.$rows->qualifyColumn(\'created_at\').\') as last_at\'' => [
        'count' => 1,
        'reason' => 'chỉ nối tên cột đã qualify do model dựng',
    ],
    'app/Actions/Schedule/RemindClientsOfUpcomingInstalments.php:whereRaw:\'instalments.amount > \'.BillingSummary::collectedExpression(\'instalments.id\')' => [
        'count' => 1,
        'reason' => 'biểu thức tổng hợp cố định của BillingSummary với tên cột hằng',
    ],
    'app/Actions/Search/SearchMatters.php:selectRaw:"CASE WHEN {$column} LIKE ? ESCAPE \'".self::ESCAPE."\' THEN 1 ELSE 0 END AS ".self::hitAlias($source)' => [
        'count' => 1,
        'reason' => 'tên cột là hằng của lớp (matters.code/title/case_number), bí danh dựng từ enum; chuỗi tìm đi qua binding ? đã escape LIKE',
    ],
    'app/Actions/Search/SearchMatters.php:whereRaw:"{$column} LIKE ? ESCAPE \'".self::ESCAPE."\'"' => [
        'count' => 1,
        'reason' => 'tên cột là hằng của lớp hoặc qualifyColumn(); chuỗi tìm đi qua binding ? đã escape LIKE',
    ],
    'app/Actions/Storage/MeasureDocumentStore.php:whereRaw:\'drive_objects.object_key = \'.$this->mediaKeyExpression()' => [
        'count' => 1,
        'reason' => 'biểu thức CONCAT cố định theo tên driver CSDL, không có giá trị người dùng',
    ],
    'app/Filament/Admin/Resources/Matters/Tables/MattersTable.php:selectRaw:\'matters.*, \'.BillingSummary::outstandingPerMatterExpression().\' as outstanding_balance_amount\'' => [
        'count' => 1,
        'reason' => 'biểu thức công nợ cố định của BillingSummary (giá trị enum ContractStatus/InstalmentStatus do code), không có đầu vào người dùng',
    ],
    'app/Filament/Admin/Widgets/MattersMissingDocumentsWidget.php:raw:ChecklistProgress::missingSinceSql()' => [
        'count' => 1,
        'reason' => 'chuỗi hằng COALESCE(cột, cột) do code dựng',
    ],
    'app/Filament/Admin/Widgets/Revenue/ClosedWithBalanceWidget.php:selectRaw:\'matters.*, (\'.BillingSummary::outstandingPerMatterExpression().\') as outstanding_amount\'' => [
        'count' => 1,
        'reason' => 'biểu thức công nợ cố định của BillingSummary, không có đầu vào người dùng',
    ],
    'app/Filament/Admin/Widgets/Revenue/ClosedWithBalanceWidget.php:whereRaw:\'(\'.BillingSummary::outstandingPerMatterExpression().\') > 0\'' => [
        'count' => 1,
        'reason' => 'biểu thức công nợ cố định của BillingSummary, không có đầu vào người dùng',
    ],
    'app/Filament/Admin/Widgets/Revenue/ReceivablesDonutWidget.php:selectRaw:\'coalesce(sum(amount - (\'.BillingSummary::collectedExpression().\')), 0) as agg\'' => [
        'count' => 1,
        'reason' => 'biểu thức tổng hợp cố định của BillingSummary với cột mặc định',
    ],
    'app/Models/ClientRequest.php:raw:self::holderIdSql().\' as holder_id\'' => [
        'count' => 1,
        'reason' => 'holderIdSql() trả chuỗi hằng COALESCE(cột, cột)',
    ],
    'app/Models/ClientRequest.php:whereRaw:self::holderIdSql().\' = ?\'' => [
        'count' => 1,
        'reason' => 'holderIdSql() là chuỗi hằng; id người đi qua binding ?',
    ],
    'app/Models/Instalment.php:whereRaw:$this->qualifyColumn(\'amount\').\' > \'.BillingSummary::collectedExpression($this->qualifyColumn(\'id\'))' => [
        'count' => 1,
        'reason' => 'chỉ nối tên cột qualify do model dựng vào biểu thức tổng hợp cố định',
    ],
    'app/Models/Task.php:orderByRaw:\'CASE WHEN tasks.status IN (\'.implode(\', \',array_fill(0,count($open),\'?\')).\') THEN 0 ELSE 1 END\'' => [
        'count' => 1,
        'reason' => 'chỉ chèn danh sách dấu ? theo số trạng thái mở của enum; giá trị đi qua binding $open',
    ],
    'app/Support/Billing/BillingSummary.php:selectRaw:self::collectedExpression().\' as collected_amount\'' => [
        'count' => 1,
        'reason' => 'biểu thức tổng hợp cố định, cột mặc định instalments.id',
    ],
    'app/Support/Billing/BillingSummary.php:selectRaw:self::outstandingExpression(\'instalments.amount\',\'instalments.id\').\' as outstanding_amount\'' => [
        'count' => 1,
        'reason' => 'biểu thức công nợ cố định với tên cột hằng',
    ],
];
