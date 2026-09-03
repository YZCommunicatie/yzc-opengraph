<?php

/**
 * build.php — builds an installable release zip for a YZC extension repo.
 *
 * One script, no per-repo configuration, meant to run unmodified in every
 * YZC-* extension repository (Joomla plugins/modules, WordPress plugins,
 * PerfexCRM modules). See CONVENTIONS.md in yzc-standards for the background:
 * chapter 5 (repo contents) and chapter 6 (release process per platform).
 *
 * Usage (from the repo root):
 *   php build.php               writes dist/<name>-<version>.zip
 *   php build.php --out <dir>   writes <dir>/<name>-<version>.zip instead
 *
 * Requires only PHP's built-in ZipArchive — no Composer, no dependency.
 * That is a hard rule from CONVENTIONS.md: project repos carry no
 * dependencies and no build tooling of their own.
 *
 * Verified against PHP 8.3.28 (cli, ZTS) on 2026-09-03; do not rely on
 * language features newer than that.
 *
 * @author YZCommunicatie <jeroen@yzcommunicatie.nl>
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Bump this whenever build.php changes, so a `grep -r BUILD_PHP_VERSION`
 * across every repo shows at a glance whether copies have drifted apart.
 */
const BUILD_PHP_VERSION = '1.0.1';

/**
 * File and directory basenames that never belong in a release zip,
 * regardless of platform. Matched by exact basename, not by path, so this
 * applies equally at the repo root and at an extension root that lives in a
 * subdirectory.
 *
 * .idea, .vscode, .claude and .superpowers are all the same category: local
 * state of an editor or an AI coding tool, never distribution content. They
 * belong here rather than in a per-repo .distignore precisely because that
 * keeps every repo covered by one line, including ones that grow such a
 * directory after this list was last touched (yzc-dashboard's tracked
 * .claude/settings.local.json shipped in a build before this was added —
 * see the 2026-09-03 fix round in the harmonisation report).
 *
 * LICENSE is deliberately NOT here: CONVENTIONS.md is explicit that it must
 * ship, because it is part of the GPL distribution requirement.
 */
const DEFAULT_EXCLUDE_BASENAMES = [
    '.git',
    '.github',
    '.idea',
    '.vscode',
    '.claude',
    '.superpowers',
    '.gitignore',
    '.git-blame-ignore-revs',
    '.distignore',
    'README.md',
    'CLAUDE.md',
    'MIGRATIE.md',
    'docs',
    'tests',
    'dist',
    'build.php',
];

/**
 * Directory basenames that are skipped while searching for a manifest, one
 * level below the repo root. None of these ever contain an extension
 * manifest, and descending into them (especially .git) is wasteful.
 */
const MANIFEST_SEARCH_SKIP_DIRS = [
    '.git',
    '.github',
    '.idea',
    '.vscode',
    '.claude',
    '.superpowers',
    'dist',
    'docs',
    'tests',
    'node_modules',
    'vendor',
];

/**
 * Thrown for any condition that should abort the build with a non-zero exit
 * code. The message is shown to the user as-is, so it must say what is
 * wrong, in which file, and what to do about it.
 */
final class BuildFailure extends RuntimeException
{
}

/**
 * One instruction such as "manifest.xml (update feed, geen extensiebestand)"
 * printed in the final "wat is uitgesloten" report.
 */
final class ExclusionNote
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $reason
    ) {
    }
}

/**
 * Describes where the extension lives and how its release version was
 * determined, once detection has run to completion.
 */
