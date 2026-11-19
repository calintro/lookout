<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Lookout\Models\Issue;
use Lookout\Services\IssueEditor;
use Lookout\Services\Issues;

/**
 * Adds a comment to an issue, as the acting user, marked as written through MCP.
 */
class CommentIssueTool extends WriteTool
{
    protected string $name = 'comment_issue';

    protected string $description = <<<'MARKDOWN'
        Add a comment (markdown, up to 10000 characters) to a NightOwl issue, for example what you found or changed. It shows under your name with an MCP badge.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Issue id from list_issues.')->required(),
            'body' => $schema->string()->description('The comment, markdown.')->required(),
        ];
    }

    public function handle(Request $request, IssueEditor $editor): Response|ResponseFactory
    {
        $admin = $this->writer($request);
        if ($admin instanceof Response) {
            return $admin;
        }

        $validated = $request->validate(['id' => ['required', 'integer'], ...IssueEditor::commentRules()]);
        $issue = Issue::query()->find((int) $validated['id']);
        if ($issue === null) {
            return Response::error('No issue with this id.');
        }

        $comment = $editor->comment($issue, $admin, trim($validated['body']), 'mcp');

        return Response::structured(['issue_id' => $issue->id, 'comment' => Issues::comment($comment)]);
    }
}
