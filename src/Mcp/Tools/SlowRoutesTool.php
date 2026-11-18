<?php

namespace Lookout\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Lookout\Services\Performance;

#[IsReadOnly]
class SlowRoutesTool extends LookoutTool
{
    protected string $name = 'slow_routes';

    protected string $description = <<<'MARKDOWN'
        HTTP routes over the last N hours from NightOwl rollups: calls, 4xx and 5xx counts, error rate (%), average and p95 duration (ms).
        Sort by p95 (default), avg_ms, calls or error_rate.
    MARKDOWN;

    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()->description('Window in hours, 1-9600. Default 24.'),
            'limit' => $schema->integer()->description('Rows to return, 1-100. Default 20.'),
            'sort' => $schema->string()->enum(['p95', 'avg_ms', 'calls', 'error_rate'])->description('Sort order. Default p95.'),
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
            'routes' => $performance->routes($hours, self::limit($request), (string) ($request->get('sort') ?? 'p95')),
        ]);
    }
}
