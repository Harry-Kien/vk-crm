<?php

/*
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, 2026-10-09) — tab "Hợp đồng và thanh toán":
 * soạn hợp đồng mới sau khi huỷ (A1), miễn và bỏ miễn đợt (A2), chọn đúng khoản thu để huỷ (A3).
 * Tệp riêng để không chạm khối của làn khác trong lang/vi/billing.php.
 */
return [
    'errors' => [
        'instalment_not_waived' => 'Không thể bỏ miễn đợt ":name": đợt này đang ở trạng thái ":status", không phải "đã miễn".',
        'instalment_not_reschedulable' => 'Không thể dời hạn đợt ":name": đợt này đang ở trạng thái ":status" — chỉ dời được đợt còn chờ thu.',
    ],

    'reschedule' => [
        'label' => 'Dời hạn',
        'heading' => 'Dời ngày đến hạn của đợt',
        'description' => 'Đợt ":name" (:amount) đang đến hạn ngày :due_date. Số tiền và trạng thái giữ nguyên; công nợ, nhắc quá hạn và cổng khách đọc theo ngày mới.',
        'field' => 'Ngày đến hạn mới',
        'success' => 'Đã dời ngày đến hạn.',
        'no_due_date_yet' => 'Đợt ":name" chưa có ngày đến hạn (đợt theo giai đoạn chưa tới giai đoạn kích hoạt) — chưa có gì để dời.',
        'same_date' => 'Ngày đến hạn mới trùng ngày cũ.',
    ],

    'waive' => [
        'description' => 'Đợt ":name" — giá trị :amount, đã thu :collected. Nếu miễn, văn phòng thôi đòi :written_off. Miễn nhầm thì bấm "Bỏ miễn" trên đúng dòng này, trước khi hoàn tất hợp đồng, để đưa đợt về chờ thu — hợp đồng đã hoàn tất thì không bỏ miễn được nữa.',
    ],

    'unwaive' => [
        'label' => 'Bỏ miễn',
        'heading' => 'Bỏ miễn đợt thanh toán',
        'description' => 'Đợt ":name" (:amount) quay lại chờ thu, theo đúng hạn đã có. Lý do miễn trước đó: :previous_reason. Lần miễn cũ và lần bỏ miễn này đều giữ trong nhật ký.',
        'success' => 'Đã bỏ miễn — đợt quay lại chờ thu.',
    ],

    'void' => [
        'heading' => 'Huỷ một khoản thu',
        'description' => 'Chọn đúng khoản cần huỷ. Mặc định là khoản GHI VÀO HỆ THỐNG gần nhất (theo lúc ghi, không theo ngày tiền về). Khoản bị huỷ vẫn ở lại trong lịch sử, kèm lý do.',
        'option' => ':date — :amount (:method), ghi lúc :recorded_at',
        'option_reference' => ':date — :amount (:method), mã :reference, ghi lúc :recorded_at',
        'option_voided' => ':date — :amount (:method) — đã huỷ',
        'field' => 'Khoản thu cần huỷ',
    ],

    'redraft' => [
        'label' => 'Soạn hợp đồng mới',
        'cancelled_hint' => 'Hợp đồng này đã huỷ. Muốn ký lại với điều khoản mới, bấm "Soạn hợp đồng mới"; hợp đồng đã huỷ và các khoản đã thu của nó ở lại làm lịch sử. Hợp đồng mới chỉ ghi phần còn phải thu: tiền đã thu trên hợp đồng đã huỷ vẫn tính doanh thu — không ghi lại trên hợp đồng mới.',
        'collected_rule' => 'Đã thu :collected trên hợp đồng đã huỷ của vụ; khoản này vẫn tính doanh thu. Hợp đồng mới chỉ ghi phần còn phải thu — không ghi lại tiền đã thu, nếu không doanh thu bị tính hai lần; ghi hợp đồng mới đủ giá trị cũ thì cổng khách và trang Công nợ báo khách còn nợ nhiều hơn thực tế.',
        'previous_heading' => 'Hợp đồng trước của vụ',
        'previous_line' => ':code — :status ngày :date — đã thu :collected',
    ],

    'cancel' => [
        'description' => 'Sau khi huỷ, :outstanding còn phải thu sẽ không còn được theo dõi công nợ, nhắc quá hạn hay hiện trên cổng khách; các khoản đã thu giữ nguyên. Không hoàn tác được. Muốn ký lại với điều khoản mới, huỷ hợp đồng này rồi bấm "Soạn hợp đồng mới": hợp đồng mới chỉ ghi phần còn phải thu — tiền đã thu trên hợp đồng này vẫn tính doanh thu, không ghi lại.',
    ],

    // làn fa3 (2026-10-16, mục 2): "Đưa về bản nháp" — RevertContractToDraft, ContractNotRevertible,
    // UpdateDraftContract (đợt giữ lịch sử khoản thu đã huỷ).
    'revert' => [
        'label' => 'Đưa về bản nháp',
        'heading' => 'Đưa hợp đồng về bản nháp',
        'description' => 'Dùng khi kích hoạt nhầm (gõ sai ngày ký, thuế suất, giá trị hay lịch thu). Hợp đồng :code trở về bản nháp: ngày ký bỏ trống, các đợt "khi ký" và "theo giai đoạn" mất hạn đã tính (tính lại khi kích hoạt lại), cổng khách thôi hiện hợp đồng này. Sau đó bấm "Sửa bản nháp" rồi "Kích hoạt (đã ký)" với đúng ngày ký. Chỉ làm được khi hồ sơ chưa kết thúc, chưa có khoản thu nào (khoản đã huỷ thì được), chưa có phụ lục và chưa đợt nào từng được miễn hay dời hạn. Thư nhắc và thư báo đã gửi cho khách theo hợp đồng này không thu hồi được — nếu khách đã nhận, báo lại cho khách. Luật sư phụ trách được báo.',
        'reason_help' => 'Ghi rõ đã nhầm gì (tối thiểu 20 ký tự) — lý do vào nhật ký.',
        'success' => 'Đã đưa hợp đồng về bản nháp — sửa rồi kích hoạt lại với đúng ngày ký.',
        'not_active' => 'Chỉ đưa về bản nháp được hợp đồng đang có hiệu lực. Hợp đồng :code đang ở trạng thái ":status".',
        'has_payments' => 'Không đưa hợp đồng :code về bản nháp được: đã có khoản thu chưa huỷ (tổng :amount). Kế toán huỷ các khoản thu này ở trang "Công nợ" (ghi lý do) trước, rồi đưa về nháp, sửa, kích hoạt lại và ghi lại khoản thu.',
        // fix round 1 (m5): RevertContractToDraft từ chối trên hồ sơ đã kết thúc.
        'matter_closed' => 'Không đưa hợp đồng :code về bản nháp được: hồ sơ :matter đã kết thúc. Hợp đồng của hồ sơ đã kết thúc giữ nguyên như đã ký (công nợ và thư nhắc của nó còn nguyên) — sửa con số bằng phụ lục, hoặc huỷ hợp đồng (ghi lý do).',
        // fix round 2 (I-B, phán quyết controller 2026-10-18): contract.enter không contract.manage chỉ đưa về hợp đồng
        // chính mình đã kích hoạt.
        'not_own_activation' => 'Không đưa hợp đồng :code về bản nháp được: hợp đồng này do :who kích hoạt. Người chỉ có quyền nhập hợp đồng chỉ đưa về bản nháp được hợp đồng chính mình đã kích hoạt — nếu thật sự kích hoạt nhầm, nhờ luật sư phụ trách hoặc trưởng phòng đưa về bản nháp.',
        'unknown_activator' => 'một người khác (không rõ tên)',
        'has_amendments' => 'Không đưa hợp đồng :code về bản nháp được: hợp đồng đã có phụ lục ký với khách. Sửa tiếp bằng phụ lục, hoặc huỷ hợp đồng (ghi lý do) rồi soạn hợp đồng mới.',
        'has_corrections' => 'Không đưa hợp đồng :code về bản nháp được: đợt ":name" đã từng được miễn hoặc dời hạn sau khi ký. Sửa tiếp bằng phụ lục, hoặc huỷ hợp đồng (ghi lý do) rồi soạn hợp đồng mới.',
        'keeps_voided_history' => 'Đợt ":name" (đợt thứ :position) còn lịch sử khoản thu đã huỷ nên không bỏ được: lịch thu phải giữ ít nhất :position đợt (đợt đó được ghi đè bằng dòng cùng vị trí).',
    ],
    // hết khối làn fa3
];
