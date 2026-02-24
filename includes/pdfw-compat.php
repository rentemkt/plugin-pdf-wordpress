<?php
/**
 * Safe mbstring wrappers — fallback to native PHP functions if extension is absent.
 */

if (! defined('ABSPATH')) {
    exit;
}

function pdfw_mb_strlen(string $s, string $enc = 'UTF-8'): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, $enc) : strlen($s);
}

function pdfw_mb_substr(string $s, int $start, ?int $len = null, string $enc = 'UTF-8'): string
{
    if (function_exists('mb_substr')) {
        return $len === null ? mb_substr($s, $start, null, $enc) : mb_substr($s, $start, $len, $enc);
    }
    return $len === null ? substr($s, $start) : substr($s, $start, $len);
}

function pdfw_mb_strtolower(string $s, string $enc = 'UTF-8'): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, $enc) : strtolower($s);
}

function pdfw_mb_strtoupper(string $s, string $enc = 'UTF-8'): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, $enc) : strtoupper($s);
}
