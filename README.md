# Pipefy Backup

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-777bb4.svg?logo=php)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-ff2d20.svg?logo=laravel)](https://laravel.com)
[![Tests](https://img.shields.io/badge/tests-73%20passed-brightgreen.svg)](#testing)

[English](README.md) · [Português (Brasil)](README.pt-BR.md)

An automated, reliable, and enterprise-grade backup solution for [Pipefy](https://www.pipefy.com/). Pipefy does not provide a native one-click export or automated backup tool for entire organizations — this project fills that gap by pulling all pipes, cards, comments, fields, phases history, and binary attachments to local disk, with real-time web monitoring and S3 / Glacier cold storage archiving.

---

## Highlights & Features

- **Complete Data Export**: Exports full card JSON payloads (custom fields, phases history, comments, assignees, labels) via Pipefy's GraphQL API.
- **Binary Attachment Archiving**: Downloads all card attachments into collision-free directory structures keyed by Pipefy's upload UUID (`uploads/{pathUuid}/{filename}`).
- **Integrity Verification**: Inspects `Content-Length` via HEAD requests, persists sizes in card JSON, and validates on-disk files.
- **Resilience & Rate Limit Handling**: Automatic OAuth 401 token refresh, exponential backoff for rate limits, and transient error recovery.
- **Smart Retries (`--retry-errored`)**: Only re-attempts missing or corrupted attachments without re-downloading existing files.
- **Idempotent / Skip-if-Exists**: Interrupted backups can be resumed safely at any time; verified local files are skipped.
- **Live Web Dashboard**: Built with Laravel and Tailwind CSS to track progress, completion percentages, active queue jobs, and error logs in real time.
- **Memory-Safe Worker Recycling**: Includes worker loop scripts for both Windows (`worker-loop.bat`) and Linux/macOS (`worker-loop.sh`) that recycle PHP worker processes to prevent memory leaks during massive, multi-hour backup runs.
- **AWS S3 / Glacier Cold Storage Ready**: Step-by-step guidance to sync local backups directly to AWS S3 (including Glacier Instant Retrieval).

---

## Storage Layout

All files are stored in `storage/app/private/pipefy-backup/`:

```
storage/app/private/pipefy-backup/
└── {pipeId}/
    ├── cards/
    │   ├── {cardId}.json          # Complete card JSON (fields, comments, attachments metadata)
    │   └── index.json             # Pipe summary index (card list, totals, backup timestamp)
    └── attachments/
        └── {cardId}/
            └── {pathUuid}/        # Pipefy upload UUID (avoids duplicate filename overwrite)
                └── {filename}     # Binary attachment file
```

---

## Prerequisites

- **PHP**: 8.3 or 8.4 (extensions: `bcmath`, `curl`, `mbstring`, `openssl`, `pdo_sqlite` or `pdo_mysql`, `fileinfo`)
- **Composer**: 2.x
- **Node.js**: 18+ and `npm`
- **Redis** *(strongly recommended for large organizations with 10k+ attachments)* or SQLite / MySQL database queue
- **Pipefy Account** with administrative access to generate API credentials
- **AWS CLI** *(optional, if syncing to S3)*

---

## Quick Start / Installation

### 1. Clone the repository

```bash
git clone https://github.com/jotafurtado/pipefy-backup.git
cd pipefy-backup
```

### 2. Run automated setup

Run the automated Composer setup command:

```bash
composer run setup
```

*This command automatically:*
1. Installs PHP dependencies (`composer install`)
2. Creates `.env` from `.env.example` (if not already present)
3. Generates the Laravel application key (`php artisan key:generate`)
4. Runs database migrations (`php artisan migrate --force`)
5. Installs npm dependencies and builds assets (`npm install && npm run build`)

---

## Configuration

Edit your `.env` file to configure your Pipefy credentials and environment:

```env
APP_NAME="Pipefy Backup"
APP_ENV=local
APP_KEY=base64:...
APP_URL=http://localhost:8000

# Database (SQLite is the default and requires no extra setup)
DB_CONNECTION=sqlite

# Queue connection (Redis is recommended for high volume)
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Pipefy API Credentials
PIPEFY_CLIENT_ID=your_pipefy_client_id
PIPEFY_CLIENT_SECRET=your_pipefy_client_secret
PIPEFY_ORGANIZATION_ID=your_organization_id
PIPEFY_TOKEN_URL=https://app.pipefy.com/oauth/token
PIPEFY_API_ENDPOINT=https://api.pipefy.com/graphql
```

### Obtaining Pipefy API Credentials

1. **Client ID & Client Secret**:
   - In Pipefy, navigate to your **Account Preferences** -> **Developers / API**.
   - Create a new OAuth App or Personal Token to obtain your `Client ID` and `Client Secret`.
2. **Organization ID**:
   - Find your Organization ID in your browser address bar when logged into Pipefy (e.g. `https://app.pipefy.com/organizations/1234567`) or under **Organization Settings**.

---

## Running the Application & Workers

Because large backups involve thousands of GraphQL requests and file downloads, work is processed asynchronously using background workers.

### Start the Server and Workers

**On Windows:**
```bash
composer run dev-windows
```
*(Starts the HTTP server, 4 recycled queue workers, and Laravel Pulse metrics monitor)*

For 8 concurrent workers on Windows:
```bash
composer run dev-windows:8w
```

**On Linux / macOS:**
```bash
composer run dev-linux
```
*(Runs the HTTP server, 4 recycled queue workers via `worker-loop.sh`, and Laravel Pulse)*

Or manually run workers using Laravel's queue command:
```bash
php artisan queue:work redis --timeout=0 --sleep=3 --tries=3
```

---

## Running Backups

### 1. Start Full Organization Backup

Dispatches backup jobs for every pipe in the organization:

```bash
php artisan pipefy:backup-all
```

### 2. Monitor in the Web Dashboard

Open `http://localhost:8000` in your web browser:
- Real-time progress bar for each pipe.
- Total cards completed, pending, and failed.
- Attachment download metrics and error logs.
- One-click retry button for errored cards.

### 3. Handle Errors & Partial Downloads

If any network timeouts, rate limit spikes, or transient Pipefy errors occur:

```bash
# Re-dispatch cards that had attachment download errors (skips already downloaded files):
php artisan pipefy:backup-all --retry-errored

# Re-dispatch cards that failed completely:
php artisan pipefy:backup-all --retry
```

---

## Additional Artisan Commands

| Command | Description |
|---|---|
| `php artisan pipefy:pipes` | Lists all pipes available in the configured Pipefy organization. |
| `php artisan pipefy:backup-status` | Displays summary of backups (card counts, completion state, errors). |
| `php artisan pipefy:verify-backup` | Verifies on-disk files against JSON manifests and checks file sizes. |
| `php artisan pipefy:verify-backup --pipe={id}` | Verifies integrity for a single specific pipe. |
| `php artisan pipefy:cleanup-attachments --dry-run` | Identifies orphaned legacy attachment files. |
| `php artisan pipefy:cleanup-attachments` | Removes orphaned legacy attachment files after migration. |

---

## AWS S3 & Glacier Archiving

Once local backups are completed, you can sync the entire repository to an AWS S3 bucket for cost-effective long-term retention.

### Recommended: S3 Glacier Instant Retrieval

For long-term compliance storage with instant access when needed:

```bash
aws s3 sync storage/app/private/pipefy-backup s3://your-bucket-name/pipefy-backup/ \
  --exclude "*.tmp" \
  --storage-class GLACIER_IR
```

*Useful flags:*
- `--storage-class GLACIER_IR`: Glacier Instant Retrieval (dramatically lower storage cost while retaining milliseconds retrieval time).
- `--exclude "*.tmp"`: Ignores temporary in-progress download files.
- `--delete`: Removes files on S3 that were deleted locally (optional).
- `--dryrun`: Simulates the transfer without copying files.

---

## Restoring and Reading Backups

Each backup is stored in human-readable JSON and standard directory structures without proprietary wrappers:

1. **Pipe Index**: `storage/app/private/pipefy-backup/{pipeId}/cards/index.json`
2. **Card Data**: `storage/app/private/pipefy-backup/{pipeId}/cards/{cardId}.json`
3. **Attachments**: `storage/app/private/pipefy-backup/{pipeId}/attachments/{cardId}/{pathUuid}/{filename}`

Example inspection with `jq`:
```bash
# List all cards in a pipe
cat storage/app/private/pipefy-backup/123456/cards/index.json | jq '.cards[] | {id, title}'

# View attachments of a card
cat storage/app/private/pipefy-backup/123456/cards/789012.json | jq '.attachments[] | {filename, path, content_length}'
```

---

## Architecture & Design Decisions

Detailed Architecture Decision Records (ADRs) are documented in `docs/adr/`:
- [ADR-0001: Attachment Path Keyed by Pipefy Upload UUID](docs/adr/0001-attachment-path-by-pipefy-upload-uuid.md)
- [ADR-0002: Pipefy Rate Limiting and Attachment Download Resilience](docs/adr/0002-pipefy-rate-limiting-and-download-resilience.md)

---

## Testing

Run the automated test suite with Pest / PHPUnit:

```bash
php artisan test --compact
```

Or run Pint to check code formatting:

```bash
vendor/bin/pint --format agent
```

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request or open an Issue for bug reports or feature requests.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

This project is open-sourced software licensed under the [MIT License](LICENSE).
