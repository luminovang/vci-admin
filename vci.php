<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// ══════════════════════════════════════════════════════════════
// PATHS — defined before everything so constants can reference them
// ══════════════════════════════════════════════════════════════
define('PROJECT_APP_ROOT',     __DIR__ . '/../');
define('PATH_CONFIG_FILE',     PROJECT_APP_ROOT . '.luminova.admin.php');
define('PATH_APP_CONFIG_FILE', PROJECT_APP_ROOT . '.luminova.php');
define('PATH_COMPOSER_JSON',   PROJECT_APP_ROOT . 'composer.json');
define('PATH_TMP',             PROJECT_APP_ROOT . 'writeable/vci-tmp');
define('PATH_LOGS',            PROJECT_APP_ROOT . 'writeable/logs/vci.log');

if (!is_dir(PATH_TMP)) {
    mkdir(PATH_TMP, 0777, true);
}

chmod(PATH_TMP, 01777);
ini_set('sys_temp_dir', PATH_TMP);
putenv('TMPDIR=' . PATH_TMP);
putenv('TMP=' . PATH_TMP);
putenv('TEMP=' . PATH_TMP);

// ══════════════════════════════════════════════════════════════
// Error handling
// ══════════════════════════════════════════════════════════════
ini_set('error_log', PATH_LOGS);

/**
 * Append a formatted log entry to the VCI error log file.
 *
 * Writes a timestamped line in the form:
 * {@code [SEVERITY YYYY-MM-DD HH:MM:SS] message in file:line}
 *
 * @param string $message  Human-readable error description.
 * @param string $severity Severity label (e.g. 'ERROR', 'FATAL').
 *                         Pass an empty string to omit the label.
 * @param string $file     Absolute path of the file where the error occurred.
 * @param int    $line     Line number inside $file.
 *
 * @return bool True when the entry was written; false on write failure.
 */
function _log(string $message, string $severity, string $file, int $line): bool {
    $log = sprintf(
        "[%s%s] %s in %s:%d\n",
        $severity ? strtoupper($severity) . ' ': '',
        date('Y-m-d H:i:s'),
        $message,
        $file,
        $line
    );

    return error_log($log, 3, PATH_LOGS);
}

/**
 * Detect the absolute path of the PHP CLI executable.
 *
 * Tries {@code command -v php} first, then iterates a platform-specific list of
 * well-known paths (XAMPP, Homebrew, {@see PHP_BINDIR}, etc.). Returns the first
 * candidate that exists and is executable.
 *
 * @return string|null Absolute path to the PHP binary, or null if none is found.
 */
function _php_binary(): ?string
{
    $candidates = [
        trim((string) shell_exec('command -v php')) ?: null,
    ];

    if (PHP_OS_FAMILY === 'Windows') {
        $candidates = array_merge($candidates, [
            'C:\\php\\php.exe',
            getenv('PHP_PATH') ?: null,
            'C:\\xampp\\php\\php.exe'
        ]);
    } else {
        $candidates = array_merge($candidates, [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            PHP_BINDIR . '/php',
            'php',
            PHP_BINARY
        ]);
    }

    foreach ($candidates as $php) {
        if(!$php){
            continue;
        }

        if (is_file($php) && is_executable($php)) {
            return $php;
        }
    }

    return null;
}

set_error_handler(function ($severity, $message, $file, $line) {
    _log($message, 'ERROR', $file, $line);
    return true;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        _log($error['message'], 'FATAL', $error['file'], $error['line']);
    }
});

// ══════════════════════════════════════════════════════════════
// BOOTSTRAP ADMIN CONFIG
// Values stored in PATH_CONFIG_FILE override the defaults below.
// ══════════════════════════════════════════════════════════════
$_admin = [];

if (@is_file(PATH_CONFIG_FILE)) {
    $_admin = include PATH_CONFIG_FILE;
}

