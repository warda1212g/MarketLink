<?php
/**
 * Chat assistant endpoint.
 * POST {message, history} -> {reply, blocked}
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/assistant.php';

api_run(function () {
    require_method('POST');
    header('Cache-Control: no-store');
    if (!csrf_valid()) {
        json_error('Your session expired. Refresh the page and try again.', 419);
    }

    // simple per-session rate limit: 20 questions / 10 minutes
    $now = time();
    $hits = array_filter($_SESSION['assistant_hits'] ?? [], fn($t) => $t > $now - 600);
    if (count($hits) >= 20) {
        json_error('You have asked a lot of questions in a short time. Please wait a few minutes and try again.', 429);
    }
    $hits[] = $now;
    $_SESSION['assistant_hits'] = array_values($hits);

    $in = request_data();
    $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 500);
    $history = [];
    foreach (array_slice(is_array($in['history'] ?? null) ? $in['history'] : [], -6) as $h) {
        $role = $h['role'] ?? '';
        $content = is_string($h['content'] ?? null) ? mb_substr(trim($h['content']), 0, 1500) : '';
        if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
            $history[] = ['role' => $role, 'content' => $content];
        }
    }

    $user = current_user();
    if ($user && !in_array($user['role'], ['customer', 'vendor'], true)) {
        $user = null; // admins get public answers only; admin reporting AI is separate
    }
    $res = assistant_answer($message, $history, $user);
    json_ok(['reply' => $res['reply'], 'blocked' => $res['blocked'], 'unavailable' => !empty($res['error'])]);
});
