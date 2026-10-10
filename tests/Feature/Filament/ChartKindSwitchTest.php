<?php

use App\Actions\Preference\RememberChartKind;
use App\Enums\ChartKind;
use App\Enums\Role;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\IntakeReport\IntakeConversionWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakeOutcomesWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakeResponseTimeWidget;
use App\Filament\Admin\Widgets\IntakeReport\IntakesBySourceWidget;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\Performance\OverdueTrendWidget;
use App\Filament\Admin\Widgets\Performance\StaleTrendWidget;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\ChartPreference;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
| Yêu cầu của chủ văn phòng (2026-10-10): người xem bảng điều khiển CHỌN ĐƯỢC dạng biểu đồ — cột,
| đường, tròn — trên từng biểu đồ, và hệ thống nhớ lựa chọn của từng người.
|
| Ba điều test này giữ, mỗi điều đã có một cách hỏng cụ thể:
|  1. Khung biểu đồ là `wire:ignore` (Chart.js vẽ lên canvas, Livewire không được đụng), nên đổi `type`
|     mà không đổi `wire:key` thì trình duyệt GIỮ biểu đồ cũ. Khoá của khung phải mang dạng đang chọn:
|     khoá đổi thì Livewire bỏ phần tử cũ, dựng phần tử mới, Alpine vẽ lại từ đầu.
|  2. `chartKind` là thuộc tính công khai của component Livewire: trình duyệt gửi được bất kỳ chuỗi nào.
|     Một dạng ngoài danh sách của widget (hoặc không phải dạng nào) phải rơi về mặc định và KHÔNG được
|     ghi vào CSDL — "tròn" cho một chuỗi tỷ lệ phần trăm là một biểu đồ nói sai.
|  3. Dữ liệu và tuỳ chọn viết cho cột ngang không dùng nguyên cho đường hay tròn (trục đảo, một màu cho
|     mọi lát). Phép chuyển nằm ở MỘT chỗ (trait), đo trên một widget giả có dữ liệu cố định.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($this->admin, 'web');
});

/** Tham số mount: hai widget xu hướng cần biết đang xem số của ai. */
function chartMountParameters(string $widget, User $subject): array
{
    return in_array($widget, [OverdueTrendWidget::class, StaleTrendWidget::class], true)
        ? ['subjectId' => $subject->id]
        : [];
}

/** Khoá của widget trong bảng `chart_preferences`. */
function chartPreferenceKey(string $widget): string
{
    return class_basename($widget);
}

dataset('biểu đồ và các dạng được phép', [
    'vụ việc theo giai đoạn' => [MattersByStageWidget::class, ['bar', 'line', 'pie'], 'bar'],
    'tải theo luật sư' => [LoadPerLawyerWidget::class, ['bar', 'line', 'pie'], 'bar'],
    'cơ cấu lĩnh vực' => [MatterMixByPracticeAreaWidget::class, ['bar', 'line', 'pie'], 'bar'],
    'đã thu, còn phải thu, quá hạn' => [ReceivablesDonutWidget::class, ['pie', 'bar', 'line'], 'doughnut'],
    'doanh thu theo đợt, giai đoạn' => [RevenueByStageWidget::class, ['bar', 'line', 'pie'], 'bar'],
    // Chuỗi theo thời gian và chuỗi tỷ lệ/trung vị: KHÔNG có "tròn" — các giá trị không phải phần của một tổng.
    'doanh thu theo thời gian' => [RevenueOverTimeWidget::class, ['bar', 'line'], 'bar'],
    'tỷ lệ chuyển đổi tiếp nhận' => [IntakeConversionWidget::class, ['bar', 'line'], 'bar'],
    'kết quả tiếp nhận' => [IntakeOutcomesWidget::class, ['bar', 'line', 'pie'], 'bar'],
    'thời gian phản hồi tiếp nhận' => [IntakeResponseTimeWidget::class, ['bar', 'line'], 'bar'],
    'tiếp nhận theo nguồn' => [IntakesBySourceWidget::class, ['bar', 'line', 'pie'], 'bar'],
    'xu hướng quá hạn' => [OverdueTrendWidget::class, ['line', 'bar'], 'line'],
    'xu hướng tồn đọng' => [StaleTrendWidget::class, ['line', 'bar'], 'line'],
]);