// ══════════════════════════════════════════════════════════════
// STATIC CONFIGURATION
//
// Generate a password hash:
//   php -r "echo password_hash('admin', PASSWORD_ARGON2ID);"
//
// All values below are overridden by PATH_CONFIG_FILE when present.
// ══════════════════════════════════════════════════════════════
define('__SELF__',            $_SERVER['PHP_SELF']);
define('PASSWORD_ALGO',       defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
define('DEFAULT_PASSWORD_HASH', (PASSWORD_ALGO === PASSWORD_ARGON2ID) 
    ? '$argon2id$v=19$m=65536,t=4,p=1$tb8BPE1vmNCPl5fST4tHzQ$V2KqGZ3T8jHvu5iUSu1oItzbMAJcTGp2cIhLoX/MzPg' 
    : '$2y$10$RaLLDqoINJGuVyU0pp22lOd0QAPkpkV7uZY7KMX5AI7NQs01ebpYW'
);
define('ADMIN_USERNAME',     'admin');
define('SESSION_LIFETIME',   3600);
define('LOGIN_MAX_ATTEMPTS',   5);
define('FAILED_LOGIN_LOCK_WINDOW_TS',   300); // 5 minutes
define('SESSION_STORAGE',    '__VCI_MANAGER__');

 // 1 (enable), 2 (enable and delete old session id)
define('SESSION_REGENERATE_ID',    0);
define('ADMIN_PASSWORD_HASH',  $_admin['password_hash'] ?? DEFAULT_PASSWORD_HASH);
define('COMPOSER_BIN',         $_admin['composer_bin'] ?? (
    @is_file(PROJECT_APP_ROOT . 'composer.phar') ? PROJECT_APP_ROOT . 'composer.phar' : '/usr/local/bin/composer'
));
define('PHP_BIN',              $_admin['php_bin'] ?? _php_binary());
define('LUMINOVA_BIN',         $_admin['luminova_bin']  ?? '');
define('ALLOWED_IP_ADDRESSES', $_admin['allowed_ip_addresses']  ?? []);

/**
 * Used to download composer.phar if composer is not installed 
 * in environment
 */
define('COMPOSER_PHAR_VERSION',  'latest-stable');

unset($_admin);

final class VCI
{
    public const VERSION = '1.0.1';

    private const COMPOSER_UPDATE_MESSAGES = [
        1  => 'Composer autoload configuration updated successfully.',
        0  => 'Failed to read or parse composer.json.',
        2  => 'composer.json is not readable or writable.',
        3  => 'composer.json permission denied.',
        -1 => 'Failed to write updated composer.json.',
    ];

    private static array $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];

    /** In-request cache buckets. */
    private static array $cache = [
        'conf'   => null,
        'paths'  => [],
        'binary' => null,
        'base'   => null,
        'home'   => null,
        'appConf' => null,
        'is_conflict'   => null,
        'versions'      => [],
        'admin.conf'    => []
    ];

    /**
     * Wipe all in-request cache buckets.
     *
     * Resets every cache bucket to its initial empty state. Must be called after
     * writing the admin config or the application config so that subsequent reads
     * reflect the newly persisted values rather than stale in-memory data.
     *
     * @return void
     */
    public static function flushCache(): void
    {
        self::$cache = [
            'conf'   => null,
            'paths'  => [],
            'binary' => null,
            'base'   => null,
            'home'   => null,
            'appConf' => null,
            'is_conflict'   => null,
            'versions'      => [],
            'admin.conf'    => []
        ];
    }

    /**
     * Resolve the current user home directory path.
     *
     * Reads the {@code HOME} environment variable on Unix systems or
     * {@code APPDATA} on Windows. Falls back to {@code posix_getpwuid()} when
     * neither environment variable is set. Result is cached in-request.
     *
     * @return string|null Absolute home directory path, or null when undetermined.
     */
    private static function userHome(): ?string
    {
        return self::$cache['home'] ??= (
            getenv(defined('PHP_WINDOWS_VERSION_MAJOR') ? 'APPDATA' : 'HOME') ?: (function () {
                $info = function_exists('posix_getpwuid')
                    ? @posix_getpwuid(posix_getuid())
                    : null;
                return $info['dir'] ?? null;
            })()
        );
    }

    /**
     * Download and install composer.phar to the project root via PHP's copy().
     *
     * Validates the CSRF token and the requested version string, then runs a
     * two-step shell command: copy the remote PHAR and verify it with --version.
     * Writes a JSON response and exits.
     *
     * @return void
     */
    public static function getComposer(): void
    {
        header('Content-Type: application/json');

        if (!self::isCsrfValid($_POST['csrf'] ?? '')) {
            self::json(false, 'CSRF validation failed.');
        }

        $version = COMPOSER_PHAR_VERSION ?: 'latest-stable';

        if (
            !preg_match(
                '/^(latest(?:-(?:stable|preview))?|snapshot|v?\d+\.\d+\.\d+)$/',
                $version
            )
        ) {
            self::json(
                false,
                sprintf('Invalid Composer version "%s".', $version)
            );
        }

        $root = rtrim(PROJECT_APP_ROOT, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;

        $target = $root . 'composer.phar';
        $temp   = $target . '.tmp';

        if (is_file($target)) {
            self::json(
                true,
                'Composer PHAR binary already installed.'
            );
        }

        $url = sprintf(
            'https://getcomposer.org/download/%s/composer.phar',
            rawurlencode($version)
        );

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            self::json(false, 'Invalid Composer download URL.');
        }

        $read = @fopen($url, 'rb');

        if ($read === false) {
            self::json(
                false,
                'Failed to download Composer PHAR.'
            );
        }

        $write = @fopen($temp, 'wb');

        if ($write === false) {
            fclose($read);

            self::json(
                false,
                'Failed to create temporary Composer file.'
            );
        }

        try {
            while (!feof($read)) {
                $chunk = fread($read, 8192);

                if ($chunk === false) {
                    self::json(
                        false,
                        'Failed while reading Composer download stream.'
                    );
                }

                if (fwrite($write, $chunk) === false) {
                    self::json(
                        false,
                        'Failed while writing Composer PHAR.'
                    );
                }
            }
        } catch (Throwable $e) {
            @unlink($temp);

            self::json(false, $e->getMessage());
        } finally {
            fclose($read);
            fclose($write);
        }

        if (!@rename($temp, $target)) {
            @unlink($temp);

            self::json(
                false,
                'Failed to finalize Composer PHAR installation.'
            );
        }

        @chmod($target, 0755);
        $php = PHP_BIN;

        if (
            !$php ||
            !is_file($php) ||
            !is_executable($php)
        ) {
            $php = PHP_BINARY ?: 'php';
        }

        $response = self::runCommand(sprintf(
            '%s %s --version',
            escapeshellarg($php),
            escapeshellarg($target)
        ));

        if (($response['code'] ?? 1) !== 0) {
            @unlink($target);

            self::json(
                false,
                'Composer PHAR verification failed.'
            );
        }

        self::json(true, [
            '✔ Composer PHAR installed to project root.',
            '',
            trim((string) ($response['output'] ?? ''))
        ], true);
    }

    /**
     * Send JSON response and terminate execution.
     */
    private static function json(
        bool $success,
        string|array $output,
        bool $isArray = false
    ): void 
    {
        echo json_encode([
            'success' => $success,
            'isArray' => $isArray,
            'output'  => $output
        ]);

        exit;
    }

    /**
     * Issue an HTTP redirect back to the panel URL and halt execution.
     *
     * Appends $params as a query string; a leading {@code ?} is added automatically
     * when the string does not already begin with one.
     *
     * @param string $params Optional query-string fragment, e.g. {@code 'action=update'}.
     *
     * @return void
     */
    public static function redirect(string $params = ''): void 
    {
        if($params && !str_starts_with($params, '?')){
            $params = '?' . $params;
        }
        
        header('Location: ' . __SELF__ . $params);
        exit;
    }

    /**
     * Return the most trusted client IP address available.
     *
     * Checks standard proxy headers in priority order; validates each candidate
     * with FILTER_VALIDATE_IP before accepting it.
     *
     * @return string A valid IP string, or '0.0.0.0' when none can be determined.
     */
    public static function getUserIp(): string
    {
        foreach (self::$headers as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $value = trim($_SERVER[$key]);

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $ips = explode(',', $value);
                $value = trim($ips[0]);
            }

            if (
                filter_var(
                    $value,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                return $value;
            }

            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Check whether the current visitor's IP is permitted to access the panel.
     *
     * When ALLOWED_IP_ADDRESSES is empty every IP is allowed. Otherwise the
     * visitor's IP must match at least one entry (exact or CIDR).
     *
     * @return bool True when access is permitted.
     */
    private static function isAllowedIp(): bool
    {
        $ip = self::getUserIp();

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $hasRules = false;

        foreach (ALLOWED_IP_ADDRESSES as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            $hasRules = true;

            if ($ip === $rule || self::isIpMatch($ip, $rule)) {
                return true;
            }
        }

        // When no non-empty rules are defined, allow all IPs.
        return !$hasRules;
    }

    /**
     * Check whether an IP matches a rule.
     *
     * Supports:
     * - Exact IPv4/IPv6 match
     * - IPv4 CIDR ranges
     *
     * Examples:
     * isIpMatch('127.0.0.1', '127.0.0.1');
     * isIpMatch('192.168.1.15', '192.168.1.0/24');
     */
    public static function isIpMatch(string $ip, string $rule): bool 
    {
        $ip = trim($ip);
        $rule = trim($rule);

        if (
            $ip === ''
            || $rule === ''
            || !filter_var($ip, FILTER_VALIDATE_IP)
        ) {
            return false;
        }

        if ($ip === $rule) {
            return true;
        }

        if (
            str_contains($rule, '/')
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            [$subnet, $mask] = explode('/', $rule, 2);

            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }

            $mask = (int) $mask;

            if ($mask < 0 || $mask > 32) {
                return false;
            }

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - $mask);

            return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
        }

        return false;
    }

    /**
     * Return true when the project targets the rolling {@code current} symlink.
     *
     * When the configured target path ends with {@code /current} (or {@code /current/})
     * the project automatically follows the latest stable release without requiring
     * an explicit version switch.
     *
     * @return bool True when the active target points at the {@code current} symlink.
     */
    public static function isFollowCurrent(): bool 
    {
        $target = self::appConf('luminova.paths')['target'] ?? '';

        if (!$target || !file_exists($target)) {
            return false;
        }

        return str_ends_with($target, '/current')
            || str_ends_with($target, '/current/');
    }

    /**
     * Validate that a filesystem path string is safe for shell and storage use.
     *
     * Rejects strings that are empty, contain null bytes, start with relative-path
     * prefixes ({@code ./} or {@code ../}), contain control characters or shell
     * match characters ({@code < > | ; & $ ` \ [ ] { } ( )}).
     * Only characters in {@code [a-zA-Z0-9/_.-]} are accepted.
     *
     * @param string $value      The path string to validate.
     * @param bool   $allowSpace When true, spaces are permitted in the value.
     *
     * @return bool True when the string passes all safety checks.
     */
    public static function isSafePathString(string $value, bool $allowSpace = false): bool
    {
        if (
            $value === ''
            || str_contains($value, "\0") 
            || str_starts_with($value, './')
            || str_starts_with($value, '../')
        ) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F<>|;&$`\\\[\]\{\}\(\)]/', $value)) {
            return false;
        }

        if (!$allowSpace && str_contains($value, ' ')) {
            return false;
        }

        if (!preg_match('#^[a-zA-Z0-9/_\.\-]+$#', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Validate that a path points to an existing, executable regular file.
     *
     * Checks in order: non-empty string, no null bytes, no relative-path prefix,
     * no shell-dangerous control characters, successful {@code realpath()} resolution,
     * {@code is_file()}, and {@code is_executable()}.
     *
     * @param string $path Candidate absolute path to a binary executable.
     *
     * @return bool True when all checks pass and the resolved file is executable.
     */
    public static function isValidExecutable(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (
            str_contains($path, "\0") 
            || str_starts_with($path, './')
            || str_starts_with($path, '../')
        ) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F<>|;&$`]/', $path)) {
            return false;
        }

        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        if (!is_file($real) || !is_executable($real)) {
            return false;
        }

        return true;
    }

    /**
     * Return candidate filesystem paths for config files or binary locations.
     *
     * @param string $type    'config' | 'bin'
     * @param array  $prepend Paths to check before the built-in list.
     */
    public static function lookupPaths(string $type = 'config', array $prepend = []): array
    {
        $home = self::userHome();
        $key  = $type . ':' . md5(json_encode($prepend) . ($home ?? ''));

        if (isset(self::$cache['paths'][$key])) {
            return self::$cache['paths'][$key];
        }

        $paths = $prepend;

        if ($type === 'config') {
            $paths[] = '/etc/luminova/vci.conf';
            $paths[] = '/var/lib/vci.conf';
            if ($home) {
                $paths[] = "{$home}/.luminova/vci.conf";
                $paths[] = "{$home}/.config/.luminova/vci.conf";
                $paths[] = "{$home}/.local/share/.luminova/vci.conf";
            }
        } elseif ($type === 'bin') {
            array_push($paths,
                '/usr/local/bin/luminova',
                '/usr/bin/luminova',
                '/opt/luminova',
                '/usr/local/luminova',
                '/srv/luminova',
                '/var/www/luminova'
            );
            if ($home) {
                $paths[] = "{$home}/luminova";
                $paths[] = "{$home}/.local/bin/luminova";

                if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                    $paths[] = "{$home}/luminova";
                }
            }
        }

        return self::$cache['paths'][$key] = array_values(array_filter($paths));
    }

    /**
     * Parse the VCI shell config file (vci.conf) into a key-value array.
     * Environment variable LUMINOVA_VCI_CONF takes priority over file values.
     */
    public static function readConf(?string $extra = null): array
    {
        if (self::$cache['conf'] !== null) {
            return self::$cache['conf'];
        }

        $cfg = [];

        foreach (['LUMINOVA_VCI_CONF', 'LUMINOVA_PACKAGE_DIR'] as $k) {
            $v = getenv($k);

            if ($v !== false && $v !== '') {
                $cfg[$k] = $v;
            }
        }

        foreach (self::lookupPaths('config', $extra ? [$extra] : []) as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $ini = @parse_ini_file($file, false, INI_SCANNER_TYPED);

            if (!is_array($ini)) {
                continue;
            }

            foreach ($ini as $k => $v) {
                if (!array_key_exists($k, $cfg) && $v !== null && $v !== '') {
                    $cfg[$k] = $v;
                }
            }
        }

        return self::$cache['conf'] = $cfg;
    }

    /**
     * Locate the luminova shell binary, optionally returning the symlink path.
     *
     * @param bool  $forLink Return the symlink path rather than the resolved real path.
     */
    public static function findBinary(bool $forLink = false): ?string
    {
        $key = md5(json_encode(self::$cache['conf'])) . ($forLink ? ':link' : ':bin');

        if (isset(self::$cache['binary'][$key])) {
            return self::$cache['binary'][$key];
        }

        $binary = self::$cache['conf']['LUMINOVA_VCI_BIN'] 
            ??  null;

        if ($binary && is_executable($binary)) {
            return self::$cache['binary'][$key] = $forLink
                ? (is_link($binary) ? $binary : null)
                : realpath($binary);
        }

        foreach (self::lookupPaths('bin') as $p) {
            if (!$p || !is_executable($p)) {
                continue;
            }

            if ($forLink) {
                if (is_link($p)) {
                    return self::$cache['binary'][$key] = $p;
                }

                continue;
            }

            return self::$cache['binary'][$key] = realpath($p);
        }

        return self::$cache['binary'][$key] = null;
    }

    /**
     * Return true when the luminova binary lives inside the packages directory
     * (i.e. VCI was not installed globally via --self=install).
     */
    public static function binaryInPackagesDir(array $cfg): bool
    {
        $vciDir     = realpath(dirname($cfg['LUMINOVA_VCI_BIN'] ?? ''));
        $packageDir = realpath($cfg['LUMINOVA_PACKAGE_DIR'] ?? '');

        if ($vciDir === false || $packageDir === false) {
            return false;
        }

        return $vciDir === $packageDir;
    }

    /**
     * Resolve the packages root directory from config or fall back to /opt/luminova.
     */
    private static function findPackagesDir(): ?string
    {
        if (self::$cache['base'] !== null) {
            return self::$cache['base'];
        }

        $cfg = [
            self::$cache['conf']['LUMINOVA_PACKAGE_DIR'] ?? null, 
            '/opt/luminova'
        ];

        foreach ($cfg as $path) {
            if ($path && is_dir($path)) {
                return self::$cache['base'] = realpath($path);
            }
        }

        return self::$cache['base'] = null;
    }

    /**
     * Resolve the real path of the currently active release (packages/current → releases/x.y.z).
     */
    private static function currentReleasePath(): ?string
    {
        $base = self::findPackagesDir();

        if (!$base) {
            return null;
        }

        $current = $base . '/current';

        if (is_link($current) || is_dir($current)) {
            return realpath($current) ?: null;
        }

        return null;
    }

    /**
     * Probe all resolvable VCI paths and return them as a structured array.
     * Pass a $context string to retrieve a single value instead of the full array.
     *
     * @param string|null $context 'config' | 'bin' | 'link' | 'packages' | 'current'
     */
    public static function detect(?string $context = null): array|string|null
    {
        static $cache = null;

        if ($cache !== null) {
            return $context ? ($cache[$context] ?? null) : $cache;
        }

        $cfg = self::readConf();

        $cache = [
            'config'   => $cfg,
            'bin'      => self::findBinary() ?: self::findBinary(true),
            'packages' => self::findPackagesDir(),
            'current'  => self::currentReleasePath(),
        ];

        return $context ? ($cache[$context] ?? null) : $cache;
    }

    /**
     * Return [packagesDir, binaryPath] from the stored admin config JSON,
     * falling back to auto-detection when the file does not exist.
     *
     * @return array{string, string}
     */
    public static function getPaths(): array
    {
        $paths = self::getAdminConf() ?: self::detect();
       
        return [
            $paths['packages'] ?? '',
            $paths['luminova_bin'] ?? $paths['bin'] ?? '',
        ];
    }

    /**
     * Load and cache the persisted VCI admin configuration array.
     *
     * Reads {@see PATH_CONFIG_FILE} on the first call and caches the result
     * in-request. Subsequent calls within the same request return the cached value
     * without touching the filesystem.
     *
     * @return array{
     *     packages?: string,
     *     composer_bin?: string,
     *     php_bin?: string,
     *     luminova_bin?: string,
     *     password_hash?: string,
     *     allowed_ip_addresses?: list<string>
     * } Config array; empty when the file does not exist or cannot be read.
     */
    public static function getAdminConf(): array
    {
        if(is_file(PATH_CONFIG_FILE) && empty(self::$cache['admin.conf'])){
            self::$cache['admin.conf'] = include PATH_CONFIG_FILE;
        }

        return self::$cache['admin.conf'] ?? [];
    }

    /**
     * Return true when $path is a valid Luminova packages root
     * (directory exists and contains a releases/ sub-directory).
     */
    public static function validPackagesDir(string $path): bool
    {
        return is_dir($path) 
            && is_readable($path) 
            && is_dir($path . '/releases');
    }

    /**
     * Handle the POST save path action from the setup form.
     * Validates user input, then persists the admin config.
     */
    private static function handleSavePath(): void
    {
        $query = '';

        if (!empty($_GET['action']) && $_GET['action'] === 'update') {
            $query = '?action=update';
        }

        if (!self::isCsrfValid($_POST['csrf'] ?? '')) {
            self::setData('setup_error', 'CSRF validation failed.');
            self::redirect($query);
        }

        $packagesDir = rtrim(trim($_POST['luminova_base'] ?? ''), '/');

        if(!self::isSafePathString($packagesDir)){
            self::setData(
                'setup_error',
                "Path '{$packagesDir}' is not allowed."
            );
            self::redirect($query);
        }

        if (!self::validPackagesDir($packagesDir)) {
            self::setData(
                'setup_error',
                "Path not valid: a releases/ directory was not found at '{$packagesDir}'."
            );
            self::redirect($query);
        }

        $luminovaPath  = rtrim(trim($_POST['luminova_bin']  ?? ''), '/');
        $composerBin = trim($_POST['composer_bin'] ?? '') ?: COMPOSER_BIN;
        $phpBin      = trim($_POST['php_bin']      ?? '') ?: PHP_BIN;

        foreach(['composer' => $composerBin, 'php' => $phpBin] as $name => $path){
            if(!self::isValidExecutable($path)){
                self::setData(
                    'setup_error',
                    "'{$path}' is not a valid '{$name}' binary path."
                );

                $query = $query . ($query ? '&' : '?') . 'tab=2';

                if($name === 'composer'){
                    self::redirect($query . '&get-composer=1');
                }

                self::redirect($query);
            }
        }

        $newPass     = $_POST['new_password']     ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        $passwordHash = null;

        if ($newPass !== '') {
            $query = $query . ($query ? '&' : '?') . 'tab=2';

            if (preg_match('/[\x00-\x1F\x7F]/', $newPass)) {
                self::setData('setup_error', 'Password cannot contain control characters and null bytes.');
                self::redirect($query);
            }

            if (strlen($newPass) < 8) {
                self::setData('setup_error', 'Password must be at least 8 characters.');
                self::redirect($query);
            }

            if ($newPass !== $confirmPass) {
                self::setData('setup_error', 'Passwords do not match.');
                self::redirect($query);
            }

            $passwordHash = password_hash($newPass, PASSWORD_ALGO);
        }

        if (!self::writeAdminConf($packagesDir, $luminovaPath, $composerBin, $phpBin, $passwordHash)) {
            self::setData(
                'setup_error',
                'Could not write ' . PATH_CONFIG_FILE . ' — check directory permissions.'
            );
            self::redirect($query);
        }

        self::redirect();
    }

    /**
     * Persist paths and optional overrides to the admin config JSON file.
     *
     * @param string      $packagesDir  Absolute path to the VCI packages directory.
     * @param string|null $luminovaBin   Absolute path to the luminova binary (optional).
     * @param string|null $composerBin  Path to the Composer executable (optional).
     * @param string|null $phpBin       Path to the PHP executable (optional).
     * @param string|null $passwordHash bcrypt hash of the new admin password (optional).
     * @param string|null $luminovaVer Target luminova composer version constraint.
     */
    private static function writeAdminConf(
        ?string $packagesDir  = null,
        ?string $luminovaBin  = null,
        ?string $composerBin  = null,
        ?string $phpBin       = null,
        ?string $passwordHash = null,
        ?string $luminovaVer = null
    ): bool 
    {
        // Load existing data so we only overwrite supplied fields
        $existing = self::getAdminConf();
        $packagesDir = $packagesDir ? rtrim($packagesDir, '/') : null;

        self::$cache['admin.conf'] = array_filter([
            'packages'      => $packagesDir  ?: ($existing['packages']      ?? null),
            'composer_bin'  => $composerBin  ?: ($existing['composer_bin']  ?? null),
            'php_bin'       => $phpBin       ?: ($existing['php_bin']       ?? null),
            'luminova_bin'  => $luminovaBin  ?: ($existing['luminova_bin']  ?? null),
            'password_hash' => $passwordHash ?: ($existing['password_hash'] ?? null),
            'luminova_ver'  => $luminovaVer  ?: ($existing['luminova_ver']  ?? null),
        ], fn($v) => $v !== null && $v !== '');

        $data = "<?php\nreturn " . var_export(self::$cache['admin.conf'], true) . ";\n";

        return file_put_contents(PATH_CONFIG_FILE, $data) !== false;
    }

    /** Start a secure session and generate a CSRF token when needed. */
    public static function start(): void
    {
        if (!self::isAllowedIp()) {
            http_response_code(404);
            exit('404 Not Found');
        }
        
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure' => true,
            'gc_maxlifetime'  => SESSION_LIFETIME,
        ]);

        if (!self::hasData('csrf')) {
            self::setData('csrf', bin2hex(random_bytes(32)));
        }
    }

    /**
     * Retrieve a value stored under the VCI session namespace.
     *
     * @param string $name    Session key to look up.
     * @param mixed  $default Value to return when the key is absent or not set.
     *
     * @return mixed The stored session value, or $default when the key is missing.
     */
    public static function getData(string $name, mixed $default = null): mixed
    {
        return $_SESSION[SESSION_STORAGE][$name] ?? $default;
    }

    /**
     * Return true when a non-empty value exists under the given session key.
     *
     * @param string $name Session key to check.
     *
     * @return bool True when the key is present and its value is truthy.
     */
    public static function hasData(string $name): bool
    {
        return !empty(self::getData($name));
    }

    /**
     * Store a value under the VCI session namespace and return it.
     *
     * @param string $name  Session key to write.
     * @param mixed  $value Value to persist in the session.
     *
     * @return mixed The value that was stored.
     */
    public static function setData(string $name, mixed $value): mixed
    {
        $_SESSION[SESSION_STORAGE][$name] = $value;
        return $value;
    }

    /**
     * Remove a key from the VCI session namespace.
     *
     * @param string $name Session key to delete.
     *
     * @return void
     */
    public static function removeData(string $name): void
    {
        unset($_SESSION[SESSION_STORAGE][$name]);
    }

    /**
     * Return true when the session is authenticated and within its lifetime.
     *
     * Verifies that the {@code auth} flag is set, the authentication timestamp
     * is within {@see SESSION_LIFETIME} seconds of now, and the current request
     * IP matches the IP recorded at login time.
     *
     * @return bool True when the current session represents a valid, active login.
     */
    public static function isLoggedIn(): bool
    {
        return self::hasData('auth')
            && self::hasData('auth_at')
            && self::isIpMatch(self::getUserIp(), self::getData('auth_ip'))
            && (time() - (int) self::getData('auth_at')) < SESSION_LIFETIME;
    }

    public static function getRemainingSessionTime(): int
    {
        $authAt = (int) self::getData('auth_at', 0);

        if ($authAt <= 0) {
            return 0;
        }

        $expiresAt = $authAt + SESSION_LIFETIME;
        $remaining = $expiresAt - time();

        return max(0, $remaining);
    }

    /**
     * Return the remaining session lifetime formatted as {@code MM:SS of N min}.
     *
     * Returns the literal string {@code 'Expired'} when the session carries no
     * recorded authentication timestamp or the lifetime has already elapsed.
     *
     * @return string Formatted time string, e.g. {@code '42:17 of 60 min'}.
     */
    public static function getRemainingSessionFormatted(): string
    {
        $seconds = self::getRemainingSessionTime();

        if ($seconds <= 0) {
            return 'Expired';
        }

        $minutes = intdiv($seconds, 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d of %d min', $minutes, $seconds, floor(SESSION_LIFETIME / 60));
    }

    /**
     * Terminate execution with a logout if the session is not authenticated.
     *
     * Serves as an authentication gate for protected actions. When $isJson is
     * true and the session has expired, a JSON error is sent instead of an HTTP
     * redirect so AJAX callers can handle the expiry gracefully.
     *
     * @param bool $isJson When true, respond with JSON on session expiry.
     *
     * @return void On an active session; otherwise terminates via {@see self::logout()}.
     */
    public static function forceLogin(bool $isJson = false): void
    {
        if (!self::isLoggedIn()) {
            self::logout($isJson);
        }
    }

    private static function removeModules(bool $keepDefault = true): int
    {
        $removed = 0;

        $rules = [
            PROJECT_APP_ROOT . 'bootstrap' => ['constants.php'],
            PROJECT_APP_ROOT . 'system'    => ['Boot.php', 'plugins']
        ];

        $plugins = 'plugins' . DIRECTORY_SEPARATOR;

        foreach ($rules as $dir => $allowed) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();
                $isAllowed = false;
                $relative = substr($path, strlen($dir) + 1);

                // Always preserve .gitignore files
                if (str_ends_with($relative, '.gitignore')) {
                    continue;
                }

                // Preserve plugins directory and contents
                if (
                    $relative === 'plugins'
                    || $relative === 'Boot.php'
                    || str_starts_with($relative, $plugins)
                    || str_starts_with($relative, 'Boot.php')
                ) {
                    continue;
                }

                if ($keepDefault) {
                    foreach ($allowed as $keep) {
                        if (
                            $relative === $keep
                            || (
                                $keep === 'plugins'
                                && str_starts_with($relative, $plugins)
                            )
                        ) {
                            $isAllowed = true;
                            break;
                        }
                    }
                }

                if ($isAllowed) {
                    continue;
                }

                $isRemoved = $item->isDir()
                    ? @rmdir($path)
                    : @unlink($path);

                if ($isRemoved) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Recursively remove every file and sub-directory inside a directory.
     *
     * The $target directory itself is preserved; only its contents are deleted.
     * Entries that cannot be removed are silently skipped.
     *
     * @param string $target Absolute path to the directory whose contents should be cleared.
     *
     * @return int Number of files and directories successfully removed.
     */
    private static function cleanDirectory(string $target): int
    {
        if (!is_dir($target)) {
            return 0;
        }

        $removed = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
       
            if ($file->isDir()) {
                $isRemoved = @rmdir($path);
            } else {
                $isRemoved = @unlink($path);
            }

            if ($isRemoved) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Move the contents of temporary staging directories to their final destinations.
     *
     * Iterates $maps where each key is a source directory path and each value is the
     * corresponding target path. Items inside the source are moved with {@code rename()};
     * the now-empty source directory is removed after all items have been relocated.
     * Terminates with a JSON error response if any individual move fails.
     *
     * @param array<string,string> $maps Source-to-destination directory path pairs.
     *
     * @return void On success; otherwise terminates with a JSON error response.
     */
    private static function renameDirectory(array $maps): void
    {
        foreach ($maps as $temp => $target) {
            if (!is_dir($target) && !mkdir($target, 0755, true)) {
                self::json(false, "Failed to create target directory: {$target}");
            }

            $items = scandir($temp);

            if ($items === false) {
                self::json(false, "Failed to read temp directory: {$temp}");
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $from = $temp . $item;
                $to   = $target . $item;

                if (!rename($from, $to)) {
                    self::json(false, "Failed to move: {$item}");
                }
            }

            @rmdir($temp);
        }
    }

    /**
     * Detect whether local framework files co-exist with shared-module autoloading.
     *
     * Checks for files that are only present when the framework has been physically
     * copied into the project (e.g. {@code bootstrap/functions.php},
     * {@code system/Luminova.php}). Finding these alongside shared-module
     * configuration means the project is in an ambiguous state. Result is cached
     * in-request to avoid redundant filesystem probes.
     *
     * @return bool True when an ambiguous module conflict is detected.
     */
    public static function isModuleConflict(): bool
    {
        if(self::$cache['is_conflict'] !== null){
            return self::$cache['is_conflict'];
        }

        $rules = [
            PROJECT_APP_ROOT . 'bootstrap' => ['functions.php', 'worker.php'],
            PROJECT_APP_ROOT . 'system'    => ['Luminova.php', 'Foundation/Core/Application.php']
        ];

        foreach ($rules as $dir => $checks) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach ($checks as $file) {
                if(is_file($dir . DIRECTORY_SEPARATOR . $file)){
                    return self::$cache['is_conflict'] = true;
                }
            }
        }

        return self::$cache['is_conflict'] = false;
    }

    /**
     * Process a login POST request and authenticate the admin session.
     *
     * Enforces brute-force protection: after {@see LOGIN_MAX_ATTEMPTS} consecutive
     * failures within {@see FAILED_LOGIN_LOCK_WINDOW_TS} seconds the panel is
     * temporarily locked. On success the session is populated with authentication
     * metadata and the stored password hash is automatically upgraded to the
     * preferred algorithm when required. On failure the attempt counter is
     * incremented and an error message is flashed to the session.
     *
     * Always terminates by calling {@see self::redirect()} — never returns normally.
     *
     * @return void
     */
    public static function login(): void
    {
        $attempts = (int) self::getData('login_attempts', 0);
        $lastAttempt = (int) self::getData('login_last_attempt', 0);

        if ($attempts >= LOGIN_MAX_ATTEMPTS && (time() - $lastAttempt) > FAILED_LOGIN_LOCK_WINDOW_TS) {
            self::removeData('login_attempts');
            self::removeData('login_last_attempt');

            $attempts = 0;
            $lastAttempt = 0;
        }

        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $remaining = FAILED_LOGIN_LOCK_WINDOW_TS - (time() - $lastAttempt);

            self::setData(
                'login_error',
                sprintf(
                    'Too many login attempts. Try again in %d seconds.',
                    max(1, $remaining)
                )
            );

            self::redirect();
        }

        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';

        if(!$user){
            self::setData('login_error', 'Username is required.');
            self::redirect();
        }

        if (
            strlen($user) < 3 ||
            strlen($user) > 32 ||
            str_contains($user, "\0") ||
            !preg_match('/^[a-zA-Z0-9._-]+$/', $user)
        ) {
            self::setData('login_error', 'Invalid username format.');
            self::redirect();
        }

        if ($pass === '') {
            self::setData('login_error', 'Password is required.');
            self::redirect();
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $pass)) {
            self::setData('login_error', 'Invalid password.');
            self::redirect();
        }

        if (
            $user === ADMIN_USERNAME &&
            password_verify($pass, ADMIN_PASSWORD_HASH)
        ) {
            if(SESSION_REGENERATE_ID > 0 && SESSION_REGENERATE_ID <= 2){
                session_regenerate_id(SESSION_REGENERATE_ID === 2);
            }

            self::setData('auth', true);
            self::setData('auth_at', time());
            self::setData('auth_ip', self::getUserIp());
            

            self::removeData('login_attempts');
            self::removeData('login_last_attempt');
            self::removeData('login_error');

            if (password_needs_rehash(ADMIN_PASSWORD_HASH, PASSWORD_ALGO)) {
                self::writeAdminConf(
                    passwordHash: password_hash($pass, PASSWORD_ALGO)
                );
            }
        } else {
            self::setData('login_attempts', $attempts + 1);
            self::setData('login_last_attempt', time());

            self::setData(
                'login_error',
                sprintf(
                    'Invalid username or password. (%d/%d)',
                    min($attempts + 1, LOGIN_MAX_ATTEMPTS),
                    LOGIN_MAX_ATTEMPTS
                )
            );
        }

        self::redirect();
    }

    /**
     * Destroy the current session and redirect to the login screen.
     *
     * When $isJson is true a JSON error payload is emitted instead of an HTTP
     * redirect, which allows AJAX endpoints to detect an expired session without
     * a page reload.
     *
     * @param bool $isJson When true, emit a JSON error response instead of redirecting.
     *
     * @return void Always terminates execution.
     */
    public static function logout(bool $isJson = false): void
    {
        $_SESSION[SESSION_STORAGE] = [];
        session_destroy();

        if ($isJson) {
            header('Content-Type: application/json');
            self::json(false, 'Login session expired.');
        }

        self::redirect();
    }

    /**
     * Validate a CSRF token against the one stored in the current session.
     *
     * Uses {@code hash_equals()} for constant-time comparison to prevent
     * timing-based token disclosure attacks.
     *
     * @param string $token Token value submitted with the POST request.
     *
     * @return bool True when the submitted token matches the session-stored value.
     */
    public static function isCsrfValid(string $token): bool
    {
        return hash_equals(self::getData('csrf', ''), $token);
    }

    /**
     * Return installed release version strings, newest first.
     * Versions that match the currently active symlink are annotated with a
     * 'current' value in the returned array.
     *
     * @param array|null $state Runtime state array from initRuntimeState(); auto-resolved when null.
     */
    public static function getInstalledVersions(?array $state = null): array
    {
        if(!empty(self::$cache['versions'])){
            return self::$cache['versions'];
        }

        $state ??= self::initRuntimeState();

        if($state['needsSetup']){
            return [];
        }

        $releasesDir = $state['releases'];
        $currentLink = $state['current'];

        if (!is_dir($releasesDir)) {
            return [];
        }

        $dirs = glob("{$releasesDir}/*", GLOB_ONLYDIR) ?: [];
        $versions = array_map(fn($d) => basename($d), $dirs);

        usort($versions, 'version_compare');

        if (is_dir($currentLink)) {
            $resolved = is_link($currentLink) 
                ? readlink($currentLink) 
                : realpath($currentLink);

            if ($resolved !== false) {
                $versions[basename($resolved)] = 'current';
            }
        }

        return self::$cache['versions'] = array_reverse($versions);
    }

    /**
     * Return [activeVersionString, resolvedPackagePath] for the project's current target.
     *
     * @return array{string, string}
     */
    public static function getAppActiveVersion(): array
    {
        $conf = self::appConf();

        $status = $conf['resolve.paths'] ?? false;
        $target = $conf['luminova.paths']['target'] ?? '';

        if (!file_exists($target)) {
            return ['', $target, $status];
        }

        $resolved = is_link($target) ? readlink($target) : realpath($target);

        return [
            ($resolved !== false) ? basename($resolved) : '',
            $resolved !== false ? $resolved : $target,
            $status
        ];
    }

    /**
     * Load and return the project's .luminova.php config array.
     *
     * @param string|null $key  Return a single top-level key when provided.
     */
    public static function appConf(?string $key = null): mixed
    {
        if (!isset(self::$cache['appConf']) && is_file(PATH_APP_CONFIG_FILE)) {
            self::$cache['appConf'] = include PATH_APP_CONFIG_FILE;
        }

        if (!is_array(self::$cache['appConf'])) {
            return null;
        }
        if ($key === null) {
            return self::$cache['appConf'];
        }

        return self::$cache['appConf'][$key] ?? null;
    }

    /**
     * Validate shared pre-conditions for any version-switch or localize request.
     *
     * Sets the JSON {@code Content-Type} header and terminates with a JSON error when:
     * the CSRF token is invalid; the runtime state shows setup is incomplete;
     * {@code composer.json} is absent from the project root; or the requested
     * version string is not in the list of installed releases.
     *
     * @param string $version Requested release version string (e.g. {@code '4.0.0'}).
     * @param array  $state   Runtime state array from {@see self::initRuntimeState()}.
     *
     * @return void On valid input; otherwise terminates with a JSON error.
     */
    private static function switchHeader(string $version, array $state): void
    {
        header('Content-Type: application/json');

        if (!self::isCsrfValid($_POST['csrf'] ?? '')) {
            self::json(false, 'CSRF validation failed.');
        }

        if ($state['needsSetup']) {
            self::json(false, 'Luminova path not configured.');
        }

        if(!self::isComposerJson()){
            self::json(false, 'composer.json file is missing in project root. Upload or create composer.json file.');
        }

        $allowed = self::getInstalledVersions($state);

        if ($version === '' || !in_array($version, $allowed, true)) {
            self::json(false, 'Invalid or unknown version.');
        }
    }

    /**
     * Handle the version switch action.
     * Validates the requested version, updates .luminova.php, and runs
     * composer dump-autoload.
     */
    private static function handleSwitch(array $state): void
    {
        $version = trim($_POST['version'] ?? '');

        self::switchHeader($version, $state);

        $packagesDir = rtrim($state['packages'], DIRECTORY_SEPARATOR);
        $newPath = match($version) {
            'current' => "{$packagesDir}/current",
            default   => "{$packagesDir}/releases/{$version}"
        };

        if (!is_dir($newPath)) {
            self::json(false, 'Target version path does not exist: ' . $version);
        }

        $log = [];
        $err = null;
        $updated = self::writeAppConfig($newPath, $packagesDir, false, $err);
        $metadata = self::restoreMetadata($newPath);

        $log[] = ($metadata > 0)
            ? "✔ {$metadata} version {$version} specific files was restored"
            : "⚠ Could not restore {$version} specific files.";

        $log[] = $updated
            ? "✔ .luminova.php updated → {$version}"
            : ($err ?? '⚠ Could not update .luminova.php, check file permissions.');

        $log[] = '';

        $status = self::updateComposerJson($newPath);
        $log[] = self::COMPOSER_UPDATE_MESSAGES[$status] 
            ?? "Unknown composer update status: {$status}";
        $log[] = '';

        $composer = self::optimizeAutoload();

        $log[]   = $composer['output'];
        $success = $updated && $composer['code'] === 0;

        self::json($success, implode("\n", $log));
    }

    /**
     * Restore framework metadata files into the active application structure.
     *
     * This method resolves the target release path, then restores
     * project-specific framework files from the `.metadata` directory
     * back into their original locations.
     *
     * Only files that exist in metadata and are successfully copied
     * will be counted as restored.
     *
     * @param string $newPath The release or build directory path.
     * 
     * @return int Number of successfully restored files.
     */
    private static function restoreMetadata(string $newPath): int
    {
        $basePath = readlink($newPath) ?: realpath($newPath) ?: $newPath;
        $basePath = rtrim($basePath, '/');

        $metadataPath = $basePath . '/.metadata';

        $files = [
            PROJECT_APP_ROOT . 'system/Boot.php'
                => $metadataPath . '/system/Boot.php',

            PROJECT_APP_ROOT . 'bootstrap/constants.php'
                => $metadataPath . '/bootstrap/constants.php',
        ];

        $restored = 0;

        foreach ($files as $destination => $source) {
            if (!is_file($source)) {
                continue;
            }

            if (@copy($source, $destination)) {
                $restored++;
            }
        }

        return $restored;
    }

    /**
     * Remove ambiguous local framework files and regenerate the Composer autoloader.
     *
     * Validates the CSRF token and confirms a module conflict exists before pruning
     * local {@code bootstrap/} and {@code system/} files. Always-required files
     * ({@code Boot.php}, {@code constants.php}, all of {@code plugins/}) are
     * preserved. Runs {@code composer dump-autoload --no-dev --optimize} after
     * cleanup and returns combined output as a JSON response.
     *
     * @return void Always terminates with a JSON response.
     */
    public static function removeAndOptimizeModule(): void
    {
        header('Content-Type: application/json');

        if (!self::isCsrfValid($_POST['csrf'] ?? '')) {
            self::json(false, 'CSRF validation failed.');
        }

        if (!self::isModuleConflict()) {
            self::json(false, 'No module conflict detected.');
        }

        $removed = self::removeModules();

        if($removed === 0){
            self::json(
                false, 
                'No files were removed. Check directory permissions and try again.'
            );
        }

        $log = ["Local module cleanup completed. Removed {$removed} files."];

        $composer = self::optimizeAutoload();
        $log[]   = $composer['output'];

        self::json(true, implode("\n", $log));
    }

    /**
     * Localize a release by physically copying its framework files into the project.
     *
     * Copies {@code bootstrap/} and {@code system/} (excluding {@code plugins/}) from
     * the selected release into a temporary staging directory, prunes existing local
     * module files, then moves the staged copies into the project root. Updates
     * {@code composer.json} to reference local paths and sets
     * {@code resolve.paths = false} in {@code .luminova.php} to disable the
     * shared-module feature. Runs {@code composer dump-autoload} and returns a JSON
     * result.
     *
     * @param array $state Runtime state array from {@see self::initRuntimeState()}.
     *
     * @return void Always terminates with a JSON response.
     */
    private static function importModuleVersion(array $state): void
    {
        $version = trim($_POST['version'] ?? '');

        self::switchHeader($version, $state);

        $packagesDir = rtrim($state['packages'], DIRECTORY_SEPARATOR);

        $newPath = ($version === 'current')
            ? (
                readlink("{$packagesDir}/current")
                    ?: realpath("{$packagesDir}/current")
                    ?: "{$packagesDir}/current"
            )
            : "{$packagesDir}/releases/{$version}";

        if (!is_dir($newPath)) {
            self::json(false, "Target version does not exist: {$version}");
        }

        $newPath = rtrim($newPath, '/');
        $metadataPath = $newPath . '/.metadata';
        $tmpRoot = PROJECT_APP_ROOT . 'writeable/modules-tmp/';

        $targets = [
            "{$newPath}/bootstrap/" => "{$tmpRoot}bootstrap/",
            "{$newPath}/system/"    => "{$tmpRoot}system/",

            "{$metadataPath}/bootstrap/" => "{$tmpRoot}bootstrap/",
            "{$metadataPath}/system/"    => "{$tmpRoot}system/"
        ];

        $imported = 0;

        foreach ($targets as $source => $destination) {
            if (!is_dir($source)) {
                unlink($tmpRoot);
                self::json(false, "Missing source directory: {$source}");
            }

            if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
                unlink($tmpRoot);
                self::json(false, "Failed to create temp directory: {$destination}");
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $srcPath = $item->getPathname();

                $relPath = substr($srcPath, strlen($source));

                // Skip plugins directory entirely
                if (
                    $relPath === 'plugins'
                    || str_starts_with($relPath, 'plugins' . DIRECTORY_SEPARATOR)
                ) {
                    continue;
                }

                $destPath = $destination . $relPath;

                if ($item->isDir()) {
                    if (!is_dir($destPath)) {
                        mkdir($destPath, 0755, true);
                    }

                    continue;
                }

                $destDir = dirname($destPath);

                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                if (copy($srcPath, $destPath)) {
                    $imported++;
                }
            }
        }

        if ($imported === 0) {
            self::json(false, 'No files were imported. Check file permissions.');
        }

        self::removeModules();
        self::renameDirectory([
            "{$tmpRoot}bootstrap/" => PROJECT_APP_ROOT . 'bootstrap/',
            "{$tmpRoot}system/"    => PROJECT_APP_ROOT . 'system/'
        ]);

        $err = null;
        $log = [];

        $log[] = "Successfully imported {$imported} files.";
        $log[] = '';

        $status = self::updateComposerJson(null);

        $log[] = self::COMPOSER_UPDATE_MESSAGES[$status]
            ?? "Unknown composer update status: {$status}";

        $log[] = '';

        $updated = self::writeAppConfig($newPath, $packagesDir, true, $err);

        $log[] = $updated
            ? '✔ .luminova.php updated successfully.'
            : ($err ?? '⚠ Failed to update .luminova.php.');

        $log[] = '';

        $composer = self::optimizeAutoload();

        $log[] = $composer['output'] ?? 'Composer optimization completed.';

        self::json(true, implode("\n", $log));
    }

    /**
     * Return true when {@code composer.json} exists in the project root.
     *
     * @return bool True when {@see PATH_COMPOSER_JSON} resolves to a regular file.
     */
    private static function isComposerJson(): bool
    {
        return is_file(PATH_COMPOSER_JSON);
    }

    /**
     * Read and JSON-decode the project {@code composer.json} file.
     *
     * Returns an integer error code when the file cannot be used:
     * {@code 0} — file missing, unreadable, or JSON parse failure;
     * {@code 2} — file exists but is not both readable and writable.
     *
     * @return array<string,mixed>|int Decoded associative array on success,
     *                                  or an integer error code on failure.
     */
    private static function readComposerJson(): array|int
    {
        if (!is_file(PATH_COMPOSER_JSON)) {
            return 0;
        }

        if (!is_readable(PATH_COMPOSER_JSON) || !is_writable(PATH_COMPOSER_JSON)) {
            return 2;
        }

        try {
            $json = json_decode(
                file_get_contents(PATH_COMPOSER_JSON),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            _log($e->getMessage(), 'EXCEPTION', $e->getFile(), $e->getLine());
            return 0;
        }

        if (!is_array($json)) {
            return 0;
        }

        return $json;
    }

    /**
     * Rewrite the PSR-4 autoload entries in {@code composer.json} for a release path.
     *
     * Adjusts the {@code Luminova\} and {@code Luminova\Funcs\} namespace mappings
     * to include both the local stub path and the shared release directory. When
     * $newPath is null the mappings are reset to local-only paths (used during the
     * localize / import workflow).
     *
     * Return codes:
     * {@code  1} — success (file updated or already correct);
     * {@code  0} — file missing, parse error, or invalid autoload section;
     * {@code  2} — file not readable or not writable;
     * {@code -1} — atomic write or rename of the temporary file failed.
     *
     * @param string|null $newPath Absolute path to the target release directory,
     *                             or null to reset to local-only paths.
     *
     * @return int Status code as described above.
     */
    private static function updateComposerJson(?string $newPath): int
    {
        $json = self::readComposerJson();

        if (!is_array($json)) {
            return $json;
        }

        $psr4 = $json['autoload']['psr-4'] ?? [];

        if (!is_array($psr4)) {
            return 0;
        }

        $updateVer = false;
        $ver = self::$cache['admin.conf']['luminova_ver'] ?? "^3.7";

        if($newPath === null){
            if (!isset($json['require']['luminovang/framework'])) {
                $json['require']['luminovang/framework'] = $ver;
            }
        }elseif (isset($json['require']['luminovang/framework'])) {
            $updateVer = true;
            self::$cache['admin.conf']['luminova_ver'] = $json['require']['luminovang/framework'] 
                ?? $ver;

            unset($json['require']['luminovang/framework']);
        }

        $newPsr4 = [];
        $changes = 0;

        foreach ($psr4 as $namespace => $path) {

            $trimmed = ltrim($namespace, '\\');

            if (!str_starts_with($trimmed, 'Luminova\\')) {
                $newPsr4[$namespace] = $path;
                continue;
            }

            $suffix = match (true) {
                str_starts_with($trimmed, 'Luminova\\Funcs\\') => 'bootstrap',
                $trimmed === 'Luminova\\' => 'system',
                default => null
            };

            if ($suffix === null) {
                $newPsr4[$namespace] = $path;
                continue;
            }

            $newValue = "{$suffix}/";

            if($newPath === null){
                if ($path === $newValue || $path === ["{$suffix}/"]) {
                    $newPsr4[$namespace] = $path;
                    continue;
                }

                $newPsr4[$namespace] = ["{$suffix}/"];
                $changes++;
                continue;
            }

            if ($path === $newValue || $path === ["{$suffix}/", "{$newPath}/{$suffix}/"]) {
                $newPsr4[$namespace] = $path;
                continue;
            }

            $newPsr4[$namespace] = [
                "{$suffix}/",
                "{$newPath}/{$suffix}/"
            ];

            $changes++;
        }

        if ($changes === 0) {
            return 1;
        }

        uksort($newPsr4, fn($a, $b) => strlen($b) <=> strlen($a));

        $json['autoload']['psr-4'] = $newPsr4;
        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return 0;
        }

        $tmp = PATH_TMP . '/composer.json.tmp';

        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            return -1;
        }

        if (!rename($tmp, PATH_COMPOSER_JSON)) {
            @unlink($tmp);
            return -1;
        }

        if($updateVer){
            self::writeAdminConf(luminovaVer: $ver);
        }

        return 1;
    }

    /**
     * Build the expected packages path from an arbitrary target path string.
     * Useful for detecting when .luminova.php points to a path outside the
     * configured packages directory.
     *
     * @param string $packages Configured packages root.
     * @param string $target Path stored in the project's .luminova.php.
     */
    public static function buildCanonicalPath(string $packages, string $target): string
    {
        $target   = str_replace('\\', '/', $target);
        $packages = rtrim(str_replace('\\', '/', $packages), '/');

        if (preg_match('#/(releases/.+|current)$#', $target, $matches)) {
            return $packages . '/' . $matches[1];
        }

        return $packages;
    }

    /**
     * Update the project's .luminova.php to reference $version.
     * Creates the file with sensible defaults when it does not yet exist.
     *
     * @param string $newPath Release version path
     * @param string $packagesDir Packages directory path
     * @param bool $isLocal Switch to local modules.
     * @param string|null $err Set to an error message on failure.
     */
    private static function writeAppConfig(
        string $newPath, 
        string $packagesDir, 
        bool $isLocal = false,
        ?string &$err = null
    ): bool
    {
        $conf = null;
        $comment = <<<COMMENT
        /**
         * Luminova Boot Configuration
         *
         * Defines how the bootloader resolves framework core paths and autoload strategy.
         * This file is loaded during early bootstrap and overrides default resolution logic.
         *
         * Modes:
         * - resolve.paths: enables shared framework installation
         * - resolve.autoloader:
         *      auto     → detect Composer, fallback to Luminova if needed
         *      composer → use Composer only (recommended updating composer psr4 mapping)
         *      luminova → use custom loader only
         *
         * Rules:
         * - Only system/bootstrap may be shared
         * - Application code (app, routes, storage) must remain local
         * - Version mismatch may cause runtime instability
         *
         * @link https://luminova.ng/docs/0.0.0/boot/shared-modules
         *
         * @return array{
         *     resolve.paths: bool,
         *     resolve.autoloader: 'auto'|'composer'|'luminova',
         *     luminova.version: string,
         *     luminova.paths: array{
         *         root: string,
         *         target: string
         *     }
         * }
         */
        COMMENT;

        if (is_file(PATH_APP_CONFIG_FILE)) {
            $conf = self::appConf();
        }

        $conf ??= [
            'resolve.paths'      => true,
            'resolve.autoloader' => 'auto',
            'luminova.version'   => '>=3.8',
            'luminova.paths'     => [
                'root'   => '',
                'target' => '',
            ],
        ];

        $oldPath = $conf['luminova.paths']['target'] ?? '';

        $conf['resolve.paths'] = !$isLocal;
        $conf['luminova.paths'] = [
            'root'   => $packagesDir,
            'target' => $newPath ?? $oldPath
        ];

        $content = "<?php\n{$comment}\nreturn " . var_export($conf, true) . ";\n";

        if(file_put_contents(PATH_APP_CONFIG_FILE, $content) !== false){
            return true;
        }

        if (!is_writable(PATH_APP_CONFIG_FILE)) {
            $err = 'Error ".luminova.php" file is not writable.';
        }

        return false;
    }

    /**
     * Execute a shell command and return its output and exit code.
     *
     * @return array{output: string, code: int}
     */
    private static function runCommand(string $cmd): array
    {
        $appRoot = PROJECT_APP_ROOT;
        $cwd     = getcwd();
        chdir($appRoot);

        $output = [];
        $code   = 0;

        try {
            exec($cmd . ' 2>&1', $output, $code);

            return [
                'output' => "$ {$cmd}\n" . implode("\n", $output),
                'code'   => $code,
            ];
        } finally {
            if ($cwd) {
                chdir($cwd);
            }
        }
    }

    /**
     * Run {@code composer dump-autoload --no-dev --optimize} in the project root.
     *
     * Temporarily changes the working directory to {@see PROJECT_APP_ROOT}, invokes
     * Composer using the configured {@see PHP_BIN} and {@see COMPOSER_BIN} constants,
     * captures all output including stderr, and restores the original working
     * directory before returning.
     *
     * @return array{output: string, code: int} Combined command output and process exit code.
     */
    private static function optimizeAutoload(): array
    {
        return self::runCommand(
            escapeshellarg(PHP_BIN) 
            . ' ' . escapeshellarg(COMPOSER_BIN)
            . ' dump-autoload --no-dev --optimize'
        );
    }

    /**
     * Return true when $target is located inside (or is equal to) $base.
     * Both paths are resolved to real paths before comparison.
     * 
     * @param string $base
     * @param string $target
     * 
     * @return bool 
     */
    public static function isPathWithin(string $base, string $target): bool
    {
        $base   = realpath($base);
        $target = realpath($target);

        if ($base === false || $target === false) {
            return false;
        }

        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $target = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($target, $base);
    }

    /**
     * Resolve and return the full runtime state used by the dashboard.
     *
     * @return array{
     *   packages: string,
     *   luminova_bin: string,
     *   needsSetup: bool,
     *   releases: string,
     *   current: string
     * }
     */
    public static function initRuntimeState(): array
    {
        [$packagesDir, $luminovaBin] = self::getPaths();
        $needsSetup = ($packagesDir === '') || !file_exists(PATH_CONFIG_FILE);

        return [
            'packages'      => $packagesDir,
            'luminova_bin'  => $luminovaBin ?? '',
            'needsSetup'    => $needsSetup,
            'releases'      => $packagesDir ? $packagesDir . '/releases' : '',
            'current'       => $packagesDir ? $packagesDir . '/current'  : '',
        ];
    }

    /**
     * Route a POST request to the appropriate action handler.
     *
     * Dispatches on the $action string. Actions that modify state
     * ({@code switch}, {@code import}, {@code optimize}, {@code remove.local})
     * require an active session enforced via {@see self::forceLogin()}.
     * Unknown actions receive a 400 JSON error response.
     *
     * @param string $action Action identifier matching one of the known names
     *                       (e.g. {@code 'login'}, {@code 'switch'}, {@code 'logout'}).
     * @param array  $state  Runtime state array from {@see self::initRuntimeState()}.
     *
     * @return void
     */
    public static function onPostRequest(string $action, array $state): void
    {
        switch ($action) {
            case 'login':
                self::login();
                break;
            case 'save.path':
                self::handleSavePath();
                break;
            case 'logout':
                self::logout();
                break;
            case 'switch':
                self::forceLogin(true);
                self::handleSwitch($state);
                break;
            case 'import':
                self::forceLogin(true);
                self::importModuleVersion($state);
                break;
            case 'optimize':
                self::forceLogin(true);
                $result = self::optimizeAutoload();
                self::json($result['code'] === 0, $result['output']);
                break;
            case 'reset.path':
                self::forceLogin();
                self::redirect('?action=update');
                break;
            case 'composer':
                self::forceLogin();
                self::getComposer();
                break;
            case 'remove.local':
                self::forceLogin(true);
                self::removeAndOptimizeModule();
                break;
            default:
                http_response_code(400);
                self::json(false, 'Unknown action.');
        }
    }
}

