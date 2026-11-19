<?php

namespace Lookout\Mcp;

use Generator;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool as BaseCallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Lookout\Mcp\Tools\WriteTool;

/**
 * tools/call of the Lookout server. Write tools are not registered for a read-only credential, so laravel/mcp would
 * answer "Tool not found"; this says why instead.
 */
class CallTool extends BaseCallTool
{
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->params['name'] ?? null;

        if (is_string($name) && ! WriteTool::canWrite() && in_array($name, LookoutServer::writeToolNames(), true)) {
            throw new JsonRpcException("Tool [{$name}] changes monitoring and needs write access. ".WriteTool::READ_ONLY, -32602, $request->id);
        }

        return parent::handle($request, $context);
    }
}