final class ManifestInfo
{
    /**
     * @param string      $platform       'joomla' | 'wordpress' | 'perfexcrm'
     * @param string      $extensionRoot  Absolute path to the directory that holds the manifest.
     * @param string      $manifestPath   Absolute path to the authoritative version source file.
     * @param string      $version        The version to build.
     * @param string      $versionSource  Human-readable description of where $version came from.
     * @param string      $packageName    The name used in "<name>-<version>.zip".
     * @param string|null $updateFeedPath Absolute path to a Joomla update feed to exclude, if any.
     */
    public function __construct(
        public readonly string $platform,
        public readonly string $extensionRoot,
        public readonly string $manifestPath,
        public readonly string $version,
        public readonly string $versionSource,
        public readonly string $packageName,
        public readonly ?string $updateFeedPath = null
    ) {
    }
}

/**
 * Normalises a filesystem path to forward slashes, as required inside a
 * zip archive (the zip format itself is slash-based regardless of OS).
 */
function to_slashes(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * Path of $path relative to $base, using forward slashes. Both must be
 * absolute, and $path must live under $base.
 */
function relative_path(string $base, string $path): string
{
    $base = to_slashes(rtrim($base, '\\/'));
    $path = to_slashes($path);

    if ($path === $base) {
        return '';
    }

    if (strncmp($path, $base . '/', strlen($base) + 1) !== 0) {
        throw new RuntimeException("Intern pad-probleem: '$path' valt niet onder '$base'.");
    }

    return substr($path, strlen($base) + 1);
}

/**
 * Reads the first block comment ( /* ... * / or /** ... * / ) in a PHP
 * file. Both WordPress plugin headers and PerfexCRM module headers are a
 * single leading comment block, so one helper serves both.
 */
function leading_comment_block(string $content): ?string
{
    if (preg_match('/\/\*(.*?)\*\//s', $content, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * Extracts a "Field: value" line from a header comment block, the way both
 * WordPress plugin headers and PerfexCRM module headers format metadata.
 */
function header_field(string $block, string $field): ?string
{
    $pattern = '/^[ \t*]*' . preg_quote($field, '/') . ':\s*(.+)$/mi';

    if (preg_match($pattern, $block, $matches) === 1) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Loads an XML file into a DOMDocument, with a clear error on malformed XML
 * instead of a silent false or a PHP warning.
 */
function load_xml(string $path): DOMDocument
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument();
    $ok = $doc->load($path);

    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$ok) {
        $detail = $errors !== [] ? trim($errors[0]->message) : 'onbekende XML-fout';
        throw new BuildFailure("Kan XML niet lezen: $path ($detail). Controleer of het bestand geldige XML bevat.");
    }

    return $doc;
}

/**
 * Reads the text content of the first node matching $xpathExpression, or
 * null if there is no match.
 */
function xml_text(DOMDocument $doc, string $xpathExpression): ?string
{
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query($xpathExpression);

    if ($nodes === false || $nodes->length === 0) {
        return null;
    }

    return trim((string) $nodes->item(0)->textContent);
}

/**
 * Finds the Joomla element name for a manifest: the value of a `plugin=`
 * or `module=` attribute somewhere under <files>. That attribute is what
 * Joomla itself uses to name the extension on disk (plugins/<group>/<el>,
 * or modules/mod_<el>), so it is the right source for "how the platform
 * names it" rather than inventing a name from the repo.
 *
 * This also naturally handles both classic manifests
 * (<filename plugin="yzcfallbackimage">...) and the modern Joomla 4/5
 * services-provider style used by yzc-styling / yzc-opengraph
 * (<folder plugin="yzcommunicatie">services</folder>), because it looks at
 * any element under <files>, not just <filename>.
 */
function joomla_element_name(DOMDocument $doc): ?string
{
    $xpath = new DOMXPath($doc);

    foreach (['plugin', 'module'] as $attribute) {
        $nodes = $xpath->query("//files//*[@$attribute]");
        if ($nodes !== false && $nodes->length > 0) {
            $value = trim($nodes->item(0)->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }
    }

    return null;
}

/**
 * Lists the immediate subdirectories of $dir (non-recursive), skipping the
 * well-known directories that never hold a manifest. Used to search "one
 * level deeper" as required by repos such as yzc-aliasfromfield and
 * yzc-planning, whose extension files live in a subdirectory rather than
 * the repo root.
 *
 * @return string[] Absolute paths.
 */
function immediate_subdirs(string $dir): array
{
    $result = [];

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (in_array($entry, MANIFEST_SEARCH_SKIP_DIRS, true)) {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            $result[] = $path;
        }
    }

    return $result;
}

/**
 * Non-recursive listing of files directly inside $dir matching a glob
 * pattern such as '*.xml' or '*.php'.
 *
 * @return string[] Absolute paths.
 */
function files_in_dir(string $dir, string $pattern): array
{
    $matches = glob($dir . DIRECTORY_SEPARATOR . $pattern);

    return $matches === false ? [] : $matches;
}

/**
 * Core of step 1: locate the extension manifest and, from it, decide the
 * platform and the extension root. Searches the repo root and one level
 * below, as specified — the extension files are not always at the repo
 * root (yzc-aliasfromfield, yzc-planning).
 *
 * The Joomla vs. "just another XML file" pitfall: an XML file's ROOT NODE
 * decides what it is, never its filename. <extension> is a Joomla
 * manifest; <updates> is an update feed (client-site metadata, not an
 * extension file) and is recorded for exclusion and for the version
 * cross-check in step 2, never treated as the manifest itself. This is
 * exactly the yzc-styling / yzc-opengraph case, where both
 * yzcommunicatie.xml/opengraph.xml (the real manifest) and manifest.xml
 * (the feed) sit side by side in the repo root.
 */
function locate_manifest(string $repoRoot): ManifestInfo
{
    $searchDirs = array_merge([$repoRoot], immediate_subdirs($repoRoot));

    $joomlaManifest = null; // ['dir' => ..., 'path' => ..., 'doc' => ...]
    $updateFeed = null;     // ['path' => ..., 'version' => ...]

    foreach ($searchDirs as $dir) {
        foreach (files_in_dir($dir, '*.xml') as $xmlPath) {
            $doc = load_xml($xmlPath);
            $root = $doc->documentElement !== null ? $doc->documentElement->nodeName : '';

            if ($root === 'extension') {
                if ($joomlaManifest !== null) {
                    throw new BuildFailure(
                        'Meerdere Joomla-extensiemanifesten gevonden ('
                        . $joomlaManifest['path'] . ' en ' . $xmlPath
                        . "). Dit script verwacht er precies één per repo. Verwijder of hernoem het overbodige bestand, of pas het script aan."
                    );
                }
                $joomlaManifest = ['dir' => $dir, 'path' => $xmlPath, 'doc' => $doc];
            } elseif ($root === 'updates') {
                // The update feed. Record it, do not treat it as a manifest.
                $version = xml_text($doc, '/updates/update/version');
                $updateFeed = ['path' => $xmlPath, 'version' => $version];
            }
        }
    }

    if ($joomlaManifest !== null) {
        return build_joomla_manifest_info($joomlaManifest, $updateFeed);
    }

    // No Joomla manifest: try WordPress, then PerfexCRM, by reading PHP
    // header comments in the same search directories.
    foreach ($searchDirs as $dir) {
        foreach (files_in_dir($dir, '*.php') as $phpPath) {
            $content = (string) file_get_contents($phpPath);
            $block = leading_comment_block($content);
            if ($block === null) {
                continue;
            }

            if (header_field($block, 'Plugin Name') !== null) {
                return build_wordpress_manifest_info($dir, $phpPath, $block);
            }
        }
    }

    foreach ($searchDirs as $dir) {
        foreach (files_in_dir($dir, '*.php') as $phpPath) {
            $content = (string) file_get_contents($phpPath);
            $block = leading_comment_block($content);
            if ($block === null) {
                continue;
            }

            if (header_field($block, 'Module Name') !== null) {
                return build_perfexcrm_manifest_info($dir, $phpPath, $block);
            }
        }
    }

    $searched = implode(', ', array_map(static fn (string $d): string => relative_path($repoRoot, $d) === '' ? '.' : relative_path($repoRoot, $d), $searchDirs));
    throw new BuildFailure(
        "Geen extensiemanifest gevonden. Doorzocht (wortel + één niveau diep): $searched. "
        . 'Verwacht een Joomla-manifest (XML met <extension> als wortelnode), een WordPress-pluginheader '
        . "('Plugin Name:' in de eerste commentaarblok van een .php-bestand), of een PerfexCRM-moduleheader "
        . "('Module Name:' idem). Staat de extensie dieper dan één niveau, dan kan dit script haar niet vinden."
    );
}

/**
 * Version policy for Joomla: <version> in the extension manifest is
 * authoritative — that is what Joomla itself reads to know the installed
 * version. manifest.xml, when present, is the update feed that client
 * sites poll; it is compared only as a consistency check, because a stale
 * feed means client sites never see the new release even though the zip
 * they would download is fine (see CONVENTIONS.md 2.3 and 3.5). No
 * manifest.xml: nothing to compare, proceed.
 */
function build_joomla_manifest_info(array $joomlaManifest, ?array $updateFeed): ManifestInfo
{
    $manifestPath = $joomlaManifest['path'];
    $doc = $joomlaManifest['doc'];

    $version = xml_text($doc, '/extension/version');
    if ($version === null || $version === '') {
        throw new BuildFailure(
            "Geen <version> gevonden in $manifestPath. Voeg een <version>-element toe aan de wortel van het extensiemanifest."
        );
    }

    if ($updateFeed !== null) {
        $feedVersion = $updateFeed['version'];
        if ($feedVersion === null || $feedVersion === '') {
            throw new BuildFailure(
                'Update-feed ' . $updateFeed['path'] . ' heeft geen <version> onder /updates/update. '
                . 'Vul die aan, of verwijder het bestand als het geen actieve feed meer is.'
            );
        }
        if ($feedVersion !== $version) {
            throw new BuildFailure(
                "Versies lopen uiteen: $manifestPath zegt <version>$version</version>, "
                . "maar " . $updateFeed['path'] . " (de update-feed) zegt <version>$feedVersion</version>. "
                . 'Er wordt geen zip gebouwd. Werk beide bestanden bij naar dezelfde versie voordat je opnieuw bouwt '
                . '(CONVENTIONS.md hoofdstuk 6: eerst de release-tag, dan pas de manifest-wijziging pushen).'
            );
        }
    }

    $elementName = joomla_element_name($doc);
    if ($elementName === null) {
        throw new BuildFailure(
            "Kan het Joomla-elementnaam niet vinden in $manifestPath (geen plugin= of module= attribuut onder <files>). "
            . 'Voeg die toe aan het manifest, zo bepaalt Joomla zelf hoe de extensie heet.'
        );
    }

    return new ManifestInfo(
        platform: 'joomla',
        extensionRoot: $joomlaManifest['dir'],
        manifestPath: $manifestPath,
        version: $version,
        versionSource: "$manifestPath (<version> in het extensiemanifest)",
        packageName: $elementName,
        updateFeedPath: $updateFeed['path'] ?? null
    );
}

/**
 * Version policy for WordPress: the `Version:` header in the plugin's main
 * file is authoritative — that is what WordPress core displays and what
 * plugin-update-checker (see yzc-dashboard) reports as the current
 * version. `Stable tag:` in readme.txt is what wp-update-server /
 * WordPress.org treat as the version to serve, per CONVENTIONS.md 5: "houd
 * het versienummer erin gelijk aan dat in het hoofdbestand." A mismatch
 * there means the update feed and the actual plugin disagree, so it is
 * compared and enforced. No readme.txt, or a readme.txt without a
 * `Stable tag:` line: nothing to compare, proceed (both are reported).
 */
function build_wordpress_manifest_info(string $dir, string $mainFile, string $headerBlock): ManifestInfo
{
    $version = header_field($headerBlock, 'Version');
    if ($version === null) {
        throw new BuildFailure(
            "Geen 'Version:' regel gevonden in de pluginheader van $mainFile. Voeg die toe aan de kopcommentaarblok."
        );
    }

    $readmePath = $dir . DIRECTORY_SEPARATOR . 'readme.txt';
    if (is_file($readmePath)) {
        $readmeContent = (string) file_get_contents($readmePath);
        $stableTag = null;
        if (preg_match('/^\s*Stable tag:\s*(.+)$/mi', $readmeContent, $matches) === 1) {
            $stableTag = trim($matches[1]);
        }

        if ($stableTag === null) {
            fwrite(STDOUT, "Let op: $readmePath bevat geen 'Stable tag:'-regel, dus daar valt niets tegen te vergelijken. Doorgebouwd met de pluginheader-versie.\n");
        } elseif ($stableTag !== $version) {
            throw new BuildFailure(
                "Versies lopen uiteen: $mainFile zegt Version: $version, "
                . "maar $readmePath zegt Stable tag: $stableTag. "
                . 'Er wordt geen zip gebouwd. Werk beide bij naar dezelfde versie (CONVENTIONS.md hoofdstuk 5: '
                . '"houd het versienummer erin gelijk aan dat in het hoofdbestand").'
            );
        }
    } else {
        fwrite(STDOUT, "Let op: geen readme.txt gevonden in $dir, dus daar valt niets tegen te vergelijken. Doorgebouwd met de pluginheader-versie.\n");
    }

    $slug = header_field($headerBlock, 'Text Domain');
    if ($slug === null || $slug === '') {
        // Fall back to the main file's basename, which is the WordPress
        // convention when Text Domain is absent.
        $slug = pathinfo($mainFile, PATHINFO_FILENAME);
    }

    return new ManifestInfo(
        platform: 'wordpress',
        extensionRoot: $dir,
        manifestPath: $mainFile,
        version: $version,
        versionSource: "$mainFile ('Version:' in de pluginheader)",
        packageName: $slug
    );
}

/**
 * Version policy for PerfexCRM: the `Version:` line in the module header
 * (the comment block containing `Module Name:`) is authoritative — that is
 * what Perfex shows in Modules > Manage. config.php frequently stores its
 * own copy in $config['<mod>_version'], read by the module's own
 * activation/upgrade logic (see yzc-planning's install.php); if that
 * drifts from the header, the module can misjudge whether it needs to run
 * its upgrade path. No config.php, or no *_version key in it: nothing to
 * compare, proceed.
 */
function build_perfexcrm_manifest_info(string $dir, string $mainFile, string $headerBlock): ManifestInfo
{
    $version = header_field($headerBlock, 'Version');
    if ($version === null) {
        throw new BuildFailure(
            "Geen 'Version:' regel gevonden in de moduleheader van $mainFile. Voeg die toe aan de kopcommentaarblok."
        );
    }

    $configPath = $dir . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($configPath)) {
        $configContent = (string) file_get_contents($configPath);
        $configVersion = null;
        if (preg_match('/\$config\[\s*[\'"](\w*_version)[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $configContent, $matches) === 1) {
            $configVersion = trim($matches[2]);
        }

        if ($configVersion === null) {
            fwrite(STDOUT, "Let op: $configPath bevat geen *_version-sleutel, dus daar valt niets tegen te vergelijken. Doorgebouwd met de moduleheader-versie.\n");
        } elseif ($configVersion !== $version) {
            throw new BuildFailure(
                "Versies lopen uiteen: $mainFile zegt Version: $version, "
                . "maar $configPath zegt \$config[...] = '$configVersion'. "
                . 'Er wordt geen zip gebouwd. Werk beide bij naar dezelfde versie voordat je opnieuw bouwt.'
            );
        }
    } else {
        fwrite(STDOUT, "Let op: geen config.php gevonden in $dir, dus daar valt niets tegen te vergelijken. Doorgebouwd met de moduleheader-versie.\n");
    }

    $moduleDirName = basename($dir);

    return new ManifestInfo(
        platform: 'perfexcrm',
        extensionRoot: $dir,
        manifestPath: $mainFile,
        version: $version,
        versionSource: "$mainFile ('Version:' in de moduleheader)",
        packageName: $moduleDirName
    );
}

/**
 * Parses the repo root's .distignore, if present. Lines are paths, '#'
 * starts a comment, blank lines do not count — exactly the format
 * yzc-dashboard's README already promises, even though nothing read the
 * file until now.
 *
 * @return string[] Raw patterns, trailing slash preserved (it is
 *                   meaningful: it marks a directory prefix).
 */
function read_distignore(string $repoRoot): array
{
    $path = $repoRoot . DIRECTORY_SEPARATOR . '.distignore';
    if (!is_file($path)) {
        return [];
    }

    $patterns = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = rtrim($line, "\r");
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $patterns[] = $trimmed;
    }

    return $patterns;
}

/**
 * Whether a repo-root-relative path is covered by a .distignore pattern.
 * Directory-style patterns (trailing '/') match the directory itself and
 * everything under it; other patterns are matched with shell-style
 * wildcards against both the basename and the full relative path.
 */
function matches_distignore(string $relPathFromRepoRoot, string $basename, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (str_ends_with($pattern, '/')) {
            $prefix = rtrim($pattern, '/');
            if ($relPathFromRepoRoot === $prefix || str_starts_with($relPathFromRepoRoot, $prefix . '/')) {
                return true;
            }
            continue;
        }

        if (fnmatch($pattern, $basename) || fnmatch($pattern, $relPathFromRepoRoot)) {
            return true;
        }
    }

    return false;
}

/**
 * One collected file: its absolute path, its path relative to the
 * extension root (used for the zip's internal layout), and its path
 * relative to the repo root (used only for .distignore matching and
 * reporting).
 */
final class CollectedFile
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativeToExtensionRoot,
        public readonly string $relativeToRepoRoot
    ) {
    }
}