VCI::start();
$state = VCI::initRuntimeState();
$isUpdate = false;

// ── Action dispatcher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    VCI::onPostRequest($_POST['action'], $state);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? null;

    if ($action) {
        VCI::forceLogin();

        $isUpdate = $action === 'update';
    }

    if (isset($_GET['logout'])) {
        VCI::logout();
    }
}

// ── View data
$versions = VCI::getInstalledVersions($state);
[$activeVersion, $appTargetPackage, $vciResolveStatus] = VCI::getAppActiveVersion();
$isFollowCurrent = VCI::isFollowCurrent();

$loginError = VCI::getData('login_error', '');
$setupError = VCI::getData('setup_error', '');

VCI::removeData('login_error');
VCI::removeData('setup_error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="same-origin">
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy"
      content="
        default-src 'self';
        script-src 'self' 'unsafe-inline';
        style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
        font-src 'self' https://fonts.gstatic.com;
        img-src 'self' data:;
        object-src 'none';
        base-uri 'self';
        frame-ancestors 'none';
        form-action 'self';
      ">
<title>PHP Luminova — Version Control Admin Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<script>
    const CSRF = <?= json_encode(VCI::getData('csrf')) ?>;
    window.submit = async function(form, onComplete = null) {
        form.set('csrf', CSRF);
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: form
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();

        if (typeof onComplete === 'function') {
            onComplete(data);
        }

        return data;
    };

    window.colorize = function(raw) {
        if (!Array.isArray(raw)) {
            raw = raw.split('\n');
        }

        return raw.map(line => {
            const s = line.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            if (/^✔|switched|autoload|Generating|updated|successful/i.test(s)) return `<span class="t-ok">${s}</span>`;
            if (/^⚠|warning|warn/i.test(s))                              return `<span class="t-warn">${s}</span>`;
            if (/^✖|error|failed|fail|fatal/i.test(s))              return `<span class="t-err">${s}</span>`;
            if (/^\$/.test(s))                                      return `<span class="t-cmd">${s}</span>`;
            return s;
        }).join('\n');
    };
</script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #09090b;
    --surface:     #111113;
    --surface-2:   #18181b;
    --border:      #27272a;
    --border-hi:   #3f3f46;
    --text:        #e4e4e7;
    --muted:       #b5b5bc;
    --amber:       #5080c8;
    --amber-hover: #3a7ee3;
    --amber-dim:   #92400e;
    --amber-glow:  rgba(245,158,11,.12);
    --green:       #22c55e;
    --green-dim:   #14532d;
    --red:         #f87171;
    --red-dim:     #7f1d1d;
    --muted-2:   #3a3a3a;
    --amber-bg:  rgba(200,149,58,.08);
    --green-bg:  rgba(58,125,74,.08);
    --green-text:#5aaa6a;
    --red-text:  #cc5555;
    --red-bg:    rgba(160,48,48,.08);
    --font-mono: 'IBM Plex Mono', 'Fira Code', 'Cascadia Code', monospace;
    --font-sans: 'IBM Plex Sans', system-ui, sans-serif;
    --radius:    4px;
    --radius-lg: 6px;
}

