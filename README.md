# Secret Santa (Docker)

The Secret Santa site as one container: PHP 8.3 + Apache + SQLite. All data lives in
one Docker volume (`/data` inside the container).

## First install

```sh
cp .env.example .env        # fill in BASE_URL, TRUSTED_PROXIES, VoIP.ms settings
docker compose up -d        # downloads ghcr.io/nickbeaus/secret-santa and starts it
docker compose logs web     # shows the one-time setup code
```

Only `compose.yaml` and `.env` are needed on the server; the image is downloaded:

```sh
wget https://raw.githubusercontent.com/nickbeaus/secret-santa/main/compose.yaml
wget -O .env https://raw.githubusercontent.com/nickbeaus/secret-santa/main/.env.example
```

Open `BASE_URL/setup.php`:

1. **Create your admin account.** Enter the setup code from the log, a username and a
   password (10+ characters). The code exists so that a stranger who reaches a fresh
   install first can't claim it. Show it again with
   `docker compose exec web php bin/admin.php setup-code`.
2. **Bring in your data.** Upload an export file, or start with an empty site.

## Moving from the old (MySQL) site

On the old site, go to **Backup → Download Export**. Or, on the old server from this folder:

```sh
php tools/export-from-mysql.php /var/www/xmas/includes/config.php > secret-santa-export.json
```

Both only read from MySQL and produce the same file. Import the file on the setup page (step 2), or from the command line:

```sh
docker compose exec -T web php bin/import.php - < secret-santa-export.json
```

The file contains names, emails and phone numbers, so delete it when you're done.

Importing only works on an empty site, and it's all or nothing: a problem in the file
means nothing is imported. Admin accounts are never part of an export.

## Admin accounts

- Manage them on the **Admins** page: change your password, add another admin, reset
  another admin's password, or remove one. Every change asks for your current password.
- Passwords are stored as Argon2id hashes in the database.
- Changing a password signs that admin out everywhere else.
- Logins end after 8 hours of inactivity, and always after 7 days.
- 5 failed logins from one IP address, or for one username, lock logins out for 15 minutes.

Locked out or forgot the password:

```sh
docker compose exec web php bin/admin.php reset-password <username>
docker compose exec web php bin/admin.php unlock       # clear lockouts
docker compose exec web php bin/admin.php list
docker compose exec web php bin/admin.php create <username>
```

## Backups

- On the **Backup** page, download an export at any time.
- From the command line:

  ```sh
  docker compose exec -T web php bin/export.php > secret-santa-$(date +%F).json
  ```

- To restore, start a new install with an empty volume and import the file on the setup page.

## Updating

Change the version in `compose.yaml` (e.g. `secret-santa:1.0.0` → `1.1.0`), then:

```sh
docker compose pull         # download the new image
docker compose up -d        # recreate the container with it; data is kept
```

- On startup the container applies any new schema files in `app/migrations/`, in order.
- Before it changes an existing database, it saves a copy in `/data`, such as
  `xmas.sqlite.before-v2.<date>.bak`.
- If a migration fails, it's rolled back and the container stops with the error in
  `docker compose logs web`.

To change the schema, add the next numbered file, for example `002_add_budget.sql`.
Never edit a file that has already been applied.

## Settings (`.env`)

| Setting | Meaning |
|---|---|
| `HOST_PORT` | Port on the Docker host (container listens on 8080) |
| `BASE_URL` | Public address used in reveal links and texts, ending in `/` |
| `TRUSTED_PROXIES` | Reverse proxy IP(s), comma-separated, whose `X-Forwarded-*` headers are trusted |
| `VOIPMS_*` | VoIP.ms API username/password and sending number. Optional: "Send from My Phone" works without them |
| `SMS_TEST_MODE` | `true` sends every text to `SMS_TEST_NUMBER` instead of participants (the default) |

Restart after editing: `docker compose up -d`.

**Reverse proxy check:** after switching over, the access log (`docker compose logs web`)
should show your proxy's IP address. If it shows a Docker address such as `172.x.x.x`
instead, Docker is hiding the real source. The site then can't detect HTTPS or real
visitor IPs, so fix the Docker networking (for example `network_mode: host`) rather than
trusting the Docker address.

**VoIP.ms** only accepts API calls from allowed IP addresses. If the container's public
IP address differs from the old server's, add it in the VoIP.ms portal.

## Publishing a release (maintainer)

Images are published to `ghcr.io/nickbeaus/secret-santa`.

**Automatic (recommended).** Push a version tag and GitHub Actions builds and publishes the
image for both amd64 and arm64 (see `.github/workflows/release.yml`):

```sh
git tag v1.0.1
git push origin v1.0.1
```

Follow the build in the repository's **Actions** tab.

**Manual fallback** with `release.sh` (builds for this machine's architecture only):

1. Once per machine: `docker login ghcr.io -u nickbeaus`, using a classic GitHub token
   with `write:packages` as the password.
2. Pick the next version number. Use the middle number for new features (`1.1.0`) and the
   last one for fixes (`1.0.1`). A published version is never reused.
3. `./release.sh 1.1.0` builds the image and pushes `:1.1.0` and `:latest`.
4. Update the version in `compose.yaml` wherever it's installed, then `docker compose pull`
   and `docker compose up -d`.

Development without publishing:
`docker compose -f compose.yaml -f compose.build.yaml up -d --build` builds from this
folder instead of downloading.

## Using a folder instead of the named volume

To keep the data in a folder next to `compose.yaml`, replace the volume line with
`- ./data:/data`, then run `sudo chown -R 33:33 data` once. The container runs as
`www-data` (uid 33).

## Layout

```
compose.yaml       uses the published image (all an install needs, plus .env)
compose.build.yaml development override: build from source instead
Dockerfile, .env.example, release.sh
docker/            Apache site, PHP settings, startup script
app/html/          web pages (document root)
app/includes/      shared code: config (from env), auth, database, backup, sms
app/migrations/    numbered schema files (SQLite)
app/bin/           command-line tools: migrate, admin, export, import
tools/             export-from-mysql.php for the old site (not in the image)
```
