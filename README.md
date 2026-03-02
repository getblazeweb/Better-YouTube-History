# Better YouTube History

A secure PHP webapp that displays your YouTube watch history in a searchable timeline. Import your data from Google Takeout and browse it locally with video thumbnails, channel links, and date filters.

## Features

- **Import from Takeout** – Upload `watch-history.html` or `watch-history.json` from your Google Takeout export
- **Timeline view** – Browse history by date with video titles, thumbnails, and channel links
- **Search & filter** – Filter by video title or channel, and by date range
- **Secure login** – Single-user auth with optional TOTP 2FA
- **Local storage** – SQLite database; your data stays on your server

## Requirements

- PHP 8.1+ with PDO SQLite, JSON, OpenSSL
- Web server (Apache recommended) with document root set to `public/`

## Installation

1. Clone or copy the project.
2. Copy `.env.example` to `.env`.
3. Generate secrets:
   ```bash
   php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;"
   php -r "echo 'ADMIN_PASSWORD_HASH=' . password_hash('your-password', PASSWORD_DEFAULT) . PHP_EOL;"
   ```
4. Edit `.env` with your `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`, and `APP_KEY`.
5. Point your web server document root to the `public/` directory.
6. Ensure `data/` is writable (chmod 0700).
7. For large Takeout files (60MB+), ensure PHP `upload_max_filesize` and `post_max_size` are at least 100M.

## Importing History

1. Go to [takeout.google.com](https://takeout.google.com).
2. Select **YouTube and YouTube Music** and export.
3. Extract the archive and find `Takeout/YouTube and YouTube Music/history/watch-history.html` (or `watch-history.json` for older exports).
4. Log in to the app, click **Refresh** in the header, then upload the file.

## Project Structure

```
├── config/          # App configuration
├── migrations/      # SQLite schema
├── public/         # Web root (document root)
│   ├── api/        # Refresh/import endpoint
│   └── assets/     # CSS
├── src/            # PHP core (auth, repo, parser)
├── views/          # Dashboard template
└── data/           # SQLite DB (created at runtime)
```

## Troubleshooting HTTP 500 on Upload

If you get "500 Internal Server Error" when uploading:

1. **Use `.user.ini`** – The project includes `public/.user.ini` with upload limits. On cPanel/shared hosting, ensure this file is in your document root. Changes take effect after ~5 minutes or a PHP-FPM restart.

2. **Alternative: `php.ini`** – If your host allows it, create `public/php.ini` (or add to the main php.ini):
   ```ini
   upload_max_filesize = 100M
   post_max_size = 100M
   memory_limit = 256M
   max_execution_time = 300
   ```

3. **Check error logs** – In cPanel: Errors, or ask your host for the PHP error log. Look for "memory_limit", "Maximum execution time", or "Allowed memory size".

4. **Try a smaller file first** – Export Takeout with a shorter date range to get a smaller watch-history.html and confirm the app works.

## Security

- All pages except login require authentication.
- Optional TOTP 2FA (enable in Security).
- Configurable login rate limiting.
- CSRF protection on all forms.
- Sensitive directories protected via `.htaccess`.

## Configuration

| Variable | Description |
|----------|-------------|
| `APP_KEY` | 32+ character secret for encryption (required) |
| `ADMIN_USERNAME` | Login username |
| `ADMIN_PASSWORD_HASH` | Output of `password_hash('password', PASSWORD_DEFAULT)` |
| `WEB_BASE` | Leave empty if app is at root; set to `/subdir` if in subdirectory |

## Demo

A demo instance is available in the `demo/` folder. See [demo/README.md](demo/README.md) for setup. Sign in with **demo** / **demo**.

**Live demo:** https://betteryoutubehistory.blazehost.co/

## License

This project is licensed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).
