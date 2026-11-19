<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Lookout\Models\Issue;
use Lookout\Services\IssueStatus;

/**
 * Marks an issue resolved (the agent reopens it if it recurs). A write tool: needs write access.
 */
#[IsIdempotent]
class ResolveIssueTool extends WriteTool
{
    protected string $name = 'resolve_issue';

    protected string $description = <<<'MARKDOWN'
        Mark a NightOwl issue as resolved. Writes an activity entry with your name. If the error happens again, the agent reopens the issue.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Issue id from list_issues.')->required(),
        ];
    }

    public function handle(Request $request, IssueStatus $statuses): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $request->validate(['id' => ['required', 'integer']]);

        $issue = Issue::query()->find((int) $request->get('id'));

        if ($issue === null) {
            return Response::error('No issue with this id.');
        }

        $changed = $statuses->change($issue, 'resolved', $admin, 'mcp');

        return Response::structured(['id' => $issue->id, 'status' => $issue->status, 'changed' => $changed]);
    }
}
