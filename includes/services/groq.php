<?php
/**
 * Groq API call. Returns the reply text or null if something fails.
 */
declare(strict_types=1);

function groq_enabled(): bool
{
    return trim((string) config('groq.api_key', '')) !== '';
}

/**
 * @param array $messages [['role' => 'user'|'assistant', 'content' => '...'], ...] (system prompt passed separately)
 */
function groq_chat(string $system, array $messages, int $maxTokens = 1500): ?string
{
    if (!groq_enabled() || !function_exists('curl_init')) {
        return null;
    }
    $models = array_values(array_unique(array_filter([
        (string) config('groq.model', 'openai/gpt-oss-120b'),
        (string) config('groq.fallback', ''),
    ])));
    $payloadMessages = array_merge([['role' => 'system', 'content' => $system]], $messages);

    foreach ($models as $model) {
        $ch = curl_init((string) config('groq.endpoint', 'https://api.groq.com/openai/v1/chat/completions'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) config('groq.timeout', 45),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim((string) config('groq.api_key')),
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'            => $model,
                'messages'         => $payloadMessages,
                'temperature'      => 0.2,
                // gpt-oss reasons before answering; reasoning tokens count toward this limit
                'max_tokens'       => $maxTokens,
                'reasoning_effort' => 'low',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $status !== 200) {
            error_log('Groq request failed (' . $model . '): HTTP ' . $status);
            continue;
        }
        $text = json_decode((string) $raw, true)['choices'][0]['message']['content'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }
    }
    return null;
}
