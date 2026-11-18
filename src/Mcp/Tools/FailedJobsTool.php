<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Lookout\Services\Performance;

#[IsReadOnly]
class FailedJobsTool extends LookoutTool
{
    protected string $name = 'failed_jobs';

    protected string $description = <<<'MARKDOWN'
        Queued job classes with at least one failure over the last N hours, the most failures first, from NightOwl rollups: attempts, processed, failed, released, average and p95 duration (ms), queue.
        For the exception behind a failure, look for the job's issue with list_issues and get_issue.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()->description('Window in hours, 1-9600. Default 24.'),
            'limit' => $schema->integer()->description('Rows to return, 1-100. Default 20.'),
        ];
    }

    public function handle(Request $request, Performance $performance): Response|ResponseFactory
    {
        if ($this->admin($request) === null) {
            return Response::error('Admins only.');
        }

        $hours = self::hours($request);

        return Response::structured([
            'hours' => $hours,
            'jobs' => $performance->jobs($hours, self::limit($request), true),
        ]);
    }
}
