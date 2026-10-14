# Cashew

> 100% vibe coded with Claude Code / Opus 5.5

Personal web app to see where your money goes: import your bank statements (Crédit Agricole and Revolut CSV exports), then get every month's income and spending at a glance, by category, with a calendar of the month and the subscriptions still going on.

## Run with Docker

> [!WARNING]
> Cashew is vibe coded and has not been security audited. Keep it on your local network, or reach it through a VPN: do not expose it to the Internet.

No need to clone the repository. Everything lives in one `cashew/` folder:

```
cashew/
├── compose.yaml
├── cashew.env      # your settings
├── caddy/          # certificates
├── database/
└── share/          # statements waiting to be imported
```

```bash
mkdir -p cashew/caddy/data cashew/caddy/config cashew/database cashew/share
cd cashew
```

In `cashew/`, create `cashew.env` with your settings and replace every `CHANGE_ME`:

```dotenv
# Your user and group, given by the `id` command: they own the caddy/ and share/ folders
PUID=1000
PGID=1000

# Server IP or domain name, then the address the worker reaches the app at (keep ", cashew:80")
SERVER_NAME=127.0.0.1, cashew:80
# Same IP or domain name as SERVER_NAME
CADDY_GLOBAL_OPTIONS=default_sni 127.0.0.1
# The address you open, then the same followed by /.well-known/mercure
DEFAULT_URI=https://127.0.0.1
MERCURE_PUBLIC_URL=https://127.0.0.1/.well-known/mercure
# Nothing to change
MERCURE_URL=http://cashew/.well-known/mercure

# Database password (letters and digits only), written three times
POSTGRES_PASSWORD=CHANGE_ME
DATABASE_URL=postgresql://app:CHANGE_ME@cashew-db:5432/app?serverVersion=18
LOCK_DSN=postgresql+advisory://app:CHANGE_ME@cashew-db:5432/app

# Two different long random texts, given by `openssl rand -hex 32`
APP_SECRET=CHANGE_ME
MERCURE_JWT_SECRET=CHANGE_ME

# Your account, created at the first start: changing them afterwards changes nothing
# Login: letters, digits, and . _ - between them. Password: 12 characters at least, hard to guess
CASHEW_LOGIN=CHANGE_ME
CASHEW_PASSWORD=CHANGE_ME
```

Then `compose.yaml`, in the same folder, nothing to change:

```yaml
services:
  cashew:
    image: smbpunt/cashew-php-prod:latest
    container_name: cashew
    env_file: cashew.env
    volumes:
      - ./caddy/data:/data # certificates
      - ./caddy/config:/config
      - ./share:/app/var/share # statements waiting for the worker
    ports:
      - 80:80 # HTTP
      - 443:443 # HTTPS
      - 443:443/udp # HTTP/3 (optional)
    depends_on:
      - cashew-db
    restart: unless-stopped

  # Imports the statements in the background
  cashew-worker:
    image: smbpunt/cashew-php-prod:latest
    container_name: cashew-worker
    command: php bin/console messenger:consume async --memory-limit=256M
    env_file: cashew.env
    volumes:
      - ./share:/app/var/share
    depends_on:
      cashew:
        condition: service_healthy
    healthcheck:
      disable: true
    restart: unless-stopped

  cashew-db:
    image: postgres:18-alpine
    container_name: cashew-db
    env_file: cashew.env
    environment:
      - POSTGRES_USER=app
      - POSTGRES_DB=app
    volumes:
      - ./database:/var/lib/postgresql
    restart: unless-stopped
```

Then `docker compose up -d` from `cashew/`, open `https://<SERVER_NAME>` and log in with `CASHEW_LOGIN` and `CASHEW_PASSWORD`. Once your account exists, you can remove `CASHEW_PASSWORD` from `cashew.env`.

To create another user, which asks for a login and a password:

```bash
docker compose run --rm cashew-worker php bin/console app:create-user
```

To update: `docker compose pull && docker compose up -d`. The database follows on its own.

### Other ports

In `compose.yaml`, change the left number only, for example `8443:443`. The address becomes `https://<SERVER_NAME>:8443`: add `:8443` to `DEFAULT_URI` and `MERCURE_PUBLIC_URL` too. With a domain name and a Let's Encrypt certificate, keep 80 and 443.

### Behind your own reverse proxy

If nginx, Traefik, Nginx Proxy Manager or Synology already handles the certificate, Cashew serves plain HTTP behind it. In `cashew.env`:

```dotenv
# HTTP only, no certificate
SERVER_NAME=:80
# Trust the proxy
SYMFONY_TRUSTED_PROXIES=private_ranges
# The address the proxy serves, over HTTPS
DEFAULT_URI=https://cashew.example.com
MERCURE_PUBLIC_URL=https://cashew.example.com/.well-known/mercure
# Remove the CADDY_GLOBAL_OPTIONS line, keep the others
```

In `compose.yaml`, replace the three `ports` lines with `- 8080:80`. The proxy forwards `https://<your domain>` to `http://<server>:8080`.

## Stack

- **Backend**: Symfony 8.1 / PHP 8.5, Doctrine ORM
- **Front**: Twig, Symfony UX (Live Components, Turbo, Dropzone, Icons), AssetMapper, Tailwind v4
- **Database**: PostgreSQL
- **Background imports**: Messenger (Doctrine transport), progress pushed through Mercure
- **Docker**: [symfony-docker](https://github.com/dunglas/symfony-docker) (FrankenPHP + Caddy, with the Mercure hub built in)

## Requirements

- Docker

## Installation

1. Start:

   ```bash
   make start
   ```

2. Create a user:

   ```bash
   docker compose exec php bin/console app:create-user
   ```

3. Open <https://localhost> and accept the local certificate.

## Commands

`make help` lists everything. The main ones:

- `make start` / `make up` / `make down`: Docker lifecycle
- `make logs`: logs
- `make bash`: shell in the PHP container
- `make sf c="debug:router"`: Symfony console
- `make migrate`: Doctrine migrations

## Quality

- `make rector-fix` then `make cs-fix`: automatic fixes (in that order)
- `make phpstan-check`: static analysis
- `make phpunit`: tests
- `make ci`: run everything

## Release

Push a `v1.2.3` tag: the Docker workflow runs the CI, then publishes `smbpunt/cashew-php-prod:1.2.3` and `latest` on Docker Hub (amd64 and arm64).

## License

MIT
