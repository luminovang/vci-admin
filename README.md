![PHP Luminova VCI](https://github.com/phpvci/admin/blob/main/logo.svg?v=3)

# PHP VCI Admin Dashboard

**Version Control Interface for the PHP Luminova Framework**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/github/license/phpvci/admin)](LICENSE)
[![Luminova](https://img.shields.io/badge/Luminova-VCI-0750e1)](https://luminova.ng)

---

**VCI Admin (`phpvci.php`)** is a single file, browser based admin panel for managing [PHP Luminova](https://luminova.ng) framework installations. Instead of bundling a full framework copy inside every project, VCI allow multiple projects to share one central installation and switch between released versions from a web UI, similar to cPanel's PHP version switcher but for the Luminova framework.

The shared installation itself is created and maintained by the `phpvci` command line tool, documented in [PHPVCI CLI README](https://github.com/phpvci/phpvci/blob/main/README.md).

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
- [Error Detections](#error-detections)
- [Composer Management](#composer-management)
- [Session Management](#session-management)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Constants Reference](#constants-reference)

---

## How It Works

By default every Luminova project ships with its own copy of the framework files. VCI replaces those copies with a single shared installation in a central directory on your server (for example `/opt/phpvci.packages`).

Each project's `.phpvci.php` file has a `target` path that points to one release in that directory. Clicking **Apply** in the dashboard rewrites the path, updates `composer.json`, and runs `composer dump-autoload`.

```
/opt/phpvci.packages/            shared packages root
    releases/
        3.8.0/
        3.9.0/
        4.0.0/
    current                      symlink to the active release
```

A project's `.phpvci.php` can point to one release, or to `current` to follow whichever release is active.

---

## Requirements

| Requirement | Details |
|---|---|
| PHP | 8.0 or later |
| PHP extensions | `session`, `json`, `filter` |
| PHP settings | `exec` and `shell_exec` must not be disabled. `allow_url_fopen` is needed to download `composer.phar` |
| Composer | Any recent version, or a `composer.phar` in the project root |
| Packages directory | Created by the [phpvci CLI](https://github.com/phpvci/phpvci). It must contain a `releases/` directory |
| Filesystem | The PHP process needs write access to the project root, `composer.json`, `.phpvci.php`, `.phpvci.admin.php` and `writeable/`, and read access to the packages directory |
| HTTPS | The session cookie is flagged `Secure`, so login only works over HTTPS |

> **Password hashing:**
> VCI uses `PASSWORD_ARGON2ID` when PHP supports it and falls back to `PASSWORD_DEFAULT` otherwise.

---

## Installation

VCI is a single PHP file with no Composer dependency and no framework bootstrap.

**Step 1: Get the file**

```bash
git clone https://github.com/phpvci/admin.git
```

**Step 2: Place the file**

Copy `phpvci.php` into a directory one level below your project root, usually the public web root:

```
your-project/
├── public/
│   └── phpvci.php       place it here
├── .phpvci.php
└── composer.json
```

VCI treats the parent of the directory that contains `phpvci.php` as the project root. That is where it looks for `composer.json` and `.phpvci.php`, and where it writes `.phpvci.admin.php` and the `writeable/` files.

> **Security tip:**
> Rename the file to something non obvious (for example `_panel_xyz.php`). VCI works with any filename.
> See [Security](#security) for more hardening options.

**Step 3: Open it in your browser**

Navigate to `https://your-domain.com/phpvci.php`. The setup wizard starts on first run.

---

## Directory Structure

VCI expects the shared packages directory to follow this layout:

```
{packages_dir}/
├── releases/          required
│   ├── 3.8.0/
│   ├── 3.9.0/
│   └── 4.0.0/
└── current            symlink to the active release, optional
```

The `releases/` directory must exist. VCI rejects any path that does not contain it during setup.

To find the packages directory on your server:

```bash
phpvci --where=packages
# or
phpvci --paths
```

---

## First-Time Setup

The wizard opens on first run, whenever `.phpvci.admin.php` does not exist or has no `packages` value, and when you click **Update Setup**. No config file needs to exist beforehand.

### Step 1: Packages Directory

Enter the absolute path of the shared packages root. This is the **parent** of `releases/`, not `releases/` or `current/` themselves.

```
✔  /opt/phpvci.packages            correct
✘  /opt/phpvci.packages/releases   rejected
✘  /opt/phpvci.packages/current    rejected
```

The field is prefilled when VCI finds `PHPVCI_PACKAGE_DIR` in the environment or in the phpvci `config.conf`. The path may contain only letters, digits, `/`, `_`, `.` and `-`, and it must be readable and contain `releases/`.

### Step 2: Binary Paths

Enter absolute paths to the PHP and Composer executables. VCI uses them to run `composer dump-autoload` after a version change.

```bash
command -v php       # for example /usr/bin/php
command -v composer  # for example /usr/local/bin/composer
```

Each path must exist, be a regular file and be executable. Empty fields fall back to the values VCI detected:

- PHP: `php` on the `PATH`, then common install locations, then `PHP_BINARY`.
- Composer: `composer` on the `PATH`, then `composer.phar` in the project root, then `/usr/local/bin/composer`.

> **No Composer?**
> When the Composer path is invalid, the wizard returns to this step and asks whether to install a single `composer.phar` into the project root. See [Composer Management](#composer-management).

### Step 3: Admin Password

Set a new admin password of at least 8 characters, without control characters. Leave both fields blank to keep the current password, which is the [default password](#default-credentials) until you change it.

When the wizard finishes, VCI writes `.phpvci.admin.php` to the project root.

---

## Configuration

### Admin Config File

VCI stores its settings in `.phpvci.admin.php` at the project root. The setup wizard creates it:

```php
<?php
return [
    'packages'      => '/opt/phpvci.packages',
    'php_bin'       => '/usr/bin/php',
    'composer_bin'  => '/usr/local/bin/composer',
    'phpvci_bin'    => '/usr/local/bin/phpvci',
    'password_hash' => '$argon2id$...',
];
```

`packages` is required. When it is missing, the setup wizard is shown. The other keys are optional.

> **Important:** Add `.phpvci.admin.php` to `.gitignore` to avoid committing credentials.

| Key | Description | Default |
|---|---|---|
| `packages` | Absolute path to the packages root | None |
| `php_bin` | PHP CLI binary used to run Composer | Detected, see [Binary Paths](#step-2-binary-paths) |
| `composer_bin` | Composer executable | Detected, see [Binary Paths](#step-2-binary-paths) |
| `phpvci_bin` | Path to the `phpvci` binary. Stored for reference, the panel does not run it | Empty |
| `password_hash` | Hash of the admin password | Built in hash of `admin` |
| `allowed_ip_addresses` | List of IPs or IPv4 CIDR ranges allowed to open the panel | `[]` (all IPs) |
| `target_ver` | Composer version constraint added for the framework package when you [localize](#localizing-a-version) | `^3.7` |
| `package_name` | Composer package added to or removed from `require` | `luminovang/framework` |

---

### IP Allowlist

Restrict panel access to specific addresses:

```php
<?php
return [
    // ...
    'allowed_ip_addresses' => [
        '203.0.113.42',     // single IP
        '192.168.1.0/24',   // IPv4 CIDR range
    ],
];
```

When the array is empty (the default), every IP is allowed. When it has entries, other visitors receive a `404 Not Found` response. IPv6 addresses must match exactly, CIDR ranges are IPv4 only.

VCI reads the visitor IP from the first of these that holds a valid address: `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR` (first entry), `HTTP_X_REAL_IP`, `HTTP_CLIENT_IP`, then `REMOTE_ADDR`. Clients can send these headers themselves, so the allowlist is only reliable behind a proxy that overwrites them. Otherwise, also restrict access in the web server.

---

## Default Credentials

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `admin` |

The username is fixed in `phpvci.php` (`ADMIN_USERNAME`) and is not read from the config file.

> **Change the default password immediately**
> in Step 3 of the setup wizard, or generate a hash from the command line:

```bash
# Argon2ID (recommended)
php -r "echo password_hash('your-new-password', PASSWORD_ARGON2ID);"

# bcrypt fallback
php -r "echo password_hash('your-new-password', PASSWORD_BCRYPT);"
```

Paste the result as the `password_hash` value in `.phpvci.admin.php`. On the next successful login, VCI upgrades the stored hash to the preferred algorithm if it detects an outdated one.

---

## Changing Your Password

1. Click **Update Setup** in the top right corner of the dashboard.
2. Click **Next** through Steps 1 and 2, changing those values if needed.
3. Enter and confirm the new password (at least 8 characters) in Step 3.
4. Click **Update Config**.

To start over, click **Reset Setup**. It deletes `.phpvci.admin.php`, including the stored password hash, signs you out and shows the setup wizard again.

---

## Version Switching

The dashboard lists the releases found in `{packages_dir}/releases/`, newest first. The first entry is the `current` symlink, shown with the version it resolves to and labelled **current-stable**. Choosing it makes the project follow `current`. The release the project uses is labelled **active**.

**To switch versions:**

1. Select the target release.
2. Click **Apply** and confirm the prompt.

VCI then:

1. Updates `.phpvci.php`: sets `enabled` to `true` and rewrites `paths.root` and `paths.target`.
2. Copies the files stored in the release's `.metadata/` directory into the project, overwriting the project copies of `system/Boot.php`, `bootstrap/constants.php` and `novakit` when the release has them.
3. Updates `composer.json`. The `Luminova\` and `Luminova\Funcs\` PSR-4 entries list the local path first and the shared release path second, and the framework package is removed from `require` if it is there.
4. Runs `composer dump-autoload --no-dev --optimize` from the project root and shows the output in the on screen terminal pane.

If `.phpvci.php` does not exist, VCI creates it with these defaults:

```php
<?php
return [
    'enabled'    => true,
    'autoloader' => 'auto',
    'version'    => '>=3.8',
    'paths'      => [
        'root'   => '/opt/phpvci.packages',
        'target' => '/opt/phpvci.packages/releases/4.0.0',
    ],
];
```

On later switches, `autoloader` and `version` are kept. Edit them by hand when you need to. See [README.md](README.md#configuring-your-php-project) for what each key means.

> **Tip:**
> Selecting the already active version and clicking **Apply** asks whether to run only `composer dump-autoload`. This regenerates the autoloader without changing versions.

---

## Localizing a Version

**Localize** copies the selected version's `bootstrap/` and `system/` files into your project and disables the shared module feature.

**Use this when:**

- The project must be self contained, for example when deploying to an environment without the shared installation.
- You want to customize framework files inside the project.

**What it does:**

1. Copies `bootstrap/` and `system/` from the selected release, skipping `plugins/`, into `writeable/modules-tmp/`. The matching files from the release's `.metadata/` are copied over them.
2. Removes the existing local module files, using the same rules as [Fix Conflict](#ambiguous-module-conflict).
3. Moves the staged files into the project's `bootstrap/` and `system/`.
4. Resets the `Luminova\` PSR-4 entries in `composer.json` to the local paths and adds the framework package to `require` when it is missing.
5. Sets `enabled` to `false` in `.phpvci.php` and records the release path.
6. Runs `composer dump-autoload --no-dev --optimize`.

> **Note:**
> Localizing increases disk usage because the framework files are duplicated in the project.
> Use **Apply** at any time to return to the shared installation. The local copies stay in place, so the dashboard then reports an ambiguous module conflict, which **Fix Conflict** clears.

---

## Error Detections

### Local Mode

When `enabled` is `false` in `.phpvci.php`, the status card shows **Local**. Select a version and click **Apply** to enable the shared module feature.

### Path Mismatch

If `paths.target` in `.phpvci.php` is outside the configured packages directory, the dashboard shows a **Path Mismatch** card with the expected path and the stored one. Click **Update Setup** to correct the packages path, or click **Apply** on any version to rewrite the target.

### Ambiguous Module Conflict

VCI reports a conflict when shared module autoloading is enabled and any of these local framework files exist:

```
bootstrap/*/*.php
bootstrap/*.php
system/*/*.php
system/!plugins/*
```

Click **Fix Conflict** to remove everything under `bootstrap/` and `system/` except `system/Boot.php` and `system/plugins/` files, then run `composer dump-autoload --no-dev --optimize`. You can also delete those files by hand and keep the same exceptions.

---

## Composer Management

VCI runs Composer after every version change. If Composer is not available, the panel can download `composer.phar` into the project root. The download starts after you confirm the prompt that follows an invalid Composer path in the setup wizard.

The file is fetched from `https://getcomposer.org/download/{version}/composer.phar`, where `{version}` is the `COMPOSER_PHAR_VERSION` constant in `phpvci.php` (default `latest-stable`). It accepts `latest`, `latest-stable`, `latest-preview`, `snapshot` or a version such as `2.7.1`.

VCI saves the download to a temporary file, moves it into place, makes it executable and runs `--version` to check it. If the check fails, the file is deleted. No checksum or signature is verified. On success the path is saved as `composer_bin`. If `composer.phar` already exists in the project root, the download is skipped.

---

## Session Management

| Setting | Value |
|---|---|
| Session lifetime | 60 minutes (3600 s), counted from login and not extended by activity |
| Cookie flags | `HttpOnly`, `SameSite=Strict`, `Secure` |
| Storage key | `__VCI_MANAGER__` |
| Max login attempts | 5 |
| Lockout window | 5 minutes |

A session is tied to the IP address used at login. A request from a different IP is treated as logged out. Logging out destroys the session.

The login attempt counter is stored in the session, and it resets after the lockout window passes.

### SESSION_REGENERATE_ID

| Value | Behavior |
|---|---|
| `0` | Session ID is not regenerated (default) |
| `1` | Session ID is regenerated on login |
| `2` | Session ID is regenerated on login and the old session is deleted |

Any value outside 0 to 2 is treated as `0`.

---

## Security

VCI can be exposed over HTTPS, but hardening is strongly recommended.

### Access Without a Login

Submitting the setup form and the Composer download request do not check for a login. Switching, localizing, optimizing, fixing conflicts, opening the update setup page and resetting setup all need an active session. Restrict access with `allowed_ip_addresses` or web server rules, and delete or rename `phpvci.php` when you are not using it.

### Built-in Protections

| Protection | Implementation |
|---|---|
| CSRF | A per session `bin2hex(random_bytes(32))` token, checked with `hash_equals()` on the setup, Composer download, switch, localize and fix conflict requests |
| Brute force limiting | 5 consecutive failures lock login for 5 minutes. The counter is kept in the session |
| Password hashing | Argon2ID when available, otherwise `PASSWORD_DEFAULT`. The hash is upgraded on the next login |
| Session cookie | `HttpOnly`, `SameSite=Strict`, `Secure`, bound to the login IP |
| Session fixation | `session_regenerate_id()` on login when `SESSION_REGENERATE_ID` is `1` or `2` |
| Path validation | Setup paths allow only `[a-zA-Z0-9/_.-]`, reject relative prefixes and null bytes, and must resolve to an existing directory (packages) or executable file (binaries) |
| Binary validation | `isValidExecutable()` checks `realpath()`, `is_file()` and `is_executable()` before a binary is accepted |
| Shell injection | Binary paths are passed through `escapeshellarg()` before any command runs |
| Content Security Policy | A meta tag limits resources to the same origin, with Google Fonts allowed for styles and fonts. Inline scripts and styles are permitted. `object-src` is `none` |
| Cache and indexing | Meta tags send `no-store, no-cache` and `noindex, nofollow, noarchive` |
| IP allowlist | Optional `allowed_ip_addresses`, other visitors get a `404` |

### Recommended Hardening

**Restrict by IP in your web server config:**

```apache
# Apache
<Files "phpvci.php">
    Require ip 203.0.113.0/24
</Files>
```

```nginx
# Nginx
location = /phpvci.php {
    allow 203.0.113.0/24;
    deny  all;
    fastcgi_pass php;
}
```

**Add HTTP Basic Auth as a second factor:**

```apache
<Files "phpvci.php">
    AuthType Basic
    AuthName "Restricted"
    AuthUserFile /etc/apache2/.htpasswd
    Require valid-user
</Files>
```

**Additional steps:**

- Use HTTPS only. The session cookie is flagged `Secure` and is not sent over plain HTTP.
- Rename `phpvci.php` to something hard to guess. The panel works with any filename.
- Delete the file when it is not in use and upload it again when you need to switch versions.

---

## Troubleshooting

PHP errors and exceptions from `phpvci.php` are written to `writeable/logs/phpvci.log`. The directory must be writable.

**Setup wizard reappears after saving**

The wizard shows whenever `.phpvci.admin.php` does not exist or has no `packages` value. Check that the PHP process can write to the project root:

```bash
ls -la /path/to/your/project/
# The web server user (for example www-data) must own or have write access
```

**"Path not valid: a releases/ directory was not found"**

The packages path must point to the parent of `releases/`, not to `releases/` itself. Run `phpvci --where=packages` to get the correct path.

**"'/path' is not a valid 'composer' binary path"**

VCI checks that the path exists, is a regular file and is executable:

```bash
which composer
ls -la $(which composer)
chmod +x /usr/local/bin/composer
```

The same check applies to the `php` binary. If Composer is not installed at all, accept the `composer.phar` prompt during setup.

**"composer.json file is missing in project root"**

Switching and localizing need `composer.json` in the project root. Add it and try again. If the panel reports that `composer.json` cannot be read, written or parsed, check its permissions and that it contains valid JSON.

**Version switch succeeds but the app still loads the old version**

PHP's OPcache may be serving stale files. Reload PHP, for example by restarting PHP-FPM or the web server, and confirm the `target` value:

```bash
cat .phpvci.php
```

**"CSRF validation failed"**

The form was submitted after the session expired (60 minutes) or the session cookie was cleared. Log in again and retry.

**Login is locked out**

The message reads "Too many login attempts". Wait 5 minutes for the lockout window to pass. For immediate access, clear the server side session storage.

**Error: ".phpvci.php" file is not writable**

```bash
chmod 664 .phpvci.php
chown www-data:www-data .phpvci.php
```

If the file does not exist yet, the project root must be writable by the web server user.

**Path Mismatch card appears on the dashboard**

The `target` in `.phpvci.php` is outside the configured packages directory. Click **Update Setup**, enter the correct packages path, then click **Apply** on any version to rewrite the target.

**"No releases installed"**

The packages directory has no release. Run `phpvci package --install` as root.

---

## Constants Reference

These constants are defined near the top of `phpvci.php`. Those marked "admin config" take their value from `.phpvci.admin.php` when it is set. The others are changed by editing `phpvci.php`.

| Constant | Default | Description |
|---|---|---|
| `PACKAGE_NAME` | `luminovang/framework` | Composer package name. The `package_name` key in the admin config overrides it |
| `PROJECT_APP_ROOT` | Parent of the directory that contains `phpvci.php` | Project root, with a trailing slash |
| `PATH_ADMIN_CONFIG_FILE` | `{PROJECT_APP_ROOT}.phpvci.admin.php` | VCI admin config file |
| `PATH_APP_VER_CONFIG_FILE` | `{PROJECT_APP_ROOT}.phpvci.php` | Project shared module config file |
| `PATH_COMPOSER_JSON` | `{PROJECT_APP_ROOT}composer.json` | Project `composer.json` |
| `PATH_TMP` | `{PROJECT_APP_ROOT}writeable/tmp/phpvci` | Temporary directory. Created if missing, set as the PHP temp directory, and used for atomic `composer.json` writes |
| `PATH_LOGS` | `{PROJECT_APP_ROOT}writeable/logs/phpvci.log` | VCI error log |
| `ADMIN_USERNAME` | `admin` | Admin username |
| `PASSWORD_ALGO` | `PASSWORD_ARGON2ID` or `PASSWORD_DEFAULT` | Hash algorithm, Argon2ID when available |
| `DEFAULT_PASSWORD_HASH` | Hash of `admin` | Used when no password hash is stored |
| `ADMIN_PASSWORD_HASH` | Admin config `password_hash`, else `DEFAULT_PASSWORD_HASH` | Hash used to verify the login |
| `SESSION_LIFETIME` | `3600` | Session lifetime in seconds |
| `SESSION_REGENERATE_ID` | `0` | `0` off, `1` regenerate on login, `2` regenerate and delete the old session |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed logins before lockout |
| `FAILED_LOGIN_LOCK_WINDOW_TS` | `300` | Lockout duration in seconds |
| `SESSION_STORAGE` | `__VCI_MANAGER__` | Key under `$_SESSION` where VCI keeps its data |
| `COMPOSER_BIN` | Admin config `composer_bin`, else detected | Composer executable |
| `PHP_BIN` | Admin config `php_bin`, else detected | PHP CLI executable |
| `PHPVCI_BIN` | Admin config `phpvci_bin`, else empty | Path to the `phpvci` binary |
| `ALLOWED_IP_ADDRESSES` | Admin config `allowed_ip_addresses`, else `[]` | IP and CIDR allowlist, an empty array permits all IPs |
| `COMPOSER_PHAR_VERSION` | `latest-stable` | Version of `composer.phar` to download |

---

Built for [PHP Luminova](https://luminova.ng) &nbsp;·&nbsp; [GitHub](https://github.com/phpvci/admin)
