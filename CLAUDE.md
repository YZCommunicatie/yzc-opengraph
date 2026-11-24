# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

De codeerstandaard voor alle YZC-extensies staat in
[`CONVENTIONS.md`](https://github.com/YZCommunicatie/yzc-standards/blob/main/CONVENTIONS.md)
in `yzc-standards`. Dit bestand vult dat aan met wat specifiek is voor dit project.

## What this is

Een Joomla 5/6-systeemplugin (`plg_system_opengraph`, element `opengraph`) die Open Graph-,
Twitter Card- en Facebook-metatags zet voor social sharing. Geen buildstap, package manager
of testsuite — gewone PHP/XML, direct bewerkt. Gebruikt de moderne namespaced pluginstructuur
(DI-service-provider, `CMSPlugin`).

## Files

- `src/Extension/Opengraph.php` — de pluginklasse
  (`YZCommunicatie\Plugin\System\Opengraph\Extension\Opengraph`), een `SubscriberInterface`
  met als enige event `onBeforeCompileHead`. Zet de tags uit de helper met
  `$document->setMetaData()`; lege waarden worden overgeslagen. Doet niets buiten de site-kant,
  bij `com_finder`, en op een `ErrorDocument`: een foutpagina draagt nog het verzoek van het
  mislukte artikel, en zou dus de titel en tekst van bijvoorbeeld een concept in de head zetten.
  - De globale `<meta name="description">` wordt **bewust** overschreven, maar alleen door een
    niet-lege waarde. Tot en met 1.3.1 wiste de plugin ook de beschrijving van de site.
- `src/Helper/OpengraphHelper.php` — alle waarde-opbouw. Laadt het artikel of de categorie
  één keer, in de constructor.
  - **`loadArticle()` en `loadCategory()` zijn een beveiligingsgrens.** Ze volgen de regels van
    `com_content` (status, publicatiedatums, toegangsniveau van artikel én categorie,
    `show_noauth`). Core geeft bij geen toegang alleen een 403-header en rendert de head gewoon;
    zonder deze controle lekt de inhoud van afgeschermde artikelen.
  - Afbeeldingsafmetingen komen uit het lokale bestand, nooit uit een URL: tot en met 1.3.1 vroeg
    elke pagina zo drie keer een afbeelding aan de eigen site op.
- `services/provider.php` — DI-containerregistratie van de plugin. Bevat twee vertakkingen voor
  Joomla 5.0 t/m 5.3 (dispatcher in de constructor, geen `lazy()`); die kunnen weg zodra de
  minimumversie 5.4 is.
- `opengraph.xml` — Joomla-extensiemanifest: versie, `<namespace path="src">`, pluginparams
  (Twitter/Facebook/fallback) en de update-server-URL.
- `script.php` — installerscript (`InstallerScriptInterface`): weigert installatie onder
  Joomla 5.0 of PHP 8.1 en activeert de plugin bij een verse installatie, niet bij een update.
- `manifest.xml` — de update-feed, ruwvorm opgehaald vanaf de `main`-branch van dit repo
  door Joomla-sites. **Niet aanraken buiten het releaseproces** — zie "Releasing a new version".
- `language/en-GB/` en `language/nl-NL/` — veldlabels (`.ini`) en de omschrijving (`.sys.ini`).
- `.distignore` — houdt het losse, niet-getrackte `yzc-opengraph.zip` uit de releasezip.

## Compatibility constraints

- Doelplatform Joomla 5 en 6. De grens staat op twee plekken: `targetplatform` in `manifest.xml`
  (wie de update aangeboden krijgt) en `MINIMUM_JOOMLA` in `script.php` (wie mag installeren).
- `targetplatform` is een regex die Joomla als `/^<waarde>/` uitvoert: `[56]\.[0-9]`, niet `5|6`,
  want dat matcht op elke versie met een 6 erin.
- Minimale PHP-versie 8.1 (het minimum van Joomla 5.0).

## Releasing a new version

Klantsites updaten automatisch via dit repo, dus een release vereist:

1. Bump `<version>` in `opengraph.xml` **en lokaal** `<version>` + `<downloadurl>` in
   `manifest.xml`; `build.php` weigert als ze verschillen. Nog niet pushen.
2. Bouw de installatiezip met `php build.php` (schrijft `dist/opengraph-<versie>.zip`) en zet de
   SHA-256 ervan in `<sha256>` in `manifest.xml`. Elke nieuwe build geeft een andere checksum.
3. Maak **eerst** de git tag / GitHub release `v<versie>` aan (met precies die zip als
   release-asset), zodat de zip-URL resolvet.
4. Push **pas daarna** `manifest.xml` naar `main` — in de andere volgorde zien klantsites een
   update waarvan de download 404't. Zie hoofdstuk 6 van `CONVENTIONS.md`.

> Deze repo heette tot 3 september 2026 `YZC-Opengraph` en serveerde de feed vanaf `master`.
> Sinds v1.3.1 wijst de feed-URL naar
> `raw.githubusercontent.com/YZCommunicatie/yzc-opengraph/main/manifest.xml`.
> De brugbranch `master` is op 10 september 2026 verwijderd, nadat was vastgesteld dat geen klantsite
> nog op v1.3.0 draait (zie uitzondering 5 in hoofdstuk 3 van `CONVENTIONS.md`). `manifest.xml` hoeft
> dus **alleen** nog op `main` bijgewerkt te worden. De laatste stand van die branch was
> `1bf882e4eb8a`; terugzetten kan met `git push origin 1bf882e4eb8a:refs/heads/master`.

## Conventions

- Alle PHP-entrypoints starten met `defined('_JEXEC') or die;` (of `\defined(...)` in de
  namespaced klassen).
- Code volgt PSR-12, zoals Joomla-core sinds 4.x.
- **Het pluginelement `opengraph` is een bevroren uitzondering op de YZC-naamgevingsregel**
  (zou volgens de regel `yzcopengraph` moeten heten). Reden: een Joomla-element hernoemen is
  geen upgrade maar een nieuwe plugin — de oude installatie blijft staan en de ingestelde
  params (`twitter_type`, `twitter_site`, `twitter_author`, `fb_page_id`, `fb_app_id`,
  `fallback_image`, `fallback_image_alt`) verhuizen niet mee. Dat zou op elke klantsite
  handwerk betekenen voor uitsluitend cosmetische winst. De naam is generiek genoeg om
  theoretisch te botsen met een andere extensie; dat risico weegt niet op tegen een
  herinstallatie per klantsite. Zie ook hoofdstuk 3 van `CONVENTIONS.md`.
- **Overlap met `yzc-styling`:** beide zijn `system`-plugins op hetzelfde event
  `onBeforeCompileHead` en dezelfde site-client. Uit onderzoek
  (`yzc-standards/docs/superpowers/opengraph-overlap-advies.md`) blijkt dat ze vandaag geen
  enkele identieke `_metaTags`-sleutel (naam + attribuut) zetten — geen "wie wint"-conflict.
  Wel is er een conceptuele overlap: `og:url` (deze plugin — de canonical van de pagina als die al
  in de head staat, anders de huidige URL, bij een SEF-pad van `com_content` zonder querystring)
  versus `<link rel="canonical">` (`yzc-styling` — zonder querystring, met `Uri::root()` als
  hostbron zodat een geconfigureerde `live_site` wint)
  drukken beide "de canonieke URL van deze pagina" uit maar kunnen op een site met beide
  plugins actief een verschillende waarde tonen. Mocht er ooit wél een botsende
  `setMetaData()`-sleutel ontstaan: Joomla's `PluginHelper` laadt system-plugins gesorteerd op
  `ordering` in `#__extensions`, en `setMetaData()` overschrijft (stapelt niet) — dus wint de
  plugin met het hoogste `ordering`-getal (de laatst uitgevoerde). Deze plugin heeft daar zelf
  geen guard tegen; `yzc-styling`'s canonical-logica wel (die slaat zichzelf over als er al een
  canonical-link staat).

## Verifying changes

Geen geautomatiseerde tests. Handmatig verifiëren:

- `php -l` op elk gewijzigd bestand.
- Installeer de zip op een Joomla 5- én een Joomla 6-testsite en controleer de gerenderde `<head>`
  (view-source) op de OG-/Twitter-/FB-tags, met en zonder `yzc-styling` gelijktijdig actief.
- Test met en zonder de optionele params (`twitter_site`, `twitter_author`,
  `fallback_image`) ingevuld, en op zowel een artikel- als een categoriepagina.
- Open als gast een afgeschermd artikel, een concept en een niet-bestaand artikel: in de `<head>`
  mag niets van de inhoud staan.
