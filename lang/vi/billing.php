<?php

/**
 * Chuỗi tiếng Việt của phần hợp đồng dịch vụ và thu phí (M9). Thông điệp lỗi ở đây nói cho người
 * dùng biết phải làm gì tiếp, không chỉ điều gì sai — cùng tinh thần với `lang/vi/exceptions.php`.
 * Số tiền trong thông điệp luôn đi qua `App\Support\Billing\Money::format()`.
 */
return [
    'validation' => [
        'money_format' => 'Số tiền chỉ gồm chữ số, có thể chia nhóm ba chữ số bằng dấu chấm (ví dụ 1.250.000). Đồng không có phần lẻ, nên không dùng dấu chấm hay dấu phẩy thập phân.',
        'money_too_large' => 'Số tiền không được vượt quá :max.',
        'amount_required' => 'Số tiền phải là một số nguyên đồng lớn hơn 0.',
        'split_needs_parts' => 'Cần ít nhất một đợt để chia số tiền.',
        'percents_must_total_100' => 'Các phần trăm phải cộng lại đúng 100%. Đợt cuối tự nhận phần dư làm tròn, không tự nhận phần trăm còn thiếu.',
        'percent_out_of_range' => 'Mỗi phần trăm phải lớn hơn 0, tối đa 100, và có nhiều nhất hai chữ số thập phân.',
        'vat_rate_out_of_range' => 'Thuế suất phải là một số nguyên từ 0 đến 100, hoặc để trống nếu hợp đồng không có dòng thuế.',
        'billing_model_invalid' => 'Cách tính phí không hợp lệ.',
        'contract_exists' => 'Vụ việc này đã có hợp đồng. Mỗi vụ việc chỉ có một hợp đồng; thay đổi giá trị hoặc lịch thu bằng phụ lục.',
        'instalment_name_required' => 'Mỗi đợt phải có tên — khách hàng sẽ thấy tên này trên cổng.',
        'instalment_name_too_long' => 'Tên đợt không được dài quá :max ký tự.',
        'trigger_type_invalid' => 'Hãy chọn đợt này đến hạn khi nào: khi ký hợp đồng, vào một ngày cụ thể, hoặc khi vụ việc tới một giai đoạn.',
        'due_date_required' => 'Đợt đến hạn vào một ngày cụ thể phải có ngày đến hạn hợp lệ.',
        'due_days_invalid' => 'Số ngày đến hạn sau khi kích hoạt phải là một số nguyên từ 0 đến :max.',
        'trigger_stage_unknown' => 'Giai đoạn kích hoạt ":stage" không có trong cấu hình của loại vụ việc này.',
        'trigger_stage_is_first' => 'Không thể gắn đợt thanh toán vào giai đoạn đầu tiên ":stage": vụ việc đã ở giai đoạn đó ngay từ khi mở, nên đợt sẽ không bao giờ được kích hoạt. Chọn "khi ký hợp đồng" cho khoản tạm ứng.',
        'date_invalid' => 'Ngày không hợp lệ.',
        'date_future' => 'Ngày không được ở tương lai.',
        'amendment_signed_before_contract' => 'Ngày ký phụ lục không được trước ngày ký hợp đồng (:date).',
        'reason_too_short' => 'Lý do phải có ít nhất :min ký tự.',
        'amendment_changes_nothing' => 'Phụ lục phải thay đổi giá trị hợp đồng hoặc lịch thu.',
        'change_action_invalid' => 'Mỗi thay đổi lịch thu phải là thêm đợt, sửa số tiền một đợt, hoặc huỷ một đợt.',
        'instalment_not_in_contract' => 'Đợt thanh toán được chọn không thuộc hợp đồng này.',
        'instalment_changed_twice' => 'Một đợt chỉ được thay đổi một lần trong cùng một phụ lục.',
        'instalment_not_pending' => 'Chỉ sửa hoặc huỷ được đợt còn đang chờ thu. Đợt ":name" đã thu đủ, đã miễn hoặc đã huỷ.',
        'instalment_below_collected' => 'Số tiền mới của đợt ":name" không được nhỏ hơn số đã thu (:collected).',
        'instalment_has_payments' => 'Không thể huỷ đợt ":name": đợt này đã có khoản thu :collected. Huỷ các khoản thu trước (kèm lý do), hoặc giảm số tiền của đợt thay vì huỷ.',
        'document_not_eligible' => 'Bản scan phụ lục phải là một tài liệu nội bộ (nhóm D) của chính vụ việc này.',
        'receipt_not_eligible' => 'Bản scan biên lai phải là một tài liệu nội bộ (nhóm D) của chính vụ việc này.',
        'reference_too_long' => 'Mã giao dịch / số biên lai không được dài quá :max ký tự.',
    ],

    'errors' => [
        'total_mismatch_on_activation' => 'Chưa kích hoạt được hợp đồng :code: tổng các đợt là :schedule, giá trị hợp đồng là :total (lệch :difference). Sửa lịch thu cho khớp đúng từng đồng rồi kích hoạt lại.',
        'total_mismatch_on_amendment' => 'Phụ lục không được ghi: sau thay đổi, tổng các đợt là :schedule, giá trị mới của hợp đồng là :total (lệch :difference). Giá trị hợp đồng và lịch thu phải đổi cùng nhau, khớp đúng từng đồng.',
        'total_mismatch_on_write' => 'Không thể lưu: thay đổi này làm tổng các đợt của hợp đồng :code (:schedule) lệch khỏi giá trị hợp đồng (:total). Giá trị và lịch thu của một hợp đồng đã ký chỉ đổi được bằng phụ lục.',
        'billing_model_not_supported' => 'Cách tính phí ":model" chưa được hỗ trợ. Hiện chỉ ghi được hợp đồng trọn gói (giá trị thoả thuận một lần).',
        'contract_not_amendable' => 'Chỉ ký phụ lục được cho hợp đồng đang có hiệu lực. Hợp đồng :code đang ở trạng thái ":status".',
        'contract_not_draft' => 'Chỉ kích hoạt được hợp đồng còn ở trạng thái "Nháp". Hợp đồng :code đang ở trạng thái ":status".',
        'contract_not_draft_for_update' => 'Chỉ sửa được hợp đồng còn ở trạng thái "Nháp". Hợp đồng :code đang ở trạng thái ":status"; sửa giá trị hoặc lịch thu của một hợp đồng đã ký bằng phụ lục.',
        'contract_not_active' => 'Chỉ hoàn tất hoặc huỷ được hợp đồng đang có hiệu lực. Hợp đồng :code đang ở trạng thái ":status".',
        'contract_has_unsettled' => 'Chưa hoàn tất được hợp đồng :code: còn :count đợt chưa thu đủ và chưa được miễn. Thu nốt, hoặc miễn các đợt còn lại kèm lý do, rồi hoàn tất.',
        'instalment_not_payable_to_record' => 'Không thể ghi khoản thu cho đợt ":name": đợt này đang ở trạng thái ":status".',
        'instalment_not_payable_to_waive' => 'Không thể miễn đợt ":name": đợt này đang ở trạng thái ":status".',
        'instalment_contract_not_active' => 'Không thể thao tác trên đợt ":name": hợp đồng :code đang ở trạng thái ":status", không còn hiệu lực.',
        'payment_exceeds_instalment' => 'Số tiền :amount vượt quá số còn phải thu (:remaining) của đợt ":name". Đây là thu vượt: không tự rải sang đợt sau — ghi đúng số còn lại, hoặc sửa lại nếu đã ghi nhầm khoản trước.',
        'payment_already_voided' => 'Khoản thu này đã được huỷ từ trước, không huỷ lần hai.',
    ],

    'check_invariants' => [
        'description' => 'Quét mọi hợp đồng đang có hiệu lực, liệt kê hợp đồng có tổng các đợt lệch khỏi giá trị hợp đồng',
        'clean' => 'Không có hợp đồng nào lệch: tổng các đợt khớp giá trị hợp đồng trên cả :count hợp đồng đang có hiệu lực.',
        'found' => 'Có :count hợp đồng đang có hiệu lực mà tổng các đợt lệch khỏi giá trị hợp đồng:',
        'columns' => [
            'code' => 'Mã hợp đồng',
            'total' => 'Giá trị hợp đồng',
            'schedule' => 'Tổng các đợt',
            'difference' => 'Chênh lệch',
        ],
    ],
];
