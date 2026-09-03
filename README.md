# YZC-Opengraph

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
ophaalt vanaf dit repo op GitHub (branch `master`).

## Releasen

Zie de algemene Joomla-procedure in
[`CONVENTIONS.md`](https://github.com/YZCommunicatie/yzc-standards/blob/main/CONVENTIONS.md).
Voor dit project concreet:

1. Bump `<version>` in `opengraph.xml`.
2. Maak **eerst** de git tag / GitHub release `v<versie>` aan, zodat de zip-URL
   resolvet.
3. Bump **pas daarna** `<version>` in `manifest.xml` op `master` en werk de
   `<downloadurl>` bij naar de zip van de nieuwe tag — in de andere volgorde
   zien klantsites een update waarvan de download 404't.

De twee versienummers moeten synchroon blijven: klantsites lezen `manifest.xml`
vanaf `master` om een update te ontdekken.

## Licentie

GNU General Public License v3.0 of later. Copyright (C) 2026 YZCommunicatie. All rights reserved.