it('mở ra ở dạng gốc và mời đúng các dạng của mình, theo đúng thứ tự', function (string $widget, array $kinds, string $nativeType) {
    $component = Livewire::test($widget, chartMountParameters($widget, $this->lawyer))->assertOk();

    expect(array_keys($component->instance()->chartKindOptions()))->toBe($kinds)
        ->and($component->instance()->chartKindOptions())->each->toBeString();

    $html = $component->html();

    expect($html)->toContain('wire:model.live="chartKind"')
        ->and($html)->toContain('data-chart-type="'.$nativeType.'"')
        // Khung là wire:ignore VÀ mang khoá có dạng đang chọn — xem điều 1 ở đầu tệp.
        ->and($html)->toMatch('/wire:ignore[^>]*wire:key="[^"]*\.chart\.'.$nativeType.'"|wire:key="[^"]*\.chart\.'.$nativeType.'"[^>]*wire:ignore/s');

    expect(ChartPreference::query()->count())->toBe(0);
})->with('biểu đồ và các dạng được phép');

it('đổi sang từng dạng khác: kiểu và khoá của khung đổi theo, lựa chọn được ghi cho đúng người', function (string $widget, array $kinds, string $nativeType) {
    foreach (array_slice($kinds, 1) as $kind) {
        $component = Livewire::test($widget, chartMountParameters($widget, $this->lawyer))
            ->set('chartKind', $kind)
            ->assertOk();

        // "Tròn" của widget công nợ giữ kiểu doughnut vốn có; ở mọi widget khác là pie.
        $type = $kind === 'pie' && $nativeType === 'doughnut' ? 'doughnut' : $kind;

        expect($component->html())->toContain('data-chart-type="'.$type.'"')
            ->and($component->html())->toMatch('/wire:key="[^"]*\.chart\.'.$type.'"/')
            ->and($component->instance()->activeChartKind()->value)->toBe($kind);

        expect(ChartPreference::query()->where('user_id', $this->admin->id)->where('widget', chartPreferenceKey($widget))->sole()->chart_kind)
            ->toBe(ChartKind::from($kind));
    }

    expect(ChartPreference::query()->count())->toBe(1);
})->with('biểu đồ và các dạng được phép');

it('một dạng ngoài danh sách, hay một chuỗi bịa, rơi về mặc định và không được ghi lại', function (string $widget, array $kinds, string $nativeType) {
    $outside = array_values(array_diff(['bar', 'line', 'pie'], $kinds));

    foreach ([...$outside, 'radar', '', 'BAR', '<script>'] as $forged) {
        $component = Livewire::test($widget, chartMountParameters($widget, $this->lawyer))
            ->set('chartKind', $forged)
            ->assertOk();

        expect($component->instance()->activeChartKind()->value)->toBe($kinds[0])
            ->and($component->html())->toContain('data-chart-type="'.$nativeType.'"');
    }

    expect(ChartPreference::query()->count())->toBe(0);
})->with('biểu đồ và các dạng được phép');

// Hai hook (mount, updated) đều tự đưa giá trị lạ về dạng gốc, nên ba test trên vẫn xanh khi gỡ phép đối chiếu
// ở CHỖ ĐỌC (phép thử ngược P2, 2026-10-10). Test này đo riêng lớp đó: một giá trị lọt vào thuộc tính mà không
// qua hook nào — một đường ghi mới sau này quên đối chiếu — vẫn không vẽ ra được dạng widget không mời.
it('chỗ đọc tự đối chiếu lại, không tin thuộc tính', function () {
    $instance = Livewire::test(RevenueOverTimeWidget::class)->instance();

    $instance->chartKind = 'pie';

    expect($instance->activeChartKind())->toBe(ChartKind::Bar)
        ->and((fn (): string => $this->getType())->call($instance))->toBe('bar')
        ->and($instance->chartFrameKey())->toEndWith('.chart.bar');
});

