<?php
/**
 * Database layer for CGS Digital Growth Score (PHP port of backend/db.py).
 *
 * - If a DATABASE_URL environment variable is set (e.g. a Postgres add-on),
 *   leads are stored in Postgres and survive restarts/redeploys.
 * - Otherwise, falls back to a local SQLite file (data/leads.db) — fine for
 *   local development, but note that on most free hosting tiers the
 *   filesystem is wiped on redeploy/restart, so leads won't persist there
 *   without Postgres.
 */

require_once __DIR__ . '/../config.php';

class Db
{
    private static ?PDO $pdo = null;
    private static ?string $backend = null;

    private static function databaseUrl(): string
    {
        return trim(cgs_env('DATABASE_URL', ''));
    }

    public static function usePostgres(): bool
    {
        return self::databaseUrl() !== '';
    }

    public static function backendName(): string
    {
        return self::usePostgres() ? 'postgres' : 'sqlite';
    }

    private static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if (self::usePostgres()) {
            $url = self::databaseUrl();
            // Some hosts hand out "postgres://" — normalize like the Python version.
            $url = preg_replace('#^postgres://#', 'postgresql://', $url, 1);
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['host'])) {
                throw new RuntimeException('Invalid DATABASE_URL.');
            }
            $host = $parts['host'];
            $port = $parts['port'] ?? 5432;
            $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } else {
            if (!is_dir(DATA_DIR)) {
                mkdir(DATA_DIR, 0775, true);
            }
            $path = DATA_DIR . '/leads.db';
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        }

        return self::$pdo;
    }

    /** Create the leads table if it doesn't exist yet. Call once at startup. */
    public static function initDb(): void
    {
        $pdo = self::connect();
        if (self::usePostgres()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS leads (
                    id SERIAL PRIMARY KEY,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL,
                    phone TEXT,
                    company TEXT,
                    website TEXT,
                    created_at TEXT NOT NULL
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS leads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL,
                    phone TEXT,
                    company TEXT,
                    website TEXT,
                    created_at TEXT NOT NULL
                )
            ");
        }
    }

    public static function saveLead(string $name, string $email, string $phone, string $company, string $website): void
    {
        $pdo = self::connect();
        // ISO-8601 UTC timestamp, mirroring datetime.now(timezone.utc).isoformat().
        $createdAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');

        $stmt = $pdo->prepare(
            'INSERT INTO leads(name,email,phone,company,website,created_at) VALUES(?,?,?,?,?,?)'
        );
        $stmt->execute([$name, $email, $phone, $company, $website, $createdAt]);
    }
}
