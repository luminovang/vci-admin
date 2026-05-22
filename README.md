![PHP Luminova VCI](https://github.com/luminovang/luminova-vci-admin/blob/main/logo.svg?v=2)

# PHP Luminova — VCI Admin Dashboard

**Version Control Interface for the PHP Luminova Framework**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/github/license/luminovang/luminova-vci-admin)](LICENSE)
[![Luminova](https://img.shields.io/badge/Luminova-VCI-0750e1)](https://luminova.ng)

---

Luminova **VCI Admin (`vci.php`)** is a single-file, browser-based admin panel for managing [PHP Luminova](https://luminova.ng) framework installations. Instead of bundling a full framework copy inside every project, VCI lets multiple projects share one central installation and switch between released versions from a simple web UI — similar to cPanel's PHP version switcher, but for the Luminova framework itself.

## Table of Contents

- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Directory Structure](#directory-structure)
- [First-Time Setup](#first-time-setup)
- [Configuration](#configuration)
- [Default Credentials](#default-credentials)
- [Changing Your Password](#changing-your-password)
- [Version Switching](#version-switching)
- [Localizing a Version](#localizing-a-version)
- [Composer Management](#composer-management)
- [Session Management](#session-management)
- [Security](#security)
- [Deployment Checklist](#deployment-checklist)
- [Troubleshooting](#troubleshooting)
- [Constants Reference](#constants-reference)

---

## How It Works

By default every Luminova project ships with its own copy of the framework files. VCI replaces those per-project copies with a single shared installation stored in a central directory on your server (e.g. `/opt/luminova`).

Each project's `.luminova.php` contains a `target` path pointing to one release inside that shared directory. Clicking **Apply** in the VCI dashboard rewrites that path and re-runs `composer dump-autoload` — no downtime, no manual file editing.

```
/opt/luminova/                  ← shared packages root
├── releases/
│   ├── 3.8.0/
│   ├── 3.9.0/
│   └── 4.0.0/
└── current → releases/4.0.0   ← symlink to latest stable
```

Your project's `.luminova.php` points to a specific release (or to `current` to always follow the latest).

**Benefits:** one framework installation shared across many projects; instant version switches; significant disk savings; centralized updates; compatible with shared hosting where global CLI tools may not be available.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.0 (8.2+ recommended) |
| PHP extensions | `session`, `json`, `tokenizer` |
| Composer | Any recent version (or `composer.phar` in project root) |
| Luminova VCI CLI | [luminovang/luminova-vci](https://github.com/luminovang/luminova-vci) |
| Filesystem | Write access to your project root |

> **Password hashing:** 
> VCI prefers `PASSWORD_ARGON2ID` (PHP 7.3+) and falls back to `PASSWORD_BCRYPT` automatically.

---

## Installation

VCI is a single self-contained PHP file with no Composer dependency and no framework bootstrap required.

**Step 1 — Download**

```bash
wget https://raw.githubusercontent.com/luminovang/luminova-vci-admin/main/vci.php
# or
git clone https://github.com/luminovang/luminova-vci-admin.git
```

**Step 2 — Place the file**

Drop `vci.php` into your project's public web root:

```
your-project/
├── public/
│   └── vci.php       ← place it here
├── .luminova.php
└── composer.json
```

> **Security tip:** 
> Rename the file to something non-obvious (e.g. `_panel_xyz.php`). VCI works with any filename. 
> See [Deployment Checklist](#deployment-checklist) for more hardening options.

**Step 3 — Open in your browser**

Navigate to `https://your-domain.com/vci.php`. The setup wizard launches automatically on first run.

---

## Directory Structure

VCI expects the shared packages directory to follow this layout:

```
{packages_dir}/
├── releases/          ← required sub-directory
│   ├── 3.8.0/
│   ├── 3.9.0/
│   └── 4.0.0/
└── current            ← symlink pointing to the active release
```

The `releases/` sub-directory **must exist** — VCI rejects any path that does not contain it during setup.

To find the correct packages directory path on your server:

```bash
luminova --where=packages
# or
luminova --paths
```

---

## First-Time Setup

Opening `vci.php` for the first time (or clicking **Update Setup**) launches a three-step wizard. No config file needs to exist beforehand.

### Step 1 — Packages Directory

Enter the absolute path to the shared Luminova packages root. This must be the **parent** of `releases/`, not `releases/` or `current/` themselves.

```
✔  /opt/luminova/packages           ← correct
✘  /opt/luminova/packages/releases  ← rejected
✘  /opt/luminova/packages/current   ← rejected
```

### Step 2 — Binary Paths

Provide absolute paths to your PHP and Composer executables. 
These are used when VCI runs `composer dump-autoload` after a version switch.

```bash
command -v php       # e.g. /usr/bin/php
command -v composer  # e.g. /usr/local/bin/composer
```

Both paths are validated (must exist, be a regular file, and be executable) before being saved.

> **No Composer?** 
> VCI can download `composer.phar` directly into your project root. 
> A prompt appears automatically when Composer cannot be found during setup.

### Step 3 — Admin Password

Set a new admin password (minimum 8 characters). Leave both fields blank to keep the [default password](#default-credentials). You can always change it later.

After completing the wizard, VCI writes `.luminova.admin.php` to your project root.

---

## Configuration

### Admin Config File

VCI stores its settings in `.luminova.admin.php` at your project root. This file is created automatically by the setup wizard:

```php
<?php
return [
    'packages'      => '/opt/luminova',
    'php_bin'       => '/usr/bin/php',
    'composer_bin'  => '/usr/local/bin/composer',
    'luminova_bin'  => '/usr/local/bin/luminova',
    'password_hash' => '$argon2id$...',
];
```

All keys are optional; omitted keys fall back to compiled-in defaults or auto-detection.

> **Important:** Add `.luminova.admin.php` to `.gitignore` to avoid committing credentials.

| Key | Description | Default |
|---|---|---|
| `packages` | Absolute path to the Luminova packages root | Auto-detected |
| `php_bin` | Absolute path to the PHP CLI binary | `PHP_BINARY` constant or `/usr/bin/php` |
| `composer_bin` | Absolute path to the Composer binary | `/usr/local/bin/composer` or `composer.phar` in project root |
| `luminova_bin` | Absolute path to the `luminova` shell binary | Auto-detected |
| `password_hash` | Argon2ID or bcrypt hash of the admin password | Built-in default hash |
| `allowed_ip_addresses` | Array of IPs or CIDR ranges allowed to access the panel | `[]` (all IPs allowed) |

---

### IP Allowlist

Restrict panel access to specific IP addresses or CIDR ranges:

```php
<?php
return [
    // ...
    'allowed_ip_addresses' => [
        '203.0.113.42',     // single IPv4
        '192.168.1.0/24',   // CIDR range
    ],
];
```

When the array is empty (the default), all IPs are permitted. When entries are present, only matching IPs can reach the panel, all others receive a `404 Not Found` response.


---

## Default Credentials

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `admin` |

> **Change the default password immediately** 
> through Step 3 of the setup wizard, or by generating a hash from the CLI:

```bash
# Argon2ID (recommended)
php -r "echo password_hash('your-new-password', PASSWORD_ARGON2ID);"

# bcrypt fallback
php -r "echo password_hash('your-new-password', PASSWORD_BCRYPT);"
```

Paste the resulting string as the `password_hash` value in `.luminova.admin.php`. VCI auto-upgrades the stored hash to the preferred algorithm on the next successful login when it detects an outdated format.

---

## Changing Your Password

**Via the UI:**

1. Click **Update Setup** in the top-right corner of the dashboard.
2. Click **Next** through Steps 1 and 2 (update those values too if needed).
3. Enter and confirm your new password (minimum 8 characters) on Step 3.
4. Click **Update Config**.

---

## Version Switching

The dashboard lists all releases found in `{packages_dir}/releases/`, sorted newest-first. The currently active version (the one your project's `.luminova.php` points to) is highlighted and labelled **active**. Releases that the `current` symlink resolves to are additionally labelled **current-stable**.

**To switch versions:**

1. Select the target release from the dropdown.
2. Click **Apply** and confirm the prompt.

VCI will:

1. Rewrite the `target` path inside `.luminova.php`
2. Update `composer.json` PSR-4 autoload entries to reference both local and shared framework paths.
3. Run `composer dump-autoload --no-dev --optimize` from your project root.
4. Display the full command output in the on-screen terminal pane.

If `.luminova.php` does not yet exist, VCI creates it with sensible defaults:

```php
<?php
return [
    'resolve.paths'      => true,
    'resolve.autoloader' => 'auto',
    'luminova.version'   => '>=3.8',
    'luminova.paths'     => [
        'root'   => '/opt/luminova',
        'target' => '/opt/luminova/releases/4.0.0',
    ],
];
```

> **Tip:** 
> Selecting the already-active version and clicking **Apply** skips the switch and runs only `composer dump-autoload`. 
> Useful when you need to regenerate the autoloader without changing versions.

---

## Localizing a Version

The **Localize** button copies the selected version's `bootstrap/` and `system/` files directly into your project directory and disables the shared-module feature.

**Use this when:**

- The project must be fully self-contained (e.g. deploying to an environment without a shared Luminova installation).
- You want to customize framework files directly inside the project.

**What it does:**

1. Removes existing local framework module files (if present).
2. Copies `bootstrap/` and `system/` from the selected release into the project root, skipping the `plugins/` sub-directory.
3. Updates `composer.json` to reference the local paths.
4. Runs `composer dump-autoload --no-dev --optimize`.

> **Note:** 
> Localizing increases disk usage since framework files are duplicated inside the project. 
> Use **Apply** at any time to switch back to the shared approach.

---

## Error Detections

### Path Mismatch Warning

If the `target` path in `.luminova.php` falls outside the configured packages directory, the dashboard shows a **Path Mismatch** card with both the expected canonical path and the path actually stored. Click **Update Setup** to correct the packages path, or switch to any version to rewrite the target automatically.

---

### Ambiguous Module Conflict

If VCI detects both local framework files and shared-module autoloading enabled simultaneously, it shows an **Ambiguous Module Detected** warning. 

- Click **Fix Conflict** to remove the redundant local files and re-optimize the autoloader. 
- Manually delete files except for (`bootstrap/constants.php`, `system/Boot.php`, and everything under `system/plugins/`)

---

## Composer Management

VCI requires Composer to regenerate the autoloader after every version switch. 
If Composer is not installed globally, VCI can download `composer.phar` directly into your project root.

The download is triggered automatically during setup when the Composer binary cannot be validated, or you can initiate it manually. The version downloaded is controlled by the `COMPOSER_PHAR_VERSION` constant in `vci.php` (default: `latest-stable`).

---

## Session Management

| Setting | Value |
|---|---|
| Session lifetime | 60 minutes (3600 s) |
| Cookie flags | `HttpOnly`, `SameSite=Strict`, `Secure` |
| Storage key | `__VCI_MANAGER__` |
| Max login attempts | 5 |
| Lockout window | 5 minutes |

Sessions use secure defaults via `session_start()`. A new session ID is generated on every successful login when `SESSION_REGENERATE_ID` is set to `1` or `2`. Logging out destroys the session completely. The lockout counter resets automatically after the 5-minute window expires.

### SESSION_REGENERATE_ID

| Value | Behavior |
|---|---|
| `0` | Session ID regeneration disabled (default) |
| `1` | Session ID regenerated on login |
| `2` | Session ID regenerated on login; old session invalidated |

Any value outside `0–2` is treated as `0`.

---

## Security

VCI is designed to be safely exposed over HTTPS, but hardening is strongly recommended.

### Built-in Protections

| Protection | Implementation |
|---|---|
| CSRF | A per-session `bin2hex(random_bytes(32))` token validated with `hash_equals()` on every state-changing POST |
| Brute-force limiting | 5 consecutive failures trigger a 5-minute lockout tracked in the session |
| Password hashing | Argon2ID when available, bcrypt otherwise; hash auto-upgraded on next login |
| Path traversal | All user-supplied paths checked against a strict allowlist regex and resolved with `realpath()` |
| Shell injection | Paths passed to `exec()` wrapped with `escapeshellarg()`; dangerous characters rejected before any shell call |
| Null-byte injection | All path inputs explicitly checked for `\0` bytes |
| Binary validation | `isValidExecutable()` verifies `realpath()`, `is_file()`, and `is_executable()` before accepting any binary |
| Session fixation | `session_regenerate_id(true)` on every login (when enabled) |
| Content-Security-Policy | Strict CSP disables external inline scripts; `object-src 'none'`; `frame-ancestors 'none'` |
| Cache prevention | `no-store, no-cache` headers prevent browsers caching the admin page |
| Search engine exclusion | `<meta name="robots" content="noindex, nofollow, noarchive">` |
| IP allowlist | Optional `allowed_ip_addresses` blocks all other IPs with a `404` response |

---

### Recommended Hardening

**Restrict by IP in your web server config:**

```apache
# Apache
<Files "vci.php">
    Require ip 203.0.113.0/24
</Files>
```

```nginx
# Nginx
location = /vci.php {
    allow 203.0.113.0/24;
    deny  all;
    fastcgi_pass php;
}
```

**Add HTTP Basic Auth as a second factor:**

```apache
<Files "vci.php">
    AuthType Basic
    AuthName "Restricted"
    AuthUserFile /etc/apache2/.htpasswd
    Require valid-user
</Files>
```

**Additional steps:**

- Use HTTPS only — the session cookie is flagged `Secure` and will not transmit over plain HTTP.
- Rename `vci.php` to something non-guessable — the panel works with any filename.
- Delete the file when not in use and re-upload it only when switching versions.

---

## Troubleshooting

**Setup wizard reappears after saving**

The wizard shows whenever `.luminova.admin.php` does not exist or could not be written. Check that the PHP process has write permission to the project root:

```bash
ls -la /path/to/your/project/
# The web server user (e.g. www-data) must own or have write access
```

**"Path not valid: a releases/ directory was not found"**

The packages directory path must point to the parent of `releases/`, not to `releases/` itself. Run `luminova --where=packages` to get the correct path.

**"Not a valid 'composer' binary path"**

VCI checks that the path exists, is a regular file, and is executable:

```bash
which composer
ls -la $(which composer)
chmod +x /usr/local/bin/composer
```

If Composer is not installed at all, use the **Install Composer PHAR** option during setup.

**Version switch succeeds but the app still loads the old version**

Composer's autoloader cache or PHP's OPcache may be serving stale files:

```bash
php -r "opcache_reset();"
cat .luminova.php   # confirm the target value was updated
```

**"CSRF validation failed"**

The form was submitted after the session expired (60 minutes by default) or the session cookie was cleared. 
Log in again and retry.

**Login is locked out**

Wait 5 minutes for the lockout window to expire. For immediate access, clear the server-side session storage directory.

**"Could not write .luminova.php — check file permissions"**

```bash
chmod 664 .luminova.php
chown www-data:www-data .luminova.php
```

If the file does not yet exist, the project root directory must be writable by the web server user.

**Path Mismatch card appears on the dashboard**

The `target` in `.luminova.php` points outside the configured packages directory. Click **Update Setup**, re-enter the correct packages path, then click **Apply** on any version to rewrite the target.

---

## Constants Reference

These constants are defined near the top of `vci.php`. Values in `.luminova.admin.php` override the defaults shown below.

| Constant | Default | Description |
|---|---|---|
| `PROJECT_APP_ROOT` | `__DIR__ . '/../'` | Project root — one level above the public web root |
| `PATH_CONFIG_FILE` | `{PROJECT_APP_ROOT}/.luminova.admin.php` | VCI admin config file path |
| `PATH_APP_CONFIG_FILE` | `{PROJECT_APP_ROOT}/.luminova.php` | Project Luminova boot config file path |
| `PATH_COMPOSER_JSON` | `{PROJECT_APP_ROOT}/composer.json` | Path to the project `composer.json` |
| `PATH_TMP` | `{PROJECT_APP_ROOT}/writeable/php-tmp` | Temporary directory for safe atomic file writes |
| `PATH_LOGS` | `{PROJECT_APP_ROOT}/writeable/logs/vci.log` | VCI error log file path |
| `ADMIN_USERNAME` | `admin` | Admin username (not configurable at runtime) |
| `PASSWORD_ALGO` | `PASSWORD_ARGON2ID` or `PASSWORD_DEFAULT` | Hash algorithm; prefers Argon2ID when available |
| `DEFAULT_PASSWORD_HASH` | Argon2ID hash of `admin` | Fallback hash when no hash is stored in the admin config |
| `ADMIN_PASSWORD_HASH` | Value from admin config, else `DEFAULT_PASSWORD_HASH` | Active password hash used for login verification |
| `SESSION_LIFETIME` | `3600` | Session TTL in seconds (60 minutes) |
| `SESSION_REGENERATE_ID` | `0` | `0` = disabled, `1` = regenerate on login, `2` = regenerate and invalidate old session |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed login attempts before lockout |
| `FAILED_LOGIN_LOCK_WINDOW_TS` | `300` | Lockout duration in seconds (5 minutes) |
| `SESSION_STORAGE` | `__VCI_MANAGER__` | Key under `$_SESSION` where VCI stores its data |
| `COMPOSER_BIN` | `/usr/local/bin/composer` or `composer.phar` | Fallback Composer path if not set in admin config |
| `PHP_BIN` | `PHP_BINARY` or `/usr/bin/php` | Fallback PHP CLI path if not set in admin config |
| `LUMINOVA_BIN` | `''` | Fallback luminova binary path if not set in admin config |
| `ALLOWED_IP_ADDRESSES` | `[]` | IP/CIDR allowlist; empty array permits all IPs |
| `COMPOSER_PHAR_VERSION` | `latest-stable` | Composer PHAR version to download when Composer is not installed |

---

Built for [PHP Luminova](https://luminova.ng) &nbsp;·&nbsp; [GitHub](https://github.com/luminovang/luminova-vci)
