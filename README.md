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
ophaalt vanaf dit repo op GitHub. Alle sites halen hem op vanaf `main`. Zie *Releasen*.

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
De twee versienummers moeten synchroon blijven: klantsites lezen `manifest.xml`
om een update te ontdekken.

### De brugbranch `master` is opgeheven

Deze repo heette tot 2026-09-03 `YZC-Opengraph` en serveerde zijn update-feed vanaf
`master`. Sinds v1.3.1 wijst de `<updateservers>`-URL in `opengraph.xml` naar `main`.
Omdat een geïnstalleerde plugin de URL bevraagt die in *zijn eigen* versie staat, bleef
`master` daarna staan als brugbranch: sites op v1.3.0 bevroegen de oude URL, en alleen
doordat `manifest.xml` óók daar werd bijgewerkt kregen zij v1.3.1 aangeboden — de
eerste versie die `main` bevraagt.

Op 2026-09-10 is vastgesteld dat geen enkele klantsite nog op v1.3.0 draait, en is
`master` verwijderd. Sindsdien is er nog één feed-URL:

| Wie bevraagt hem | URL |
|---|---|
| Alle sites | `https://raw.githubusercontent.com/YZCommunicatie/yzc-opengraph/main/manifest.xml` |

Wat daarbij hoort, en wat niet mag worden weggeredeneerd: de oude URL op `master` is er
niet meer. Blijkt er tóch nog een site op v1.3.0 te staan, dan krijgt die nooit meer
een update aangeboden, zonder foutmelding en zonder zichtbaar symptoom. De laatste stand
van de branch is daarom vastgelegd — `1bf882e4eb8a` — en terugzetten is één commando:

```bash
git push origin 1bf882e4eb8a:refs/heads/master
```

Zie uitzondering 5 in
[`CONVENTIONS.md`](https://github.com/YZCommunicatie/yzc-standards/blob/main/CONVENTIONS.md).

## Licentie

GNU General Public License v3.0 of later. Copyright (C) 2026 YZCommunicatie. All rights reserved.
