<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Actions\Client\CreateClient as CreateClientAction;
use App\Exceptions\DuplicateClientDetected;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Schemas\ClientForm;
use App\Models\User;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * "Tạo khách hàng mới" (SPEC §4.2; M6.5 Task 6, finding `intake/intake-07`, phán quyết R4).
 * Trang này không có nghiệp vụ riêng — nó thu form và gọi `App\Actions\Client\CreateClient`, đúng
 * CLAUDE.md. Chỉ actor có `client.manage` mở được trang này (`ClientPolicy::create`, không đổi).
 *
 * **Luồng hai lượt — cùng hình dạng "conflict-check" của `CreateMatter`.** Lượt 1: người dùng bấm
 * lưu; nếu `CreateClient::handle()` tìm thấy một hồ sơ trùng số điện thoại/CCCD (so với các bên
 * `is_our_client` đã lưu), Action ném `DuplicateClientDetected` — trang bắt, nhớ hồ sơ trùng vào
 * `$duplicateClient` (`#[Locked]`, cùng lý do `CreateMatter::$conflictResult`: đây là bằng chứng
 * hiển thị, một client sửa được nó vô giá trị), và ném `ValidationException` gắn vào ô
 * `confirm_duplicate` để form GIỮ NGUYÊN dữ liệu đã nhập. Lượt 2: người dùng đọc cảnh báo (kèm
 * liên kết tới hồ sơ trùng), tích "vẫn tạo mới", bấm lưu lại — `handleRecordCreation()` gửi lại
 * `confirm_duplicate = true`, và `CreateClient::handle()` tạo một hồ sơ MỚI dù trùng định danh.
 *
 * Ô "vẫn tạo mới" và khối cảnh báo chỉ TỒN TẠI ở lượt 2 (`visible()` theo `$duplicateClient !==
 * null`), cùng lý do Critical C-1 của `CreateMatter`: một trường `hidden` không được Filament
 * dehydrate, nên đây là một cổng phía MÁY CHỦ — `handleRecordCreation()` vẫn đọc lại
 * `$data['confirm_duplicate']` (không tồn tại khi ô chưa từng hiện) qua `?? false`.
 */
class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * Hồ sơ trùng của lần kiểm tra GẦN NHẤT, dạng mảng thuần (id/code/name) để Livewire tuần tự
     * hoá được — `null` khi chưa có lần nào bị chặn, hoặc sau khi lưu thành công.
     */
    #[Locked]
    public ?array $duplicateClient = null;

    public function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema, [
            View::make('filament.duplicate-client-warning')
                ->viewData(fn (self $livewire): array => [
                    'code' => $livewire->duplicateClient['code'] ?? null,
                    'name' => $livewire->duplicateClient['name'] ?? null,
                    'url' => $livewire->duplicateClient === null
                        ? null
                        : ClientResource::getUrl('edit', ['record' => $livewire->duplicateClient['id']], panel: 'admin'),
                ])
                ->visible(fn (self $livewire): bool => $livewire->duplicateClient !== null)
                ->columnSpanFull(),
            Toggle::make('confirm_duplicate')
                ->label(__('clients.duplicate.confirm'))
                ->helperText(__('clients.duplicate.confirm_help'))
                ->visible(fn (self $livewire): bool => $livewire->duplicateClient !== null)
                ->default(false),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        try {
            $client = app(CreateClientAction::class)->handle(
                $actor,
                $data,
                confirmDuplicate: (bool) ($data['confirm_duplicate'] ?? false),
            );
        } catch (DuplicateClientDetected $exception) {
            $this->duplicateClient = [
                'id' => $exception->client->getKey(),
                'code' => $exception->client->code,
                'name' => $exception->client->name,
            ];

            throw ValidationException::withMessages([
                $this->errorKey('confirm_duplicate') => [$exception->getMessage()],
            ]);
        }

        $this->duplicateClient = null;

        return $client;
    }

    /** Cùng công thức `CreateMatter::errorKey()`: khoá lỗi phải khớp state path THẬT của form. */
    private function errorKey(string $field): string
    {
        $statePath = $this->getSchema('form')?->getStatePath();

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }
}
