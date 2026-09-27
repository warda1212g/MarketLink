<?php
/**
 * Shared helpers: escaping, URLs, JSON responses, validation, uploads, formatting.
 */
declare(strict_types=1);

const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const DAY_KEYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
const PRODUCT_UNITS = ['kg', 'g', 'dozen', 'piece', 'bundle', 'litre', 'pack', 'box'];

const ORDER_STATUSES = ['pending', 'accepted', 'preparing', 'ready', 'picked_up', 'completed', 'declined', 'cancelled'];

/**
 * Status changes a vendor is allowed to make.
 * ready -> picked_up is done by the customer, then the vendor can complete it.
 */
const ORDER_TRANSITIONS = [
    'pending'   => ['accepted', 'declined'],
    'accepted'  => ['preparing', 'ready', 'cancelled'],
    'preparing' => ['ready', 'cancelled'],
    'ready'     => ['cancelled'],
    'picked_up' => ['completed'],
];

/* output / urls */

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = config('app.base_url');
    if ($configured !== null) {
        return $base = rtrim($configured, '/');
    }
    $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root    = str_replace('\\', '/', (string) realpath(APP_ROOT));
    if ($docRoot !== '' && stripos($root, $docRoot) === 0) {
        $base = substr($root, strlen($docRoot));
    } else {
        $base = '';
    }
    $base = trim($base, '/');
    return $base = ($base === '' ? '' : '/' . $base);
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url($path) . ($v ? '?v=' . $v : '');
}

function upload_url(?string $path): ?string
{
    return $path ? url($path) : null;
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* flash messages */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/* Json api */

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = [], string $message = ''): void
{
    json_response(['ok' => true, 'message' => $message] + $data);
}

function json_error(string $message, int $status = 400, array $errors = []): void
{
    json_response(['ok' => false, 'message' => $message, 'errors' => (object) $errors], $status);
}

/** Wrap an API handler so uncaught exceptions become JSON errors. */
function api_run(callable $handler): void
{
    try {
        $handler();
    } catch (ValidationException $ex) {
        json_error($ex->getMessage(), 422, $ex->errors);
    } catch (Throwable $ex) {
        error_log('[MarketLink API] ' . $ex);
        json_error(config('app.debug') ? $ex->getMessage() : 'Something went wrong. Please try again.', 500);
    }
}

/** Read request body: JSON or form-encoded. */
function request_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($decoded) ? $decoded : [];
    } else {
        $data = $_POST;
    }
    return $data;
}

/* validation */

class ValidationException extends RuntimeException
{
    public array $errors;

    public function __construct(array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }
}

function str_input(array $src, string $key, int $max = 255): string
{
    $v = isset($src[$key]) && is_scalar($src[$key]) ? trim((string) $src[$key]) : '';
    return mb_substr($v, 0, $max);
}

function int_input(array $src, string $key, ?int $default = null): ?int
{
    if (!isset($src[$key]) || $src[$key] === '' || !is_scalar($src[$key])) {
        return $default;
    }
    $v = filter_var($src[$key], FILTER_VALIDATE_INT);
    return $v === false ? $default : (int) $v;
}

function float_input(array $src, string $key, ?float $default = null): ?float
{
    if (!isset($src[$key]) || $src[$key] === '' || !is_scalar($src[$key])) {
        return $default;
    }
    $v = filter_var($src[$key], FILTER_VALIDATE_FLOAT);
    return $v === false ? $default : (float) $v;
}

function valid_time(string $t): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/* image uploads */

/**
 * Validate and store an uploaded image. Returns relative path (uploads/...) or null if no file.
 * Throws ValidationException on invalid files.
 */
function store_uploaded_image(string $field, string $folder): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if (is_array($file['error'])) {
        throw new ValidationException([$field => 'Upload one image only.']);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ValidationException([$field => 'The image could not be uploaded (error ' . (int) $file['error'] . ').']);
    }
    $max = (int) config('uploads.max_bytes');
    if ($file['size'] <= 0 || $file['size'] > $max) {
        throw new ValidationException([$field => 'Image must be smaller than ' . round($max / 1048576, 1) . ' MB.']);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ValidationException([$field => 'Invalid upload.']);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = config('uploads.allowed');
    if (!isset($allowed[$mime])) {
        throw new ValidationException([$field => 'Only JPG, PNG or WEBP images are allowed.']);
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000) {
        throw new ValidationException([$field => 'The file is not a valid image.']);
    }

    $folder = trim($folder, '/');
    $dir = APP_ROOT . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload directory is not writable.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    return 'uploads/' . $folder . '/' . $name;
}

function delete_upload(?string $path): void
{
    if (!$path || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $full = APP_ROOT . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/* formatting */

function money($amount, bool $decimals = false): string
{
    return config('app.currency', 'Rs.') . ' ' . number_format((float) $amount, $decimals ? 2 : 0);
}

function qty($n): string
{
    $n = (float) $n;
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function fmt_time(?string $t): string
{
    return $t ? date('g:i A', strtotime($t)) : '';
}

function fmt_date(?string $d, string $format = 'M j, Y'): string
{
    return $d ? date($format, strtotime($d)) : '';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 172800) return 'yesterday';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return fmt_date($datetime);
}

function pickup_label(?string $date, ?string $start, ?string $end): string
{
    if (!$date) return '';
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $day = $date === $today ? 'Today' : ($date === $tomorrow ? 'Tomorrow' : date('D, M j', strtotime($date)));
    $time = $start ? fmt_time($start) . ($end ? ' - ' . fmt_time($end) : '') : '';
    return trim($day . ' ' . $time);
}

/** Convert a SET('mon',...) value to an array of ISO day numbers. */
function days_from_set(?string $set): array
{
    if (!$set) return [];
    $flip = array_flip(DAY_KEYS);
    $out = [];
    foreach (explode(',', $set) as $k) {
        if (isset($flip[$k])) $out[] = $flip[$k];
    }
    sort($out);
    return $out;
}

function days_short_label(array $days): string
{
    if (!$days) return 'Not set';
    return implode(', ', array_map(fn($d) => substr(DAYS[$d], 0, 3), $days));
}

function status_label(string $status): string
{
    return ['pending' => 'New', 'declined' => 'Rejected', 'ready' => 'Ready for Pickup', 'picked_up' => 'Picked Up'][$status] ?? ucfirst($status);
}

function order_number(int $id): string
{
    return 'ML' . str_pad((string) (1000 + $id), 4, '0', STR_PAD_LEFT);
}
