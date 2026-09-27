<?php

namespace App\Support;

use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Một khách hàng có ĐÚNG BẰNG này thấy được với MỘT actor cụ thể hay không (fix round 1, C1,
 * M6.5 Task 6) — MỘT luật, dùng ở cả tầng hiển thị (`App\Filament\Admin\Support\
 * VisibleClientOptions`, ai có `client.manage` thấy toàn bộ, còn lại chỉ thấy khách hàng của
 * những vụ việc mình liệt kê được — `Matter::scopeListableBy`) LẪN tầng nghiệp vụ
 * (`App\Actions\Client\CreateClient`, quyết định có được DÙNG LẠI một hồ sơ trùng hay không).
 *
 * **Vì sao lớp này nằm ở `App\Support`, không phải `App\Filament\...`.** `VisibleClientOptions`
 * (nơi luật này BAN ĐẦU sống, chỉ cho tầng hiển thị) nằm trong cây `App\Filament`, và
 * `CreateClient` là một Action — CLAUDE.md/`ArchitectureTest` cấm nghiệp vụ phụ thuộc Filament.
 * Đặt luật gốc ở đây, để cả hai tầng CÙNG gọi một chỗ, thay vì lặp lại nó lần thứ hai (đúng bài
 * học "danh sách khách đã rò rỉ hai lần ở M3" mà R4 nhắc tới): `VisibleClientOptions` giờ cũng
 * đi qua lớp này cho phần kiểm tra MỘT id (xem `assertVisibleToCurrentUser()`), chỉ còn giữ lại
 * việc dựng DANH SÁCH (một mối quan tâm khác — liệt kê nhiều dòng để hiển thị trong Select, không
 * phải kiểm tra một id).
 *
 * **Nhận `$user` tường minh, không đọc `Auth::` ambient.** `CreateClient::handle()` nhận actor
 * làm tham số (cùng quy ước với mọi Action khác của dự án — actor có thể không phải phiên đăng
 * nhập hiện tại: console, job, import), nên lớp dùng chung phải theo đúng hợp đồng đó.
 */
final class ClientVisibility
{
    public static function isVisibleTo(User $user, int $clientId): bool
    {
        // Cả hai nhánh đòi hồ sơ Client THẬT SỰ còn tồn tại (chưa xoá mềm — `Client::query()`
        // mang sẵn `SoftDeletingScope`, đúng những gì `VisibleClientOptions::forCurrentUser()`
        // cũ đã ngầm định qua `whereIn()`): một `client_id` mồ côi trên một vụ việc cũ (khách
        // hàng đã bị xoá mềm sau đó) không được coi là "thấy được" chỉ vì còn một dòng
        // `matters.client_id` trỏ tới nó.
        if (! Client::query()->whereKey($clientId)->exists()) {
            return false;
        }

        if ($user->can(Permission::ClientManage->value)) {
            return true;
        }

        return Matter::query()->listableBy($user)->where('client_id', $clientId)->exists();
    }

    /**
     * Fix round 2, E1 (M6.5 Task 6, re-review): tra ĐÚNG định danh (R4 a) có được ĐƯA RA hay
     * không — nhẹ hơn `isVisibleTo()`. Trước bản sửa này, `FindClientByIdentifier` không lọc gì
     * cả, nên một luật sư B gõ đúng CCCD của khách hàng C, mà vụ DUY NHẤT là `restricted` do luật
     * sư A phụ trách, vẫn được tra ra tên và mã hồ sơ — đúng lỗ hổng C1 đã vá cho nhánh "tạo
     * khách mới", nhưng bỏ sót nhánh "tra".
     *
     * **Luật (R4a tinh chỉnh):** từ chối, trung lập, CHỈ KHI mọi vụ việc CHƯA xoá mềm của khách
     * hàng này đều `restricted` VÀ không vụ nào trong số đó `listableBy($user)`. Hai trường hợp
     * còn lại vẫn tra được:
     *  - khách hàng CHƯA có vụ việc nào (`intake-03` — khách trợ lý vừa tạo phải tra được);
     *  - khách hàng có ÍT NHẤT một vụ việc THƯỜNG (không `restricted`), bất kể vụ đó có
     *    `listableBy` actor này hay không — một vụ thường không phải bí mật, `confidentiality`
     *    mới là ranh giới cần giữ, không phải "actor này có tham gia đội ngũ hay không" (đó là
     *    ranh giới của `VisibleClientOptions`, một mối lo KHÁC — xem docblock lớp).
     *
     * **`resolveClientId()` gọi LẠI đúng hàm này lúc LƯU**, không chỉ lúc tra — một khoảng trống
     * giữa hai lượt (vụ việc DUY NHẤT của khách hàng chuyển sang `restricted`, hoặc bị xoá mềm
     * giữa chừng) không được để một kết quả tra CŨ còn hiệu lực.
     */
    public static function isOfferableByLookup(User $user, int $clientId): bool
    {
        if (! Client::query()->whereKey($clientId)->exists()) {
            return false;
        }

        if (! Matter::query()->where('client_id', $clientId)->exists()) {
            return true; // Chưa có vụ việc nào — intake-03.
        }

        if (Matter::query()->where('client_id', $clientId)->where('confidentiality', '!=', Confidentiality::Restricted->value)->exists()) {
            return true; // Có ít nhất một vụ THƯỜNG.
        }

        // Mọi vụ đều restricted — offerable chỉ khi actor liệt kê được ÍT NHẤT một trong số đó
        // (lead của chính vụ đó, hoặc admin — xem Matter::scopeListableBy()).
        return Matter::query()->where('client_id', $clientId)->listableBy($user)->exists();
    }

    /**
     * Fix round 2 (Minor): danh sách khách hàng cho ô CHỌN (`VisibleClientOptions::forCurrentUser()`)
     * đi qua ĐÚNG MỘT truy vấn ở đây — client.manage thấy toàn bộ, còn lại chỉ thấy khách hàng của
     * những vụ việc mình liệt kê được. Trước bản sửa này, `VisibleClientOptions` tự viết lại truy
     * vấn này (đúng logic, nhưng là một BẢN SAO thứ hai của cùng một luật).
     *
     * @return Builder<Client>
     */
    public static function visibleClientQuery(User $user): Builder
    {
        if ($user->can(Permission::ClientManage->value)) {
            return Client::query();
        }

        $visibleClientIds = Matter::query()->listableBy($user)->pluck('client_id')->unique();

        return Client::query()->whereIn('id', $visibleClientIds);
    }
}
