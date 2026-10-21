<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Jobs\SendMatterAssignmentNotice;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * Gộp integ-fa — rà soát chéo 2026-10-18, mục 5: một job đã nằm trong hàng đợi TRƯỚC bản cập nhật vẫn chạy được sau
 * bản cập nhật. Làn fa2 thêm tham số `withMail` vào hàm dựng của {@see SendMatterAssignmentNotice} (job có từ trước, đã
 * chạy trên máy thật); payload cũ không có khoá đó. Một thuộc tính `readonly` khai trong hàm dựng không có giá trị mặc
 * định khi unserialize, nên job cũ ném lỗi; thuộc tính thường có mặc định thì không.
 *
 * Các job khác đổi hàm dựng trong integ-fa đều là job MỚI (không có trên `main`, không có payload cũ):
 * SendDeadlineAssignmentNotice, SendDocumentReviewNotice, SendLeadChangeNotices, SendStaffWorkTransferNotices,
 * SendTeamDepartureNotices. SendTaskNotice và SendWorkReminder không đổi hàm dựng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * Payload của một job như bản trước đó xếp — không có khoá `$property`. `SerializesModels::__serialize()` đã bỏ một
 * thuộc tính đang mang đúng giá trị mặc định khai báo, nên khoá chỉ có mặt khi thuộc tính KHÔNG có mặc định (thuộc tính
 * `readonly` khai trong hàm dựng) — khi đó gỡ nó ra.
 */
function payloadWithout(object $job, string $property): string
{
    $serialized = serialize($job);
    $pattern = '/s:'.strlen($property).':"'.preg_quote($property, '/').'";b:[01];/';

    if (preg_match($pattern, $serialized) !== 1) {
        return $serialized;
    }

    $stripped = preg_replace($pattern, '', $serialized, 1);

    return preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':', $stripped, 1);
}

/** Mutation probe: đưa `withMail` về `public readonly bool $withMail = false` trong hàm dựng → đỏ. */
it('runs a seat-assignment notice queued before the update, whose payload has no "withMail"', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant, $lead);
    $event = Audit::record('team_member_added', $matter, ['user_id' => $assistant->id, 'role' => MatterRole::Assistant->value], $lead);

    $old = unserialize(payloadWithout(
        new SendMatterAssignmentNotice($matter->id, $assistant->id, MatterAssignmentAlert::ASSIGNED, $assistant->id, $event->id),
        'withMail',
    ));

    expect($old)->toBeInstanceOf(SendMatterAssignmentNotice::class)
        ->and($old->withMail)->toBeFalse();

    $old->handle(app(ResolveStaffRecipients::class));

    expect($assistant->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(1);
    Mail::assertNotSent(MatterAssignmentNotice::class);
});

/** Đúng chuỗi `main` (trước làn fa2) xếp vào hàng đợi: năm khoá, không `withMail`. */
it('runs the exact payload the previous release queued', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant, $lead);
    $event = Audit::record('team_member_added', $matter, ['user_id' => $assistant->id, 'role' => MatterRole::Assistant->value], $lead);

    $class = SendMatterAssignmentNotice::class;
    $kind = MatterAssignmentAlert::ASSIGNED;
    $payload = 'O:'.strlen($class).':"'.$class.'":5:{'
        .'s:8:"matterId";i:'.$matter->id.';'
        .'s:8:"memberId";i:'.$assistant->id.';'
        .'s:4:"kind";s:'.strlen($kind).':"'.$kind.'";'
        .'s:11:"recipientId";i:'.$assistant->id.';'
        .'s:7:"eventId";i:'.$event->id.';}';

    $old = unserialize($payload);
    $old->handle(app(ResolveStaffRecipients::class));

    expect($old->withMail)->toBeFalse()
        ->and($assistant->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(1);
});

it('still carries "withMail" for a job queued after the update', function () {
    $job = unserialize(serialize(new SendMatterAssignmentNotice(1, 2, MatterAssignmentAlert::ASSIGNED, 2, 3, withMail: true)));

    expect($job->withMail)->toBeTrue()
        ->and($job->matterId)->toBe(1)
        ->and($job->eventId)->toBe(3);
});
