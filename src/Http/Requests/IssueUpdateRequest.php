<?php

namespace Lookout\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Lookout\Lookout;
use Lookout\Models\Issue;

/**
 * Edits of one issue (PATCH /issues/{id}) or many (POST /issues/bulk, with ids). assigned_to must be an assignable user's id
 * (Lookout::assignableUsersUsing()) or null (unassign); priority one of Issue::PRIORITIES or null. Access is checked by EnsureLookoutAccess.
 */
class IssueUpdateRequest extends FormRequest
{
    /** Issues one bulk call may change. */
    public const MAX_BULK = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = self::fieldRules();

        if ($this->routeIs('lookout.issues.bulk')) {
            return [...$rules, 'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK], 'ids.*' => ['integer', 'distinct']];
        }

        return [...$rules, 'description' => ['sometimes', 'nullable', 'string', 'max:20000']];
    }

    /**
     * Status, priority and assignee, shared by the web API and MCP (update_issue).
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'status' => ['sometimes', 'in:'.implode(',', Issue::STATUSES)],
            'priority' => ['sometimes', 'nullable', 'in:'.implode(',', Issue::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Lookout::isAssignable((int) $value)) {
                    $fail('Issues can be assigned to admins only.');
                }
            }],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->safe()->only(['status', 'priority', 'assigned_to', 'description']);
    }
}