it('nhớ lựa chọn của từng người: lần mở sau của chính người đó, không phải của người khác', function () {
    Livewire::test(MattersByStageWidget::class)->set('chartKind', 'pie');

    // Cùng người, lần mở sau (component mới): vào thẳng dạng đã chọn.
    $again = Livewire::test(MattersByStageWidget::class);

    expect($again->instance()->activeChartKind())->toBe(ChartKind::Pie)
        ->and($again->get('chartKind'))->toBe('pie')
        ->and($again->html())->toContain('data-chart-type="pie"');

    // Biểu đồ KHÁC của cùng người: không bị kéo theo.
    expect(Livewire::test(IntakesBySourceWidget::class)->instance()->activeChartKind())->toBe(ChartKind::Bar);

    // Người khác: mặc định.
    $this->actingAs($this->manager, 'web');

    expect(Livewire::test(MattersByStageWidget::class)->instance()->activeChartKind())->toBe(ChartKind::Bar);
});

it('một lựa chọn đã lưu mà widget không còn mời nữa thì bị bỏ qua, không làm hỏng biểu đồ', function () {
    // Dòng cũ từ một bản trước (hoặc sửa tay trong CSDL): "tròn" cho chuỗi theo thời gian.
    ChartPreference::query()->forceCreate([
        'user_id' => $this->admin->id,
        'widget' => chartPreferenceKey(RevenueOverTimeWidget::class),
        'chart_kind' => 'pie',
    ]);

    $component = Livewire::test(RevenueOverTimeWidget::class)->assertOk();

    expect($component->instance()->activeChartKind())->toBe(ChartKind::Bar)
        // Ô chọn cũng phải đứng ở dạng gốc, không mang một giá trị mà nó không có lựa chọn nào khớp.
        ->and($component->get('chartKind'))->toBe('bar')
        ->and($component->html())->toContain('data-chart-type="bar"');
});

it('ghi lựa chọn: một dòng cho mỗi người và mỗi biểu đồ, lần sau ghi đè lần trước', function () {
    $remember = app(RememberChartKind::class);

    $remember->handle($this->admin, 'MattersByStageWidget', ChartKind::Line);
    $remember->handle($this->admin, 'MattersByStageWidget', ChartKind::Pie);
    $remember->handle($this->admin, 'IntakesBySourceWidget', ChartKind::Line);
    $remember->handle($this->manager, 'MattersByStageWidget', ChartKind::Line);

    expect(ChartPreference::query()->count())->toBe(3)
        ->and(ChartPreference::kindFor($this->admin, 'MattersByStageWidget'))->toBe(ChartKind::Pie)
        ->and(ChartPreference::kindFor($this->admin, 'IntakesBySourceWidget'))->toBe(ChartKind::Line)
        ->and(ChartPreference::kindFor($this->manager, 'MattersByStageWidget'))->toBe(ChartKind::Line)
        ->and(ChartPreference::kindFor($this->lawyer, 'MattersByStageWidget'))->toBeNull();
});

it('lựa chọn là của riêng từng nhân sự: người khác và tài khoản cổng khách không đọc được', function () {
    app(RememberChartKind::class)->handle($this->lawyer, 'MattersByStageWidget', ChartKind::Pie);

    $preference = ChartPreference::query()->sole();
    $client = ClientUser::factory()->create();

    expect(Gate::forUser($this->lawyer)->allows('view', $preference))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('view', $preference))->toBeFalse()
        ->and(Gate::forUser($client)->allows('view', $preference))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('viewAny', ChartPreference::class))->toBeFalse()
        ->and(Gate::forUser($client)->allows('viewAny', ChartPreference::class))->toBeFalse();
});

