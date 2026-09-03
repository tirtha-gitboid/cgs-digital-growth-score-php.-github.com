# CGS Digital Growth Score™ (PHP port)

A PHP port of the original FastAPI/Python MVP — a free lead-generation /
website audit tool for CGS Infotech. Functionality, scoring logic, and the
frontend are unchanged; only the backend language changed (Python → PHP).

## What it does

A prospect enters a website URL and the tool checks:

- HTTPS
- Mobile viewport
- Title and meta description
- H1 structure
- Canonical URL
- Open Graph tags
- Image alt coverage
- Internal links
- robots.txt
- sitemap.xml
- Schema.org JSON-LD
- Organization/LocalBusiness signals
- FAQ content
- social profile links
- content depth
- optional Google PageSpeed Insights data

It produces:

- Overall CGS Digital Growth Score™ /100
- Website score
- SEO score
- Local SEO score
- AI Search Readiness score
- Social Presence score
- Priority recommendations
- Print / Save as PDF report (browser print, unchanged from the original)
- Local SQLite lead capture (or Postgres, via `DATABASE_URL`)

## Important

The "AI Search Readiness" score is a technical readiness proxy. It does NOT
query ChatGPT, Gemini or Perplexity and does NOT claim that a business will
appear in those systems.

Search Console data is intentionally not pulled in automatically because it
requires the website owner's Google authorization.

PageSpeed Insights is optional. If you add a Google PageSpeed API key in
`.env`, the audit blends Lighthouse-based performance/SEO/accessibility/
best-practices results into the report.

## Requirements

- PHP 8.1+ with the `curl`, `dom`, `mbstring`, and `pdo_sqlite` extensions
  (all bundled with most PHP distributions — on Debian/Ubuntu install with
  `apt install php-cli php-curl php-xml php-mbstring php-sqlite3`), plus
  `pdo_pgsql` if you plan to use Postgres (`php-pgsql`).
- No Composer packages are required — everything uses PHP's built-in
  `curl`, `DOMDocument`/`DOMXPath`, and `PDO` extensions.

## Project layout

```
index.php            Serves the frontend and runs startup checks
router.php            Router for PHP's built-in dev server
.htaccess              Rewrite rules for Apache deployment
config.php             .env loader + shared constants
backend/Audit.php      URL validation, fetching, HTML parsing, scoring
backend/Db.php         SQLite/Postgres lead storage
api/audit.php          POST /api/audit
api/leads.php          POST /api/leads
api/health.php         GET  /api/health
frontend/index.html    Unchanged frontend (same HTML/CSS/JS as the original)
frontend/cgs-logo.png
data/                  SQLite database lives here (data/leads.db)
```

## Run locally (PHP built-in server)

1. Install PHP 8.1+.
2. Copy `.env.example` to `.env`.
3. Optional: add a Google PageSpeed Insights API key to `.env`:
   `PAGESPEED_API_KEY=YOUR_KEY`
4. From the project folder, start the built-in server with the router:

   ```
   php -S 127.0.0.1:8000 router.php
   ```

5. Open:

   http://127.0.0.1:8000

## Deploy with Apache

1. Point the virtual host's document root at this folder.
2. Ensure `mod_rewrite` is enabled and `AllowOverride All` is set for the
   directory so `.htaccess` is honored.
3. Make sure `data/` is writable by the web server user (for SQLite) — the
   included `.htaccess` blocks direct web access to that folder regardless.
4. Set `DATABASE_URL` / `PAGESPEED_API_KEY` in `.env` or as real server
   environment variables.

## Deploy with Nginx + PHP-FPM

Route `/`, `/static/*`, `/api/audit`, `/api/leads`, and `/api/health` the
same way `router.php` does, e.g.:

```
location = / { rewrite ^ /index.php last; }
location /static/ { alias /path/to/app/frontend/; }
location = /api/audit { rewrite ^ /api/audit.php last; }
location = /api/leads { rewrite ^ /api/leads.php last; }
location = /api/health { rewrite ^ /api/health.php last; }
location ~ \.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
```

## Using the tool for CGS Infotech

Try:
`https://www.cgsinfotech.com`

Then save the generated report as PDF from the browser.

## Lead data

Leads are stored in:
`data/leads.db` (SQLite), or your Postgres database if `DATABASE_URL` is set.

For production, replace the local SQLite lead storage with a proper
CRM/database and add authentication, consent/privacy text, rate limiting,
CAPTCHA, and audit logging.

## Production improvements to build next

1. Google OAuth + Search Console connection
2. Google Business Profile/GBP integration where permitted
3. Competitor comparison
4. AI visibility research workflow with human verification
5. Branded PDF generator
6. Email report delivery
7. CRM integration
8. Admin dashboard
9. Lead scoring
10. Rate limiting/CAPTCHA
