<?php

use App\Actions\Notification\RecordOutboundMessage;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Mail\BrandedMailable;
use App\Mail\OutboundHeaders;
use App\Models\ClientUser;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Notifications\Client\SendLoginCode;
use App\Support\Mail\OutboundLedgerTransport;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Cánh cửa thư đi ra — SPEC §4.15, Phán quyết R1 của kế hoạch M6.
 *
 * Bảng `outbound_messages` tồn tại để trả lời đúng một câu: "tôi không nhận được thông báo của
 * văn phòng". Câu đó được hỏi về những thư mà KHÔNG AI NHỚ là mình đã gửi, nên nhật ký không
 * được phụ thuộc vào việc nơi gửi có nhớ ghi hay không. Vì vậy nó được ghi bởi một listener
 * nghe sự kiện thư của chính framework, và phép đo quyết định của tệp này là test đầu tiên:
 * một `Mail::raw()` trần — không Mailable, không header, không ai khai báo gì — vẫn phải sinh
 * ra một dòng.
 *
 * Bộ test này KHÔNG dùng `Mail::fake()`, và đó là điều kiện để nó đo được thứ nó nói: `MailFake`
 * thay cả trình gửi thư, nên nó không phát `MessageSending`/`MessageSent` và cũng không đi qua
 * transport — tức nó vô hiệu hoá đúng cái cơ chế đang được kiểm chứng. `phpunit.xml` ghim
 * `MAIL_MAILER=array`, nên thư đi qua đường thật rồi dừng lại trong bộ nhớ.
 */

/** Mailable cơ sở có khai báo mẫu và bản ghi liên quan — bản mẫu cho chín mẫu thư của M6. */
class LedgerProbeMail extends BrandedMailable
{
    public function __construct(private readonly ?Model $record = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Thư thử nhật ký');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Nội dung thử.</p>');
    }

    protected function template(): string
    {
        return 'test.probe';
    }

    protected function relatedRecord(): ?Model
    {
        return $this->record;
    }
}

/** Một transport luôn hỏng, để đo thứ chỉ nhìn thấy được khi việc gửi thất bại. */
class ClosedDoorTransport implements TransportInterface
{
    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        throw new TransportException('SMTP cửa đóng');
    }

    public function __toString(): string
    {
        return 'closed-door://';
    }
}

/** Cắm `ClosedDoorTransport` vào một mailer có tên, đi đúng đường cấu hình thật của Laravel. */
function closedDoorMailer(): string
{
    config()->set('mail.mailers.closed_door', ['transport' => 'closed_door']);
    Mail::extend('closed_door', fn (): TransportInterface => new ClosedDoorTransport);

    return 'closed_door';
}

it('ghi một dòng nhật ký cho cả một Mail::raw() trần', function () {
    Mail::raw('Xin chào.', fn ($message) => $message->to('khach@vidu.test')->subject('Không khai báo gì cả'));

    $row = OutboundMessage::query()->sole();

    expect($row->channel)->toBe(OutboundChannel::Email)
        ->and($row->recipient)->toBe('khach@vidu.test')
        ->and($row->template)->toBe(OutboundMessage::TEMPLATE_UNDECLARED)
        ->and($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->related_type)->toBeNull()
        ->and($row->related_id)->toBeNull()
        ->and($row->payload['subject'] ?? null)->toBe('Không khai báo gì cả');
});

it('lấy mẫu và bản ghi liên quan từ header của Mailable cơ sở', function () {
    $stageLog = StageLog::factory()->create();

    Mail::to('khach@vidu.test')->send(new LedgerProbeMail($stageLog));

    $row = OutboundMessage::query()->sole();

    expect($row->template)->toBe('test.probe')
        ->and($row->related_type)->toBe($stageLog->getMorphClass())
        ->and($row->related_id)->toBe($stageLog->getKey())
        ->and($row->related->is($stageLog))->toBeTrue()
        ->and($row->status)->toBe(OutboundStatus::Sent);
});

it('để related trống khi Mailable không khai báo bản ghi liên quan', function () {
    Mail::to('khach@vidu.test')->send(new LedgerProbeMail);

    $row = OutboundMessage::query()->sole();

    expect($row->template)->toBe('test.probe')
        ->and($row->related_type)->toBeNull()
        ->and($row->related_id)->toBeNull();
});