/**
 * Step 3: walks the extension root and collects every file that belongs in
 * the release, applying the always-on exclusions, the detected update
 * feed, and .distignore. Appends a note to $exclusions for everything it
 * leaves out, and finally adds the repo root's LICENSE explicitly — it is
 * required by CONVENTIONS.md even when the extension root is a
 * subdirectory that does not itself contain a LICENSE file.
 *
 * @param ExclusionNote[] $exclusions Appended to by reference.
 * @return CollectedFile[]
 */
function collect_release_files(string $repoRoot, ManifestInfo $info, array &$exclusions): array
{
    $distignorePatterns = read_distignore($repoRoot);
    $updateFeedReal = $info->updateFeedPath !== null ? realpath($info->updateFeedPath) : null;

    if ($info->updateFeedPath !== null) {
        $exclusions[] = new ExclusionNote(
            relative_path($repoRoot, $info->updateFeedPath),
            'update-feed voor klantsites, geen extensiebestand'
        );
    }

    $files = [];
    walk_for_release($info->extensionRoot, $repoRoot, $info->extensionRoot, $distignorePatterns, $updateFeedReal, $files, $exclusions);

    $hasLicense = false;
    foreach ($files as $file) {
        if ($file->relativeToExtensionRoot === 'LICENSE') {
            $hasLicense = true;
            break;
        }
    }

    if (!$hasLicense) {
        $licensePath = $repoRoot . DIRECTORY_SEPARATOR . 'LICENSE';
        if (is_file($licensePath)) {
            $files[] = new CollectedFile($licensePath, 'LICENSE', relative_path($repoRoot, $licensePath));
        } else {
            fwrite(STDOUT, "Let op: geen LICENSE-bestand gevonden in $repoRoot. CONVENTIONS.md verwacht er een voor een GPL-distributie; deze zip gaat zonder verder.\n");
        }
    }

    return $files;
}

