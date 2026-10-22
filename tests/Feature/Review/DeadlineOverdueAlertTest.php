<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\DeadlineOverdueAlert;
use App\Support\MatterTab;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn matter — chuông "Mốc thời hạn đã quá hạn".
 *
 * Hai lỗi của cùng một chuông, cảnh báo quan trọng nhất của panel:
 *  - tên mốc (chữ người dùng gõ) không qua `e()` — Filament vẽ nội dung chuông bằng `sanitizeHtml()`, nên
 *    một tên có dạng thẻ (`Nộp đơn <kháng cáo> …`) bị CẮT mất từ chỗ đó trở đi, người đọc chỉ thấy "Nộp đơn";
 *  - chuông không có nút nào: người nhận phải tự đi tìm hồ sơ. Chuông "được giao mốc" cùng loại đã có nút
 *    mở tab "Mốc thời hạn".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Nộp đơn <kháng cáo> bản án sơ thẩm',
        'due_date' => today()->subDays(2),
        'responsible_user_id' => $this->lawyer->id,
        'reminders_sent' => [],
    ]);
});

function overdueBellFor(User $user): FilamentNotification
{
    return FilamentNotification::fromDatabase(
        DatabaseNotification::query()
            ->where('type', DeadlineOverdueAlert::class)
            ->where('notifiable_id', $user->getKey())
            ->sole()
    );
}

it('shows the whole deadline name in the overdue bell, even when it looks like markup', function () {
    (new CheckDeadlines)->handle();

    $body = (string) str(overdueBellFor($this->lawyer)->getBody())->sanitizeHtml();

    expect(html_entity_decode($body))->toContain('Nộp đơn <kháng cáo> bản án sơ thẩm');
});

it('links the overdue bell to the deadlines tab of the matter', function () {
    (new CheckDeadlines)->handle();

    $urls = collect(overdueBellFor($this->lawyer)->getActions())->map(fn ($action) => $action->getUrl())->all();

    expect($urls)->toContain(MatterTab::url($this->matter->id, DeadlinesRelationManager::class));
});
