<?php

/**
 * M13 — theo dõi đội ngũ và hiệu suất (kế hoạch `docs/superpowers/plans/2026-10-04-m13-team-performance.md`).
 *
 * Tệp này hai làn M13 cùng sửa (làn A: Task 1, 2, 4, 5, 7, 8; làn m13b: Task 3, 6). Mỗi task thêm
 * khoá theo KHỐI liền nhau, mỗi khối mở bằng một chú thích `// Task N`, để người gộp giữ cả hai bên
 * mà không phải đọc lại cả tệp.
 */
return [
    // Task 1 — câu phạm vi cố định (R4) và tên ba trang.

    // R4: MỌI trang in đúng câu này cho MỌI người xem, kể cả admin — luôn có mặt nên sự có mặt của
    // nó không mang tín hiệu nào về vụ `restricted`. Kế hoạch viết "bạn"; panel nội bộ xưng
    // "anh/chị" (cùng câu phạm vi của M9, `billing.receivables.scope_note`), nên câu theo panel.
    'scope_note' => 'Mọi con số tính trên các vụ việc anh/chị được xem.',

    'pages' => [
        'team_overview' => [
            'navigation_label' => 'Theo dõi đội ngũ',
            'title' => 'Theo dõi đội ngũ',
        ],
        'team_member' => [
            // Mục điều hướng của người KHÔNG có `performance.viewAny`: trỏ tới trang của chính họ.
            'navigation_label' => 'Việc của tôi',
            'title_self' => 'Việc của tôi',
            'title' => 'Công việc của :name',
        ],
        'performance' => [
            'navigation_label' => 'Hiệu suất',
            'title' => 'Hiệu suất theo kỳ',
        ],
    ],

    // Task 2 — nhãn của App\Enums\DeadlineOutcome (kết quả của Deadline::outcomeAt(), cột P1).
    'outcomes' => [
        'on_time' => 'Đúng hạn',
        'late' => 'Trễ hạn',
        'missed' => 'Lỡ hạn',
    ],
];
