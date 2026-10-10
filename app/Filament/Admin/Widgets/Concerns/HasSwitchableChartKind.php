<?php

namespace App\Filament\Admin\Widgets\Concerns;

use App\Actions\Preference\RememberChartKind;
use App\Enums\ChartKind;
use App\Models\ChartPreference;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Cho một `ChartWidget` để người xem CHỌN dạng biểu đồ — cột, đường, tròn — và nhớ lựa chọn theo từng
 * người (yêu cầu của chủ văn phòng, 2026-10-10). Widget chỉ khai `chartKinds()`: các dạng nó mời, dạng
 * ĐẦU là dạng gốc — dạng mà `getData()` và `getOptions()` của nó được viết cho. Trait lo phần còn lại.
 *
 * View phải là `filament.admin.widgets.revenue.chart-with-table`: nó vẽ ô chọn
 * (`wire:model.live="chartKind"`), đọc tuỳ chọn qua {@see self::chartOptionsForKind()}, và đặt
 * `wire:key` mang kiểu biểu đồ lên khung `wire:ignore`. Chi tiết cuối là điều làm tính năng này chạy:
 * khung là `wire:ignore` (Chart.js vẽ lên canvas), nên Livewire không cập nhật nó; khoá đổi thì Livewire
 * bỏ hẳn phần tử cũ và dựng phần tử mới, Alpine khởi tạo lại biểu đồ với kiểu, dữ liệu và tuỳ chọn mới.
 *
 * `chartKind` là thuộc tính công khai của Livewire, tức là trình duyệt gửi được bất kỳ chuỗi nào. Mọi
 * chỗ đọc đều đi qua {@see self::activeChartKind()}, nơi một giá trị ngoài `chartKinds()` rơi về dạng
 * gốc — "tròn" cho một chuỗi tỷ lệ phần trăm là một biểu đồ nói sai, không chỉ là xấu.
 *
 * Phép chuyển giữa các dạng giả định MỘT chuỗi số (`datasets[0]`), đúng với mọi biểu đồ của dự án;
 * chuỗi thứ hai trở đi được để nguyên.
 */
trait HasSwitchableChartKind
{
    /**
     * Bảng màu cho các lát của biểu đồ tròn khi chuỗi gốc chỉ có một màu. Mã hex cố định như mọi màu
     * chuỗi khác của dự án (Chart.js vẽ lên canvas, biến CSS không tới được); mười màu phân biệt được
     * trên cả nền sáng lẫn nền tối, bốn màu đầu trùng các màu chuỗi đang dùng.
     *
     * @var list<string>
     */
    private const PIE_PALETTE = [
        '#4a73bd', '#0ca30c', '#d97706', '#d03b3b', '#7c3aed',
        '#0891b2', '#64748b', '#be185d', '#65a30d', '#a16207',
    ];

    /** Màu nét trung tính cho biểu đồ đường khi chuỗi gốc mang mỗi mục một màu. */
    private const NEUTRAL_LINE_COLOUR = '#64748b';

    /** Giá trị của ô chọn; `null` cho tới khi mount đọc lựa chọn đã lưu. Luôn đọc qua `activeChartKind()`. */
    public ?string $chartKind = null;

    /**
     * Các dạng widget này mời, dạng đầu là dạng gốc.
     *
     * @return non-empty-list<ChartKind>
     */
    abstract protected function chartKinds(): array;

    /** Kiểu Chart.js cho dạng "tròn". Widget công nợ ghi đè thành `doughnut`. */
    protected function pieChartType(): string
    {
        return 'pie';
    }

    /** Hook mount của trait (Livewire gọi `mount{TênTrait}`): vào thẳng dạng người này đã chọn lần trước. */
    public function mountHasSwitchableChartKind(): void
    {
        $user = Auth::user();
        $stored = $user instanceof User ? ChartPreference::kindFor($user, $this->chartPreferenceKey()) : null;

        $this->chartKind = ($stored !== null && in_array($stored, $this->chartKinds(), true))
            ? $stored->value
            : $this->chartKinds()[0]->value;
    }

    /**
     * Người xem vừa chọn một dạng. Chỉ ghi lại khi đó ĐÚNG là một dạng của widget này; một giá trị lạ
     * được đưa về dạng gốc ngay trên thuộc tính (để ô chọn và biểu đồ không lệch nhau) và không tới CSDL.
     */
    public function updatedChartKind(): void
    {
        $requested = is_string($this->chartKind) ? ChartKind::tryFrom($this->chartKind) : null;

        if ($requested === null || ! in_array($requested, $this->chartKinds(), true)) {
            $this->chartKind = $this->chartKinds()[0]->value;

            return;
        }

        $user = Auth::user();

        if ($user instanceof User) {
            app(RememberChartKind::class)->handle($user, $this->chartPreferenceKey(), $requested);
        }
    }

    /** Dạng đang hiệu lực: giá trị của ô chọn nếu widget có mời dạng đó, không thì dạng gốc. */
    public function activeChartKind(): ChartKind
    {
        $kind = is_string($this->chartKind) ? ChartKind::tryFrom($this->chartKind) : null;

        return ($kind !== null && in_array($kind, $this->chartKinds(), true))
            ? $kind
            : $this->chartKinds()[0];
    }

