<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\DocumentGroup;
use App\Support\Mcp\Presenters\ChecklistItemPresenter;
use App\Support\Mcp\Presenters\ClientPresenter;
use App\Support\Mcp\Presenters\ClientRequestPresenter;
use App\Support\Mcp\Presenters\ClientRequestReplyPresenter;
use App\Support\Mcp\Presenters\DeadlinePresenter;
use App\Support\Mcp\Presenters\DocumentPresenter;
use App\Support\Mcp\Presenters\MatterChecklistPresenter;
use App\Support\Mcp\Presenters\MatterPresenter;
use App\Support\Mcp\Presenters\PartyPresenter;
use App\Support\Mcp\Presenters\StaffPresenter;
use App\Support\Mcp\Presenters\StageLogPresenter;
use App\Support\Mcp\UntrustedText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * Mảnh `outputSchema` dùng chung cho các tool đọc (kế hoạch M11, "Quy ước chung": `structuredContent`
 * có `outputSchema`). Mỗi mảnh mô tả ĐÚNG đầu ra của một presenter ở `App\Support\Mcp\Presenters`:
 * cùng tên khoá, cùng thứ tự với hằng `FIELDS` của presenter đó, mọi khoá bắt buộc, và object ĐÓNG
 * (`additionalProperties: false`).
 *
 * Object đóng biến schema thành một allowlist thứ hai ở tầng giao thức: test của từng tool so
 * `structuredContent` với schema này (`Tests\Support\McpToolCall::structured()`), nên một trường
 * presenter thêm vào mà schema không khai — hay ngược lại — làm test đỏ, thay vì lặng lẽ ra ngoài.
 * Client nào kiểm `structuredContent` theo `outputSchema` cũng nhận đúng hình dạng đã hứa.
 */
final class OutputSchemas
{
    /**
     * Object đóng, mọi thuộc tính bắt buộc (presenter luôn trả đủ khoá, giá trị có thể `null`).
     *
     * @param  array<string, Type>  $properties
     */
    public static function closed(JsonSchema $schema, array $properties): ObjectType
    {
        return $schema->object(self::required($properties))->withoutAdditionalProperties();
    }

    /**
     * Đánh dấu mọi thuộc tính là bắt buộc — cho mức gốc của `outputSchema()`, nơi laravel/mcp tự dựng
     * object (và `CrmReadTool::toArray()` tự đóng nó).
     *
     * @param  array<string, Type>  $properties
     * @return array<string, Type>
     */
    public static function required(array $properties): array
    {
        foreach ($properties as $property) {
            $property->required();
        }

        return $properties;
    }