html, body {
    height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-mono);
    font-size: 14px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

code {
    font-family: var(--font-mono);
    background: var(--surface-2);
    border: 1px solid var(--border-hi);
    border-radius: 3px;
    padding: .05em .3em;
    color: var(--text);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-family: var(--font-mono);
    font-size: .8125rem;
    font-weight: 500;
    cursor: pointer;
    border-radius: var(--radius);
    padding: .75rem 1rem;
    transition: background 120ms, border-color 120ms, color 120ms;
    border: 1px solid transparent;
    white-space: nowrap;
    text-decoration: none;
}

.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }

.btn-primary {
    background: var(--amber);
    color: #ffff;
    border-color: transparent;
    width: 100%;
    justify-content: center;
}

.btn-primary:hover:not(:disabled) { background: var(--amber-hover); }

.btn-ghost {
    background: transparent;
    border-color: var(--border-hi);
    color: var(--muted);
    font-size: .75rem;
    padding: .35rem .7rem;
}

.btn-ghost:hover:not(:disabled) {
    border-color: var(--muted-2);
    color: var(--text);
}

.btn-primary-sm {
    background: var(--amber);
    color: #ffff;
    border-color: transparent;
    font-weight: 600;
    flex-shrink: 0;
}

.btn-primary-sm:hover:not(:disabled) { background: var(--amber-hover); }

