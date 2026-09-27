<?php
/**
 * Google login for customers and vendors (settings in config/config.php).
 */
declare(strict_types=1);

function google_configured(): bool
{
    return trim((string) config('google.client_id', '')) !== '' && trim((string) config('google.client_secret', '')) !== '';
}

/** Must match an "Authorized redirect URI" in Google Cloud Console exactly. */
function google_redirect_uri(): string
{
    $custom = trim((string) config('google.redirect_uri', ''));
    if ($custom !== '') {
        return $custom;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('auth/google-callback.php');
}

function google_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Saves state + PKCE verifier + chosen role in the session, returns Google's consent URL. */
function google_begin(string $from, string $role): string
{
    $verifier = google_b64url(random_bytes(32));
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth'] = [
        'state'    => $state,
        'verifier' => $verifier,
        'from'     => $from === 'register' ? 'register' : 'login',
        'role'     => $role === 'vendor' ? 'vendor' : 'customer',
        'time'     => time(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'             => config('google.client_id'),
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'code_challenge'        => google_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** @return array{0:int,1:string,2:string} [status, body, curl error] */
function google_http(string $url, array $options): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

/** Exchanges ?code for tokens. Returns [tokens|null, error]. */
function google_exchange_code(string $code, string $verifier): array
{
    [$status, $body, $err] = google_http('https://oauth2.googleapis.com/token', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code'          => $code,
            'client_id'     => config('google.client_id'),
            'client_secret' => config('google.client_secret'),
            'redirect_uri'  => google_redirect_uri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    if ($err !== '') {
        return [null, 'Could not reach Google (' . $err . ').'];
    }
    $data = json_decode($body, true);
    if ($status !== 200 || empty($data['access_token'])) {
        $detail = is_array($data) ? trim(($data['error'] ?? '') . ' ' . ($data['error_description'] ?? '')) : '';
        return [null, 'Google rejected the sign-in' . ($detail !== '' ? ' (' . $detail . ')' : '') . '.'];
    }
    return [$data, ''];
}

/** Returns [profile|null, error]. */
function google_userinfo(string $accessToken): array
{
    [$status, $body, $err] = google_http('https://openidconnect.googleapis.com/v1/userinfo', [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
    ]);
    if ($err !== '') {
        return [null, 'Could not reach Google (' . $err . ').'];
    }
    $data = json_decode($body, true);
    if ($status !== 200 || !is_array($data) || empty($data['email']) || empty($data['sub'])) {
        return [null, 'Could not read your Google profile.'];
    }
    return [$data, ''];
}

/** One-time error shown on the login / register page. */
function google_fail(string $from, string $message): void
{
    $_SESSION['auth_error'] = $message;
    redirect($from === 'register' ? 'auth/register.php' : 'auth/login.php');
}

function google_icon(): void
{
    echo '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
}

/** Keeps the Google button's ?role= in sync with the Customer / Vendor choice. */
function role_pick_script(): void
{ ?>
      <script>
        (function () {
          var btn = document.getElementById('googleBtn');
          if (!btn) return;
          document.querySelectorAll('input[name="role"]').forEach(function (r) {
            r.addEventListener('change', function () {
              if (r.checked) btn.href = btn.href.replace(/role=(customer|vendor)/, 'role=' + r.value);
            });
          });
        })();
      </script>
<?php }
