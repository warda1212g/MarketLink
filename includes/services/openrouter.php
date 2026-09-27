<?php
/**
 * OpenRouter API (optional). Put the key in config/config.php.
 * If there is no key or the request fails, the site uses its own text instead.
 */

require_once APP_ROOT . '/includes/services/ai_features.php';

/** True when an OpenRouter API key is configured. */
function openrouter_enabled(): bool
{
    return trim((string) config('openrouter.api_key', '')) !== '';
}

/**
 * Get text from the model, or null if it fails.
 */
function openrouter_generate(string $prompt, string $system = '', int $maxTokens = 400): ?string
{
    if (!openrouter_enabled()) {
        return null;
    }

    // don't let the AI slow the page down: limit the time, and skip it for a while after a failure
    static $started = null;
    $started = $started ?? microtime(true);
    $budget = (float) config('openrouter.page_budget', 12);
    if (microtime(true) - $started > $budget || openrouter_is_down()) {
        return null;
    }

    $key = trim((string) config('openrouter.api_key'));
    $endpoint = trim((string) config('openrouter.endpoint', 'https://openrouter.ai/api/v1/chat/completions'));
    $timeout = min(10, (int) config('openrouter.timeout', 10));

    // try the main model, then the fallbacks
    $models = array_values(array_unique(array_merge(
        [trim((string) config('openrouter.model', 'nvidia/nemotron-3-super-120b-a12b:free'))],
        (array) config('openrouter.fallbacks', [])
    )));

    $messages = [];
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $payload = json_encode([
        'messages'    => $messages,
        'max_tokens'  => max(50, min(1000, $maxTokens)),
        'temperature' => 0.4,
    ]);

    $models = array_slice($models, 0, 2); // primary + one fallback is enough
    foreach ($models as $model) {
        if (microtime(true) - $started > $budget) {
            break;
        }
        if ($model === '') {
            continue;
        }
        $body = json_decode($payload, true);
        $body['model'] = $model;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                // OpenRouter attribution and response cache.
                'HTTP-Referer: ' . (config('app.base_url') ?: 'http://localhost'),
                'X-Title: MarketLink',
                'X-OpenRouter-Cache: true',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status !== 200) {
            continue; // try the next fallback model
        }
        $data = json_decode((string) $raw, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }
    }

    openrouter_mark_down(); // pause AI calls for a few minutes so pages stay fast
    return null; // all models failed - caller falls back to rule-based text
}

function openrouter_down_file(): string
{
    return APP_ROOT . '/storage/cache/ai/_down.flag';
}

function openrouter_is_down(): bool
{
    $f = openrouter_down_file();
    return is_file($f) && (time() - (int) filemtime($f)) < 300;
}

function openrouter_mark_down(): void
{
    $dir = dirname(openrouter_down_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @touch(openrouter_down_file());
}

/**
 * Same as above but cached in storage/cache/ai/
 */
function openrouter_cached(string $cacheKey, string $prompt, string $system = '', int $maxTokens = 400): ?string
{
    $dir = APP_ROOT . '/storage/cache/ai';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/or_' . sha1($cacheKey) . '.txt';
    $ttl = (int) config('openrouter.cache_hours', 12) * 3600;

    if (is_file($file)) {
        $mtime = (int) filemtime($file);
        if ($mtime > 0 && (time() - $mtime) < $ttl) {
            $cached = @file_get_contents($file);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }
    }

    $text = openrouter_generate($prompt, $system, $maxTokens);
    if ($text !== null) {
        @file_put_contents($file, $text);
    }
    return $text;
}