<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'matter_id' => null,
            'title' => 'Soạn '.fake()->words(3, true),
            'description' => null,
            'assignee_id' => User::factory(),
            'assigned_by' => User::factory(),
            'due_date' => null,
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Pending,
            'assigned_at' => now(),
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn (): array => ['matter_id' => $matter->getKey()]);
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn (): array => array_filter([
            'status' => $status,
            'accepted_at' => in_array($status, [TaskStatus::Accepted, TaskStatus::Completed], true) ? now() : null,
            'declined_at' => $status === TaskStatus::Declined ? now() : null,
            'decline_reason' => $status === TaskStatus::Declined ? 'Đang quá tải việc' : null,
            'completed_at' => $status === TaskStatus::Completed ? now() : null,
            'cancelled_at' => $status === TaskStatus::Cancelled ? now() : null,
            'cancel_reason' => $status === TaskStatus::Cancelled ? 'Không còn cần' : null,
        ], fn (mixed $value): bool => $value !== null));
    }
}
