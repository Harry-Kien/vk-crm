<?php

namespace App\Support\Settings;

use Filament\Support\Icons\Heroicon;

/**
 * Một thẻ của trang tổng "Cài đặt" (làn st): biểu tượng, tên, một dòng mô tả, trạng thái THẬT đang chạy
 * (hoặc `null` khi thẻ không có trạng thái để nói), sắc thái của trạng thái, và đường dẫn tới trang cài
 * đặt của nó (`null` = đặt trên máy chủ, thẻ không bấm được). Dựng bởi {@see SettingsCatalog}.
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
