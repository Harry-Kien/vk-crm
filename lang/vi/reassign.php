<?php

/**
 * Bàn giao vụ việc (`App\Actions\Matter\ReassignMatter`, SPEC §6.11; M6.5 Task 4, R7) — header
 * action "Bàn giao" trên `ViewMatter`.
 */
return [
    'action' => [
        'label' => 'Bàn giao',
        'modal_heading' => 'Bàn giao vụ việc cho luật sư phụ trách mới',
        'submit' => 'Bàn giao',
        'success' => 'Đã bàn giao vụ việc.',
    ],
    'fields' => [
        'new_lead_id' => 'Luật sư phụ trách mới',
        'keep_old_lead_as_associate' => 'Giữ luật sư cũ trong đội ngũ với vai luật sư cộng sự',
        'keep_old_lead_as_associate_hint' => 'Nếu tắt, luật sư cũ sẽ bị gỡ hẳn khỏi đội ngũ vụ việc này.',
        'reason' => 'Lý do bàn giao',
    ],
    'stage_log' => [
        'internal_note' => 'Đã bàn giao vụ việc từ :from sang :to. Lý do: :reason',
    ],
    'validation' => [
        'same_lead' => 'Không thể bàn giao: người được chọn đã đang là luật sư phụ trách của chính vụ việc này.',
        'new_lead_inactive' => 'Không thể bàn giao cho người này: tài khoản đã bị vô hiệu hoá hoặc đã nghỉ việc.',
        'reason_required' => 'Phải nhập lý do bàn giao.',
        // Vụ `restricted`: Matter::isListableBy() nhánh đó chỉ cho admin hoặc chính lead_lawyer_id
        // xem được — một lead cũ ở lại với vai associate sẽ không bao giờ mở lại được vụ việc này.
        'old_lead_would_not_see_matter' => 'Không thể giữ luật sư cũ trong đội ngũ: vụ việc đang ở chế độ hạn chế, người này sẽ không còn xem được vụ việc với vai luật sư cộng sự. Hãy tắt công tắc "Giữ luật sư cũ trong đội ngũ" rồi bàn giao lại.',
    ],
];
