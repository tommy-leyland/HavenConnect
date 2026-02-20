<?php

if (!defined('ABSPATH')) exit;

/**
 * helpers.php
 *
 * Small utility helpers used (or useful) across HavenConnect.
 * Keep this file tiny and framework-agnostic.
 */

/**
 * Safe array getter with dot-notation:
 *   hcn_array_get($arr, 'address.city', 'Unknown')
 *
 * @param array|ArrayAccess|null $array
 * @param string|array $path
 * @param mixed $default
 * @return mixed
 */
function hcn_array_get($array, $path, $default = null) {
    if (!is_array($array) && !($array instanceof ArrayAccess)) return $default;
    if ($path === null || $path === '') return $default;

    $keys = is_array($path) ? $path : explode('.', $path);
    $cursor = $array;

    foreach ($keys as $key) {
        if (is_array($cursor) && array_key_exists($key, $cursor)) {
            $cursor = $cursor[$key];
        } elseif ($cursor instanceof ArrayAccess && isset($cursor[$key])) {
            $cursor = $cursor[$key];
        } else {
            return $default;
        }
    }
    return $cursor;
}

/**
 * Simple assoc array check.
 *
 * @param mixed $arr
 * @return bool
 */
function hcn_is_assoc($arr): bool {
    if (!is_array($arr)) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * Quick URL sanitizer wrapper.
 *
 * @param string $url
 * @return string
 */
function hcn_sanitize_url(string $url): string {
    $url = trim($url);
    return esc_url_raw($url);
}

/**
 * Convert numeric-like strings to int, otherwise return as-is or default.
 *
 * @param mixed $value
 * @param mixed $default
 * @return mixed
 */
function hcn_maybe_int($value, $default = null) {
    if (is_numeric($value)) {
        return (int) $value;
    }
    return $value !== null && $value !== '' ? $value : $default;
}

/**
 * Normalize a tag string by collapsing "[x] " → "[x]" and trimming.
 *
 * @param string $tag
 * @return string
 */
function hcn_normalize_tag(string $tag): string {
    $tag = trim($tag);
    return preg_replace('/^\[(l|g)\]\s+/i', '[$1]', $tag);
}