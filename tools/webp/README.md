# WSS WebP Converter

Herbruikbare versie van Joeys webp-snippet. Bedoeld om bij elke klant identiek
uit te rollen en headless te draaien via ClaudeWP.

## Uitrollen bij een klant

1. Zet `wss-webp.php` op de site als `wp-content/plugins/wss-webp/wss-webp.php`.
2. `wp plugin activate wss-webp`
3. `wp wss-webp status` (controleer dat GD of Imagick WebP aankan en hoeveel
   afbeeldingen er zijn)
4. `wp wss-webp convert` (draait door tot alles gehad is)
5. `wp wss-webp serve on` en `wp wss-webp auto on`
6. Controleer een productpagina en de homepage: laden de plaatjes nog.

Draait er al een oude versie van het snippet (Code Snippets, prefix `wic_`),
zet die dan eerst uit. De plugin neemt de al gedane status eenmalig over, dus
werk dat al gedaan is wordt niet overgedaan.

## Commando's

    wp wss-webp status
    wp wss-webp convert [--limit=200] [--retry-failed]
    wp wss-webp reset [--failed-only]
    wp wss-webp serve on|off
    wp wss-webp auto on|off

## Instelbaar per site (wp-config.php)

    define( 'WSS_WEBP_QUALITY', 80 );   // 1-100
    define( 'WSS_WEBP_PER_BATCH', 3 );  // lager = veiliger bij zware plaatjes

## Wat het doet en niet doet

- Originelen worden nooit verwijderd; de `.webp` komt ernaast te staan.
- Een `.webp` die groter uitvalt dan het origineel wordt weggegooid; die
  afbeelding krijgt status `skipped` en blijft als jpg/png geserveerd.
- Serveren gebeurt via een HTML-rewrite, alleen bij browsers die WebP accepteren
  en alleen als het bestand echt bestaat. Geen .htaccess, geen redirect.
- Nieuwe uploads worden kort na de upload via cron omgezet, niet in het
  upload-request zelf. Converteren tijdens de upload maakt uploaden en het
  dupliceren van variatieproducten merkbaar traag.
- Alleen JPG en PNG. Geen GIF, geen SVG.

## Waar het mis kan gaan

- Geen `imagewebp` en geen Imagick: dan kan de server het niet en stopt de CLI
  meteen met een melding.
- Pagecache (LiteSpeed, WP Rocket, Cloudflare) kan oude HTML met .jpg blijven
  serveren nadat `serve` aan is gezet. Cache leegmaken na stap 5.
- Zware afbeeldingen kunnen een ronde laten klappen. De afbeelding staat dan al
  op `failed` en wordt overgeslagen; met `--retry-failed` probeer je ze later
  opnieuw, eventueel met een lagere `WSS_WEBP_PER_BATCH`.