/**
 * @param ExclusionNote[] $exclusions
 * @param CollectedFile[] $out
 */
function walk_for_release(
    string $absDir,
    string $repoRoot,
    string $extensionRoot,
    array $distignorePatterns,
    ?string $updateFeedReal,
    array &$out,
    array &$exclusions
): void {
    $entries = scandir($absDir);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $absPath = $absDir . DIRECTORY_SEPARATOR . $entry;
        $relRepo = relative_path($repoRoot, $absPath);
        $isDir = is_dir($absPath);

        if (in_array($entry, DEFAULT_EXCLUDE_BASENAMES, true)) {
            $exclusions[] = new ExclusionNote($relRepo, 'standaarduitsluiting');
            continue;
        }

        if ($updateFeedReal !== null && realpath($absPath) === $updateFeedReal) {
            // Already reported once at the top level; skip silently here.
            continue;
        }

        if (matches_distignore($relRepo, $entry, $distignorePatterns)) {
            $exclusions[] = new ExclusionNote($relRepo, '.distignore');
            continue;
        }

        if ($isDir) {
            walk_for_release($absPath, $repoRoot, $extensionRoot, $distignorePatterns, $updateFeedReal, $out, $exclusions);
            continue;
        }

        $out[] = new CollectedFile($absPath, relative_path($extensionRoot, $absPath), $relRepo);
    }
}