it('ghi mọi địa chỉ nhận của một thư vào đúng một dòng', function () {
    Mail::to(['mot@vidu.test', 'hai@vidu.test'])
        ->cc('ba@vidu.test')
        ->bcc('bon@vidu.test')
        ->send(new LedgerProbeMail);

    $row = OutboundMessage::query()->sole();

    expect($row->recipient)->toBe('mot@vidu.test, hai@vidu.test, ba@vidu.test, bon@vidu.test');
});

it('ghi status failed kèm nguyên văn lý do khi việc gửi ném lỗi', function () {
    $mailer = closedDoorMailer();

    expect(fn () => Mail::mailer($mailer)->to('khach@vidu.test')->send(new LedgerProbeMail))
        ->toThrow(TransportException::class);

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Failed)
        ->and($row->error)->toContain('SMTP cửa đóng')
        ->and($row->sent_at)->toBeNull()
        ->and($row->template)->toBe('test.probe');
});

/**
 * `Symfony\Component\Mime\Header\Headers::add()` cho phép nhiều header cùng tên với một tên
 * ngoài danh sách chuẩn, và `get()` trả về cái ĐẦU TIÊN. Nên một mẫu thư tự đặt
 * `X-VKCRM-Ledger-Id` — cố ý, hay chép nhầm từ một mẫu khác — sẽ cướp được khoá dòng nhật ký nếu
 * nhật ký chỉ nối thêm header của mình vào sau.
 */
it('không để một mẫu thư tự trỏ dòng nhật ký của mình sang chỗ khác', function () {
    Mail::raw('Xin chào.', function ($message) {
        $message->to('khach@vidu.test')->subject('Mẫu tự đặt khoá');
        $message->getSymfonyMessage()->getHeaders()->addTextHeader(OutboundHeaders::LEDGER_ID, '999');
    });

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->sent_at)->not->toBeNull();
});

/**
 * M6.5 Task 12 (`notify/notify-11`): trước bản sửa này, chặng "gửi xong" nằm ở
 * `RecordOutboundMail::recordSent()` (nghe `MessageSent`) — test này khi đó bắn thẳng sự kiện đó
 * với một `RawMessage` không có header để chứng minh listener không sập. Từ Task 12, chặng đó
 * chuyển hẳn sang `OutboundLedgerTransport::send()` (xem docblock lớp và của
 * `App\Listeners\RecordOutboundMail`), nên phép đo tương đương là gọi THẲNG transport thật với
 * một `RawMessage` trần và xác nhận nó gửi THÀNH CÔNG mà không dựng/sửa dòng nào — không có
 * header nào để tra khoá, nên không có gì để `markSent()`.
 */
it('không ngã khi một thư thô không có header đi thẳng vào transport và gửi thành công', function () {
    $transport = Mail::mailer()->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(OutboundLedgerTransport::class);

    $email = (new Email)
        ->from('gui@vidu.test')
        ->to('nhan@vidu.test')
        ->subject('Đi tắt, không qua Mailer')
        ->text('Không có MessageSending nào mở dòng cho thư này.');

    $sent = $transport->send($email);

    expect($sent)->not->toBeNull()
        ->and(OutboundMessage::query()->count())->toBe(0);
});

it('không ngã khi transport hỏng trên một thư thô không có header nào', function () {
    $transport = Mail::mailer(closedDoorMailer())->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(OutboundLedgerTransport::class)
        ->and(fn () => $transport->send(new RawMessage('Thư thô, không phải Message.')))
        ->toThrow(TransportException::class)
        ->and(OutboundMessage::query()->count())->toBe(0);
});

it('không dựng dòng nào khi một thư đi thẳng vào transport rồi hỏng', function () {
    $transport = Mail::mailer(closedDoorMailer())->getSymfonyTransport();

    $email = (new Email)
        ->from('gui@vidu.test')
        ->to('nhan@vidu.test')
        ->subject('Đi tắt, không qua Mailer')
        ->text('Không có MessageSending nào mở dòng cho thư này.');

    expect(fn () => $transport->send($email))->toThrow(TransportException::class)
        ->and(OutboundMessage::query()->count())->toBe(0);
});

/**
 * Mặt còn lại của cùng một mối nguy: một header khoá nhật ký KHÔNG phải số nguyên. Nó tới được
 * đây qua con đường mà `MessageSending` không đi qua — một `Email` đưa thẳng vào transport —
 * nên không có lượt `remove()` nào dọn nó đi, và `(int) '1abc'` thì bằng 1.
 */
