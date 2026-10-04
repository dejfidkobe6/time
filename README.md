# BeSix Time — Harmonogram

## Export API

**URL:** `https://time.besix.cz/api/export.php`

**Parametry (GET):**
- `token` — statický API token (viz `api/secrets.php`, klíč `EXPORT_TOKEN`)
- `project` — ID projektu (číslo) nebo přesný název (string), např. `"E3 - HMG Fasády"`
- `from` *(volitelné)* — YYYY-MM-DD, dolní hranice intervalu
- `to` *(volitelné)* — YYYY-MM-DD, horní hranice intervalu

**Token:** Uložen v `api/secrets.php` pod klíčem `EXPORT_TOKEN`. Soubor je v `.gitignore` a musí být nasazen ručně na server.

**Příklad:**
```bash
curl "https://time.besix.cz/api/export.php?token=VÁŠ_TOKEN&project=E3+-+HMG+Fasády&from=2026-01-01&to=2026-12-31"
```
