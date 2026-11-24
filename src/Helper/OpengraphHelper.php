<?php

/*
 *  package: YZC - Open Graph plugin
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | YZCommunicatie
 *  license: GNU General Public License version 3 or later
 *  link: https://www.yzcommunicatie.nl
 */

namespace YZCommunicatie\Plugin\System\Opengraph\Helper;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Builds the tag values for one request. The article or category is loaded once, in the constructor.
 */
final class OpengraphHelper
{
    private const DESCRIPTION_LENGTH = 156;

    private string $option;

    private string $view;

    /**
     * The article or category of this request, null when there is none or the visitor may not see it.
     */
    private ?object $item = null;

    private string $description = '';

    /**
     * @var array{url: string, alt: string, width: int, height: int, mime: string}|null
     */
    private ?array $image = null;

    public function __construct(
        private SiteApplication $app,
        private DatabaseInterface $db,
        private Registry $params
    ) {
        $input        = $app->getInput();
        $this->option = $input->getCmd('option', '');
        $this->view   = $input->getCmd('view', '');

        if ($this->option !== 'com_content') {
            return;
        }

        $id = $input->getInt('id', 0);

        if ($this->view === 'article') {
            $this->item = $this->loadArticle($id);
        } elseif ($this->view === 'category') {
            $this->item = $this->loadCategory($id);
        }

        $this->description = $this->buildDescription();
        $this->image       = $this->buildImage();
    }

    /**
     * @return array<string, string>
     */
    public function getPropertyTags(HtmlDocument $document): array
    {
        $tags = [
            'fb:pages'     => (string) $this->params->get('fb_page_id', ''),
            'fb:app_id'    => (string) $this->params->get('fb_app_id', ''),
            'og:locale'    => str_replace('-', '_', $this->app->getLanguage()->getTag()),
            'og:site_name' => (string) $this->app->get('sitename', ''),
            'og:url'       => $this->getUrl($document),
            'og:title'     => $this->getTitle($document),
            'og:type'      => 'website',
        ];

        if ($this->view === 'article' && $this->item) {
            $modified = $this->toIso8601($this->item->modified);

            $tags['og:type']                 = 'article';
            $tags['article:author']          = $this->getAuthor();
            $tags['article:published_time']  = $this->toIso8601($this->item->publish_up);
            $tags['article:expiration_time'] = $this->toIso8601($this->item->publish_down);
            $tags['article:modified_time']   = $modified;
            $tags['og:updated_time']         = $modified;
        }

        $tags['og:description'] = $this->description;

        if ($this->image) {
            $tags['og:image']        = $this->image['url'];
            $tags['og:image:type']   = $this->image['mime'];
            $tags['og:image:width']  = $this->image['width'] ? (string) $this->image['width'] : '';
            $tags['og:image:height'] = $this->image['height'] ? (string) $this->image['height'] : '';
            $tags['og:image:alt']    = $this->image['alt'] ?: $this->getTitle($document);
        }

        return $tags;
    }

    /**
     * @return array<string, string>
     */
    public function getNameTags(HtmlDocument $document): array
    {
        $tags = [
            'twitter:card'        => (string) $this->params->get('twitter_type', 'summary_large_image'),
            'twitter:site'        => $this->getTwitterHandle('twitter_site'),
            'twitter:creator'     => $this->getTwitterHandle('twitter_author'),
            'twitter:title'       => $this->getTitle($document),
            'twitter:description' => $this->description,
        ];

        if ($this->image) {
            $tags['twitter:image']     = $this->image['url'];
            $tags['twitter:image:alt'] = $this->image['alt'] ?: $this->getTitle($document);
        }

        return $tags;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    private function getTitle(HtmlDocument $document): string
    {
        return (string) ($this->item->title ?? '') ?: (string) $document->getTitle();
    }

    private function getTwitterHandle(string $param): string
    {
        $handle = ltrim(trim((string) $this->params->get($param, '')), '@');

        return $handle === '' ? '' : '@' . $handle;
    }

    /**
     * The canonical URL when the page has one. Otherwise the current URL, without query string when a SEF path
     * identifies the com_content page. Elsewhere the query string can be part of the identity.
     */
    private function getUrl(HtmlDocument $document): string
    {
        foreach ($document->getHeadData()['links'] ?? [] as $href => $link) {
            if (($link['relation'] ?? '') === 'canonical' && preg_match('#^https?://#i', (string) $href)) {
                return $href;
            }
        }

        $uri   = Uri::getInstance();
        $parts = ['scheme', 'host', 'port', 'path'];

        // A non-SEF link to com_content stays valid on a SEF site: there the query string is the identity
        $identified = !$uri->hasVar('option') && !$uri->hasVar('view') && !$uri->hasVar('id');

        if ($this->option !== 'com_content' || !$this->app->get('sef') || !$identified) {
            $parts[] = 'query';
        }

        return $uri->toString($parts);
    }

    /**
     * Respects the author alias and the "show author" setting, so a hidden account name stays hidden.
     */
    private function getAuthor(): string
    {
        if (!$this->item->params->get('show_author')) {
            return '';
        }

        return (string) ($this->item->created_by_alias ?: $this->item->author);
    }

    private function toIso8601(?string $date): string
    {
        if (!$date || str_starts_with($date, '0000')) {
            return '';
        }

        return Factory::getDate($date)->toISO8601();
    }

    private function buildDescription(): string
    {
        $menuItem = $this->app->getMenu()->getActive();
        $text     = $menuItem ? (string) $menuItem->getParams()->get('menu-meta_description', '') : '';

        if ($this->item) {
            $text = $this->item->metadesc ?: $text ?: $this->item->text;
        }

        return $this->truncate((string) $text, self::DESCRIPTION_LENGTH);
    }

    /**
     * @return array{url: string, alt: string, width: int, height: int, mime: string}|null
     */
    private function buildImage(): ?array
    {
        $candidates   = $this->item ? $this->item->images : [];
        $candidates[] = [
            (string) $this->params->get('fallback_image', ''),
            (string) $this->params->get('fallback_image_alt', ''),
        ];

        foreach ($candidates as [$source, $alt]) {
            $image = $this->resolveImage($source);

            if ($image) {
                return $image + ['alt' => trim($alt)];
            }
        }

        return null;
    }

    /**
     * @return array{url: string, width: int, height: int, mime: string}|null
     */
    private function resolveImage(string $source): ?array
    {
        // The media field appends its own data behind a #
        $source = trim(explode('#', $source, 2)[0]);
        $info   = ['width' => 0, 'height' => 0, 'mime' => ''];

        if ($source === '') {
            return null;
        }

        if (str_starts_with($source, '//')) {
            $source = Uri::getInstance()->getScheme() . ':' . $source;
        }

        if (preg_match('#^https?://#i', $source)) {
            return ['url' => $source] + $info;
        }

        $path = ltrim(str_replace('\\', '/', explode('?', $source, 2)[0]), '/');

        // The path may be stored URL encoded
        if (!is_file(JPATH_ROOT . '/' . $path)) {
            $path = rawurldecode($path);
        }

        // Other schemes (data:, javascript:) and paths leaving the site root are never a shareable image
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $path) || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return null;
        }

