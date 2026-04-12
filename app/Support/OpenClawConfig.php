<?php

namespace App\Support;

class OpenClawConfig
{
    /**
     * JSON-encode an OpenClaw config array, ensuring empty associative arrays
     * serialize as {} (objects) not [] (arrays).
     *
     * PHP's json_encode outputs [] for empty arrays, but OpenClaw expects {}
     * for config keys like channels, plugins, tools, auth, etc.
     */
    public static function toJson(array $config): string
    {
        // Keys that OpenClaw expects as objects (not arrays) when empty
        $objectKeys = ['channels', 'plugins', 'tools', 'auth', 'env', 'gateway', 'browser', 'logging', 'messages', 'session', 'routing'];

        foreach ($objectKeys as $key) {
            if (isset($config[$key]) && $config[$key] === []) {
                $config[$key] = (object) [];
            }
        }

        // Also fix nested entries that should be objects
        if (isset($config['plugins']['entries']) && $config['plugins']['entries'] === []) {
            $config['plugins']['entries'] = (object) [];
        }

        if (isset($config['skills']['entries']) && $config['skills']['entries'] === []) {
            $config['skills']['entries'] = (object) [];
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
