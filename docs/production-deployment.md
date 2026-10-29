# LibreCode production deployment

The production installation uses the versioned `docker-compose.production.yml` overlay. This keeps the PHP/Nginx runtime configuration in Git while leaving credentials, database content, TLS certificates and HTTP Basic Auth hashes on the server. Do not commit `.env` or `.htpasswd`.

## One-time installation configuration

The production working directory on Conceição is `/root/projects/producao-cooperativista`. Its private `.env` must include:

```dotenv
APP_ENV=prod
APP_DEBUG=false
COMPOSE_FILE=docker-compose.yml:docker-compose.production.yml
PRODUCAO_HOST=producao.librecode.coop
# PRODUCAO_ACTION_TOKEN=<random secret generated on the server>
# PRODUCAO_STAGING_DATABASE=<exact DATABASE_URL database name>
```

Create `PRODUCAO_ACTION_TOKEN` from cryptographic random bytes (for example, `openssl rand -hex 32`) and keep it out of terminal transcripts, Git, logs, and shell history. It is **not** the HTTP Basic Auth password nor an Akaunting/Kimai API credential. The operator must obtain it through a private, approved channel; do not display it to untrusted users. This second secret protects ETL imports, financial publication and staging reset requests beyond the basic site password.

`PRODUCAO_STAGING_DATABASE` must match the ETL database configured in `DATABASE_URL`, and the application separately verifies that it is not the Akaunting database. A configured name alone does not authorize a reset: the operation also requires CSRF, a specific confirmation, and the operations secret. Never run reset/import/publication commands as a deployment smoke test.

Set permissions on `.env` for the deployment owner and runtime user only. Provide a readable `.htpasswd` file containing hashes for the authorized HTTP Basic Auth users; the production Compose overlay mounts it in the Nginx configuration directory. Do not install it under `public/`.

Create `var/log` with the PHP-FPM user's UID/GID and a setgid directory (`2770`) to allow daily rotating log files to remain writable. Monolog sets file permissions to `0660`. The application data, database content and `var/log` stay outside Git.

## Updating the running application

Review the target Git SHA before deployment. With the private environment already in place:

```sh
cd /root/projects/producao-cooperativista
# Fetch the reviewed branch and move the deployment branch to its exact commit.
git fetch origin fix/production-calculation-integrity-2026
git switch deploy/pr263
git merge --ff-only FETCH_HEAD

# COMPOSE_FILE in .env selects both versioned Compose files.
docker compose config -q
docker compose build php
docker compose up -d php nginx
docker compose exec -T php composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
docker compose exec -T -u www-data php php bin/console cache:clear --env=prod --no-debug
docker compose exec -T php php bin/console doctrine:migrations:status --no-ansi
docker compose ps
```

Do not run automatic migration, ETL reset, external import, or write-to-Akaunting commands merely to deploy UI changes. Migration execution and financial publication require separate validation and authorization. A PHP worker restart after cache clear ensures old in-memory opcode caches cannot continue serving the previous code.

Verify that an anonymous HTTPS request returns `401`, a request authenticated through HTTP Basic Auth renders the dashboard, the ETL reset form is protected, and `/css/app.css` loads successfully after authentication. Both the HTML and all static assets require HTTP Basic Auth.

Node.js 24 is used for development and test scripts; this project currently has no application `package.json` / production Node dependency to install. Tests should use separate `*_test` databases, never the production financial databases.

To revert application changes, redeploy a reviewed earlier Git commit through the same versioned Compose configuration. Never check in secrets or rely on uncommitted production code.