        $file = JPATH_ROOT . '/' . $path;

        // Read the local file: passing the URL would make the site request its own image over HTTP
        $size = is_file($file) && is_readable($file) ? getimagesize($file) : false;

        if ($size) {
            $info = ['width' => $size[0], 'height' => $size[1], 'mime' => $size['mime']];
        }

        return ['url' => Uri::root() . implode('/', array_map('rawurlencode', explode('/', $path)))] + $info;
    }

    /**
     * Loads the article under the rules of com_content: an article the visitor may not read yields null, so
     * nothing of it ends up in the head of the 403 page.
     */
    private function loadArticle(int $id): ?object
    {
        $db    = $this->db;
        $now   = Factory::getDate()->toSql();
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'a.id', 'a.title', 'a.introtext', 'a.fulltext', 'a.metadesc', 'a.images', 'a.attribs', 'a.access',
                'a.created_by_alias', 'a.modified', 'a.publish_up', 'a.publish_down',
            ]))
            ->select($db->quoteName(['c.access', 'u.name'], ['category_access', 'author']))
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'INNER',
                $db->quoteName('#__categories', 'c'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u'),
                $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by')
            )
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' IN (1, 2)')
            ->where($db->quoteName('c.published') . ' > 0')
            ->where('(' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= :up)')
            ->where(
                '(' . $db->quoteName('a.publish_down') . ' IS NULL OR '
                . $db->quoteName('a.publish_down') . ' >= :down)'
            )
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':up', $now)
            ->bind(':down', $now);

        $article = $db->setQuery($query)->loadObject();

        if (!$article) {
            return null;
        }

        $article->params = $this->getArticleParams($id, (string) $article->attribs);
        $article->text   = $article->introtext . ' ' . $article->fulltext;

        if (!$this->isAuthorised((int) $article->access) || !$this->isAuthorised((int) $article->category_access)) {
            // "Show unauthorised links" presents the intro as a teaser, but only when there is more to read
            if (!$article->params->get('show_noauth') || (string) $article->fulltext === '') {
                return null;
            }

            $article->text = $article->introtext;
        }

        $images          = new Registry((string) $article->images);
        $article->images = [
            [(string) $images->get('image_intro', ''), (string) $images->get('image_intro_alt', '')],
            [(string) $images->get('image_fulltext', ''), (string) $images->get('image_fulltext_alt', '')],
        ];

        return $article;
    }

    private function loadCategory(int $id): ?object
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['title', 'description', 'metadesc', 'params', 'access']))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' > 0')
            ->bind(':id', $id, ParameterType::INTEGER);

        $category = $db->setQuery($query)->loadObject();

        if (!$category || !$this->isAuthorised((int) $category->access)) {
            return null;
        }

        $params           = new Registry((string) $category->params);
        $category->text   = $category->description;
        $category->images = [[(string) $params->get('image', ''), (string) $params->get('image_alt', '')]];

        return $category;
    }

    /**
     * Same precedence as the article view: the menu item wins when it links to this article.
     */
    private function getArticleParams(int $id, string $attribs): Registry
    {
        $params        = clone $this->app->getParams('com_content');
        $articleParams = new Registry($attribs);
        $query         = $this->app->getMenu()->getActive()->query ?? [];

        if (
            ($query['option'] ?? '') === 'com_content'
            && ($query['view'] ?? '') === 'article'
            && (int) ($query['id'] ?? 0) === $id
        ) {
            $articleParams->merge($params);

            return $articleParams;
        }

        $params->merge($articleParams);

        return $params;
    }

    private function isAuthorised(int $level): bool
    {
        return \in_array($level, $this->app->getIdentity()->getAuthorisedViewLevels());
    }

    private function truncate(string $text, int $length): string
    {
        $patterns = [
            // {tag}text{/tag}
            '#{(.*?)}(.*?){\/(.*?)}#s',
            // {tag}
            '#{(.*?)}#s',
            // <script>...</script>
            '#<script\b[^>]*>(.*?)<\/script>#is',
            // [widgetkit: xyz]
            '#\[(.*?)\]#s',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        $text = trim(preg_replace('#(?:\s|&nbsp;)+#', ' ', strip_tags($text)) ?? '');

        if (mb_strlen($text) > $length) {
            $text = mb_substr($text, 0, $length - 3) . '...';
        }

        return $text;
    }
}
