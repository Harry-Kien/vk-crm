<?php

use App\Mcp\Tools\Concerns\CrmTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/*
|--------------------------------------------------------------------------
| M11 Task 1 — lớp tool cơ sở (R14): bốn hint TƯỜNG MINH và `title` ở cả hai chỗ
|--------------------------------------------------------------------------
| laravel/mcp 1.0.1 chỉ xuất hint nào có attribute, và không bao giờ xuất `annotations.title`
| (`Tool::toArray()`, `HasAnnotations::annotations()`). Chưa có tool thật nào ở Task 1 (`whoami` đến
| ở Task 10), nên test dựng hai tool tối thiểu kế thừa lớp cơ sở, đúng cách một tool thật sẽ làm.
*/

beforeEach(function () {
    app('translator')->addLines([
        'mcp.tools.thu_doc.title' => 'Thử đọc',
        'mcp.tools.thu_doc.description' => 'Dùng khi thử. Không dùng để làm gì khác.',
        'mcp.tools.thu_ghi.title' => 'Thử ghi',
        'mcp.tools.thu_ghi.description' => 'Dùng khi thử ghi. Không dùng để gửi gì.',
    ], 'vi');
});

function crmReadToolForTest(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'thu_doc';

        protected function writes(): bool
        {
            return false;
        }

        public function handle(Request $request): Response
        {
            return Response::text('ok');
        }
    };
}

function crmWriteToolForTest(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'thu_ghi';

        protected function writes(): bool
        {
            return true;
        }

        public function handle(Request $request): Response
        {
            return Response::text('ok');
        }
    };
}

it('R14 tool đọc: readOnlyHint true, destructive false, idempotent true, openWorld false — cả bốn tường minh', function () {
    $annotations = crmReadToolForTest()->toArray()['annotations'];

    expect($annotations)->toBe([
        'title' => 'Thử đọc',
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('R14 tool ghi: readOnlyHint false, destructive false, idempotent true, openWorld false', function () {
    $annotations = crmWriteToolForTest()->toArray()['annotations'];

    expect($annotations)->toBe([
        'title' => 'Thử ghi',
        'readOnlyHint' => false,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('R14 title có ở cả Tool.title lẫn annotations.title, mô tả tiếng Việt qua lang/vi', function () {
    $array = crmReadToolForTest()->toArray();

    expect($array['name'])->toBe('thu_doc')
        ->and($array['title'])->toBe('Thử đọc')
        ->and($array['annotations']['title'])->toBe($array['title'])
        ->and($array['description'])->toBe('Dùng khi thử. Không dùng để làm gì khác.');
});

it('R14 tool thiếu chuỗi tiêu đề/mô tả trong lang/vi bị từ chối, không lộ khoá dịch ra client', function () {
    $tool = new class extends CrmTool
    {
        protected string $name = 'chua_dich';

        protected function writes(): bool
        {
            return false;
        }

        public function handle(Request $request): Response
        {
            return Response::text('ok');
        }
    };

    expect(fn () => $tool->toArray())->toThrow(LogicException::class, 'mcp.tools.chua_dich.title');
});

it('R14 tool không được tự gắn attribute annotation của gói: một nguồn sự thật duy nhất', function () {
    $tool = new #[IsDestructive] class extends CrmTool
    {
        protected string $name = 'thu_doc';

        protected function writes(): bool
        {
            return false;
        }

        public function handle(Request $request): Response
        {
            return Response::text('ok');
        }
    };

    expect(fn () => $tool->toArray())->toThrow(LogicException::class);
});
