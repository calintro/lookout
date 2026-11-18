<?php

namespace Lookout\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The prebuilt SPA (dist/app.js, dist/app.css) straight from the package, so an app needs neither npm nor a publish
 * step. Behind the same middleware as the shell; the URL carries the file's mtime, so it can be cached for long.
 */
class AssetController extends Controller
{
    public const DIST = __DIR__.'/../../../dist';

    public function __invoke(string $file): BinaryFileResponse
    {
        $path = self::DIST.'/'.$file;
        abort_unless(in_array($file, ['app.js', 'app.css'], true) && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.js') ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
