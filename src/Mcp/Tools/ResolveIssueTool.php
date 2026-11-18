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
 * The only write tool of the Lookout MCP server: marks an issue resolved (the agent reopens it if it recurs). Needs a
 * credential with mcp_can_resolve (AuthenticateMcpToken): the shared token, or a personal token with can_resolve.
 */
#[IsIdempotent]
class ResolveIssueTool extends LookoutTool
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
        $admin = $this->admin($request);

        if ($admin === null) {
            return Response::error('Admins only.');
        }

        if (request()->attributes->get('mcp_can_resolve') !== true) {
            return Response::error('This monitoring token is read-only. Allow "can resolve" on it in Lookout settings.');
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