.btn-secondary {
    background: transparent;
    border-color: var(--border-hi);
    color: var(--muted);
    flex: 1;
    justify-content: center;
}

.btn-secondary:hover:not(:disabled) {
    border-color: var(--muted-2);
    color: var(--text);
}

.btn-danger {
    background: transparent;
    border-color: var(--red);
    color: var(--red-text);
    width: 100%;
    justify-content: center;
}

.btn-danger:hover:not(:disabled) {
    background: var(--red-bg);
}

.spinner {
    display: none;
    width: 12px; height: 12px;
    border: 1.5px solid rgba(0,0,0,.25);
    border-top-color: #0c0c0c;
    border-radius: 50%;
    animation: spin .55s linear infinite;
    flex-shrink: 0;
}

@keyframes spin { to { transform: rotate(360deg); } }

.btn-apply.loading .spinner { display: block; }
.btn-apply.loading .btn-label { opacity: .55; }

.field-label {
    display: block;
    font-size: .85rem;
    font-weight: bold;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: .35rem;
    font-family: var(--font-sans);
}

.field-input,
.field-select {
    display: block;
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    color: var(--text);
    font-family: var(--font-mono);
    font-size: .9125rem;
    padding: .75rem .97rem;
    outline: none;
    transition: border-color 120ms;
}

.field-input:focus,
.field-select:focus { border-color: var(--amber); }

.field-input::placeholder { color: var(--muted); }

.field-group { margin-bottom: 1.1rem; }

.field-hint {
    font-size: .7rem;
    color: var(--muted);
    margin-top: .3rem;
    line-height: 1.5;
}

.field-select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%235a5a5a' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .7rem center;
    padding-right: 2rem;
}

.field-select option { background: var(--surface-2); }

.alert {
    border-radius: var(--radius);
    padding: .6rem .75rem;
    font-size: .8rem;
    margin-bottom: 1.1rem;
    display: flex;
    align-items: flex-start;
    gap: .5rem;
}

.alert-error {
    background: var(--red-bg);
    border: 1px solid var(--red);
    color: var(--red-text);
}

.alert-warn {
    background: var(--amber-bg);
    border: 1px solid var(--amber);
    color: var(--amber);
}

.auth-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    padding: 2rem 1rem;
}

.auth-card {
    width: 100%;
    max-width: 420px;
    background: var(--surface);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg);
    padding: 2rem;
}

/* Logo / brand */
.auth-logo {
    display: flex;
    align-items: center;
    gap: .6rem;
    margin-bottom: 1.75rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--border);
}

.auth-logo-title {
    font-size: .875rem;
    font-weight: 500;
    letter-spacing: .06em;
    color: var(--text);
}

.auth-logo-sub {
    color: var(--muted);
    font-weight: 300;
}

.setup-paths-list {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .65rem .85rem;
    margin-bottom: 1.25rem;
    font-size: .75rem;
    color: var(--muted);
    line-height: 1.9;
}

.app {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.header {
    border-bottom: 1px solid var(--border);
    padding: 0 1.75rem;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    background: rgba(9,9,11,.96);
    backdrop-filter: blur(8px);
    z-index: 100;
    gap: 1rem;
}

.header-brand {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-shrink: 0;
    min-width: 0;
}

.header-indicator {
    width: 6px; height: 6px;
    background: var(--amber);
    border-radius: 50%;
    flex-shrink: 0;
}

.header-title {
    font-size: .8rem;
    font-weight: 500;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text);
    white-space: nowrap;
}

.header-sep {
    color: var(--border-hi);
    flex-shrink: 0;
}

.header-sub {
    font-size: .75rem;
    color: var(--muted);
    font-weight: 300;
    white-space: nowrap;
}

.header-right {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-shrink: 0;
}

.path-chip {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .2rem .55rem;
    font-size: .7rem;
    color: var(--muted);
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex-shrink: 1;
    min-width: 0;
}

