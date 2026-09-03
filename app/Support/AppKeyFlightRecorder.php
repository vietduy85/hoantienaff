<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Flight Recorder diagnostic for MissingAppKeyException.
 *
 * This class captures, at the moment a MissingAppKeyException is reported,
 * the precise state of the environment / .env / config WITHOUT altering how
 * Laravel loads APP_KEY and without changing the root cause. It must never
 * leak secret values (APP_KEY, passwords, tokens...) - only presence flags.
 *
 * It must also never throw, so a diagnostic failure can never make a request
 * fail differently or change the original exception flow.
 */
class AppKeyFlightRecorder
{
    protected const MARKER = 'APPKEY_DIAGNOSTIC';

    /**
     * Write a single-line APPKEY_DIAGNOSTIC entry to the default log channel.
     */
    public static function capture(?\Throwable $e = null): void
    {
        try {
            $data = [];

            // process / runtime context
            try {
                $data['pid'] = (int) getmypid();
            } catch (\Throwable $t) {
                $data['pid'] = 'n/a';
            }
            $data['sapi'] = (string) PHP_SAPI;

            // request context (path only - no query string, no secrets)
            try {
                $request = app()->bound('request') ? app('request') : null;
                if ($request !== null) {
                    $data['method'] = (string) $request->method();
                    $data['uri'] = (string) $request->path();
                } else {
                    $data['method'] = 'n/a';
                    $data['uri'] = 'n/a';
                }
            } catch (\Throwable $t) {
                $data['method'] = 'unavailable';
                $data['uri'] = 'unavailable';
            }

            // --- .env state (never log contents) ---
            try {
                $envPath = base_path('.env');
                $data['env_exists'] = file_exists($envPath) ? 'YES' : 'NO';
                $size = @filesize($envPath);
                $data['env_filesize'] = ($size === false) ? 'unreadable' : (string) $size;
                $data['env_readable'] = is_readable($envPath) ? 'YES' : 'NO';
                $read = @file_get_contents($envPath);
                if ($read === false) {
                    $data['env_read_success'] = 'NO';
                    $data['env_read_length'] = 'n/a';
                } else {
                    $data['env_read_success'] = 'YES';
                    $data['env_read_length'] = (string) strlen($read);
                }
            } catch (\Throwable $t) {
                $data['env_exists'] = 'unavailable';
                $data['env_filesize'] = 'unavailable';
                $data['env_readable'] = 'unavailable';
                $data['env_read_success'] = 'unavailable';
                $data['env_read_length'] = 'unavailable';
            }

            // --- environment shadowing (presence ONLY; value never logged) ---
            foreach (['APP_KEY', 'APP_ENV'] as $name) {
                $data['server_'.$name] = self::presence($_SERVER[$name] ?? null);
                $data['env_'.$name] = self::presence($_ENV[$name] ?? null);
                $data['getenv_'.$name] = self::presence(getenv($name));
            }

            // --- Laravel config state ---
            try {
                $key = config('app.key');
                $data['config_app_key'] = (is_string($key) && $key !== '') ? 'SET' : 'EMPTY';
            } catch (\Throwable $t) {
                $data['config_app_key'] = 'unavailable';
            }
            try {
                $cached = app()->getCachedConfigPath();
                $data['config_cached'] = (is_string($cached) && file_exists($cached)) ? 'YES' : 'NO';
            } catch (\Throwable $t) {
                $data['config_cached'] = 'unavailable';
            }
            $data['exception'] = $e !== null ? get_class($e) : 'n/a';

            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                $json = 'encode-failed';
            }

            Log::error(self::MARKER.' '.$json);
        } catch (\Throwable $t) {
            // swallow: the diagnostic must never affect request handling.
        }
    }

    /**
     * Report environment presence only as ABSENT / PRESENT_EMPTY / PRESENT_NONEMPTY.
     */
    protected static function presence($v): string
    {
        if ($v === null) {
            return 'ABSENT';
        }
        if (is_string($v) && $v === '') {
            return 'PRESENT_EMPTY';
        }

        return 'PRESENT_NONEMPTY';
    }
}