it('không để một header khoá nhật ký viết sai cướp dòng của thư khác', function () {
    Mail::raw('Xin chào.', fn ($message) => $message->to('that@vidu.test')->subject('Thư thật'));

    $real = OutboundMessage::query()->sole();
    expect($real->status)->toBe(OutboundStatus::Sent);

    $email = (new Email)
        ->from('gui@vidu.test')
        ->to('nhan@vidu.test')
        ->subject('Khoá viết sai')
        ->text('Khoá nhật ký của thư này không phải một số.');

    $email->getHeaders()->addTextHeader(OutboundHeaders::LEDGER_ID, $real->getKey().'abc');

    expect(fn () => Mail::mailer(closedDoorMailer())->getSymfonyTransport()->send($email))
        ->toThrow(TransportException::class);

    $real->refresh();

    expect($real->status)->toBe(OutboundStatus::Sent)
        ->and($real->error)->toBeNull()
        ->and(OutboundMessage::query()->count())->toBe(1);
});

/**
 * Bảng này bị `RestrictedToClientPortal` chặn SẠCH khi có khách đang mở cổng
 * (`applyClientPortalConstraints` gọi `whereRaw('1 = 0')`). Nhật ký phải ghi được kể cả khi ấy,
 * vì phần lớn thư gửi cho nhân sự (M6 Task 4) được kích hoạt bởi chính khách đang đăng nhập:
 * nếu phép tra dòng vừa tạo đi qua scope đó, dòng sẽ mãi mãi dừng ở `queued` và nhật ký nói dối
 * đúng vào lúc nó được hỏi.
 */
it('ghi đủ trạng thái kể cả khi một khách hàng đang mở cổng', function () {
    $clientUser = ClientUser::factory()->create();

    ClientPortalScope::actingAs($clientUser, function (): void {
        Mail::to('nhansu@vidu.test')->send(new LedgerProbeMail);
    });

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->sent_at)->not->toBeNull();
});

it('ghi một dòng mang mẫu client.otp cho thư mã đăng nhập của M5', function () {
    $clientUser = ClientUser::factory()->create();

    $clientUser->notify(new SendLoginCode('123456', 5));

    $row = OutboundMessage::query()->sole();

    expect($row->template)->toBe('client.otp')
        ->and($row->recipient)->toBe($clientUser->email)
        ->and($row->status)->toBe(OutboundStatus::Sent);
});

