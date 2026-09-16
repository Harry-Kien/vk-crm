<?php

/**
 * Thông điệp lỗi xác thực dữ liệu đầu vào mặc định của framework (khác với
 * lang/vi/actions.php, dành cho lỗi xác thực nghiệp vụ riêng của từng Action).
 *
 * Bản dịch từ lang/en/validation.php (Laravel 13, `artisan lang:publish`). Dịch đủ mọi khoá —
 * thiếu một khoá sẽ lặng lẽ rơi về tiếng Anh (fallback locale) mà không có gì báo lỗi, xem
 * tests/Feature/LocalizationTest.php.
 */
return [

    'accepted' => 'Vui lòng xác nhận :attribute.',
    'accepted_if' => 'Vui lòng xác nhận :attribute khi :other là :value.',
    'active_url' => ':attribute phải là một URL hợp lệ.',
    'after' => ':attribute phải là ngày sau :date.',
    'after_or_equal' => ':attribute phải là ngày sau hoặc bằng :date.',
    'alpha' => ':attribute chỉ được chứa chữ cái.',
    'alpha_dash' => ':attribute chỉ được chứa chữ cái, số, dấu gạch ngang và gạch dưới.',
    'alpha_num' => ':attribute chỉ được chứa chữ cái và số.',
    'any_of' => ':attribute không hợp lệ.',
    'array' => ':attribute phải là một mảng.',
    'array_keys' => ':attribute chỉ được chứa các khoá sau: :values.',
    'ascii' => ':attribute chỉ được chứa chữ, số và ký hiệu một byte.',
    'base64' => ':attribute phải là một chuỗi Base64 hợp lệ.',
    'before' => ':attribute phải là ngày trước :date.',
    'before_or_equal' => ':attribute phải là ngày trước hoặc bằng :date.',
    'between' => [
        'array' => ':attribute phải có từ :min đến :max phần tử.',
        'file' => ':attribute phải có dung lượng từ :min đến :max kilobyte.',
        'numeric' => ':attribute phải có giá trị từ :min đến :max.',
        'string' => ':attribute phải có độ dài từ :min đến :max ký tự.',
    ],
    'boolean' => ':attribute phải là đúng hoặc sai.',
    'can' => ':attribute chứa một giá trị không được phép.',
    'confirmed' => 'Xác nhận :attribute không khớp.',
    'contains' => ':attribute còn thiếu một giá trị bắt buộc.',
    'current_password' => 'Mật khẩu không đúng.',
    'date' => ':attribute phải là một ngày hợp lệ.',
    'date_equals' => ':attribute phải là ngày bằng :date.',
    'date_format' => ':attribute phải đúng định dạng :format.',
    'decimal' => ':attribute phải có :decimal chữ số thập phân.',
    'declined' => 'Vui lòng từ chối :attribute.',
    'declined_if' => 'Vui lòng từ chối :attribute khi :other là :value.',
    'different' => ':attribute và :other phải khác nhau.',
    'digits' => ':attribute phải có :digits chữ số.',
    'digits_between' => ':attribute phải có từ :min đến :max chữ số.',
    'dimensions' => ':attribute có kích thước ảnh không hợp lệ.',
    'distinct' => ':attribute có giá trị bị trùng lặp.',
    'doesnt_contain' => ':attribute không được chứa bất kỳ giá trị nào sau đây: :values.',
    'doesnt_end_with' => ':attribute không được kết thúc bằng một trong các giá trị sau: :values.',
    'doesnt_start_with' => ':attribute không được bắt đầu bằng một trong các giá trị sau: :values.',
    'email' => ':attribute phải là một địa chỉ email hợp lệ.',
    'encoding' => ':attribute phải được mã hoá theo :encoding.',
    'ends_with' => ':attribute phải kết thúc bằng một trong các giá trị sau: :values.',
    'enum' => ':attribute đã chọn không hợp lệ.',
    'exists' => ':attribute đã chọn không hợp lệ.',
    'extensions' => ':attribute phải có một trong các phần mở rộng sau: :values.',
    'file' => ':attribute phải là một tệp.',
    'filled' => 'Vui lòng nhập :attribute.',
    'gt' => [
        'array' => ':attribute phải có nhiều hơn :value phần tử.',
        'file' => ':attribute phải lớn hơn :value kilobyte.',
        'numeric' => ':attribute phải lớn hơn :value.',
        'string' => ':attribute phải dài hơn :value ký tự.',
    ],
    'gte' => [
        'array' => ':attribute phải có :value phần tử trở lên.',
        'file' => ':attribute phải lớn hơn hoặc bằng :value kilobyte.',
        'numeric' => ':attribute phải lớn hơn hoặc bằng :value.',
        'string' => ':attribute phải dài :value ký tự trở lên.',
    ],
    'hex_color' => ':attribute phải là một mã màu hex hợp lệ.',
    'image' => ':attribute phải là một ảnh.',
    'in' => ':attribute đã chọn không hợp lệ.',
    'in_array' => ':attribute phải tồn tại trong :other.',
    'in_array_keys' => ':attribute phải chứa ít nhất một trong các khoá sau: :values.',
    'integer' => ':attribute phải là một số nguyên.',
    'ip' => ':attribute phải là một địa chỉ IP hợp lệ.',
    'ipv4' => ':attribute phải là một địa chỉ IPv4 hợp lệ.',
    'ipv6' => ':attribute phải là một địa chỉ IPv6 hợp lệ.',
    'json' => ':attribute phải là một chuỗi JSON hợp lệ.',
    'list' => ':attribute phải là một danh sách.',
    'lowercase' => ':attribute phải viết thường.',
    'lt' => [
        'array' => ':attribute phải có ít hơn :value phần tử.',
        'file' => ':attribute phải nhỏ hơn :value kilobyte.',
        'numeric' => ':attribute phải nhỏ hơn :value.',
        'string' => ':attribute phải ngắn hơn :value ký tự.',
    ],
    'lte' => [
        'array' => ':attribute không được có nhiều hơn :value phần tử.',
        'file' => ':attribute phải nhỏ hơn hoặc bằng :value kilobyte.',
        'numeric' => ':attribute phải nhỏ hơn hoặc bằng :value.',
        'string' => ':attribute không được dài hơn :value ký tự.',
    ],
    'mac_address' => ':attribute phải là một địa chỉ MAC hợp lệ.',
    'max' => [
        'array' => ':attribute không được có nhiều hơn :max phần tử.',
        'file' => ':attribute không được lớn hơn :max kilobyte.',
        'numeric' => ':attribute không được lớn hơn :max.',
        'string' => ':attribute không được dài hơn :max ký tự.',
    ],
    'max_digits' => ':attribute không được có nhiều hơn :max chữ số.',
    'mimes' => ':attribute phải là một tệp thuộc loại: :values.',
    'mimetypes' => ':attribute phải là một tệp thuộc loại: :values.',
    'min' => [
        'array' => ':attribute phải có ít nhất :min phần tử.',
        'file' => ':attribute phải có dung lượng ít nhất :min kilobyte.',
        'numeric' => ':attribute phải có giá trị ít nhất :min.',
        'string' => ':attribute phải có ít nhất :min ký tự.',
    ],
    'min_digits' => ':attribute phải có ít nhất :min chữ số.',
    'missing' => ':attribute phải để trống.',
    'missing_if' => ':attribute phải để trống khi :other là :value.',
    'missing_unless' => ':attribute phải để trống trừ khi :other là :value.',
    'missing_with' => ':attribute phải để trống khi có :values.',
    'missing_with_all' => ':attribute phải để trống khi có :values.',
    'multiple_of' => ':attribute phải là bội số của :value.',
    'not_in' => ':attribute đã chọn không hợp lệ.',
    'not_regex' => 'Định dạng :attribute không hợp lệ.',
    'numeric' => ':attribute phải là một số.',
    'password' => [
        'letters' => ':attribute phải chứa ít nhất một chữ cái.',
        'mixed' => ':attribute phải chứa ít nhất một chữ hoa và một chữ thường.',
        'numbers' => ':attribute phải chứa ít nhất một chữ số.',
        'symbols' => ':attribute phải chứa ít nhất một ký hiệu.',
        'uncompromised' => ':attribute đã xuất hiện trong một vụ rò rỉ dữ liệu. Vui lòng chọn :attribute khác.',
    ],
    'present' => 'Vui lòng cung cấp :attribute.',
    'present_if' => 'Vui lòng cung cấp :attribute khi :other là :value.',
    'present_unless' => 'Vui lòng cung cấp :attribute trừ khi :other là :value.',
    'present_with' => 'Vui lòng cung cấp :attribute khi có :values.',
    'present_with_all' => 'Vui lòng cung cấp :attribute khi có :values.',
    'prohibited' => 'Không được nhập :attribute.',
    'prohibited_if' => 'Không được nhập :attribute khi :other là :value.',
    'prohibited_if_accepted' => 'Không được nhập :attribute khi :other được chấp nhận.',
    'prohibited_if_declined' => 'Không được nhập :attribute khi :other bị từ chối.',
    'prohibited_unless' => 'Không được nhập :attribute trừ khi :other nằm trong :values.',
    'prohibits' => ':attribute không cho phép :other xuất hiện cùng lúc.',
    'regex' => 'Định dạng :attribute không hợp lệ.',
    'required' => 'Vui lòng nhập :attribute.',
    'required_array_keys' => ':attribute phải chứa các mục: :values.',
    'required_if' => 'Vui lòng nhập :attribute khi :other là :value.',
    'required_if_accepted' => 'Vui lòng nhập :attribute khi :other được chấp nhận.',
    'required_if_declined' => 'Vui lòng nhập :attribute khi :other bị từ chối.',
    'required_unless' => 'Vui lòng nhập :attribute trừ khi :other nằm trong :values.',
    'required_with' => 'Vui lòng nhập :attribute khi có :values.',
    'required_with_all' => 'Vui lòng nhập :attribute khi có :values.',
    'required_without' => 'Vui lòng nhập :attribute khi không có :values.',
    'required_without_all' => 'Vui lòng nhập :attribute khi không có bất kỳ giá trị nào trong :values.',
    'same' => ':attribute phải khớp với :other.',
    'size' => [
        'array' => ':attribute phải chứa :size phần tử.',
        'file' => ':attribute phải có dung lượng :size kilobyte.',
        'numeric' => ':attribute phải bằng :size.',
        'string' => ':attribute phải có :size ký tự.',
    ],
    'starts_with' => ':attribute phải bắt đầu bằng một trong các giá trị sau: :values.',
    'string' => ':attribute phải là một chuỗi ký tự.',
    'timezone' => ':attribute phải là một múi giờ hợp lệ.',
    'unique' => ':attribute đã tồn tại.',
    'uploaded' => 'Tải lên :attribute không thành công.',
    'uppercase' => ':attribute phải viết hoa.',
    'url' => ':attribute phải là một URL hợp lệ.',
    'ulid' => ':attribute phải là một ULID hợp lệ.',
    'uuid' => ':attribute phải là một UUID hợp lệ.',

    /*
    |--------------------------------------------------------------------------
    | Thông điệp xác thực tuỳ chỉnh
    |--------------------------------------------------------------------------
    |
    | Đặt thông điệp riêng cho một cặp "tên-trường.tên-luật" cụ thể tại đây khi thông điệp mặc
    | định ở trên chưa đủ rõ cho trường đó.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'thông điệp tuỳ chỉnh',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tên trường dùng trong thông điệp xác thực
    |--------------------------------------------------------------------------
    |
    | Thay thế cho :attribute ở trên bằng một tên tiếng Việt dễ hiểu, để "Vui lòng nhập email"
    | thay vì "Vui lòng nhập email_address". Phần lớn form của Filament đã tự truyền nhãn riêng
    | (label()) nên bảng này chủ yếu là lưới an toàn cho những chỗ chưa có nhãn — nhưng vẫn phải
    | phủ đủ tên trường thật của sản phẩm (SPEC §11, xem app/Filament và database/migrations).
    |
    */

    'attributes' => [
        // Dùng chung nhiều bảng — clients, client_users, users, matter_types, matter parties.
        'code' => 'mã',
        'type' => 'loại',
        'name' => 'tên',
        'id_number' => 'số căn cước / mã số thuế',
        'phone' => 'số điện thoại',
        'email' => 'email',
        'address' => 'địa chỉ',
        'representative_name' => 'người đại diện',
        'note' => 'ghi chú',
        'description' => 'mô tả',
        'sort_order' => 'thứ tự',
        'is_active' => 'trạng thái hoạt động',
        'password' => 'mật khẩu',
        'position' => 'chức danh',
        'bar_number' => 'số thẻ luật sư',

        // client_users — tài khoản đăng nhập portal của khách.
        'client_id' => 'khách hàng',
        'must_change_password' => 'bắt buộc đổi mật khẩu lần đầu',
        'activated_at' => 'ngày kích hoạt',

        // matter_types và giai đoạn (matter_type_stages / StagesRelationManager).
        'key' => 'định danh',
        'label' => 'nhãn nội bộ',
        'client_label' => 'nhãn cho khách',
        'client_description' => 'giải thích cho khách',
        'is_terminal' => 'giai đoạn kết thúc',
        'allowed_next' => 'được chuyển tới',
        'default_next_update_days' => 'số ngày dự kiến cập nhật tiếp theo',

        // matters — form mở vụ việc (MatterForm) và trang tổng quan.
        'client_role' => 'vai của khách hàng',
        'matter_type_id' => 'loại vụ việc',
        'lead_lawyer_id' => 'luật sư phụ trách',
        'title' => 'tiêu đề',
        'description_internal' => 'ghi chú nội bộ',
        'summary_for_client' => 'tóm tắt cho khách',
        'opened_at' => 'ngày mở',
        'closed_at' => 'ngày đóng',
        'confidentiality' => 'độ mật',
        'court_name' => 'toà án',
        'case_number' => 'số hồ sơ vụ án',
        'is_published_to_portal' => 'công bố portal',

        // Các bên khác trong vụ việc (Repeater other_parties, và PartiesRelationManager).
        'other_parties' => 'các bên khác',
        'other_parties.*.role' => 'vai trò',
        'other_parties.*.name' => 'tên',
        'other_parties.*.id_number' => 'số căn cước / mã số thuế',
        'other_parties.*.phone' => 'số điện thoại',
        'other_parties.*.is_our_client' => 'là khách hàng của văn phòng',
        'other_parties.*.client_id' => 'khách hàng',
        'other_parties.*.address' => 'địa chỉ',
        'other_parties.*.note' => 'ghi chú',
        'role' => 'vai trò',
        'is_our_client' => 'là khách hàng của văn phòng',

        // Kiểm tra xung đột lợi ích khi mở vụ việc/thêm bên.
        'acknowledge_conflict' => 'xác nhận đã xem xét kết quả kiểm tra xung đột lợi ích',
        'override_reason' => 'lý do ghi đè',

        // Chuyển giai đoạn / thêm cập nhật (TransitionStageAction, AddUpdateAction).
        'to_stage' => 'giai đoạn mới',
        'occurred_at' => 'ngày xảy ra',
        'internal_note' => 'ghi chú nội bộ',
        'public_content' => 'nội dung công bố cho khách',
        'next_step' => 'tiếp theo sẽ là gì',
        'client_action' => 'anh/chị cần làm gì',
        'expected_next_update_at' => 'dự kiến có tin tiếp theo trước ngày',
        'publish' => 'công bố cho khách ngay',
    ],

];
