<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Lookout\Models\Issue;

#[IsReadOnly]
class ListIssuesTool extends LookoutTool
{
    protected string $name = 'list_issues';

    protected string $description = <<<'MARKDOWN'
        List NightOwl monitoring issues (grouped exceptions and slow executions), the most recently seen first.
        Filters: status (open, resolved, ignored; default open), type (e.g. exception), environment. Returns id, status, type, class, message, occurrences, users, first/last seen (UTC).
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([...Issue::STATUSES, 'any'])->description('Issue status; "any" for all. Default open.'),
            'type' => $schema->string()->description('Issue type, e.g. exception.'),
            'environment' => $schema->string()->description('Environment, e.g. production.'),
            'limit' => $schema->integer()->description('Rows to return, 1-100. Default 20.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->admin($request) === null) {
            return Response::error('Admins only.');
        }

        $status = $request->get('status') ?? 'open';

        $issues = Issue::query()
            ->when($status !== 'any', fn ($query) => $query->where('status', $status))
            ->when($request->get('type'), fn ($query, string $type) => $query->where('type', $type))
            ->when($request->get('environment'), fn ($query, string $environment) => $query->where('environment', $environment))
            ->orderByDesc('last_seen_at')
            ->limit(self::limit($request))
            ->get()
            ->map(fn (Issue $issue): array => [
                'id' => $issue->id,
                'status' => $issue->status,
                'type' => $issue->type,
                'class' => $issue->exception_class,
                'message' => $issue->exception_message !== null ? mb_strimwidth($issue->exception_message, 0, 300, '...') : $issue->description,
                'occurrences' => $issue->occurrences_count,
                'users' => $issue->users_count,
                'environment' => $issue->environment,
                'first_seen_at' => $issue->first_seen_at?->toIso8601String(),
                'last_seen_at' => $issue->last_seen_at?->toIso8601String(),
            ])
            ->all();

        return Response::structured(['issues' => $issues, 'count' => count($issues)]);
    }
}
