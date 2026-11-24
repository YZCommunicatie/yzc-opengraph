<?php

/*
 *  package: YZC - Open Graph plugin
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | YZCommunicatie
 *  license: GNU General Public License version 3 or later
 *  link: https://www.yzcommunicatie.nl
 */

namespace YZCommunicatie\Plugin\System\Opengraph\Extension;

use Joomla\CMS\Document\ErrorDocument;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use YZCommunicatie\Plugin\System\Opengraph\Helper\OpengraphHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class Opengraph extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    public static function getSubscribedEvents(): array
    {
        return ['onBeforeCompileHead' => 'onBeforeCompileHead'];
    }

    public function onBeforeCompileHead(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site') || $app->getInput()->getCmd('option') === 'com_finder') {
            return;
        }

        $document = $app->getDocument();

        // An error page still carries the request of the failed article: it has nothing to share
        if (!$document instanceof HtmlDocument || $document instanceof ErrorDocument) {
            return;
        }

        $helper = new OpengraphHelper($app, $this->getDatabase(), $this->params);

        foreach ($helper->getPropertyTags($document) as $name => $content) {
            $this->setTag($document, $name, $content, 'property');
        }

        foreach ($helper->getNameTags($document) as $name => $content) {
            $this->setTag($document, $name, $content, 'name');
        }

        // Never replace the page description by an empty one
        if ($helper->getDescription() !== '') {
            $document->setDescription($helper->getDescription());
        }
    }

    private function setTag(HtmlDocument $document, string $name, string $content, string $attribute): void
    {
        if ($content !== '') {
            $document->setMetaData($name, $content, $attribute);
        }
    }
}
