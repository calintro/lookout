<?php

namespace Lookout\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Lookout\Services\Settings;

/**
 * Lookout settings: performance thresholds and alert channels (read by the NightOwl agent) and auto-resolve.
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->all());
    }

    public function thresholds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'thresholds' => ['present', 'array', 'max:200'],
            'thresholds.*.type' => ['required', Rule::in(Settings::THRESHOLD_TYPES)],
            'thresholds.*.target' => ['nullable', 'string', 'max:500'],
            'thresholds.*.duration_ms' => ['required', 'integer', 'min:1', 'max:3600000'],
        ]);
        $this->settings->saveThresholds(array_map(fn (array $t): array => [
            'type' => $t['type'], 'target' => ($t['target'] ?? null) ?: null, 'duration_ms' => (int) $t['duration_ms'],
        ], $validated['thresholds']));

        return response()->json(['thresholds' => $this->settings->thresholds()]);
    }

    public function autoResolve(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['present', 'nullable', 'integer', Rule::in(Settings::AUTO_RESOLVE_DAYS)]]);
        $this->settings->saveAutoResolveDays($validated['days'] !== null ? (int) $validated['days'] : null);

        return response()->json(['auto_resolve_days' => Settings::autoResolveDays()]);
    }

    public function storeChannel(Request $request): JsonResponse
    {
        $validated = $request->validate([...$this->channelRules($request->input('type'), true),
            'type' => ['required', Rule::in(Settings::CHANNEL_TYPES)]]);

        return response()->json(['channel' => $this->settings->createChannel($validated)], 201);
    }

    public function updateChannel(Request $request, int $channel): JsonResponse
    {
        $type = $this->settings->db()->table('nightowl_alert_channels')->where('id', $channel)->value('type');
        abort_if($type === null, 404);
        $updated = $this->settings->updateChannel($channel, $request->validate($this->channelRules($type, false)));

        return response()->json(['channel' => $updated]);
    }

    public function destroyChannel(int $channel): JsonResponse
    {
        abort_unless($this->settings->deleteChannel($channel), 404);

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelRules(mixed $type, bool $creating): array
    {
        $urlKey = $type === 'webhook' ? 'url' : 'webhook_url';
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'config' => [$required, 'array'],
            "config.{$urlKey}" => [$creating ? 'required' : 'sometimes', 'url:https', 'max:2000'],
            'config.secret' => ['sometimes', 'nullable', 'string', 'max:200'],
            'config.notify_events' => ['sometimes', 'nullable', 'array'],
            'config.notify_events.*' => [Rule::in(Settings::EVENTS)],
        ];
    }
}
