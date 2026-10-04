<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Performance\TeamRoster;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * "Trang của một người" (`/team/{user}`, M13 R1) — chỗ đi sâu từ "Theo dõi đội ngũ" và "Hiệu suất
 * theo kỳ". Với người không có `performance.viewAny` (luật sư, trợ lý) đây là mục "Việc của tôi",
 * trỏ tới trang của chính họ. Task 1 dựng KHUNG (cổng, điều hướng, câu phạm vi R4); nội dung, nhật
 * ký `performance_viewed` khi xem người khác do Task 5 lấp, xu hướng do Task 7.
 *
 * # Hai cổng, cả hai hỏi ở mọi request, từ chối là MỘT 404
 *
 * 1. Cổng trang, {@see self::canAccess()}: `matter.view` HOẶC `performance.viewAny`. Kế toán không
 *    có cả hai: 404, kể cả trang "của mình".
 * 2. Cổng người, {@see self::authorizedSubject()}: người dùng CHƯA xoá mềm mang đúng id đó (đang
 *    hoạt động hay đã nghỉ việc đều được), rồi `Gate::forUser($viewer)->allows('viewPerformance',
 *    $subject)` ({@see UserPolicy::viewPerformance()}: `performance.viewAny`, hoặc chính mình
 *    với `matter.view`; và người đó thuộc danh sách {@see TeamRoster}). Id không phải số nguyên
 *    dương, id không tồn tại, người đã xoá mềm, admin, kế toán và đồng nghiệp của một luật sư đều đi
 *    cùng MỘT `abort(404)` — cùng một response từng byte (SPEC §10.10). Chuỗi kiểu `5abc` bị chặn
 *    trước truy vấn: MariaDB ép nó thành số 5 khi so với cột số.
 *
 * Cổng người MẠNH HƠN cổng trang: `viewPerformance` đòi `performance.viewAny` hoặc `matter.view`, tức
 * đã kéo theo `canAccess()`. Vì vậy:
 *  - {@see self::mount()} hỏi cổng người; ngay sau đó `mountCanAuthorizeAccess()` của Filament hỏi
 *    `canAccess()` (403, đổi thành 404 bởi middleware của panel trên lần tải trang);
 *  - {@see self::boot()} hỏi lại cổng người ở MỌI request cập nhật Livewire, TRƯỚC
 *    `hydrateCanAuthorizeAccess()` của Filament (403 bên trong vòng đời component không qua được
 *    middleware 404) — đọc lại người dùng từ CSDL mỗi lần, không tin request trước: người xem mất
 *    quyền, người được xem rời danh sách hay bị xoá mềm khi trang còn mở thì request kế tiếp là 404.
 *    `boot()` không hỏi thêm `canAccess()`: câu đó bị cổng người bao trọn, nên một lần hỏi như vậy
 *    không mutation probe nào chứng minh được (khác `TeamOverview`/`Performance`, không có chủ thể).
 *
 * # Id người là `#[Locked]`, và là thuộc tính công khai DUY NHẤT
 *
 * {@see self::$subjectId} được đặt đúng một lần ở `mount()`, từ URL; trình duyệt không đặt lại được
 * (Livewire ném `CannotUpdateLockedPropertyException`). Khoá không thay cho việc gác: `boot()` vẫn
 * hỏi lại Gate trên id đó ở mỗi request. Không thuộc tính công khai nào khác mang id người hay id vụ.
 */
class TeamMember extends Page
{
    protected string $view = 'filament.admin.pages.team-member';

    protected static ?string $slug = 'team-member';

    /** "Việc của tôi". Không trùng icon nào đã dùng trong `app/` (`OutlinedUserCircle` là icon của hành động "Đổi người phụ trách" trên tab Mốc thời hạn). */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    #[Locked]
    public int $subjectId;

    /** Bộ nhớ đệm TRONG một request (thuộc tính `private` không được Livewire serialize). */
    private ?User $subject = null;

    /**
     * Đường dẫn `/team/{user}`: nối dưới slug của {@see TeamOverview}. Slug riêng (`team-member`) giữ
     * tên route khác (`filament.admin.pages.team-member`), vì `getRelativeRouteName()` dựng tên route
     * từ slug — cùng hình dạng với `MyRequests::getRoutePath()` của cổng khách.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.TeamOverview::getSlug($panel).'/{user}';
    }

    public static function getNavigationLabel(): string
    {
        return __('performance.pages.team_member.navigation_label');
    }

    /** "Việc của tôi" trỏ tới trang của chính người đang đăng nhập (`Page::getNavigationUrl()` mặc định là `getUrl()` không tham số). */
    public static function getNavigationUrl(): string
    {
        return static::getUrl(['user' => Auth::id()]);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $gate = Gate::forUser($user);

        return $gate->allows(Permission::MatterView->value)
            || $gate->allows(Permission::PerformanceViewAny->value);
    }

    /**
     * Mục "Việc của tôi" chỉ cho người KHÔNG có `performance.viewAny` (họ đã có "Theo dõi đội ngũ")
     * và chỉ khi trang của chính họ mở được — không bao giờ một liên kết dẫn tới 404.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $gate = Gate::forUser($user);

        return ! $gate->allows(Permission::PerformanceViewAny->value)
            && $gate->allows('viewPerformance', $user);
    }

    /**
     * Xem docblock lớp, mục "Hai cổng" — 404 ở mọi request cập nhật Livewire. Lần mount: id chưa có
     * (`boot()` chạy trước `mount()`, nơi id được đặt và cổng người được hỏi lần đầu). Request cập
     * nhật: id đã hydrate từ snapshot trước khi `boot()` chạy.
     */
    public function boot(): void
    {
        if (isset($this->subjectId)) {
            $this->subject = $this->authorizedSubject($this->subjectId);
        }
    }

    public function mount(int|string $user): void
    {
        $this->subject = $this->authorizedSubject($user);
        $this->subjectId = $this->subject->getKey();
    }

    public function getTitle(): string
    {
        $subject = $this->subject();

        return $subject->is(Auth::user())
            ? __('performance.pages.team_member.title_self')
            : __('performance.pages.team_member.title', ['name' => $subject->name]);
    }

    protected function subject(): User
    {
        return $this->subject ??= $this->authorizedSubject($this->subjectId);
    }

    /** Xem docblock lớp, mục "Hai cổng" — mọi lời từ chối là cùng một `abort(404)`. */
    private function authorizedSubject(int|string $id): User
    {
        $viewer = Auth::user();
        $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subject = $key === false ? null : User::query()->with(['roles.permissions', 'permissions'])->find($key);

        abort_unless(
            $viewer instanceof User
                && $subject instanceof User
                && Gate::forUser($viewer)->allows('viewPerformance', $subject),
            404,
        );

        return $subject;
    }
}
