# yzc-opengraph

Joomla-systeemplugin (`plg_system_opengraph`) die Open Graph-, Twitter Card- en Facebook-metatags zet voor social sharing.

## Wat het doet

- Zet algemene tags: `og:locale`, `og:site_name`, `og:url`, `og:title`, `fb:pages`, `fb:app_id`.
- Zet Twitter Card-tags: `twitter:card` (instelbaar type), optioneel `twitter:site` en `twitter:creator`, plus `twitter:title`.
- Op een artikel (`com_content.article`): `og:type=article` met `article:author`, `article:published_time`, `article:expiration_time`, `article:modified_time` en `og:updated_time`.
- Op een categorie (`com_content.category`): `og:type=website`.
- Binnen `com_content`: paginabeschrijving (`description`, `og:description`, `twitter:description`) en, als er een afbeelding gevonden wordt (artikel-, categorie- of fallback-afbeelding), `og:image` met afmetingen/mimetype/alt-tekst en `twitter:image`.
- Draait alleen aan de site-kant, op het event `onBeforeCompileHead`, en slaat feed-formaten en `com_finder` over.

## Vereisten

- Joomla 4 / 5
- PHP 7.2 of hoger (bepaald door de minimale Joomla-versie)

## Installatie

Via de Joomla-extensiemanager: zip de repobestanden en installeer de zip via
**Systeem → Installeren → Extensies**. Activeer daarna de systeemplugin
**System - YZC Open Graph** en vul desgewenst de Twitter-/Facebook-/
fallback-instellingen in.

De plugin update zichzelf automatisch via de feed in `manifest.xml`, die Joomla
ophaalt vanaf dit repo op GitHub. Sites op v1.3.1 of nieuwer halen hem op vanaf
`main`; sites die nog v1.3.0 draaien vanaf de brugbranch `master`. Zie *Releasen*.

## Releasen

Zie de algemene Joomla-procedure in
[`CONVENTIONS.md`](https://github.com/YZCommunicatie/yzc-standards/blob/main/CONVENTIONS.md).
Voor dit project concreet:

1. Bump `<version>` in `opengraph.xml`.
2. Bump `<version>` in `manifest.xml` **en** werk de `<downloadurl>` bij naar de
   zip van de nieuwe `v<versie>`-tag.
3. Bouw de installatiezip met `php build.php` (schrijft
   `dist/opengraph-<versie>.zip`) en hang die aan de GitHub release van stap 5.
4. Commit met een Conventional Commit-bericht.
5. Maak **eerst** de git tag / GitHub release `v<versie>` aan, zodat de zip-URL
   resolvet — **pas daarna** de manifest-wijziging naar `main` pushen. In de
   andere volgorde zien klantsites een update waarvan de download 404't.
6. Zet `manifest.xml` daarna **ook op de brugbranch `master`**. Deze stap is geen
   bijzaak en geen optie:

   ```bash
   git checkout master
   git checkout main -- manifest.xml
   git commit -m "chore(release): manifest op de brugbranch naar v<versie>"
   git push origin master
   git checkout main
   ```

De twee versienummers moeten synchroon blijven: klantsites lezen `manifest.xml`
om een update te ontdekken.

### Waarom `manifest.xml` op twee branches moet

Deze repo heette tot 2026-09-03 `YZC-Opengraph` en serveerde zijn update-feed vanaf
`master`. Sinds v1.3.1 wijst de `<updateservers>`-URL in `opengraph.xml` naar `main`.
Een geïnstalleerde plugin bevraagt de URL die in *zijn eigen* versie staat, dus beide
URL's zijn tegelijk in gebruik:

| Wie bevraagt hem | URL |
|---|---|
| Sites op v1.3.1 of nieuwer | `https://raw.githubusercontent.com/YZCommunicatie/yzc-opengraph/main/manifest.xml` |
| Sites op v1.3.0 | `https://raw.githubusercontent.com/YZCommunicatie/YZC-Opengraph/master/manifest.xml` |

Sla je stap 6 over, dan zien de sites op v1.3.0 de nieuwe release niet, installeren ze
nooit de versie die hun feed-URL naar `main` verlegt, en blijven ze eeuwig hangen. Sla
je stap 5 over, dan gebeurt precies hetzelfde bij de sites die juist wél netjes zijn
bijgewerkt. Er is geen enkele branch die in zijn eentje volstaat.

Op `master` wordt **alleen** `manifest.xml` bijgehouden. De rest van die branch is
bevroren op de toestand van vóór de migratie — `opengraph.xml` staat er nog op 1.3.0
met de oude feed-URL, en dat hoort zo. Werk daar dus nooit iets anders bij.

De brugbranch mag pas weg als zeker is dat élke klantsite v1.3.1 of nieuwer heeft
geïnstalleerd. Dat is van buitenaf niet te zien — er is geen telemetrie en geen lijst
van installaties — dus in de praktijk blijft `master` staan. Een ongebruikte branch
kost niets; verwijder hem nooit als opruimactie. Zie uitzondering 5 in
[`CONVENTIONS.md`](https://github.com/YZCommunicatie/yzc-standards/blob/main/CONVENTIONS.md).

## Licentie

GNU General Public License v3.0 of later. Copyright (C) 2026 YZCommunicatie. All rights reserved.
