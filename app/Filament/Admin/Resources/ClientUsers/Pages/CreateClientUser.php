<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Actions\Client\IssuePortalAccess;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Filament\Admin\Resources\ClientUsers\Pages\Concerns\ConfirmsPortalAccessIssue;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateClientUser extends CreateRecord
{
    use ConfirmsPortalAccessIssue;

    protected static string $resource = ClientUserResource::class;

    /**
     * Việc sau gộp M6 (làn fu, mục 6): mọi lần tạo đều gửi (hoặc, với tài khoản tắt, hẹn gửi) mật
     * khẩu tạm tới địa chỉ vừa gõ, nên "Tạo" luôn hỏi xác nhận nêu rõ địa chỉ — xem
     * {@see ConfirmsPortalAccessIssue}.
     */
    protected function getCreateFormAction(): Action
    {
        return $this->confirmPortalAccessIssue(parent::getCreateFormAction(), 'create', fn (): bool => true, fn () => $this->create());
    }

    /** "Tạo và tạo thêm" là đường thứ hai tới cùng một lần tạo — cùng hộp xác nhận. */
    protected function getCreateAnotherFormAction(): Action
    {
        return $this->confirmPortalAccessIssue(parent::getCreateAnotherFormAction(), 'create', fn (): bool => true, fn () => $this->createAnother());
    }

    /** Enter trong một ô của form mở hộp xác nhận của nút "Tạo", không gọi thẳng `create()`. */
    protected function getSubmitFormLivewireMethodName(): string
    {
        return $this->formActionMountHandler('create');
    }

    /**
     * Review fix round 1, Important #2: VisibleClientOptions chỉ hạn chế những gì Ô CHỌN hiển
     * thị — một request bị chỉnh sửa (bỏ qua UI) vẫn có thể gửi thẳng client_id ngoài tầm nhìn.
     * Chặn thật ở đây, đúng lúc client_id đã biết, bằng chính ClientUserPolicy::create() với
     * $client làm ngữ cảnh (xem docblock của ability đó).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $client = Client::query()->find($data['client_id'] ?? null);

        // client_id là trường bắt buộc trên form; nếu không khớp một Client thật (id giả mạo
        // ngoài danh sách VisibleClientOptions đã gửi), không cho lọt qua như "chưa biết
        // client" (nhánh đó của policy cho phép chung) — từ chối thẳng.
        abort_if($client === null, 403);

        Gate::authorize('create', [ClientUser::class, $client]);

        // Task 7 (R12, phát hiện `intake/intake-05`): must_change_password LUÔN true khi tạo —
        // không còn công tắc trên form (ClientUserForm đã gỡ Toggle này) để nhân sự tắt nó ngay
        // lúc tạo. Ép lại ở đây, KHÔNG ĐỌC bất kỳ gì $data mang cho khoá này (kể cả khi có), cùng
        // thành ngữ phòng thủ hai lớp đã dùng cho client_id ở trên: field không còn tồn tại thì
        // đủ để chặn qua UI, còn dòng này chặn cả một request đã "chỉnh sửa tay".
        $data['must_change_password'] = true;

        // Task 3: ô mật khẩu đã gỡ khỏi form, nhưng cột `client_users.password` là `string`
        // KHÔNG NULL. Chuỗi ngẫu nhiên này chỉ là một giá trị TẠM để thoả ràng buộc CSDL — không
        // ai biết nó, không ai dùng nó để đăng nhập: `afterCreate()` ngay bên dưới gọi
        // `IssuePortalAccess`, việc này sẽ GHI ĐÈ nó bằng một mật khẩu tạm THẬT (gửi qua thư
        // `client.activation`) ngay khi job hàng đợi chạy.
        $data['password'] = Str::password(32);

        return $data;
    }

    /**
     * Cấp quyền truy cập cổng NGAY sau khi tài khoản được tạo — đúng lỗ hổng brief Task 3 nêu:
     * "hôm nay tài khoản portal được tạo bằng cách một luật sư gõ tay mật khẩu ... không có thư
     * kích hoạt nào cả". `afterCreate()` chạy sau khi bản ghi đã lưu, nên `$this->record` đã có
     * khoá chính thật để `IssuePortalAccess` khoá dòng và dispatch job gửi thư.
     *
     * Việc sau gộp M6 (làn fu, mục 6): `reissue: false` — thư nói "đã tạo tài khoản"; và một thông
     * báo nói thư đi tới địa chỉ nào, hoặc vì sao chưa đi (tài khoản tạo ở trạng thái tắt).
     */
    protected function afterCreate(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        $this->notifyPortalAccessIssue(
            app(IssuePortalAccess::class)->handle($this->record, $actor, reissue: false),
        );
    }
}
