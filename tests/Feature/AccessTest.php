<?php

use Lookout\Lookout;

it('answers 404 while Lookout is disabled, following nightowl.enabled when unset', function () {
    $this->actingAs($this->admin());

    config(['lookout.enabled' => false]);
    $this->get('/lookout')->assertNotFound();

    config(['lookout.enabled' => null, 'nightowl.enabled' => false]);
    $this->getJson('/lookout/api/overview')->assertNotFound();

    config(['nightowl.enabled' => true]);
    $this->get('/lookout')->assertOk();
});

it('sends guests to the login and answers the API with 401', function () {
    $this->get('/lookout')->assertRedirect('/login');
    $this->getJson('/lookout/api/overview')->assertUnauthorized();
});

it('refuses users the viewLookout gate refuses, and everyone while the gate is undefined', function () {
    $this->actingAs($this->admin(false));
    $this->get('/lookout')->assertForbidden();
    $this->getJson('/lookout/api/mcp/tokens')->assertForbidden();

    $this->actingAs($this->admin());
    config(['lookout.gate' => 'undefinedAbility']);
    $this->get('/lookout')->assertForbidden();
});

it('serves the shell on every client path with its boot data', function (string $path) {
    $admin = $this->admin();
    $this->actingAs($admin);

    $this->get($path)->assertOk()
        ->assertSee('id="lookout-app"', false)
        ->assertSee('"api_base":"\/lookout\/api"', false)
        ->assertSee('"storage_prefix":"lookout-"', false)
        ->assertSee($admin->email);
})->with(['/lookout', '/lookout/issues/3', '/lookout/requests/routes/abc']);

it('fills the user menu from config outside Nova', function () {
    $this->actingAs($this->admin());
    config(['lookout.home' => ['label' => 'Back to admin', 'url' => '/admin'], 'lookout.logout_url' => '/logout']);

    $this->get('/lookout')->assertOk()
        ->assertSee('"home":{"label":"Back to admin","url":"\/admin"}', false)
        ->assertSee('"logout_url":"\/logout"', false)
        ->assertSee('"nova_path":null', false);
});

it('lists as assignees the users the gate lets in, or the configured scope', function () {
    $admin = $this->admin();
    $this->admin(false);
    $this->actingAs($admin);

    expect(collect($this->getJson('/lookout/api/issues/assignees')->assertOk()->json('assignees'))->pluck('id')->all())->toBe([$admin->id])
        ->and(Lookout::isAssignable($admin->id))->toBeTrue();

    Lookout::assignableUsersUsing(fn ($query) => $query->where('is_admin', false));
    expect(Lookout::assignableUsers())->toHaveCount(1)->and(Lookout::isAssignable($admin->id))->toBeFalse();
    Lookout::assignableUsersUsing(null);
});
