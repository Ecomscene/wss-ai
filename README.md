# WSS AI

AI-gereedschap voor webshops van Webshopschool, als WordPress-plugin.

Op dit moment voegt de plugin alleen een lege beheerpagina toe. Dat is met opzet:
eerst moet het bijwerken werken, daarna pas de inhoud. Zie hieronder waarom.

## De regel voor deze plugin: hou hem dun

Alles wat rekent, met een AI-model praat of geld kost hoort op de server van
Webshopschool, niet in deze plugin. De plugin is een schermpje dat die server
belt.

Twee redenen:

1. Een fout op onze server is binnen een minuut gerepareerd. Een fout in deze
   plugin moet langs tientallen webshops die allemaal moeten bijwerken.
2. Deze code draait in de PHP van een klant. Wat hier stukgaat, gaat op zijn
   winkel stuk.

Deze plugin staat daarom **los van claude-bridge** en dat blijft zo. Die heeft
`wp_eval` en schrijfrechten op bestanden; dat hoort niet in een plugin die bij
iedereen aanstaat en waar klanten zelf in kijken.

## Bijwerken

De plugin werkt zichzelf bij vanaf de releases in deze repo. WordPress kijkt
standaard op wordpress.org; de `Update URI` in de plugin-header voorkomt dat een
gelijknamige plugin daar de updates kaapt.

Een nieuwe versie uitbrengen:

1. Zet het nieuwe versienummer op **twee** plekken in `wss-ai.php`: de
   `Version:`-regel in de header en de constante `WSS_AI_VERSIE`. Lopen die uit
   elkaar, dan meldt WordPress iets anders dan de plugin zelf denkt te zijn.
2. Commit en tag: `git tag v0.2.0 && git push --tags`
3. Maak een release op die tag en hang er een zip aan met daarin één map
   `wss-ai/`.

Die zip is belangrijk. Zonder zip valt de updater terug op de automatische
zipball van GitHub; die pakt uit als `Ecomscene-wss-ai-a1b2c3` en bevat de
hele repo. De plugin hernoemt die map wel, maar een schone zip is beter.

Zip maken vanuit deze map (PowerShell):

```powershell
Remove-Item -Recurse -Force build -ErrorAction SilentlyContinue
New-Item -ItemType Directory build\wss-ai | Out-Null
Copy-Item wss-ai.php, includes, readme.txt build\wss-ai -Recurse -ErrorAction SilentlyContinue
Compress-Archive build\wss-ai build\wss-ai.zip -Force
```

## Controleren of het bijwerken werkt

Op de pagina **WSS AI** in wp-admin staat de huidige versie en of de controle
lukt. Er staan drie mogelijke standen, en het verschil telt:

| Stand | Betekenis |
|---|---|
| Werkt | We hebben GitHub gesproken en je hebt de laatste versie |
| Update beschikbaar | Er staat een nieuwere release klaar |
| Niet gecontroleerd | We konden GitHub niet bereiken — dit is **geen** "je bent bij" |

Die laatste is met opzet geen groen vinkje. Anders denk je dat updates
binnenkomen terwijl er al drie versies zijn uitgekomen.

## Installeren bij een klant

Plugins → Nieuwe plugin → Plugin uploaden → `wss-ai.zip`. Daarna activeren.
Vanaf dat moment komen updates vanzelf.