/**
 * Step 4: writes the zip with the internal layout each platform's
 * installer expects. This was checked against each platform's own
 * install/upgrade mechanism rather than assumed:
 *
 * - Joomla: the installer unpacks the zip and looks for the manifest
 *   directly inside it, so extension files sit flat at the zip root.
 *   Nesting them in a subfolder makes Joomla report "install package
 *   detected as invalid" or similar (no manifest found at the expected
 *   level).
 * - WordPress: the core zip installer (Plugins > Add New > Upload) expects
 *   either a single top-level folder or files at the root; it is served
 *   from wp-update-server keyed by slug and extracted straight into
 *   wp-content/plugins/<slug>/, so wrapping everything in one folder named
 *   after the slug is what keeps the extracted plugin directory correctly
 *   named regardless of the zip's filename.
 * - PerfexCRM: modules are extracted into modules/, and Perfex's own
 *   module zip uploader expects the zip to contain exactly one top-level
 *   folder — the module directory — which becomes modules/<name>/.
 *
 * @param CollectedFile[] $files
 */
function write_release_zip(string $zipPath, array $files, ManifestInfo $info): void
{
    $dir = dirname($zipPath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new BuildFailure("Kan de outputmap niet aanmaken: $dir. Controleer schrijfrechten of geef --out anders op.");
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        throw new BuildFailure("Kan de zip niet aanmaken op $zipPath (ZipArchive-foutcode $openResult). Controleer schrijfrechten en of het pad bestaat.");
    }

    $prefix = match ($info->platform) {
        'joomla' => '',
        'wordpress', 'perfexcrm' => $info->packageName . '/',
        default => throw new RuntimeException("Onbekend platform '{$info->platform}' bij het schrijven van de zip."),
    };

    foreach ($files as $file) {
        $internalPath = to_slashes($prefix . $file->relativeToExtensionRoot);
        if (!$zip->addFile($file->absolutePath, $internalPath)) {
            $zip->close();
            throw new BuildFailure("Kan bestand niet toevoegen aan de zip: {$file->absolutePath} (als $internalPath). Bestaat het nog en is het leesbaar?");
        }
    }

    if (!$zip->close()) {
        throw new BuildFailure("Sluiten van de zip is mislukt: $zipPath. Mogelijk is de schijf vol of het bestand vergrendeld door een ander proces.");
    }
}