.hamburger {
    display: none;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 5px;
    background: none;
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    padding: 0;
    cursor: pointer;
    width: 36px;
    height: 32px;
    flex-shrink: 0;
    transition: border-color 120ms;
}

.hamburger:hover { border-color: var(--muted-2); }

.hamburger span {
    display: block;
    width: 15px;
    height: 1.5px;
    background: var(--muted);
    border-radius: 2px;
    transition: transform 220ms ease, opacity 160ms ease, background 120ms;
    transform-origin: center;
}

.hamburger:hover span { background: var(--text); }

.hamburger[aria-expanded="true"] span:nth-child(1) {
    transform: translateY(6.5px) rotate(45deg);
}
.hamburger[aria-expanded="true"] span:nth-child(2) {
    opacity: 0;
    transform: scaleX(0.4);
}
.hamburger[aria-expanded="true"] span:nth-child(3) {
    transform: translateY(-6.5px) rotate(-45deg);
}

.mobile-nav {
    display: none;
    position: fixed;
    top: 48px;
    left: 0;
    right: 0;
    background: rgba(9,9,11,.98);
    border-bottom: 1px solid var(--border-hi);
    padding: .85rem 1.25rem 1rem;
    z-index: 99;
    flex-direction: column;
    gap: .4rem;
    backdrop-filter: blur(10px);
    box-shadow: 0 12px 32px rgba(0,0,0,.5);
    animation: mobileNavIn 180ms ease forwards;
}

@keyframes mobileNavIn {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.mobile-nav.open { display: flex; }

.mobile-nav-path {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .55rem .75rem;
    margin-bottom: .2rem;
    word-break: break-all;
}

.mobile-nav-path-label {
    display: block;
    font-size: .6rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted-2);
    font-family: var(--font-sans);
    margin-bottom: .2rem;
}

.mobile-nav-path-value {
    font-size: .725rem;
    color: var(--muted);
    line-height: 1.5;
}

.mobile-nav-divider {
    height: 1px;
    background: var(--border);
    margin: .3rem 0;
}

.mobile-nav-group {
    display: flex;
    flex-direction: column;
    gap: .35rem;
}

.mobile-nav .btn {
    width: 100%;
    justify-content: center;
    padding: .6rem 1rem;
    font-size: .8rem;
}

.mobile-nav form { width: 100%; }
.mobile-nav form .btn { width: 100%; justify-content: center; }

@media (max-width: 768px) {
    .header { padding: 0 1rem; }
    .header-right { display: none; }
    .hamburger { display: flex; }
    .path-chip { display: none; }
    .header-sep.hide-mobile { display: none; }
    .header-sub { display: none; }
}

@media (max-width: 420px) {
    .header-title { font-size: .72rem; letter-spacing: .04em; }
    .header-sep { display: none; }
}

.main {
    flex: 1;
    padding: 2rem 1.75rem;
    max-width: 800px;
    margin: 0 auto;
    width: 100%;
}

@media (max-width: 640px) {
    .main { padding: 1.5rem 1rem; }
}

.section-heading {
    font-size: 1.25rem;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: .85rem;
    display: flex;
    align-items: center;
    gap: .65rem;
    font-family: var(--font-sans);
}

.section-heading::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

.status-card {
    background: var(--surface);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.status-meta { display: flex; flex-direction: column; gap: .2rem; }

.status-label {
    font-size: .85rem;
    font-weight:bold;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    font-family: var(--font-sans);
}

.status-version {
    font-size: 2.65rem;
    font-weight: 600;
    color: var(--amber);
    letter-spacing: -.02em;
    line-height: 1;
}

.status-none {
    font-size: 1rem;
    color: var(--muted);
    font-weight: 300;
}

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border-radius: 3px;
    padding: .25rem .6rem;
    font-size: .65rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    flex-shrink: 0;
}

.status-indicator::before {
    content: '';
    width: 5px; height: 5px;
    background: var(--green-text);
    border-radius: 50%;
}

.status-live {
    background: var(--green-bg);
    border: 1px solid var(--green);
    color: var(--green-text);
}

.status-live::before {
    background: var(--green-text);
}

.status-inactive {
    background: var(--red-bg);
    border: 1px solid var(--red);
    color: var(--red-text);
}

.status-inactive::before {
    background: var(--red-text);
}

.error-card {
    background: var(--red-bg);
    border: 1px solid var(--red);
    border-radius: var(--radius-lg);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}

.error-card-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .5rem;
}

.error-card-icon {
    color: var(--red-text);
    font-size: 1.6rem;
    font-weight: 500;
    flex-shrink: 0;
}

.error-card-title {
    font-size: 1rem;
    font-weight: 500;
    color: var(--red-text);
    letter-spacing: .02em;
}

.error-card-body {
    font-size: .975rem;
    color: var(--muted);
    line-height: 1.7;
}

.error-card-body strong {
    color: var(--text);
    font-weight: 400;
}

.path-compare {
    margin-top: .6rem;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .2rem .75rem;
    font-size: .75rem;
}

.path-compare dt { color: var(--muted); }
.path-compare dd { color: var(--text); word-break: break-all; }

.switch-card {
    background: var(--surface);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}

.switch-row {
    display: flex;
    gap: .65rem;
    align-items: flex-end;
}

.select-wrap { flex: 1; }

.switch-notice {
    margin-top: .65rem;
    font-size: .85rem;
    color: var(--muted);
    display: none;
}

.switch-notice.visible { display: block; }

.result-banner {
    border-radius: var(--radius);
    padding: .6rem .85rem;
    font-size: .8rem;
    margin-bottom: 1rem;
    display: none;
    align-items: center;
    gap: .5rem;
}

.result-banner.visible { display: flex; }
.result-banner.success { background: var(--green-bg); border: 1px solid var(--green); color: var(--green-text); }
.result-banner.failure { background: var(--red-bg);   border: 1px solid var(--red);   color: var(--red-text);   }

.terminal-wrap {
    display: none;
    margin-bottom: 1.5rem;
    border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.terminal-wrap.visible { display: block; }

.terminal-bar {
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    padding: .4rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.terminal-bar-title {
    font-size: .65rem;
    color: var(--muted);
    letter-spacing: .1em;
    text-transform: uppercase;
    font-family: var(--font-sans);
}

.terminal-bar-close {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: .75rem;
    padding: 0 .2rem;
    font-family: var(--font-mono);
    line-height: 1;
    transition: color 120ms;
}

.terminal-bar-close:hover { color: var(--text); }

.terminal-body {
    background: #080808;
    padding: 1rem 1.1rem;
    font-family: var(--font-mono);
    font-size: .8rem;
    line-height: 1.8;
    color: #888;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 280px;
    overflow-y: auto;
}

.terminal-body .t-ok   { color: #5aaa6a; }
.terminal-body .t-warn { color: var(--amber); }
.terminal-body .t-err  { color: #cc5555; }
.terminal-body .t-cmd  { color: #ccc; }

.releases-card {
    background: var(--surface);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.release-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem 1.5rem;
    border-bottom: 1px solid var(--border);
    font-size: .8125rem;
}

.release-row:last-child { border-bottom: none; }
.release-row.active { background: var(--amber-bg); }

.release-version { color: var(--text); }

.release-badge-active {
    font-size: .6rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--amber);
    border: 1px solid var(--amber);
    border-radius: 3px;
    padding: .15rem .45rem;
    font-family: var(--font-sans);
}

.release-badge-installed {
    font-size: .7rem;
    color: var(--muted);
}

.no-releases {
    padding: 1.5rem;
    color: var(--muted);
    font-size: .8rem;
}

.footer {
    border-top: 1px solid var(--border);
    padding: .7rem 1.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    font-size: .7rem;
    color: var(--muted);
    letter-spacing: .03em;
    background: var(--surface);
}

.footer-brand {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-shrink: 0;
}

.footer-dot {
    width: 5px;
    height: 5px;
    background: var(--amber);
    border-radius: 50%;
    opacity: .7;
    flex-shrink: 0;
}

.footer-name {
    font-weight: 500;
    color: var(--text);
    letter-spacing: .05em;
}

.footer-name-sub {
    color: var(--muted);
    font-weight: 300;
}

.footer-right {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-shrink: 0;
}

.footer-links {
    display: flex;
    align-items: center;
    gap: .6rem;
}

.footer-links a {
    color: var(--muted);
    text-decoration: none;
    transition: color 120ms;
    letter-spacing: .03em;
}

.footer-links a:hover { color: var(--text); }

.footer-sep-v {
    width: 1px;
    height: 10px;
    background: var(--border-hi);
    flex-shrink: 0;
}

.footer-session {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .18rem .5rem;
    font-size: .67rem;
    letter-spacing: .04em;
    color: var(--muted);
    white-space: nowrap;
}

.footer-session-dot {
    width: 5px;
    height: 5px;
    background: var(--green-text);
    border-radius: 50%;
    flex-shrink: 0;
    animation: sessionPulse 2.5s ease-in-out infinite;
}

@keyframes sessionPulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: .35; }
}

@media (max-width: 640px) {
    .footer { padding: .65rem 1rem; }
    .footer-links { display: none; }
    .footer-sep-v { display: none; }
}

@media (max-width: 380px) {
    .footer-name-sub { display: none; }
}

.icon {
    fill: none;
    margin: auto;
    display: block;
}
</style>
</head>
<body>
<svg xmlns="http://www.w3.org/2000/svg" style="display:none;">
    <symbol id="luminova-vci-logo" data-viewBox="0 0 400 100" viewBox="10 10 330 100">
        <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#063ca9"/>
                <stop offset="50%" stop-color="#0750e1"/>
                <stop offset="100%" stop-color="#0880e9"/>
            </linearGradient>

            <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
            <feGaussianBlur stdDeviation="4" result="blur"/>
            <feMerge>
                <feMergeNode in="blur"/>
                <feMergeNode in="SourceGraphic"/>
            </feMerge>
            </filter>
        </defs>

        <g transform="translate(20,20)">
            <circle cx="40" cy="40" r="34" fill="none" stroke="url(#g)" stroke-width="6"/>
            <path d="M25 25 L40 60 L55 25"
                fill="none"
                stroke="url(#g)"
                stroke-width="6"
                stroke-linecap="round"
                stroke-linejoin="round"
                filter="url(#glow)"/>
            <circle cx="40" cy="60" r="4.5" fill="#7aa1f0"/>
        </g>

        <text x="110" y="62"
                font-family="Inter, Segoe UI, Arial, sans-serif"
                font-size="28"
                fill="#063ca9"
                font-weight="600">
            PHP LUMINOVA
        </text>

        <text x="110" y="88"
                font-family="Inter, Segoe UI, Arial, sans-serif"
                font-size="18"
                fill="#acacb3"
                letter-spacing="2">
            VCI SYSTEM
        </text>
    </symbol>
</svg>
<?php if ($state['needsSetup'] || $isUpdate): ?>
<style>
.wizard-steps {
    display: flex;
    align-items: center;
    margin-bottom: 1.75rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border);
    gap: 0;
}

.wizard-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .35rem;
    flex: 1;
    position: relative;
}

.wizard-step-circle {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 1.5px solid var(--border-hi);
    background: var(--surface-2);
    color: var(--muted);
    font-size: .7rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color 200ms, background 200ms, color 200ms;
    position: relative;
    z-index: 1;
}

.wizard-step.active .wizard-step-circle {
    border-color: #5080c8;
    background: rgba(80,128,200,.15);
    color: #7aa0e0;
}

.wizard-step.done .wizard-step-circle {
    border-color: var(--green);
    background: rgba(34,197,94,.1);
    color: var(--green-text);
}

.wizard-step-label {
    font-size: .6rem;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: var(--muted);
    font-family: var(--font-sans);
    white-space: nowrap;
    transition: color 200ms;
}

