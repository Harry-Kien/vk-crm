<?php

namespace App\Support\Settings;

use Filament\Support\Icons\Heroicon;

/**
 * Một thẻ của trang tổng "Cài đặt" (làn st): biểu tượng, tên, một dòng mô tả, trạng thái THẬT đang chạy
 * (hoặc `null` khi thẻ không có trạng thái để nói), sắc thái của trạng thái, và đường dẫn tới trang của nó.
 * `url = null` nghĩa là NGƯỜI XEM không mở được trang đích (`canAccess()` sai): {@see SettingsCatalog::groups()}
 * bỏ thẻ đó, nên trang tổng không bao giờ vẽ một thẻ không bấm được. Giá trị chỉ đặt trên máy chủ (giờ làm
 * việc, ngưỡng theo luật…) không có thẻ "không bấm được": thẻ của chúng dẫn tới mục đọc-chỉ của "Cài đặt
 * nghiệp vụ" (gộp integ-4). Dựng bởi {@see SettingsCatalog}.
 */
final readonly class SettingsCard
{
    /**
     * @param  'success'|'warning'|'danger'|'gray'  $tone
     */
    public function __construct(
        public string $key,
        public Heroicon $icon,
        public string $title,
        public string $description,
        public ?string $status,
        public string $tone,
        public ?string $url,
    ) {}

    /** Thẻ có khớp ô tìm không: tên, mô tả hay trạng thái chứa `$term`, bỏ dấu tiếng Việt và hoa thường. */
    public function matches(string $term): bool
    {
        $needle = SettingsCatalog::fold($term);

        return $needle === '' || str_contains(SettingsCatalog::fold($this->title.' '.$this->description.' '.$this->status), $needle);
    }
}
