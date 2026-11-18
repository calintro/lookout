<?php

use Lookout\Http\Controllers\AssetController;

it('keeps dist/ in sync with the SPA sources', function () {
    $root = dirname(__DIR__, 2);
    $built = file_get_contents($root.'/dist/app.js');

    foreach (glob($root.'/resources/js/*.js') as $source) {
        expect(str_contains($built, file_get_contents($source)))->toBeTrue(basename($source).' changed: run bin/build.sh');
    }
    expect(str_contains(file_get_contents($root.'/dist/app.css'), file_get_contents($root.'/resources/css/app.css')))->toBeTrue('app.css changed: run bin/build.sh');
});

it('serves the built SPA from the package with a cache-busting version, behind the access check', function () {
    $this->actingAs($this->admin());

    $this->get('/lookout')->assertOk()
        ->assertSee('/lookout/assets/app.js?v='.filemtime(AssetController::DIST.'/app.js'), false)
        ->assertSee('/lookout/assets/app.css?v=', false);
    $this->get('/lookout/assets/app.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    $this->get('/lookout/assets/app.css')->assertOk();
    $this->get('/lookout/assets/other.js')->assertOk()->assertSee('id="lookout-app"', false);

    $this->actingAs($this->admin(false));
    $this->get('/lookout/assets/app.js')->assertForbidden();
});
