# Run and recover Stage CMS

## Local development

Install PHP 8.4/8.5 and Composer with the extensions listed in `composer.json`.
`composer install`, `php bin/cms setup`, and `composer serve` are the supported
local path. The built-in PHP server is a development server.

Runtime defaults to `.runtime/data/`, outside `public/` and ignored by Git.
Do not store databases or uploads in a synced source directory.

| Setting | Meaning | Default |
| --- | --- | --- |
| `CMS_DATA_DIR` | Absolute, private, persistent data directory | `.runtime/data` |
| `CMS_URL` | Public HTTP origin, without a path | `http://127.0.0.1:8088` |

Set `CMS_URL` to the HTTPS origin in production. This enables Secure cookies.
Configure the same settings for PHP-FPM and the command line. The application
does not read `.env` files automatically.

## Deployment

Use a PHP-FPM pool running as an unprivileged application user, a persistent
data directory owned by that user, and an HTTPS reverse proxy. SQLite and its
WAL files must be on a local filesystem with working locks. Do not use a
network share or several servers writing separate copies.

Install a reviewed release:

```sh
git clone --branch v0.4.1 git@github.com:skyyware/stage-cms.git
cd stage-cms
composer install --no-dev --no-interaction --no-plugins
```

Use the release's lockfile. Give the runtime user read access to code and write
access only to the private data directory. Use `umask 0077` for setup and backup.
The PHP entrypoint also applies this mask. Keep `display_errors=Off`, upload
limit 5M, POST limit 8M, and an appropriate private error log.

Configure the server so only `public/` is reachable. Route application requests
to `public/index.php`; only the packaged files in `public/assets/` are static.
Never execute uploaded files. Forward the Authorization header to PHP for
the agent API. Preserve the real connecting address for login rate limiting;
do not trust a client-supplied forwarding header.

This Nginx location fragment assumes the server root is this release's
`public/` and the FPM socket is configured for the application:

```nginx
client_max_body_size 8m;
location = /assets/cms.css { try_files $uri =404; }
location = /assets/cms.js { try_files $uri =404; }
location = /assets/mark.svg { try_files $uri =404; }
location = /assets/D-DIN.otf { try_files $uri =404; }
location = /assets/D-DIN-Bold.otf { try_files $uri =404; }
location = /assets/D-DIN-OFL.txt { try_files $uri =404; }
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/stage-cms.sock;
}
location / { rewrite ^ /index.php last; }
```

Configure certificates, the FPM pool, environment variables, and logs for your
host. This fragment is not an automatic provisioning system. Verify `/health`,
sign-in, a private draft, publication, API authentication, image upload, and
private path denial before directing visitors to a deployment.

## Recovery

Revisions recover content mistakes. They do not replace backups.

Export via Settings or the trusted server CLI:

```sh
php bin/cms export /private/backups/publication.zip --json
```

The ZIP contains `content.json` and stored image bytes. It includes drafts,
archived pages, revision history, and settings. Treat it as private. It excludes
owner credentials, browser sessions, login attempts, and agent tokens.
Portable archives are limited to 128 MiB of uncompressed content and images.
The command refuses an existing destination and writes with private permissions.

Restore to a fresh, empty data directory:

```sh
CMS_DATA_DIR=/private/recovery/data php bin/cms restore /private/backups/publication.zip
CMS_DATA_DIR=/private/recovery/data php bin/cms setup --name="Your name" --email="you@example.com"
```

Restore refuses a publication with any pages or images. It preserves an owner
already set up in an empty installation. ZIP paths are validated and images
are hash-checked. Nothing is extracted using archive-supplied filesystem paths.
Validate the restored site before moving traffic to it.

For a complete instance backup, stop both web and CLI writers, copy the entire
private data directory including SQLite, WAL/SHM files if present, and `media/`,
then restart. This backup includes active credentials and must be protected
accordingly. Restore it with the application stopped and the same release
first. Keep an off-server copy and test restoration periodically.

Reset the owner password from the trusted server:

```sh
php bin/cms password
```

All browser sessions are revoked. Agent tokens remain active; revoke them from
Agents if required. For noninteractive setup/reset, pass `--password-stdin`
and supply a secret through standard input, never a command-line argument.

## Updates

Read the changelog, take and test a backup, install a tagged release, and run
the checks in a development environment first. The application checks database
schema version during startup and refuses an unknown newer schema.

Version 0.4 migrates schema 1 to schema 2 on first startup. It retains existing
pages and publication pointers, adds type `page`, locale `en`, and empty named
fields to every revision, and leaves the default theme selected. Back up before
that first request. Version 0.3 cannot open schema 2: rollback requires stopping
writers and restoring the pre-upgrade data directory together with the old code.

New exports use `stage-cms/2` and include types, locales, fields, and theme IDs.
Version 0.4 accepts both format 1 and format 2 exports. Reinstall the application's
page type and theme definitions before editing or rendering restored content;
archives preserve data and do not contain executable themes. Downgrading a
format 2 archive to an earlier release is unsupported.

Public source availability is separate from production deployment. No
production domain or hosted CMS service is bundled with this repository.