.wizard-step.active .wizard-step-label { color: #7aa0e0; }
.wizard-step.done   .wizard-step-label { color: var(--green-text); }

.wizard-connector {
    flex: 1;
    height: 1px;
    background: var(--border);
    margin: 0;
    position: relative;
    top: -10px; 
    transition: background 200ms;
}

.wizard-connector.done { background: var(--green); }

.setup-step-section { display: none; }
.setup-step-section.active { display: block; }

.wizard-nav {
    display: flex;
    gap: .6rem;
    margin-top: 1.5rem;
}

.step-error {
    display: none;
    background: var(--red-bg);
    border: 1px solid var(--red);
    color: var(--red-text);
    border-radius: var(--radius);
    padding: .5rem .75rem;
    font-size: .775rem;
    margin-bottom: .9rem;
}

.step-error.visible { display: block; }
.auth-card.wizard { max-width: 460px; }
</style>

<div class="auth-wrap">
    <div class="auth-card wizard">

        <div class="auth-logo">
            <span class="auth-logo-title">Luminova <span class="auth-logo-sub">/ Setup</span></span>
        </div>

        <svg class="icon" width="300" height="100">
            <use href="#luminova-vci-logo"></use>
        </svg>

        <div class="wizard-steps" id="wizard-steps" aria-label="Setup progress">
            <div class="wizard-step active" data-step="1">
                <div class="wizard-step-circle">1</div>
                <span class="wizard-step-label">Directory</span>
            </div>
            <div class="wizard-connector" id="conn-1"></div>
            <div class="wizard-step" data-step="2">
                <div class="wizard-step-circle">2</div>
                <span class="wizard-step-label">Binaries</span>
            </div>
            <div class="wizard-connector" id="conn-2"></div>
            <div class="wizard-step" data-step="3">
                <div class="wizard-step-circle">3</div>
                <span class="wizard-step-label">Password</span>
            </div>
        </div>

        <?php if ($setupError): ?>
            <div class="alert alert-error" id="server-error"><?= htmlspecialchars($setupError) ?></div>
        <?php endif; ?>
        <?php if (!empty($_GET['get-composer']) && (int) $_GET['get-composer'] === 1): ?>
            <script>setTimeout(() => shouldInstallComposer(), 2000); </script>
            <div id="composer-result" style="display:none;margin-bottom:1rem;border:1px solid var(--border-hi);border-radius:var(--radius-lg);overflow:hidden;">
                <div class="terminal-bar">
                    <span class="terminal-bar-title">composer output</span>
                    <button class="terminal-bar-close" type="button"
                        onclick="document.getElementById('composer-result').style.display='none'">✕</button>
                </div>
                <div class="terminal-body" id="composer-output" style="max-height:160px"></div>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" id="setup-form" novalidate>
            <input type="hidden" name="action"          value="save.path">
            <input type="hidden" name="luminova_bin"    value="<?= htmlspecialchars($state['luminova_bin']) ?>">
            <input type="hidden" name="csrf"            value="<?= htmlspecialchars(VCI::getData('csrf')) ?>">

            <div class="setup-step-section active" data-step="1">

                <div class="setup-intro" style="margin-bottom:1rem">
                    <h3 class="setup-intro-title">VCI PACKAGE DIRECTORY</h3>
                    <p class="setup-intro-text">
                        <?php if(empty($state['packages'])): ?>
                        The Luminova packages base directory could not be found automatically.
                        Provide the absolute path where releases are stored.
                        <?php else: ?>
                        Set Luminova packages base directory.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="setup-paths-list" style="margin-bottom:1rem">
                    Find Path: <code>luminova --where=packages</code> &nbsp;·&nbsp;<code>luminova --paths</code>
                </div>

                <div class="step-error" id="err-1"></div>

                <div class="field-group">
                    <label class="field-label" for="luminova_base">Packages directory</label>
                    <input class="field-input" type="text" id="luminova_base" name="luminova_base"
                        placeholder="/opt/luminova/packages"
                        value="<?= htmlspecialchars($state['packages']) ?>"
                        autocomplete="on"
                        spellcheck="false" autofocus>
                    <p class="field-hint">Must not target <code>releases/</code> or <code>current/</code> sub-directory.</p>
                </div>

                <div class="wizard-nav">
                    <?php if($isUpdate): ?>
                        <a class="btn btn-secondary" href="<?= __SELF__ ?>">&larr; Home</a>
                    <?php endif;?>
                    <button class="btn btn-primary" type="button"
                        style="background:#5080c8;color:#fff;border-color:transparent"
                        onclick="wizardNext(1)">
                        Next &rarr;
                    </button>
                </div>
            </div>

            <div class="setup-step-section" data-step="2">

                <div class="setup-intro" style="margin-bottom:1rem">
                    <h3 class="setup-intro-title">BINARY PATHS</h3>
                    <p class="setup-intro-text">
                        Provide the absolute paths to the Composer and PHP executables
                        used when switching releases.
                    </p>
                </div>

                <div class="setup-paths-list" style="margin-bottom:1rem">
                    Find Path: <code>command -v composer</code> &nbsp;·&nbsp;<code>command -v php</code>
                </div>

                <div class="step-error" id="err-2"></div>

                <div class="field-group">
                    <label class="field-label" for="composer_bin">Composer binary</label>
                    <input class="field-input" type="text" id="composer_bin" name="composer_bin"
                        placeholder="/usr/local/bin/composer"
                        value="<?= htmlspecialchars(COMPOSER_BIN) ?>"
                        autocomplete="on"
                        spellcheck="false">
                    <p class="field-hint">
                        Run <code>command -v composer</code> if unsure.
                    </p>
                </div>

                <div class="field-group">
                    <label class="field-label" for="php_bin">PHP binary</label>
                    <input class="field-input" type="text" id="php_bin" name="php_bin"
                        placeholder="/usr/bin/php"
                        value="<?= htmlspecialchars(PHP_BIN) ?>"
                        autocomplete="on"
                        spellcheck="false">
                    <p class="field-hint">
                        PHP CLI binary used to run Composer.
                        Run <code>command -v php</code> if unsure.
                    </p>
                </div>

                <div class="wizard-nav">
                    <button class="btn btn-secondary" type="button" onclick="wizardPrev(2)">&larr; Back</button>
                    <button class="btn btn-primary" type="button"
                        style="background:#5080c8;color:#fff;border-color:transparent"
                        onclick="wizardNext(2)">
                        Next &rarr;
                    </button>
                </div>
            </div>

            <div class="setup-step-section" data-step="3">

                <div class="setup-intro" style="margin-bottom:1rem">
                    <h3 class="setup-intro-title">ADMIN PASSWORD</h3>
                    <p class="setup-intro-text">
                        Optionally change the admin password. Leave both fields blank
                        to keep the existing password.
                    </p>
                </div>

                <div class="step-error" id="err-3"></div>

                <div class="field-group">
                    <label class="field-label" for="new_password">New password</label>
                    <input class="field-input" type="password" id="new_password" name="new_password"
                        placeholder="Leave blank to keep existing"
                        autocomplete="new-password">
                    <p class="field-hint">Minimum 8 characters.</p>
                </div>

                <div class="field-group">
                    <label class="field-label" for="confirm_password">Confirm password</label>
                    <input class="field-input" type="password" id="confirm_password" name="confirm_password"
                        placeholder="Repeat new password"
                        autocomplete="new-password">
                </div>

                <div class="wizard-nav">
                    <button class="btn btn-secondary" type="button" onclick="wizardPrev(3)">&larr; Back</button>
                    <button class="btn btn-primary" type="submit"
                        style="background:#5080c8;color:#fff;border-color:transparent">
                        <?= ($isUpdate ? 'Update Config' : 'Save &amp; Finish') ?>
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const TOTAL   = 3;
    let current   = 1;

    const serverErr = document.getElementById('server-error');
    const to = <?= json_encode(max(1, (int) ($_GET['tab'] ?? 1)));?>;

    if (serverErr) {
        goToStep(to);
    }

    if(to > 1){
        setTimeout(() =>  goToStep(to), 2000);
    }

    function stepEl(n)      { return document.querySelector('.setup-step-section[data-step="' + n + '"]'); }
    function dotEl(n)       { return document.querySelector('.wizard-step[data-step="' + n + '"]'); }
    function errEl(n)       { return document.getElementById('err-' + n); }
    function connEl(n)      { return document.getElementById('conn-' + n); }

    function showErr(step, msg) {
        const el = errEl(step);
        if (!el) return;
        el.textContent = msg;
        el.classList.add('visible');
    }

    function clearErr(step) {
        const el = errEl(step);
        if (el) { el.textContent = ''; el.classList.remove('visible'); }
    }

    function validateStep(step) {
        clearErr(step);

        if (step === 1) {
            const val = document.getElementById('luminova_base').value.trim();
            if (!val) { showErr(1, 'Packages directory is required.'); return false; }
            if (!val.startsWith('/')) { showErr(1, 'Path must be absolute (start with /).'); return false; }
            return true;
        }

        if (step === 2) {
            const comp = document.getElementById('composer_bin').value.trim();
            const php  = document.getElementById('php_bin').value.trim();
            if (comp && !comp.startsWith('/')) { showErr(2, 'Composer path must be absolute.'); return false; }
            if (php  && !php.startsWith('/'))  { showErr(2, 'PHP path must be absolute.'); return false; }
            return true;
        }

        if (step === 3) {
            const pw  = document.getElementById('new_password').value;
            const pw2 = document.getElementById('confirm_password').value;
            if (pw !== '' && pw.length < 8) { showErr(3, 'Password must be at least 8 characters.'); return false; }
            if (pw !== pw2)                 { showErr(3, 'Passwords do not match.'); return false; }
            return true;
        }

        return true;
    }

    function goToStep(n) {
        for (let i = 1; i <= TOTAL; i++) {
            const s = stepEl(i);
            if (s) s.classList.toggle('active', i === n);
        }

        for (let i = 1; i <= TOTAL; i++) {
            const d = dotEl(i);
            if (!d) continue;
            d.classList.remove('active', 'done');
            if (i < n)  d.classList.add('done');
            if (i === n) d.classList.add('active');

            const circle = d.querySelector('.wizard-step-circle');
            if (circle) circle.textContent = (i < n) ? '✔' : String(i);
        }

        for (let i = 1; i < TOTAL; i++) {
            const c = connEl(i);
            if (c) c.classList.toggle('done', i < n);
        }

        current = n;

        const section = stepEl(n);
        if (section) {
            const first = section.querySelector('input');
            if (first) setTimeout(() => first.focus(), 50);
        }
    }

    window.shouldInstallComposer = function() {
        if (!confirm(
            'Install a single composer.phar file into the project root?'
        )) {
            return;
        }

        installComposer();
    };

    window.installComposer = async function () {
        const resultEl = document.getElementById('composer-result');
        const outputEl = document.getElementById('composer-output');

        if (resultEl) { resultEl.style.display = 'none'; }
        if (outputEl) { outputEl.innerHTML = ''; }

        const form = new FormData();
        form.set('action', 'composer');

        try {
            await submit(form, (data) => {
                const success = !!data?.success;
                if (outputEl) { outputEl.innerHTML = colorize(data.output); }
                if (resultEl) { resultEl.style.display = 'block'; }
            });
        } catch (err) {
            if (outputEl) {
                outputEl.innerHTML = `<span class="t-err">✖ Request failed: ${err.message}</span>`;
            }
            if (resultEl) { resultEl.style.display = 'block'; }
        }
    };

    window.wizardNext = function (step) {
        if (!validateStep(step)) return;
        if (step < TOTAL) goToStep(step + 1);
    };

    window.wizardPrev = function (step) {
        if (step > 1) { clearErr(step); goToStep(step - 1); }
    };

    document.getElementById('setup-form').addEventListener('submit', function (e) {
        if (!validateStep(3)) { e.preventDefault(); }
    });

    document.getElementById('setup-form').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        const active = document.querySelector('.setup-step-section.active');
        if (!active) return;
        const step = parseInt(active.dataset.step, 10);
        if (step < TOTAL) {
            e.preventDefault();
            wizardNext(step);
        }
    });

}());
</script>