    /**
     * Các lựa chọn của ô chọn, theo thứ tự `chartKinds()`. View chỉ vẽ ô chọn khi có từ hai dạng.
     *
     * @return array<string, string>
     */
    public function chartKindOptions(): array
    {
        $options = [];

        foreach ($this->chartKinds() as $kind) {
            $options[$kind->value] = $kind->label();
        }

        return $options;
    }

    protected function getType(): string
    {
        return $this->chartTypeFor($this->activeChartKind());
    }

    /** Dữ liệu của `ChartWidget`, đã chuyển sang dạng đang chọn. Cũng là thứ `updateChartData()` gửi đi. */
    protected function getCachedData(): array
    {
        $data = parent::getCachedData();
        $kind = $this->activeChartKind();

        if ($kind === $this->chartKinds()[0] || ! isset($data['datasets'][0]) || ! is_array($data['datasets'][0])) {
            return $data;
        }

        $dataset = $data['datasets'][0];
        $colour = $dataset['backgroundColor'] ?? null;

        if ($kind === ChartKind::Pie && ! is_array($colour)) {
            // Một màu cho cả chuỗi là đúng với cột; với tròn thì mọi lát giống hệt nhau.
            $dataset['backgroundColor'] = $this->pieColours(count($data['labels'] ?? $dataset['data'] ?? []));
        }

        if ($kind === ChartKind::Line) {
            if (is_array($colour)) {
                // Một nét không mang được nhiều màu: nét trung tính, từng điểm giữ màu của mục mình.
                $dataset['borderColor'] = self::NEUTRAL_LINE_COLOUR;
                $dataset['pointBackgroundColor'] = $colour;
            } elseif (is_string($colour) && ! isset($dataset['borderColor'])) {
                $dataset['borderColor'] = $colour;
            }
        }

        $data['datasets'][0] = $dataset;

        return $data;
    }

    /**
     * `getOptions()` của widget, đã chuyển sang dạng đang chọn. View gọi phương thức này thay cho
     * `getOptions()` — một trait không ghi đè được phương thức do chính lớp dùng nó khai báo.
     *
     * @return array<string, mixed>
     */
    public function chartOptionsForKind(): array
    {
        $options = $this->getOptions();
        $options = is_array($options) ? $options : [];

        $native = $this->chartKinds()[0];
        $kind = $this->activeChartKind();

        if ($kind === $native) {
            return $options;
        }

        if ($kind === ChartKind::Pie) {
            // Tròn không có trục; và phải có chú giải, nếu không chẳng ai biết lát nào là gì.
            unset($options['indexAxis'], $options['scales']);
            $options['plugins']['legend'] = ['display' => true, 'position' => 'bottom'];

            return $options;
        }

        if ($native === ChartKind::Pie) {
            // Từ tròn sang cột/đường: một chuỗi duy nhất thì chú giải thừa, và trục giá trị phải bắt đầu từ 0
            // (một cột không bắt đầu từ 0 phóng đại chênh lệch).
            $options['plugins']['legend'] = [...($options['plugins']['legend'] ?? []), 'display' => false];
            $options['scales'] = ['y' => ['beginAtZero' => true]];

            return $options;
        }

        if ($kind === ChartKind::Line && ($options['indexAxis'] ?? 'x') === 'y') {
            // Cột NGANG sang đường: đường đọc từ trái sang phải, nên bỏ đảo trục và đưa cấu hình của trục
            // giá trị (x của cột ngang) sang y, cấu hình của trục mục (y) sang x.
            unset($options['indexAxis']);

            $scales = is_array($options['scales'] ?? null) ? $options['scales'] : [];
            $swapped = [];

            if (isset($scales['y'])) {
                $swapped['x'] = $scales['y'];
            }

            if (isset($scales['x'])) {
                $swapped['y'] = $scales['x'];
            }

            if ($swapped !== []) {
                $options['scales'] = $swapped;
            } else {
                unset($options['scales']);
            }
        }

        return $options;
    }

    /** Phần đuôi của `wire:key` trên khung biểu đồ — đổi theo kiểu để Livewire dựng lại khung. */
    public function chartFrameKey(): string
    {
        return $this->getId().'.chart.'.$this->getType();
    }

    /** Khoá của widget trong `chart_preferences`: tên lớp không kèm namespace. */
    protected function chartPreferenceKey(): string
    {
        return class_basename(static::class);
    }

    private function chartTypeFor(ChartKind $kind): string
    {
        return match ($kind) {
            ChartKind::Bar => 'bar',
            ChartKind::Line => 'line',
            ChartKind::Pie => $this->pieChartType(),
        };
    }

    /**
     * @return list<string>
     */
    private function pieColours(int $count): array
    {
        $colours = [];

        for ($i = 0; $i < $count; $i++) {
            $colours[] = self::PIE_PALETTE[$i % count(self::PIE_PALETTE)];
        }

        return $colours;
    }
}
