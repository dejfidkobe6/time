<?php
/**
 * Ochrana jednorázových údržbových skriptů (migrace, čištění dat, diagnostika).
 *
 * Tyto skripty mažou data a prozrazují informace o serveru, takže nesmí být
 * veřejně spustitelné. Přístup má jen ten, kdo zná ADMIN_TOKEN z api/secrets.php.
 *
 * Použití:  https://time.besix.cz/api/migrate.php?token=<ADMIN_TOKEN>
 *           nebo hlavička  X-Admin-Token: <ADMIN_TOKEN>
 *
 * Bez nastaveného ADMIN_TOKEN jsou skripty nedostupné — to je záměr.
 * Token se nastavuje jako GitHub secret ADMIN_TOKEN (viz .github/workflows/deploy.yml).
 */

error_reporting(0);
ini_set('display_errors', '0');

if (file_exists(__DIR__ . '/secrets.php')) require_once __DIR__ . '/secrets.php';

function requireAdmin(): void {
    if (PHP_SAPI === 'cli') return;   // spuštění z příkazové řádky serveru je v pořádku

    $given = $_GET['token'] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';

    $ok = defined('ADMIN_TOKEN')
       && is_string(ADMIN_TOKEN) && ADMIN_TOKEN !== ''
       && is_string($given)
       && hash_equals(ADMIN_TOKEN, $given);

    if (!$ok) {
        // 404 místo 403 — ať skript neprozradí, že vůbec existuje
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Nenalezeno']);
        exit;
    }
}
