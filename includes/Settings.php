<?php
namespace CEWP;
if ( ! defined('ABSPATH') ) exit;

/** Shared settings schema; existing core response keys and defaults stay unchanged. */
class Settings {
    private static ?array $cached = null;
    private static array $listeners = [];

    public static function schema(): array {
        global $wp_filter;
        $listeners = $wp_filter['cewp_settings_schema']->callbacks ?? [];
        if ( self::$cached !== null && self::$listeners === $listeners ) return self::$cached;
        $core = [];
        foreach ( ['show_retail_to_wholesale','hide_retail_from_wholesale','disallow_coupons','catalog_mode','delete_on_uninstall'] as $key ) {
            $core[$key] = ['type'=>'boolean','default'=>false,'sanitize_callback'=>'rest_sanitize_boolean','owner'=>'core'];
        }
        $schema = Pricing::filtered('cewp_settings_schema', $core, [], static function (&$next) use ($core) {
            if ( ! is_array($next) ) return false;
            $valid = $core;
            foreach ( $next as $key => $entry ) {
                if ( isset($core[$key]) || ! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]+$/D', $key)
                    || preg_match('/secret|password|license|token|credential/i', $key) || ! is_array($entry) ) continue;
                if ( ! isset($entry['type'], $entry['sanitize_callback'], $entry['owner']) || ! array_key_exists('default', $entry)
                    || ! is_callable($entry['sanitize_callback']) || ! is_string($entry['owner'])
                    || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $entry['owner']) || $entry['owner'] === 'core'
                    || ! str_starts_with($key, str_replace('-', '_', $entry['owner']) . '_') ) continue;
                if ( $entry['type'] === 'array' && ! is_callable($entry['validate_callback'] ?? null) ) continue;
                if ( ! self::valid($entry['default'], $entry) ) continue;
                $valid[$key] = $entry;
            }
            $next = $valid;
            return true;
        });
        self::$listeners = $listeners;
        return self::$cached = $schema;
    }

    public static function valid($value, array $entry): bool {
        $valid = match ($entry['type']) {
            'boolean' => is_bool($value) || in_array($value, [0,1,'0','1'], true),
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float)$value),
            'string' => is_string($value),
            'array' => is_array($value) && is_callable($entry['validate_callback'] ?? null),
            default => false,
        };
        return $valid && (! isset($entry['validate_callback']) || (bool)call_user_func($entry['validate_callback'], $value));
    }
}
