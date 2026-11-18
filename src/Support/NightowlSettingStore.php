<?php

namespace Lookout\Support;

use Illuminate\Support\Facades\DB;
use Lookout\Contracts\SettingStore;
use Lookout\Lookout;

/**
 * Settings as JSON values in nightowl_settings, the agent's own key/value table (the agent reads only its own keys).
 */
class NightowlSettingStore implements SettingStore
{
    public function get(string $key): mixed
    {
        $raw = DB::connection(Lookout::connection())->table('nightowl_settings')->where('key', $key)->value('value');

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    public function put(string $key, mixed $value, string $description = ''): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        DB::connection(Lookout::connection())->table('nightowl_settings')->upsert(
            [['key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]],
            ['key'], ['value', 'updated_at'],
        );
    }
}
