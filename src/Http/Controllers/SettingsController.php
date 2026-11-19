<?php

namespace Lookout\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Lookout\Services\Settings;
use Lookout\Services\Telegram;
use Lookout\Services\TelegramChannels;

/**
 * Lookout settings: performance thresholds and alert channels (read by the NightOwl agent), Telegram channels (sent
 * by Lookout itself) and auto-resolve.
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
            'thresholds' => ['present', 'array', 'max:'.Settings::MAX_THRESHOLDS],
            ...Settings::thresholdRules('thresholds.*.'),
        ]);
        $this->settings->saveThresholds(array_map(fn (array $t): array => [
            'type' => $t['type'], 'target' => ($t['target'] ?? null) ?: null, 'duration_ms' => (int) $t['duration_ms'],
        ], $validated['thresholds']));

        return response()->json(['thresholds' => $this->settings->thresholds()]);
    }

    public function autoResolve(Request $request): JsonResponse
    {
        $validated = $request->validate(Settings::autoResolveRules());
        $this->settings->saveAutoResolveDays($validated['days'] !== null ? (int) $validated['days'] : null);

        return response()->json(['auto_resolve_days' => Settings::autoResolveDays()]);
    }

    public function storeChannel(Request $request): JsonResponse
    {
        $validated = $request->validate([...Settings::channelRules($request->input('type'), true),
            'type' => ['required', Rule::in(Settings::CHANNEL_TYPES)]]);

        return response()->json(['channel' => $this->settings->createChannel($validated)], 201);
    }

    public function updateChannel(Request $request, int $channel): JsonResponse
    {
        $type = $this->settings->db()->table('nightowl_alert_channels')->where('id', $channel)->value('type');
        abort_if($type === null, 404);
        $updated = $this->settings->updateChannel($channel, $request->validate(Settings::channelRules($type, false)));

        return response()->json(['channel' => $updated]);
    }

    public function destroyChannel(int $channel): JsonResponse
    {
        abort_unless($this->settings->deleteChannel($channel), 404);

        return response()->json(['deleted' => true]);
    }

    public function storeTelegram(Request $request, TelegramChannels $channels): JsonResponse
    {
        return response()->json(['channel' => $channels->create($request->validate(TelegramChannels::rules(true)))], 201);
    }

    public function updateTelegram(Request $request, TelegramChannels $channels, int $channel): JsonResponse
    {
        abort_if($channels->find($channel) === null, 404);

        return response()->json(['channel' => $channels->update($channel, $request->validate(TelegramChannels::rules(false)))]);
    }

    public function destroyTelegram(TelegramChannels $channels, int $channel): JsonResponse
    {
        abort_unless($channels->delete($channel), 404);

        return response()->json(['deleted' => true]);
    }

    /**
     * Sends a test message now, whatever the channel's events and enabled flag; 422 with Telegram's own error text.
     */
    public function testTelegram(TelegramChannels $channels, Telegram $telegram, int $channel): JsonResponse
    {
        $stored = $channels->find($channel);
        abort_if($stored === null, 404);
        $error = $telegram->send($stored, $telegram->testMessage());

        return $error === null
            ? response()->json(['sent' => true])
            : response()->json(['sent' => false, 'message' => 'Telegram: '.$error], 422);
    }
}