it('gọi tên ba dạng bằng tiếng Việt', function () {
    expect(ChartKind::Bar->label())->toBe('Cột')
        ->and(ChartKind::Line->label())->toBe('Đường')
        ->and(ChartKind::Pie->label())->toBe('Tròn')
        ->and(__('widgets.chart_kind.label'))->toBe('Dạng biểu đồ');
});

// ---------------------------------------------------------------------------------------------
// Phép chuyển dữ liệu và tuỳ chọn giữa các dạng — đo trên một widget giả có dữ liệu cố định
// ---------------------------------------------------------------------------------------------

/** Cột NGANG một chuỗi, một màu: hình dạng của phần lớn widget thật. */
class HorizontalBarProbeChart extends ChartWidget
{
    use HasSwitchableChartKind;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    protected function chartKinds(): array
    {
        return [ChartKind::Bar, ChartKind::Line, ChartKind::Pie];
    }

    protected function getData(): array
    {
        return [
            'labels' => ['Sơ thẩm', 'Phúc thẩm', 'Thi hành án', 'Kết thúc'],
            'datasets' => [['label' => 'Số vụ', 'data' => [5, 3, 2, 7], 'backgroundColor' => '#4a73bd']],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}

/** Vòng tròn nhiều màu có sẵn: hình dạng của widget công nợ. */
class DoughnutProbeChart extends ChartWidget
{
    use HasSwitchableChartKind;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    protected function chartKinds(): array
    {
        return [ChartKind::Pie, ChartKind::Bar, ChartKind::Line];
    }

    protected function pieChartType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        return [
            'labels' => ['Đã thu', 'Chưa đến hạn', 'Quá hạn'],
            'datasets' => [['data' => [70, 20, 10], 'backgroundColor' => ['#0ca30c', '#4a73bd', '#d03b3b']]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => true, 'position' => 'bottom']]];
    }
}

/** @return array{data: array<string, mixed>, options: array<string, mixed>} */
function chartPayloadFor(string $widget, ?string $kind): array
{
    $component = Livewire::test($widget);

    if ($kind !== null) {
        $component->set('chartKind', $kind);
    }

    $instance = $component->instance();

    return [
        'data' => (fn (): array => $this->getCachedData())->call($instance),
        'options' => $instance->chartOptionsForKind(),
    ];
}

it('dạng gốc: dữ liệu và tuỳ chọn đi qua nguyên vẹn', function () {
    $payload = chartPayloadFor(HorizontalBarProbeChart::class, null);

    expect($payload['data']['datasets'][0])->toBe(['label' => 'Số vụ', 'data' => [5, 3, 2, 7], 'backgroundColor' => '#4a73bd'])
        ->and($payload['options'])->toBe([
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ]);
});