    /** {@see StaffPresenter::FIELDS} */
    public static function staff(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, self::staffProperties($schema));
    }

    /** {@see ClientPresenter::FIELDS} */
    public static function client(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'name' => $schema->string(),
            'type' => $schema->string()->nullable(),
            'type_label' => $schema->string()->nullable(),
            'phone_masked' => $schema->string()->nullable(),
        ]);
    }

    /** {@see PartyPresenter::FIELDS} */
    public static function party(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'role' => $schema->string(),
            'role_label' => $schema->string(),
            'label' => $schema->string(),
            'is_our_client' => $schema->boolean(),
            'is_pseudonym' => $schema->boolean(),
        ]);
    }

    /** {@see MatterPresenter::REFERENCE_FIELDS} */
    public static function matterReference(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'id' => $schema->string(),
            'code' => $schema->string(),
            'title' => $schema->string(),
            'url' => $schema->string(),
        ]);
    }

    /**
     * {@see MatterPresenter::ROW_FIELDS}, chưa đóng — tool ghép thêm khoá của mình rồi gọi
     * {@see self::closed()}.
     *
     * @return array<string, Type>
     */
    public static function matterRowProperties(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string(),
            'code' => $schema->string(),
            'title' => $schema->string(),
            'matter_type' => $schema->string()->nullable(),
            'stage' => $schema->string(),
            'stage_label' => $schema->string()->nullable(),
            'client_name' => $schema->string()->nullable(),
            'lead_lawyer' => $schema->string()->nullable(),
            'is_open' => $schema->boolean(),
            'opened_at' => $schema->string()->nullable(),
            'closed_at' => $schema->string()->nullable(),
            'url' => $schema->string(),
        ];
    }

    /**
     * {@see MatterPresenter::DETAIL_FIELDS}, chưa đóng.
     *
     * @return array<string, Type>
     */
    public static function matterDetailProperties(JsonSchema $schema): array
    {
        return [
            ...self::matterRowProperties($schema),
            'court_name' => $schema->string()->nullable(),
            'case_number' => $schema->string()->nullable(),
            'has_internal_note' => $schema->boolean(),
            'client' => self::client($schema)->nullable(),
            'team' => self::listOf($schema, self::closed($schema, [
                ...self::staffProperties($schema),
                'role_in_matter' => $schema->string()->nullable(),
                'role_in_matter_label' => $schema->string()->nullable(),
            ])),
            'parties' => self::listOf($schema, self::party($schema)),
        ];
    }

    /** {@see DeadlinePresenter::FIELDS} */
    public static function deadline(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'id' => $schema->string(),
            'matter' => self::matterReference($schema)->nullable(),
            'name' => $schema->string(),
            'due_date' => $schema->string()->nullable(),
            'severity' => $schema->string()->nullable(),
            'severity_label' => $schema->string()->nullable(),
            'responsible' => self::staff($schema)->nullable(),
            'is_completed' => $schema->boolean(),
            'completed_at' => $schema->string()->nullable(),
            'is_published' => $schema->boolean(),
            'created_via' => $schema->string()->nullable(),
            'created_via_label' => $schema->string()->nullable(),
            'awaiting_confirmation' => $schema->boolean(),
            'url' => $schema->string(),
        ]);
    }

    /** {@see StageLogPresenter::FIELDS} — không có khoá nào cho nội dung `internal_note` (R4). */
    public static function stageLog(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'id' => $schema->string(),
            'matter_id' => $schema->string(),
            'occurred_at' => $schema->string()->nullable(),
            'from_stage' => $schema->string()->nullable(),
            'from_stage_label' => $schema->string()->nullable(),
            'to_stage' => $schema->string()->nullable(),
            'to_stage_label' => $schema->string()->nullable(),
            'public_content' => $schema->string()->nullable(),
            'next_step' => $schema->string()->nullable(),
            'client_action' => $schema->string()->nullable(),
            'expected_next_update_at' => $schema->string()->nullable(),
            'is_published' => $schema->boolean(),
            'published_at' => $schema->string()->nullable(),
            'client_viewed_at' => $schema->string()->nullable(),
            'has_internal_note' => $schema->boolean(),
            'url' => $schema->string(),
        ]);
    }

    /** {@see ChecklistItemPresenter::FIELDS} */
    public static function checklistItem(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'id' => $schema->string(),
            'name' => $schema->string(),
            'is_required' => $schema->boolean(),
            'status' => $schema->string()->nullable(),
            'status_label' => $schema->string()->nullable(),
            'rejection_reason' => $schema->string()->nullable(),
            'document_count' => $schema->integer(),
            'url' => $schema->string(),
        ]);
    }

    /** {@see MatterChecklistPresenter::progress()} — "Đã nộp X/Y", chung cho `get_matter` và `get_checklist`. */
    public static function checklistProgress(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'submitted' => $schema->integer(),
            'total' => $schema->integer(),
            'label' => $schema->string(),
        ]);
    }

    /**
     * {@see DocumentPresenter::FIELDS}. `group` là enum các nhóm KHÔNG nội bộ (A, B, C): một tài liệu
     * nhóm D lọt tới đây — dù presenter đã từ chối nó — cũng làm kết quả lệch schema (R4).
     */
    public static function document(JsonSchema $schema): ObjectType
    {
        $groups = array_values(array_map(
            fn (DocumentGroup $group): string => $group->value,
            array_filter(DocumentGroup::cases(), fn (DocumentGroup $group): bool => ! $group->isInternal()),
        ));

        return self::closed($schema, [
            'id' => $schema->string(),
            'group' => $schema->string()->enum($groups),
            'group_label' => $schema->string(),
            'title' => $schema->string()->nullable(),
            'untrusted_client_content' => self::closed($schema, ['title' => self::untrusted($schema)])->nullable(),
            'status' => $schema->string()->nullable(),
            'status_label' => $schema->string()->nullable(),
            'version' => $schema->integer(),
            'issued_at' => $schema->string()->nullable(),
            'published_at' => $schema->string()->nullable(),
            'created_at' => $schema->string()->nullable(),
            'client_can_view' => $schema->boolean(),
            'client_can_download' => $schema->boolean(),
            'url' => $schema->string(),
        ]);
    }

    /**
     * {@see ClientRequestPresenter::ROW_FIELDS}, chưa đóng. `$untrusted` là các khoá trong
     * `untrusted_client_content`: `subject` cho một dòng, thêm `content` cho cả luồng.
     *
     * @param  list<string>  $untrusted
     * @return array<string, Type>
     */
    public static function clientRequestProperties(JsonSchema $schema, array $untrusted): array
    {
        $wrapped = [];

        foreach ($untrusted as $key) {
            $wrapped[$key] = self::untrusted($schema);
        }

        return [
            'id' => $schema->string(),
            'matter' => self::matterReference($schema)->nullable(),
            'status' => $schema->string()->nullable(),
            'status_label' => $schema->string()->nullable(),
            'assignee' => self::staff($schema)->nullable(),
            'created_at' => $schema->string()->nullable(),
            'last_activity_at' => $schema->string()->nullable(),
            'answered_at' => $schema->string()->nullable(),
            'untrusted_client_content' => self::closed($schema, $wrapped),
            'url' => $schema->string(),
        ];
    }

    /** {@see ClientRequestReplyPresenter::FIELDS} */
    public static function reply(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'id' => $schema->string(),
            'author' => $schema->string()->enum(['office', 'client']),
            'author_name' => $schema->string()->nullable(),
            'created_at' => $schema->string()->nullable(),
            'content' => $schema->string()->nullable(),
            'untrusted_client_content' => self::closed($schema, ['content' => self::untrusted($schema)])->nullable(),
        ]);
    }

    /** Một trường đã qua {@see UntrustedText::from()}: `{text, truncated}` (R11). */
    public static function untrusted(JsonSchema $schema): ObjectType
    {
        return self::closed($schema, [
            'text' => $schema->string(),
            'truncated' => $schema->boolean(),
        ]);
    }

    public static function listOf(JsonSchema $schema, Type $items): ArrayType
    {
        return $schema->array()->items($items);
    }

    /** @return array<string, Type> */
    private static function staffProperties(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string(),
            'name' => $schema->string(),
            'position' => $schema->string()->nullable(),
            'position_label' => $schema->string()->nullable(),
        ];
    }
}