<?php elseif (!VCI::isLoggedIn()): ?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">
            <span class="auth-logo-title">Luminova <span class="auth-logo-sub">/ Admin Manager</span></span>
        </div>

        <svg class="icon" width="300" height="100">
            <use href="#luminova-vci-logo"></use>
        </svg>

        <?php if ($loginError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="login">

            <div class="field-group">
                <label class="field-label" for="username">Username</label>
                <input 
                    class="field-input" 
                    type="text" 
                    id="username" 
                    name="username"
                    placeholder="Username"
                    required autofocus autocomplete="username" spellcheck="false">
            </div>

            <div class="field-group">
                <label class="field-label" for="password">Password</label>
                <input 
                    class="field-input" 
                    type="password" 
                    id="password" 
                    name="password"
                    placeholder="Password"
                    required autocomplete="current-password">
            </div>

            <button class="btn btn-primary" type="submit">Sign in</button>
        </form>
    </div>
</div>

<?php else: ?>
<div class="app">

    <header class="header" id="site-header">
        <div class="header-brand">
            <svg class="icon" width="200" height="50" aria-label="PHP Luminova VCI">
                <use href="#luminova-vci-logo"></use>
            </svg>
            <span class="header-title">Admin Dashboard</span>
            <span class="header-sep hide-mobile" aria-hidden="true">/</span>
            <span class="path-chip" title="<?= htmlspecialchars($state['packages']) ?>">
                <?= htmlspecialchars($state['packages']) ?>
            </span>
        </div>

        <nav class="header-right" aria-label="Site navigation">
            <a href="https://luminova.ng" class="btn btn-ghost" target="_blank" rel="noopener noreferrer">Luminova</a>
            <a href="https://github.com/luminovang/vci-admin/" class="btn btn-ghost" target="_blank" rel="noopener noreferrer">Github</a>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="reset.path">
                <button class="btn btn-ghost" type="submit">Update Setup</button>
            </form>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-ghost" type="submit">Sign out</button>
            </form>
        </nav>

        <button
            class="hamburger"
            id="hamburger-btn"
            aria-label="Open navigation menu"
            aria-expanded="false"
            aria-controls="mobile-nav"
            type="button">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav class="mobile-nav" id="mobile-nav" aria-label="Mobile navigation" aria-hidden="true">
            <div class="mobile-nav-path">
                <span class="mobile-nav-path-label">Packages directory</span>
                <span class="mobile-nav-path-value"><?= htmlspecialchars($state['packages']) ?></span>
            </div>
            <div class="mobile-nav-divider"></div>
            <div class="mobile-nav-group">
                <a href="https://luminova.ng" class="btn btn-ghost" target="_blank" rel="noopener noreferrer">
                    Luminova &#x2197;
                </a>
                <a href="https://github.com/luminovang/vci/" class="btn btn-ghost" target="_blank" rel="noopener noreferrer">
                    Github (CLI) &#x2197;
                </a>
                <a href="https://github.com/luminovang/vci-admin/" class="btn btn-ghost" target="_blank" rel="noopener noreferrer">
                    Github &#x2197;
                </a>
            </div>
            <div class="mobile-nav-divider"></div>
            <div class="mobile-nav-group">
                <form method="POST">
                    <input type="hidden" name="action" value="reset.path">
                    <button class="btn btn-ghost" type="submit">Update Setup</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn btn-danger" type="submit">Sign out</button>
                </form>
            </div>
        </nav>
    </header>

    <main class="main">
        <?php if (VCI::isPathWithin($state['packages'], $appTargetPackage)): ?>
            <?php if($vciResolveStatus === false): ?>
                <div class="status-card">
                    <div class="status-meta">
                        <span class="status-label">Currently deployed</span>
                            <span class="status-version" id="js-active-version">
                                <?= htmlspecialchars($activeVersion) ?>
                            </span>
                            <p class="switch-notice visible">
                                Application is using local luminova modules and does not enable Luminova shared module feature in <code>.luminova.php -> resolve.paths</code>. Select preferred version and click <code>"Apply"</code> to enable shared module
                            </p>
                    </div>
                    <span class="status-indicator status-inactive" id="js-live-badge">Local</span>
                </div>
            <?php else: ?>
                <div class="section-heading">Active Release</div>
                <?php if(VCI::isModuleConflict()): ?>
                    <div class="error-card" id="remove-notice">
                        <div class="error-card-header">
                            <span class="error-card-icon">⚠</span>
                            <span class="error-card-title">Ambiguous Module Detected</span>
                        </div>

                        <div class="error-card-body">
                            This application is currently using both VCI shared module autoloading and local project modules. Removing local framework files can reduce disk usage and avoid duplicated resources.

                            <br><br>

                            <strong>Remove local framework modules?</strong>

                            <dl class="path-compare">
                                <dt>/bootstrap/</dt>
                                <dd>
                                    Removes all files from <code>bootstrap/*</code>
                                    except <code>bootstrap/constants.php</code>
                                </dd>

                                <dt>/system/</dt>
                                <dd>
                                    Removes all files from <code>system/*</code>
                                    except <code>system/Boot.php</code> and
                                    <code>system/plugins/*</code>
                                </dd>
                            </dl>
                            <div style="margin-top:1rem;text-align:right">
                                <button id="remove-btn" class="btn btn-primary-sm" type="button">
                                    <span class="spinner"></span>
                                    <span class="btn-label">Fix Conflict</span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="status-card">
                    <div class="status-meta">
                        <span class="status-label">Currently deployed</span>
                        <?php if ($activeVersion): ?>
                            <span class="status-version" id="js-active-version">
                                <?= htmlspecialchars($activeVersion) ?>
                            </span>
                            <p class="switch-notice visible">
                                <?php if ($isFollowCurrent): ?>
                                    Application follows the latest Luminova stable version.
                                <?php else: ?>
                                    Application is locked to Luminova <?= htmlspecialchars($activeVersion) ?>.
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <span class="status-none" id="js-active-version">none</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($activeVersion): ?>
                        <span class="status-indicator status-live" id="js-live-badge">Live</span>
                    <?php else: ?>
                        <span class="status-indicator status-inactive" id="js-live-badge">Inactive</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="section-heading">Active Release</div>
            <div class="error-card">
                <div class="error-card-header">
                    <span class="error-card-icon">⚠</span>
                    <span class="error-card-title">Path Mismatch</span>
                </div>
                <div class="error-card-body">
                    The target path in <code>.luminova.php</code> does not fall within the
                    configured packages directory.
                    <dl class="path-compare">
                        <dt>Expected</dt>
                        <dd><code><?= htmlspecialchars(VCI::buildCanonicalPath($state['packages'], $appTargetPackage)) ?></code></dd>
                        <dt>Found</dt>
                        <dd><code><?= htmlspecialchars($appTargetPackage) ?></code></dd>
                    </dl>
                </div>
            </div>
        <?php endif; ?>
        <div class="section-heading">Switch Version</div>

        <div class="switch-card">
            <?php if ($versions): ?>
            <div class="switch-row">
                <div class="select-wrap">
                    <label class="field-label" for="version-select">Target release</label>
                    <select class="field-select" id="version-select">
                        <?php $isSelected  = false; ?>
                        <?php foreach ($versions as $vn => $v): ?>
                            <?php
                                $isActive  = false;
                                $isCurrent = ($v === 'current' && is_string($vn));
                                $value     = htmlspecialchars($v);
                                $label     = htmlspecialchars($isCurrent ? $vn : $v);
                                
                                if(!$isSelected){
                                    $isActive  = ($isFollowCurrent && $label === $activeVersion)
                                        || ($value === $activeVersion);

                                    $isSelected = $isActive;
                                }
                            ?>
                            <option value="<?= $value ?>" <?= $isActive ? 'selected' : '' ?>>
                                <?= $label ?>
                                <?= $isActive  ? ' (active)'  : '' ?>
                                <?= $isCurrent ? ' (current-stable)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button 
                    class="btn btn-primary-sm" 
                    id="apply-btn" 
                    type="button" 
                    title="Switch to the selected version and deploy it to the application."
                    aria-describedby="switch-notice">
                    <span class="spinner"></span>
                    <span class="btn-label">Apply</span>
                </button>
                <button 
                    class="btn btn-primary-sm" 
                    id="import-btn" 
                    type="button" 
                    title="Import the selected version into project modules directory and disable shared module feature."
                    aria-describedby="switch-notice">
                    <span class="spinner"></span>
                    <span class="btn-label">Localize</span>
                </button>
            </div>
            <p class="switch-notice" id="switch-notice">
                Select the Luminova version for this application.
            </p>

            <?php else: ?>
            <p class="no-releases">
                No releases installed. If you are system admin, run <code>luminova package --install</code> first.
            </p>
            <?php endif; ?>
        </div>

        <div class="result-banner" id="result-banner"></div>

        <div class="terminal-wrap" id="terminal-wrap">
            <div class="terminal-bar">
                <span class="terminal-bar-title">deploy output</span>
                <button class="terminal-bar-close" id="terminal-close" type="button">✕</button>
            </div>
            <div class="terminal-body" id="terminal-body"></div>
        </div>

        <div class="section-heading">Installed Releases</div>

        <div class="releases-card" id="releases-list">
            <?php if ($versions): ?>
                <?php foreach ($versions as $vn => $v): ?>
                    <?php
                        if ($v === 'current') continue;
                        $displayVer =  htmlspecialchars($v);
                        $isActive   = ($v === $activeVersion);
                    ?>
                    <div class="release-row <?= $isActive ? 'active' : '' ?>" data-version="<?= $displayVer ?>">
                        <span class="release-version"><?= $displayVer ?></span>
                        <?php if ($isActive): ?>
                            <span class="release-badge-active" data-badge>active</span>
                        <?php else: ?>
                            <span class="release-badge-installed" data-badge>installed</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="no-releases">No releases installed.</div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="footer-brand">
            <span class="footer-name">
                PHP Luminova Version Control Admin <span class="footer-name-sub">/ v<?= VCI::VERSION ?></span> &middot;
            </span>
            <div class="footer-links">
                <a 
                    href="https://luminova.ng" 
                    target="_blank" 
                    rel="noopener noreferrer">Docs</a>
                    <span class="footer-name-sub">/</span>
                <a 
                    href="https://github.com/luminovang/vci-admin/" 
                    target="_blank" 
                    rel="noopener noreferrer">Github</a>
                <span class="footer-name-sub">/</span>
                <a 
                    href="https://github.com/luminovang/vci/" 
                    target="_blank" 
                    rel="noopener noreferrer">Github (CLI)</a>
            </div>
        </div>
        <div class="footer-right">
            <span class="footer-sep-v" aria-hidden="true"></span>
            <span class="footer-session">
                <span class="footer-session-dot" aria-hidden="true"></span>
                Session &middot; <?= VCI::getRemainingSessionFormatted() ?>
                <span class="footer-session-dot" aria-hidden="true"></span>
                IP &middot; <?= VCI::getData('auth_ip'); ?>
            </span>
        </div>
    </footer>
</div>

<script>
(function () {
    'use strict';

    const hamburgerBtn = document.getElementById('hamburger-btn');
    const mobileNav    = document.getElementById('mobile-nav');

    if (hamburgerBtn && mobileNav) {
        let isOpen = false;

        function openMenu() {
            isOpen = true;
            mobileNav.classList.add('open');
            hamburgerBtn.setAttribute('aria-expanded', 'true');
            mobileNav.setAttribute('aria-hidden', 'false');

            setTimeout(() => document.addEventListener('click', onOutsideClick), 10);
            document.addEventListener('keydown', onEscKey);
        }

        function closeMenu() {
            isOpen = false;
            mobileNav.classList.remove('open');
            hamburgerBtn.setAttribute('aria-expanded', 'false');
            mobileNav.setAttribute('aria-hidden', 'true');
            document.removeEventListener('click', onOutsideClick);
            document.removeEventListener('keydown', onEscKey);
        }

        function onEscKey(e) {
            if (e.key === 'Escape') { closeMenu(); hamburgerBtn.focus(); }
        }

        function onOutsideClick(e) {
            if (!mobileNav.contains(e.target) && e.target !== hamburgerBtn) {
                closeMenu();
            }
        }

        hamburgerBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            isOpen ? closeMenu() : openMenu();
        });

        // Close menu on window resize to desktop width
        window.addEventListener('resize', () => {
            if (isOpen && window.innerWidth > 768) closeMenu();
        }, { passive: true });
    }

    let ACTIVE_VER = <?= json_encode($isFollowCurrent ? 'current' : $activeVersion) ?>;

    const selectEl    = document.getElementById('version-select');
    const applyBtn    = document.getElementById('apply-btn');
    const importBtn   = document.getElementById('import-btn');
    const removeBtn   = document.getElementById('remove-btn');
    const notice      = document.getElementById('switch-notice');
    const banner      = document.getElementById('result-banner');
    const termWrap    = document.getElementById('terminal-wrap');
    const termBody    = document.getElementById('terminal-body');
    const termClose   = document.getElementById('terminal-close');
    const statusVer   = document.getElementById('js-active-version');
    const liveBadge   = document.getElementById('js-live-badge');

    if (!selectEl) return;

    function showNotice() {
        notice.classList.toggle('visible', selectEl.value === ACTIVE_VER);
    }

    function runOptimize(version){
        const runOptimize = confirm(
            `Your project is already set to '${version}'.\nRun composer autoload optimization instead?`
        );

        if (!runOptimize) return;

        switchVersion('optimize', version);
    }

    /**
     * Update the dashboard DOM in-place after a successful version switch.
     * Avoids a full page reload while keeping displayed state accurate.
     */
    function applyVersionToDom(version) {
        if (statusVer) {
            statusVer.textContent = version;
            statusVer.className   = 'status-version';
        }
        if (liveBadge) {
            liveBadge.style.display = '';
        }

        document.querySelectorAll('.release-row').forEach(row => {
            const ver    = row.dataset.version;
            const badge  = row.querySelector('[data-badge]');
            const active = ver === version;

            row.classList.toggle('active', active);

            if (badge) {
                if (active) {
                    badge.className   = 'release-badge-active';
                    badge.textContent = 'active';
                } else {
                    badge.className   = 'release-badge-installed';
                    badge.textContent = 'installed';
                }
            }
        });

        Array.from(selectEl.options).forEach(opt => {
            opt.text = opt.text.replace(/\s*\(active\)/, '');
            if (opt.value === version) opt.text += ' (active)';
        });
    }

    function response(message, success){
        banner.textContent = message;
        banner.className = 'result-banner visible ' + (success ? 'success' : 'failure');
    }

    async function switchVersion(action, version) {
        applyBtn.disabled = true;
        applyBtn.classList.add('loading');

        banner.className = 'result-banner';
        banner.textContent = '';

        termWrap.classList.remove('visible');
        termBody.innerHTML = '';

        try {
            const form = new FormData();
            form.set('action', action);
            form.set('version', version);

            await submit(form, (data) => {
                const success = !!data?.success;

                if (data.output) {
                    termBody.innerHTML = colorize(data.output);
                    termWrap.classList.add('visible');
                    termBody.scrollTop = termBody.scrollHeight;
                }

                response((action === 'optimize') 
                    ? (success 
                        ? `✔ Composer optimize version ${version} autoload.` 
                        : '✖ Composer optimize failed. See output above.')
                    : (success 
                        ? `✔ Version ${version} is now active.` 
                        : '✖ Switch failed. See output above.'),
                    success
                );

                if (success) {
                    applyVersionToDom(version);
                    showNotice();
                    ACTIVE_VER = version;
                }
            });
        } catch (error) {
            response(`✖ Request failed: ${error.message}`, false);
        } finally {
            applyBtn.disabled = false;
            applyBtn.classList.remove('loading');
        }
    }

    selectEl.addEventListener('change', showNotice);
    showNotice();

    termClose.addEventListener('click', () => {
        termWrap.classList.remove('visible');
        termBody.innerHTML = '';
    });

    applyBtn.addEventListener('click', async () => {
        const version = selectEl.value;

        if (version === ACTIVE_VER) {
            runOptimize(version);
            return;
        }

        const confirmed = confirm(
            `Switch project luminova version to '${version}'?\n\n`
            + 'This will:\n'
            + '  1. Update .luminova.php release path\n'
            + '  2. Run composer autoload optimization\n'
            + '  3. Modify composer.json'
        );

        if (!confirmed) {
            return;
        }

        switchVersion('switch', version);
    });

    importBtn.addEventListener('click', async () => {
        const version = selectEl.value;

        if (version === ACTIVE_VER) {
            runOptimize(version);
            return;
        }

        const confirmed = confirm(
            `Importing '${version}' will copy framework files into project modules directory`
            + '\nand disable VCI shared module feature.\n\n'
            + 'This can increase disk usage.'
        );

        if (!confirmed) {
            return;
        }

        switchVersion('import', version);
    });

    if(removeBtn !== null){
        removeBtn.addEventListener('click', async () => {
            removeBtn.disabled = true;
            removeBtn.classList.add('loading');

            banner.className = 'result-banner';
            banner.textContent = '';

            termWrap.classList.remove('visible');
            termBody.innerHTML = '';

            try {
                const form = new FormData();
                form.set('action', 'remove.local');

                await submit(form, (data) => {
                    const success = !!data?.success;

                    if (data.output) {
                        termBody.innerHTML = colorize(data.output);
                        termWrap.classList.add('visible');
                        termBody.scrollTop = termBody.scrollHeight;
                    }

                    response(success 
                            ? `✔ Ambiguous local modules removed.` 
                            : '✖ Failed to remove ambiguous modules. See output above.',
                        success
                    );

                    if (success) {
                        const removeNotice = document.getElementById('remove-notice');
                        removeNotice.style.display = 'none';
                        showNotice();
                    }
                });
            } catch (error) {
                response(`✖ Request failed: ${error.message}`, false);
            } finally {
                removeBtn.disabled = false;
                removeBtn.classList.remove('loading');
            }
        });
    }
}());
</script>
<?php endif; ?>
</body>
</html>
