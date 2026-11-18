<?php

namespace Lookout\Contracts;

/**
 * Where Lookout keeps its own settings (today: the auto-resolve days). The default stores them in the
 * NightOwl database (nightowl_settings); an app can bind its own store to keep them next to its other settings.
 */
interface SettingStore
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, string $description = ''): void;
}
