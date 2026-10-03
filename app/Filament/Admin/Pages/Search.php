<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Search\MatterSearchResults;
use App\Actions\Search\SearchMatters;
use App\Enums\SearchSource;
use App\Models\Matter;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Trang "Tìm kiếm" (SPEC §6.13; M7 Task 9) — một ô tìm vụ việc bằng mã, tiêu đề, tên khách, số thụ
 * lý, tên các bên hay tiêu đề tài liệu. Luật nghiệp vụ (ai tìm theo nguồn nào, luật hiển thị trước
 * giới hạn, nhóm D, kế toán, tiếng Việt) ở {@see SearchMatters}; trang chỉ nhận chuỗi, gọi Action và
 * vẽ kết quả.
 *
 * # Cổng: nhân sự đang hoạt động có `MatterPolicy::viewAny`, hỏi ở MỌI request
 *
 * "Đang hoạt động" là việc của panel: `Filament\Http\Middleware\Authenticate` hỏi `canAccessPanel()`
 * (đọc `is_active`) ở cả lần tải trang lẫn request cập nhật Livewire (Livewire giữ middleware xác
 * thực làm middleware bền), và `AnswerDeniedPanelRequestsWithNotFound` đổi lời từ chối đó thành 404
 * — đo ở `SearchPageTest` bằng request cập nhật thật của một tài khoản vừa bị vô hiệu hoá. Trang
 * không hỏi lại `is_active` (một điều kiện không có đường nào tới được là một điều kiện không probe
 * nào chứng minh được).
 *
 * {@see self::canAccess()} = `Gate::forUser()->allows('viewAny', Matter::class)` (`matter.viewAny`
 * hoặc `matter.view` — cả năm vai trò nội bộ). Hỏi ở:
 *  - {@see self::boot()} — Livewire gọi `boot()` ở đầu lần mount VÀ mọi request cập nhật, TRƯỚC
 *    `hydrateCanAuthorizeAccess()` của Filament (hook đó trả 403, và lời từ chối bên trong vòng đời
 *    component không qua được middleware 404 của panel). Một người mất vai trò khi trang còn mở nhận
 *    404 ở lần gõ kế tiếp, không nhận kết quả;
 *  - {@see self::search()} — hành động thật hỏi lại;
 *  - và chính Action không trả gì cho tài khoản không còn hiệu lực hay không có nguồn nào.
 *
 * # Không giữ kết quả trong trạng thái Livewire, không đưa chuỗi tìm lên URL
 *
 * Thuộc tính công khai duy nhất là `$term`. Kết quả tính lại ở mỗi lần vẽ trong
 * {@see self::getViewData()} (`protected`: một phương thức `public` trả danh sách sẽ là một điểm cuối
 * gọi được từ xa). Chuỗi tìm KHÔNG đi lên query string (`#[Url]`): nó thường là tên người, và URL đi
 * vào lịch sử trình duyệt và nhật ký truy cập của máy chủ.
 */
class Search extends Page
{
    protected string $view = 'filament.admin.pages.search';

    protected static ?string $slug = 'search';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    public string $term = '';

    public static function getNavigationLabel(): string
    {
        return __('search.navigation_label');
    }

    public function getTitle(): string
    {
        return __('search.page_title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', Matter::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Xem docblock lớp, mục "Cổng" — 404 ở mount VÀ ở mọi request cập nhật Livewire. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    /**
     * Nút "Tìm" / phím Enter. Kết quả đã được vẽ lại theo `$term` ở mọi request; hành động này chỉ
     * hỏi lại cổng, để một lần gọi thẳng không vòng qua `boot()` cũng không chạy được.
     */
    public function search(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    /**
     * @return array{results: ?MatterSearchResults, tooShort: bool, sources: list<SearchSource>, minLength: int, limit: int}
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();

        $hasTerm = trim($this->term) !== '';
        $searchable = SearchMatters::normalizeTerm($this->term) !== null;

        return [
            'results' => $searchable ? app(SearchMatters::class)->handle($user, $this->term) : null,
            'tooShort' => $hasTerm && ! $searchable,
            'sources' => SearchMatters::sourcesFor($user),
            'minLength' => SearchMatters::MIN_TERM_LENGTH,
            'limit' => SearchMatters::DEFAULT_LIMIT,
        ];
    }
}
