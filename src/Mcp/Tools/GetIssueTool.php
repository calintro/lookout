<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Lookout\Models\Issue;
use Lookout\Services\IssueOccurrence;

#[IsReadOnly]
class GetIssueTool extends LookoutTool
{
    protected string $name = 'get_issue';

    protected string $description = <<<'MARKDOWN'
        One NightOwl issue by id, with its latest occurrence for an exception issue: class, message, file:line, handled flag, time (UTC) and the stack trace (frames "file:line" with the calling function).
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Issue id from list_issues.')->required(),
            'max_frames' => $schema->integer()->description('Stack frames to return, 1-200. Default 50.'),
        ];
    }

    public function handle(Request $request, IssueOccurrence $occurrences): Response|ResponseFactory
    {
        if ($this->admin($request) === null) {
            return Response::error('Admins only.');
        }

        $request->validate(['id' => ['required', 'integer']]);

        $issue = Issue::query()->find((int) $request->get('id'));

        if ($issue === null) {
            return Response::error('No issue with this id.');
        }

        $occurrence = $occurrences->latest($issue);

        if ($occurrence !== null) {
            $frames = max(1, min((int) ($request->get('max_frames') ?? 50), 200));
            $occurrence['trace_frames_total'] = count($occurrence['trace']);
            $occurrence['trace'] = array_slice($occurrence['trace'], 0, $frames);
        }

        return Response::structured([
            'id' => $issue->id,
            'status' => $issue->status,
            'type' => $issue->type,
            'subtype' => $issue->subtype,
            'class' => $issue->exception_class,
            'message' => $issue->exception_message,
            'description' => $issue->description,
            'occurrences' => $issue->occurrences_count,
            'users' => $issue->users_count,
            'environment' => $issue->environment,
            'threshold_ms' => $issue->threshold_ms,
            'triggered_duration_ms' => $issue->triggered_duration_ms,
            'first_seen_at' => $issue->first_seen_at?->toIso8601String(),
            'last_seen_at' => $issue->last_seen_at?->toIso8601String(),
            'latest_occurrence' => $occurrence,
        ]);
    }
}
