<?php

namespace App\Filament\Admin\Widgets\IntakeReport;

use App\Enums\IntakeSource;
use App\Filament\Admin\Widgets\IntakeReport\Concerns\ReadsIntakeReport;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Thời gian phản hồi lần đầu, trung vị (M10 Task 6, R5) — cột theo nguồn, đơn vị GIỜ, một chuỗi, một
 * màu `#4a73bd`.
 *
 * **Đo cái gì:** từ `received_at` (lúc nhận liên hệ) tới `first_response_at` (lần đầu bản ghi rời
 * trạng thái "Mới", R5 — Task 5 ghi cột này), trên {@see ReadsIntakeReport::contactsInReport()} (nhận
 * trong kỳ, thấy được, trừ bản trùng đã gộp).
 *
 * **Theo GIỜ ĐỒNG HỒ, không theo giờ làm việc** — có chủ đích, và mô tả widget nói ra: ngưỡng nhắc
 * việc của R5 tính theo giờ làm việc (`config/vkcrm.php`, Task 5), còn đây là thời gian người liên hệ
 * THẬT SỰ chờ, kể cả đêm và cuối tuần. Hai con số trả lời hai câu hỏi khác nhau; một cuộc gọi tối thứ
 * Sáu được gọi lại sáng thứ Hai là gần ba ngày chờ ở đây.
 *
 * **Trung vị, không trung bình** (kế hoạch): một bản ghi bị bỏ quên một tuần không kéo cả con số lên.
 * SQLite và MariaDB không có chung hàm trung vị, nên tính trong PHP trên tập đã lọc: sắp xếp, lấy phần
 * tử giữa; số phần tử chẵn thì trung bình hai phần tử giữa. Hiển thị làm tròn tới phút.
 *
 * **Bản ghi chưa phản hồi** (`first_response_at` null) KHÔNG có thời gian để xếp vào trung vị — bảng số
 * đếm chúng ở một dòng riêng, cạnh số bản ghi đã tính, để một trung vị đẹp không che một chồng cuộc
 * gọi chưa ai gọi lại. Một phản hồi ghi TRƯỚC lúc nhận (giờ nhận gõ tay muộn hơn lúc thật) tính là 0 phút,
 * không âm.
 *
 * Nguồn không có bản ghi đã phản hồi: cột để trống (`null`) và bảng số ghi "—" — không vẽ 0 giờ.
 *
 * Khuôn M9: view dùng chung `chart-with-table`, không tooltip callback, không `RawJs` (phán quyết CSP
 * của M8 R4); `$isDiscovered = false`.
 */
class IntakeResponseTimeWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;
    use ReadsIntakeReport;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var array{sources: array<string, list<int>>, all: list<int>, unanswered: int}|null */
    private ?array $minutesCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('intake_report.response_time.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('intake_report.response_time.description', [
            'range' => $this->reportFilters()->rangeLabel(),
            'median' => $this->formatMedian($this->minutes()['all']),
            'clock_note' => __('intake_report.response_time.clock_note'),
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function numberTableRows(): array
    {
        $minutes = $this->minutes();

        $rows = [
            ['label' => __('intake_report.response_time.overall'), 'value' => $this->formatMedian($minutes['all'])],
            ['label' => __('intake_report.response_time.responded'), 'value' => (string) count($minutes['all'])],
            ['label' => __('intake_report.response_time.unanswered'), 'value' => (string) $minutes['unanswered']],
        ];

        foreach (IntakeSource::cases() as $source) {
            $values = $minutes['sources'][$source->value];
            $rows[] = [
                'label' => $source->label(),
                'value' => $values === []
                    ? '—'
                    : __('intake_report.response_time.source_row', [
                        'duration' => $this->formatMedian($values),
                        'count' => count($values),
                    ]),
            ];
        }

        return $rows;
    }

    protected function getData(): array
    {
        return [
            'labels' => array_map(fn (IntakeSource $source): string => $source->label(), IntakeSource::cases()),
            'datasets' => [
                [
                    'label' => __('intake_report.response_time.series'),
                    'data' => array_values(array_map(
                        fn (array $values): ?float => $values === [] ? null : round(self::median($values) / 60, 1),
                        $this->minutes()['sources'],
                    )),
                    'backgroundColor' => '#4a73bd',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }

    /** Trung vị đã làm tròn tới phút, viết thành chữ; "Chưa có bản ghi đã phản hồi" khi tập rỗng. */
    private function formatMedian(array $values): string
    {
        if ($values === []) {
            return __('intake_report.response_time.none');
        }

        return self::formatDuration((int) round(self::median($values)));
    }

    /**
     * @param  non-empty-list<int>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** "1 ngày 1 giờ", "1 giờ 15 phút", "53 phút", "0 phút" — bỏ phần bằng 0, trừ khi mọi phần bằng 0. */
    private static function formatDuration(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = __('intake_report.response_time.duration.days', ['count' => $days]);
        }

        if ($hours > 0) {
            $parts[] = __('intake_report.response_time.duration.hours', ['count' => $hours]);
        }

        if ($rest > 0 || $parts === []) {
            $parts[] = __('intake_report.response_time.duration.minutes', ['count' => $rest]);
        }

        return implode(' ', $parts);
    }

    /** @return array{sources: array<string, list<int>>, all: list<int>, unanswered: int} */
    private function minutes(): array
    {
        return $this->minutesCache ??= $this->computeMinutes();
    }

    /**
     * Số phút chờ của từng bản ghi đã phản hồi, theo nguồn (đủ sáu nguồn, thứ tự enum) và gộp chung,
     * cộng số bản ghi chưa phản hồi.
     *
     * @return array{sources: array<string, list<int>>, all: list<int>, unanswered: int}
     */
    private function computeMinutes(): array
    {
        $sources = [];
        foreach (IntakeSource::cases() as $source) {
            $sources[$source->value] = [];
        }

        $all = [];

        $rows = $this->contactsInReport()
            ->whereNotNull('first_response_at')
            ->toBase()
            ->get(['source', 'received_at', 'first_response_at']);

        foreach ($rows as $row) {
            $seconds = Carbon::parse($row->first_response_at)->getTimestamp() - Carbon::parse($row->received_at)->getTimestamp();
            $minutes = intdiv(max(0, $seconds), 60);

            $sources[$row->source][] = $minutes;
            $all[] = $minutes;
        }

        return [
            'sources' => $sources,
            'all' => $all,
            'unanswered' => $this->contactsInReport()->whereNull('first_response_at')->count(),
        ];
    }
}