it('cột ngang sang đường: trục trở về chiều thường, cấu hình trục giá trị đi theo, nét mang màu của chuỗi', function () {
    $payload = chartPayloadFor(HorizontalBarProbeChart::class, 'line');

    expect($payload['options'])->not->toHaveKey('indexAxis')
        // Trục GIÁ TRỊ của cột ngang là x; của đường là y. Cấu hình (bắt đầu từ 0, số nguyên) phải đi theo trục giá trị.
        ->and($payload['options']['scales'])->toBe(['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]])
        ->and($payload['options']['plugins']['legend'])->toBe(['display' => false])
        ->and($payload['data']['datasets'][0]['borderColor'])->toBe('#4a73bd')
        ->and($payload['data']['datasets'][0]['backgroundColor'])->toBe('#4a73bd')
        ->and($payload['data']['datasets'][0]['data'])->toBe([5, 3, 2, 7])
        ->and($payload['data']['labels'])->toBe(['Sơ thẩm', 'Phúc thẩm', 'Thi hành án', 'Kết thúc']);
});

it('cột sang tròn: mỗi lát một màu, không còn trục, chú giải hiện để đọc được lát nào là gì', function () {
    $payload = chartPayloadFor(HorizontalBarProbeChart::class, 'pie');
    $colours = $payload['data']['datasets'][0]['backgroundColor'];

    expect($colours)->toBeArray()->toHaveCount(4)
        ->and(array_unique($colours))->toHaveCount(4)
        ->and($colours)->each->toMatch('/^#[0-9a-f]{6}$/')
        ->and($payload['options'])->not->toHaveKey('indexAxis')
        ->and($payload['options'])->not->toHaveKey('scales')
        ->and($payload['options']['plugins']['legend'])->toBe(['display' => true, 'position' => 'bottom'])
        ->and($payload['data']['datasets'][0]['data'])->toBe([5, 3, 2, 7]);
});

/** Nhiều mục hơn số màu của bảng màu. */
class ManySlicesProbeChart extends HorizontalBarProbeChart
{
    protected function getData(): array
    {
        return [
            'labels' => array_map(fn (int $i): string => "Mục {$i}", range(1, 23)),
            'datasets' => [['data' => range(1, 23), 'backgroundColor' => '#4a73bd']],
        ];
    }
}

/** Widget chỉ mời một dạng. */
class SingleKindProbeChart extends HorizontalBarProbeChart
{
    protected function chartKinds(): array
    {
        return [ChartKind::Bar];
    }
}

it('tròn nhiều hơn số màu của bảng màu thì quay vòng, không thiếu màu cho lát nào', function () {
    $colours = chartPayloadFor(ManySlicesProbeChart::class, 'pie')['data']['datasets'][0]['backgroundColor'];

    expect($colours)->toHaveCount(23)->each->toMatch('/^#[0-9a-f]{6}$/');
});

it('vòng tròn nhiều màu sang cột và sang đường: giữ màu từng mục, tắt chú giải, trục giá trị bắt đầu từ 0', function () {
    // Dạng gốc TRƯỚC, khi người này chưa chọn gì: doughnut, tuỳ chọn nguyên vẹn. (Sau hai lượt đổi bên dưới,
    // một lần mở mới sẽ vào dạng đã nhớ chứ không phải dạng gốc — test "nhớ lựa chọn" đo điều đó.)
    $native = chartPayloadFor(DoughnutProbeChart::class, null);

    expect($native['options'])->toBe(['plugins' => ['legend' => ['display' => true, 'position' => 'bottom']]])
        ->and($native['data']['datasets'][0]['backgroundColor'])->toBe(['#0ca30c', '#4a73bd', '#d03b3b'])
        ->and(Livewire::test(DoughnutProbeChart::class)->html())->toContain('data-chart-type="doughnut"');

    $bar = chartPayloadFor(DoughnutProbeChart::class, 'bar');

    expect($bar['data']['datasets'][0]['backgroundColor'])->toBe(['#0ca30c', '#4a73bd', '#d03b3b'])
        ->and($bar['options']['plugins']['legend'])->toBe(['display' => false, 'position' => 'bottom'])
        ->and($bar['options']['scales'])->toBe(['y' => ['beginAtZero' => true]]);

    $line = chartPayloadFor(DoughnutProbeChart::class, 'line');

    // Một nét không thể mang ba màu: nét trung tính, điểm giữ màu của từng mục.
    expect($line['data']['datasets'][0]['borderColor'])->toBeString()->toMatch('/^#[0-9a-f]{6}$/')
        ->and($line['data']['datasets'][0]['pointBackgroundColor'])->toBe(['#0ca30c', '#4a73bd', '#d03b3b'])
        ->and($line['options']['scales'])->toBe(['y' => ['beginAtZero' => true]]);
});

it('widget chỉ có một dạng thì không hiện ô chọn', function () {
    expect(Livewire::test(SingleKindProbeChart::class)->assertOk()->html())
        ->not->toContain('wire:model.live="chartKind"')
        ->toContain('data-chart-type="bar"');
});
