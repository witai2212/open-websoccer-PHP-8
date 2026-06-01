# CM23 Installation Guide

This file describes how to install or deploy the current CM23 / Championship Manager project.

## 1. Requirements

Recommended server environment:

- PHP 8.1 or newer. The current live dump was created from an environment running PHP 8.3.x.
- MariaDB 10.6+ or MySQL 8.x. The current live dump was created from MariaDB 10.11.x.
- Apache or Nginx with PHP-FPM or an equivalent PHP-capable web server.
- PHP extensions:
  - required: `mysqli`, `json`, `simplexml`, `dom`, `session`
  - required for image uploads: `gd`
  - recommended: `mbstring`, `curl`, `openssl`, `fileinfo`, `zip`
  - optional/legacy integrations: Facebook/Google+ modules require `curl`; premium/payment integrations may require provider-specific connectivity.
- Working PHP `mail()` or SMTP replacement if password reset, notifications or system mail should be used.

## 2. Prepare the project files

Upload or extract the project archive into the desired web directory, for example:

```text
/var/www/html/cm23
```

or on a shared host:

```text
/httpd.www/cm23
```

The public application root is the project root, where `index.php` is located.

## 3. Create writable folders

Create missing runtime folders if they are not present in the archive:

```bash
mkdir -p generated \
  uploads/club \
  uploads/cup \
  uploads/player \
  uploads/sponsor \
  uploads/stadium \
  uploads/stadiumbuilder \
  uploads/stadiumbuilding \
  uploads/users
```

Make them writable by the web server user. On many shared hosts this is handled by the hosting panel. On a typical Linux server:

```bash
chown -R www-data:www-data generated uploads
chmod -R 775 generated uploads
chmod 664 admin/config/jobs.xml admin/config/termsandconditions.xml
```

Adjust the user/group to your server. Avoid using `777` unless your host leaves no other option.

## 4. Create the database

Create an empty database and a database user with full rights on that database.

Example:

```sql
CREATE DATABASE cm23 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'cm23_user'@'localhost' IDENTIFIED BY 'change-this-password';
GRANT ALL PRIVILEGES ON cm23.* TO 'cm23_user'@'localhost';
FLUSH PRIVILEGES;
```

Do not use production credentials in committed files.

## 5. Fresh web installation

Open the installer in the browser:

```text
https://your-domain.example/cm23/install/
```

Follow the installer steps:

1. Select language. Use German as the maintained language.
2. Run the system check.
3. Enter database connection details.
4. Enter project name, system email, base URL and context root.
5. Choose fresh installation.
6. Create the first admin user.

Important prefix note:

- The original installer default is `ws3`.
- The current project/live schema uses `cm23`.
- For a new CM23 installation that should match the current project convention, enter `cm23` as database prefix.

The installer writes runtime configuration to:

```text
generated/config.inc.php
```

## 6. Manual installation from SQL dump

If you do not use the web installer, import the database schema manually.

For a current CM23 database structure, import a current schema dump such as:

```text
moritzschneider_netcm23.sql
```

Then create or verify `generated/config.inc.php` with the correct database connection and prefix.

Minimal example:

```php
<?php
$conf['db_host'] = 'localhost';
$conf['db_user'] = 'cm23_user';
$conf['db_passwort'] = 'change-this-password';
$conf['db_name'] = 'cm23';
$conf['db_prefix'] = 'cm23';
$conf['supported_languages'] = 'de';
$conf['homepage'] = 'https://your-domain.example';
$conf['context_root'] = '/cm23';
$conf['projectname'] = 'Championship Manager';
$conf['systememail'] = 'noreply@your-domain.example';
?>
```

After the first admin login, complete the remaining settings in the admin backend.

## 7. Admin backend

Open:

```text
https://your-domain.example/cm23/admin/
```

Recommended first checks:

1. Verify global settings: project name, homepage, context root, timezone, supported languages, currency and offline mode.
2. Verify login and registration settings.
3. Verify game modules that should be enabled/disabled.
4. Verify jobs in `admin/config/jobs.xml`.
5. Clear/regenerate configuration and message caches if the admin backend provides that option.
6. Create or verify leagues, seasons, clubs, players, stadiums, sponsors and competitions.

## 8. Cron / job execution

CM23 jobs are configured in:

```text
admin/config/jobs.xml
```

They are executed through webservice scripts, not automatically by the operating system. Configure a real cron job to call the job runner regularly.

Example using `curl`:

```bash
*/5 * * * * curl -fsS "https://your-domain.example/cm23/webservices/executeMyJobs.php" >/dev/null 2>&1
```

Recommended protection:

- Restrict webservice access by IP or web server rules.
- Use a secret endpoint or server-side cron wrapper where possible.
- Do not leave job endpoints publicly abusable.

Important jobs include match simulation, open transfers, computer transfers, youth processing, scouting, training matchday processing and staff salary processing.

## 9. Production hardening

After successful installation:

- Delete or server-protect `/install`.
- Delete or server-protect `/update` unless actively needed.
- Protect `generated/config.inc.php` from download.
- Protect SQL dumps and backups from public access.
- Remove temporary development files and duplicates such as files ending in ` (2).php`, unless intentionally required.
- Confirm that error display is disabled in production and logs are written safely.
- Ensure upload folders do not execute PHP files.
- Use HTTPS.

## 10. Updating an existing installation

Before updates:

1. Put the site into offline mode if users might be active.
2. Back up all project files.
3. Back up the complete database.
4. Compare the current database schema with the new SQL changes.
5. Apply migrations carefully and idempotently.
6. Deploy changed PHP/Twig/XML/JS/CSS files.
7. Clear generated caches.
8. Run a test login, page load and simulation/job test.

Never run schema-changing SQL on production without a verified backup.

## 11. Language files

German is the maintained language for the customized CM23 modules.

When adding or changing labels/messages:

- Frontend module messages: `modules/<module>/messages_de.xml`
- Admin labels/settings: `modules/<module>/adminmessages_de.xml`
- Entity labels: `modules/<module>/entitymessages_de.xml`

English/Spanish files may exist from upstream but should not be treated as complete for custom CM23 features.

## 12. Common troubleshooting

### Blank page or fatal error

- Check PHP error log.
- Verify PHP version and extensions.
- Verify `generated/config.inc.php` exists and contains correct credentials.
- Verify generated cache files are not stale after module/XML changes.

### Database connection error

- Verify database host, user, password and database name.
- Verify the configured database prefix matches the installed table prefix.
- On shared hosting, confirm whether the database host is `localhost` or a provider-specific hostname.

### Missing translation like `???some_key???`

- Add the missing key to the relevant German XML file.
- Clear/regenerate message cache.
- Make sure the key is in the correct module message file.

### Uploads fail

- Verify the correct upload subfolder exists.
- Verify write permissions.
- Verify PHP upload limits and image extension support.

### Jobs do not run

- Check `admin/config/jobs.xml` for `stop="1"`.
- Check `last_ping`, `interval` and `error` attributes.
- Call the job webservice manually and inspect output/logs.
- Verify job endpoints are reachable from cron.

### Fresh install lacks current custom tables

If the web installer uses an old `install/ws3_ddl_full.sql`, regenerate or replace that DDL with the current full schema before relying on fresh installs. Existing production deployments should be updated with migrations instead of re-running a fresh installer.