it('bỏ qua header related viết sai thay vì dựng một liên kết rỗng', function () {
    foreach (['', 'stage_log', 'stage_log:', ':7', 'stage_log:bảy'] as $value) {
        OutboundMessage::query()->withoutGlobalScopes()->delete();

        Mail::raw('Xin chào.', function ($message) use ($value) {
            $message->to('khach@vidu.test')->subject('Header hỏng');
            $message->getSymfonyMessage()->getHeaders()
                ->addTextHeader(OutboundHeaders::RELATED, $value);
        });

        $row = OutboundMessage::query()->sole();

        expect($row->related_type)->toBeNull("[{$value}] không được dựng thành related_type")
            ->and($row->related_id)->toBeNull("[{$value}] không được dựng thành related_id");
    }
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 12 (`notify/notify-11`) — gỡ header X-VKCRM-* khỏi thư TRƯỚC KHI nó rời máy chủ.
// Nhật ký đã đọc xong template/related ở MessageSending (RecordOutboundMessage::sending()), nên
// không mất ngữ cảnh khi gỡ — chỉ mất thứ lộ id tuần tự nội bộ ra hộp thư khách.
// ---------------------------------------------------------------------------------------------

/** @return Collection<int, string> Tên MỌI header của thông điệp thật đã "gửi". */
function headerNamesOf(Message $message): Collection
{
    return collect(iterator_to_array($message->getHeaders()->all()))
        ->map(fn ($header) => $header->getName());
}

it('gỡ cả ba header X-VKCRM-* khỏi thư thật trước khi nó rời máy chủ, nhưng dòng nhật ký vẫn còn template và related', function () {
    $stageLog = StageLog::factory()->create();

    Mail::to('khach@vidu.test')->send(new LedgerProbeMail($stageLog));

    $sent = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();
    $names = headerNamesOf($sent);

    expect($names->contains(fn (string $name) => str_starts_with($name, 'X-VKCRM-')))->toBeFalse();

    $row = OutboundMessage::query()->sole();
    expect($row->template)->toBe('test.probe')
        ->and($row->related_type)->toBe($stageLog->getMorphClass())
        ->and($row->related_id)->toBe($stageLog->getKey())
        ->and($row->status)->toBe(OutboundStatus::Sent);
});

/** Cặp dương: một thư KHÔNG khai báo mẫu/bản ghi liên quan thì cũng không còn header nào, dòng nhật ký vẫn ghi "undeclared". */
it('vẫn ghi undeclared khi thư không khai báo gì, sau khi đã gỡ header', function () {
    Mail::raw('Xin chào.', fn ($message) => $message->to('khach@vidu.test')->subject('Không khai báo gì'));

    $sent = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();

    expect(headerNamesOf($sent)->contains(fn (string $name) => str_starts_with($name, 'X-VKCRM-')))->toBeFalse();

    $row = OutboundMessage::query()->sole();
    expect($row->template)->toBe(OutboundMessage::TEMPLATE_UNDECLARED);
});

/**
 * Đối chứng "gửi hỏng": header bị gỡ TRƯỚC KHI transport thật chạy (không phải sau), nên ngay cả
 * khi việc gửi ném lỗi, dòng nhật ký vẫn phải còn template/related — chúng được đọc ở
 * MessageSending, không phụ thuộc gì vào việc gỡ header có xảy ra hay không.
 *
 * Mutation probe: xem báo cáo — đổi thứ tự gỡ/gửi trong `OutboundLedgerTransport::send()` (gỡ
 * SAU khi gửi thành công thay vì TRƯỚC) làm chính test "gỡ cả ba header..." ở trên đỏ, vì
 * `ArrayTransport` lưu lại đúng thông điệp đã đi qua `doSend()` — tức đã có header.
 */
it('vẫn ghi status failed kèm template/related khi việc gửi hỏng, dù header đã bị gỡ trước khi transport thật chạy', function () {
    $stageLog = StageLog::factory()->create();
    $mailer = closedDoorMailer();

    expect(fn () => Mail::mailer($mailer)->to('khach@vidu.test')->send(new LedgerProbeMail($stageLog)))
        ->toThrow(TransportException::class);

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Failed)
        ->and($row->template)->toBe('test.probe')
        ->and($row->related_type)->toBe($stageLog->getMorphClass())
        ->and($row->related_id)->toBe($stageLog->getKey());
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1 (minor) — `markSent()` phải nằm NGOÀI `try`: một lỗi khi ĐÓNG dòng nhật ký (ví dụ
// CSDL bận đúng lúc đó) không được biến một thư ĐÃ GỬI THÀNH CÔNG thành một dòng `failed` — dòng
// `failed` là tín hiệu cho hàng đợi (`SendDeadlineReminderMail`, `SendStageUpdateNotification`)
// THỬ LẠI, và thử lại một thư đã thật sự tới nơi là GỬI TRÙNG cho người nhận.
// ---------------------------------------------------------------------------------------------

/** Ledger giả: `markSent()` luôn ném lỗi, mọi lời gọi khác giữ nguyên hành vi thật. */
class ThrowingOnMarkSentLedger extends RecordOutboundMessage
{
    public function markSent(int $ledgerId): void
    {
        throw new RuntimeException('CSDL bận đúng lúc đóng dòng nhật ký.');
    }
}

/**
 * Mutation probe: xem báo cáo — đưa `markSent()` trở lại BÊN TRONG `try` (bản trước vòng sửa 1)
 * làm chính test này đỏ: dòng chuyển sang `Failed` thay vì giữ nguyên KHÔNG PHẢI `Failed`.
 */
it('does not mark a delivered mail as failed when closing the ledger row itself throws', function () {
    app()->instance(RecordOutboundMessage::class, new ThrowingOnMarkSentLedger);

    expect(fn () => Mail::to('khach@vidu.test')->send(new LedgerProbeMail))->toThrow(RuntimeException::class);

    $row = OutboundMessage::query()->sole();

    expect($row->status)->not->toBe(OutboundStatus::Failed);
});

/** Cặp dương: khi markSent() không ném gì, dòng đóng thành `Sent` bình thường — ghim rằng test trên đỏ đúng vì markSent ném lỗi, không vì lý do khác. */
it('still marks the row sent when closing the ledger row does not throw', function () {
    $row = null;
    Mail::to('khach@vidu.test')->send(new LedgerProbeMail);

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Sent);
});
