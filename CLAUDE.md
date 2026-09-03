# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

De codeerstandaard voor alle YZC-extensies staat in
[`CONVENTIONS.md`](https://github.com/YZCommunicatie/yzc-standards/blob/main/CONVENTIONS.md)
in `yzc-standards`. Dit bestand vult dat aan met wat specifiek is voor dit project.

## What this is

Een Joomla 4/5-systeemplugin (`plg_system_opengraph`, element `opengraph`) die Open Graph-,
Twitter Card- en Facebook-metatags zet voor social sharing. Geen buildstap, package manager
of testsuite — gewone PHP/XML, direct bewerkt. Gebruikt de moderne namespaced pluginstructuur
(DI-service-provider, `CMSPlugin`).

## Files

- `src/Extension/Opengraph.php` — de pluginklasse
  (`YZCommunicatie\Plugin\System\Opengraph\Extension\Opengraph`), enige event
  `onBeforeCompileHead` (alleen site-kant, overgeslagen bij `format=feed` en `com_finder`).
  Zet de OG-/Twitter-/FB-tags rechtstreeks via `$document->setMetaData()`.
  - Let op regel 74, `setMetaData('description', ..., 'property')`: Joomla-core leidt de
    sleutel `description` intern om naar `setDescription()` en negeert daarbij het derde
    argument (`'property'`). Deze regel overschrijft dus de globale
    `<meta name="description">` van het document, niet een aparte property-tag — vermoedelijk
    niet wat de oorspronkelijke auteur bedoelde, maar functioneel gedrag, niet aanpassen zonder
    het expliciet te toetsen op een testsite.
  - **Bekende bug, bewust niet opgelost:** regel 79, `setMetadata('og:image:type ', ...)`, bevat
    een spatie ín de tagnaam. Daardoor komt de metatag onder een verkeerde naam in de output
    terecht in plaats van als `og:image:type`. Oplossen verandert de gerenderde output op
    klantsites; dat is een aparte beslissing en gebeurt hier niet stilzwijgend.
- `src/Helper/OpengraphHelper.php` — alle waarde-opbouw: sitename, locale, URL, titel,
  beschrijving (truncate naar 156 tekens), afbeelding (artikel-/categorie-/fallback-afbeelding)
  en auteursnaam.
- `services/provider.php` — DI-containerregistratie van de plugin.
- `opengraph.xml` — Joomla-extensiemanifest: versie, `<namespace path="src">`, pluginparams
  (Twitter/Facebook/fallback) en de update-server-URL.
- `script.php` — installer-script (legacy-vernoemde klasse
  `plgSystemOpengraphInstallerScript`, Joomla-conventie voor installer scripts): activeert de
  plugin automatisch bij installatie.
- `manifest.xml` — de update-feed, ruwvorm opgehaald vanaf de `main`-branch van dit repo
  door Joomla-sites (en tot de brugbranch verdwijnt ook nog vanaf `master`). **Niet aanraken buiten het releaseproces** — zie "Releasing a new version".
- `language/en-GB/en-GB.plg_system_opengraph.ini` — alle veldlabels die in `opengraph.xml`
  gebruikt worden. Er is alleen een en-GB-taalbestand, geen nl-NL — niet vanzelfsprekend voor
  een Nederlandse extensie, maar de reden dat de instellingen van deze plugin in het Engels
  worden getoond.

## Compatibility constraints

- Doelplatform Joomla 4 / 5 (`targetplatform` in `manifest.xml`, niet in `opengraph.xml` — dat
  laatste bevat geen enkele Joomla-versiegrens); geen Joomla 6-ondersteuning gemeld.
- Minimale PHP-versie wordt bepaald door de minimale Joomla-versie (Joomla 4: PHP 7.2.5+;
  Joomla 5: PHP 8.1+).

## Releasing a new version

Klantsites updaten automatisch via dit repo, dus een release vereist:

1. Bump `<version>` in `opengraph.xml`.
2. Maak **eerst** de git tag / GitHub release `v<versie>` aan, zodat de zip-URL resolvet.
3. Werk **pas daarna** `manifest.xml` bij (versie + `<downloadurl>`) en push — in de andere
   volgorde zien klantsites een update waarvan de download 404't. Zie hoofdstuk 6 van
   `CONVENTIONS.md`.

> Deze repo heette tot 3 september 2026 `YZC-Opengraph` en serveerde de feed vanaf `master`.
> Sinds v1.3.1 wijst de feed-URL naar
> `raw.githubusercontent.com/YZCommunicatie/yzc-opengraph/main/manifest.xml`.
> De brugbranch `master` blijft bewust bestaan (zie hoofdstuk 3 van `CONVENTIONS.md`): klantsites
> die nog v1.3.0 draaien bevragen de oude URL op `master`. Zolang die brug bestaat, moet
> `manifest.xml` op `master` bij elke release meebijgewerkt worden (`git checkout master &&
> git checkout main -- manifest.xml`) — anders blijven die sites eeuwig hangen op de oude versie.

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
  Wel is er een conceptuele overlap: `og:url` (deze plugin — de volledige `Uri::getInstance()`,
  inclusief een eventuele querystring) versus `<link rel="canonical">` (`yzc-styling` — zonder
  querystring, met `Uri::root()` als hostbron zodat een geconfigureerde `live_site` wint)
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
- Installeer de zip op een Joomla 4/5-testsite en controleer de gerenderde `<head>`
  (view-source) op de OG-/Twitter-/FB-tags, met en zonder `yzc-styling` gelijktijdig actief.
- Test met en zonder de optionele params (`twitter_site`, `twitter_author`,
  `fallback_image`) ingevuld, en op zowel een artikel- als een categoriepagina.
