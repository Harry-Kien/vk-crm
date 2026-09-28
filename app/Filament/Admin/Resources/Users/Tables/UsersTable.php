<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Actions\User\DeleteStaffMember;
use App\Enums\UserPosition;
use App\Models\User;
use DomainException;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\LazyCollection;
use Throwable;

/** Không có cột nào cho password / two_factor_secret / two_factor_recovery_codes (ràng buộc task). */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('users.fields.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('position')
                    ->label(__('users.fields.position'))
                    ->badge()
                    ->formatStateUsing(fn (UserPosition $state): string => $state->label()),
                TextColumn::make('phone')
                    ->label(__('users.fields.phone'))
                    ->searchable(),
                TextColumn::make('bar_number')
                    ->label(__('users.fields.bar_number'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('users.fields.is_active'))
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label(__('users.fields.last_login_at'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('position')
                    ->label(__('users.fields.position'))
                    ->options(fn () => collect(UserPosition::cases())->mapWithKeys(fn (UserPosition $position) => [$position->value => $position->label()])),
                TernaryFilter::make('is_active')
                    ->label(__('users.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                // M6.5 Task 4 (carry-over từ rà soát Task 2, C1-class hole): trước bản sửa này,
                // UserPolicy không định nghĩa deleteAny()/restoreAny()/forceDeleteAny() — một
                // ability KHÔNG có phương thức tương ứng được Filament coi là CHO PHÉP ở chế độ
                // không nghiêm ngặt (mặc định dự án), nên MỌI người vào được trang này bấm xoá
                // hàng loạt trót lọt, bỏ qua cả settings.manage lẫn luật R7 (còn việc dở dang, hoặc
                // là admin cuối cùng). authorizeIndividualRecords() là lớp phòng thủ THỨ HAI, độc
                // lập với ba ability "thô" kia: nó bắt MỖI bản ghi đã chọn đi qua đúng
                // UserPolicy::delete()/restore()/forceDelete() — không có nó, cổng thô chỉ quyết
                // định nút có bấm được không, còn Filament vẫn xử lý MỌI dòng đã chọn mà không hỏi
                // lại policy cho từng dòng. Cùng thành ngữ ClientsTable/ClientUsersTable.
                //
                // `->using()` (I2.1, fix round 2): trước bản sửa này, sau khi
                // `authorizeIndividualRecords('delete')` lọc bằng UserPolicy::delete() KHÔNG khoá
                // gì, Filament tự `$record->delete()` từng dòng còn lại — bỏ qua hẳn khoá dòng VÀ
                // Cache::lock('staff-admin-headcount') mà DeleteStaffMember giữ (I2, fix round 1/2)
                // cho đúng nút xoá ĐƠN của EditUser. Hai đường xoá một nhân sự (đơn và hàng loạt)
                // phải cùng đi qua MỘT luật, không phải hai luật tưởng giống nhau. `$records` ở đây
                // đã qua bộ lọc coarse ở trên (không khoá), nên DeleteStaffMember::handle() vẫn là
                // nơi DUY NHẤT khoá dòng và hỏi lại dưới khoá — using() chỉ định tuyến, không lặp
                // lại luật.
                //
                // **Bắt rộng hơn, và khoá theo THÔNG ĐIỆP thay vì theo bản ghi (minor, fix round
                // 3).** Ba nhánh, theo đúng thứ tự bắt (cụ thể trước, chung chung sau):
                //  - `ModelNotFoundException` — bộ lọc "kèm đã xoá" cho chọn được một hàng ĐÃ xoá
                //    mềm; `authorizeIndividualRecords('delete')` không loại nó (`UserPolicy::
                //    delete()` không hỏi `trashed()`), nên nó TỚI được `using()`, và
                //    `DeleteStaffMember::handle()`'s `firstOrFail()` (không `withTrashed()`) ném
                //    đúng exception này. Bản trước KHÔNG bắt nó — nó thoát khỏi `$records->each()`
                //    và phá vỡ toàn bộ lượt xoá hàng loạt, để những bản ghi ĐỨNG SAU trong danh
                //    sách không hề được xử lý. Bỏ qua hàng đó, báo lỗi, KHÔNG dừng cả lượt.
                //  - `DomainException`/`AuthorizationException` — như cũ.
                //  - `Throwable` — lưới an toàn cuối: một lỗi hạ tầng thật (không phải một trong ba
                //    trên) không được phép làm 500 cả trang hay bỏ dở nửa chừng những bản ghi còn
                //    lại; `report($exception)` giữ nguyên dấu vết cho việc điều tra thật, còn
                //    người dùng chỉ thấy đúng một câu chung SPEC §10.10 đòi.
                // Khoá `reportBulkProcessingFailure()` giờ là CHÍNH thông điệp (không phải khoá
                // nhân sự), để nhiều bản ghi bị từ chối cùng một lý do được ĐẾM GỘP thành một dòng,
                // thay vì lặp lại y hệt nhau nhiều lần trong thông báo — cùng cách Filament tự đếm
                // gộp `bulkAuthorizationFailureMessages` ở cổng coarse phía trên.
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void {
                            /** @var User $actor */
                            $actor = Auth::user();

                            $records->each(function (User $record) use ($action, $actor): void {
                                try {
                                    app(DeleteStaffMember::class)->handle($actor, $record);
                                } catch (DomainException $exception) {
                                    $action->reportBulkProcessingFailure($exception->getMessage(), $exception->getMessage());
                                } catch (AuthorizationException) {
                                    $message = __('actions.unauthorized');
                                    $action->reportBulkProcessingFailure($message, $message);
                                } catch (ModelNotFoundException) {
                                    $message = __('actions.unauthorized');
                                    $action->reportBulkProcessingFailure($message, $message);
                                } catch (Throwable $exception) {
                                    report($exception);
                                    $message = __('actions.unauthorized');
                                    $action->reportBulkProcessingFailure($message, $message);
                                }
                            });
                        }),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords('forceDelete'),
                    RestoreBulkAction::make()
                        ->authorizeIndividualRecords('restore'),
                ]),
            ]);
    }
}