/**
 * Parses `--out <dir>` from the CLI arguments. Any other argument is
 * rejected, since the script is meant to run without configuration.
 */
function parse_output_dir(array $argv, string $repoRoot): string
{
    $args = array_slice($argv, 1);
    $out = null;

    for ($i = 0, $count = count($args); $i < $count; $i++) {
        if ($args[$i] === '--out') {
            if (!isset($args[$i + 1])) {
                throw new BuildFailure("--out heeft een mapargument nodig, bijvoorbeeld: php build.php --out /pad/naar/map");
            }
            $out = $args[$i + 1];
            $i++;
            continue;
        }

        throw new BuildFailure("Onbekend argument: {$args[$i]}. Dit script accepteert alleen --out <map>, of geen argumenten.");
    }

    if ($out === null) {
        return $repoRoot . DIRECTORY_SEPARATOR . 'dist';
    }

    // Allow both absolute and repo-relative --out values.
    if (preg_match('/^([A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $out) === 1) {
        return rtrim($out, '\\/');
    }

    return rtrim($repoRoot . DIRECTORY_SEPARATOR . $out, '\\/');
}

/**
 * Entry point.
 */
function main(array $argv): int
{
    $repoRoot = getcwd();
    if ($repoRoot === false) {
        fwrite(STDERR, "Kan de huidige map niet bepalen.\n");
        return 1;
    }

    try {
        $outDir = parse_output_dir($argv, $repoRoot);
        $info = locate_manifest($repoRoot);

        $exclusions = [];
        $files = collect_release_files($repoRoot, $info, $exclusions);

        if ($files === []) {
            throw new BuildFailure(
                "Geen enkel bestand over om in de zip te stoppen voor extensiewortel {$info->extensionRoot}. "
                . 'Controleer of alle bestanden per ongeluk zijn uitgesloten (bijvoorbeeld door een te ruime .distignore-regel).'
            );
        }

        $zipPath = $outDir . DIRECTORY_SEPARATOR . $info->packageName . '-' . $info->version . '.zip';
        write_release_zip($zipPath, $files, $info);

        report_success($repoRoot, $info, $files, $exclusions, $zipPath);

        return 0;
    } catch (BuildFailure $failure) {
        fwrite(STDERR, 'FOUT: ' . $failure->getMessage() . "\n");
        return 1;
    } catch (Throwable $unexpected) {
        fwrite(STDERR, 'Onverwachte fout: ' . $unexpected->getMessage() . "\n");
        return 1;
    }
}

/**
 * @param CollectedFile[] $files
 * @param ExclusionNote[] $exclusions
 */
function report_success(string $repoRoot, ManifestInfo $info, array $files, array $exclusions, string $zipPath): void
{
    $platformLabel = match ($info->platform) {
        'joomla' => 'Joomla',
        'wordpress' => 'WordPress',
        'perfexcrm' => 'PerfexCRM',
        default => $info->platform,
    };

    echo "Gebouwd: $zipPath\n";
    echo 'Platform: ' . $platformLabel . ' (extensiewortel: ' . relative_display($repoRoot, $info->extensionRoot) . ")\n";
    echo 'Aantal bestanden in de zip: ' . count($files) . "\n";
    echo "Versie: {$info->version}\n";
    echo "Versiebron: {$info->versionSource}\n";

    if ($exclusions === []) {
        echo "Uitgesloten: niets.\n";
        return;
    }

    echo "Uitgesloten:\n";
    $grouped = [];
    foreach ($exclusions as $exclusion) {
        $grouped[$exclusion->reason][] = $exclusion->relativePath;
    }
    foreach ($grouped as $reason => $paths) {
        sort($paths);
        echo "  - $reason: " . implode(', ', $paths) . "\n";
    }
}

function relative_display(string $repoRoot, string $path): string
{
    $rel = relative_path($repoRoot, $path);
    return $rel === '' ? '.' : $rel;
}

exit(main($argv));
