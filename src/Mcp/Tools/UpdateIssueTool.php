<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Lookout\Http\Requests\IssueUpdateRequest;
use Lookout\Models\Issue;
use Lookout\Services\IssueEditor;

/**
 * Changes an issue's status, priority or assignee with the web API's rules (IssueUpdateRequest::fieldRules()). Each
 * change is one activity row with the acting user and the MCP badge (actor_meta.via = mcp).
 */
#[IsIdempotent]
class UpdateIssueTool extends WriteTool
{
    protected string $name = 'update_issue';

    protected string $description = <<<'MARKDOWN'
        Change a NightOwl issue: status (open, resolved, ignored), priority (low, medium, high, or null for none) and assignee (a user id, or null to unassign). Give only the fields to change. Each change shows in the issue's activity with your name.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Issue id from list_issues.')->required(),
            'status' => $schema->string()->enum(Issue::STATUSES)->description('New status.'),
            'priority' => $schema->string()->enum(Issue::PRIORITIES)->nullable()->description('New priority; null removes it.'),
            'assigned_to' => $schema->integer()->nullable()->description('User id of an admin to assign; null unassigns.'),
        ];
    }

    public function handle(Request $request, IssueEditor $editor): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $validated = $request->validate(['id' => ['required', 'integer'], ...IssueUpdateRequest::fieldRules()]);
        $changes = array_intersect_key($validated, array_flip(['status', 'priority', 'assigned_to']));
        if ($changes === []) {
            return Response::error('Nothing to change: give status, priority or assigned_to.');
        }

        $issue = Issue::query()->find((int) $validated['id']);
        if ($issue === null) {
            return Response::error('No issue with this id.');
        }

        $changed = $editor->update($issue, $changes, $admin, 'mcp');
        $issue->refresh();

        return Response::structured([
            'id' => $issue->id,
            'status' => $issue->status,
            'priority' => $issue->priority,
            'assigned_to' => $issue->assigned_to !== null ? (string) $issue->assigned_to : null,
            'changed' => $changed,
        ]);
    }
}
