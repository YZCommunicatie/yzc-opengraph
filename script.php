<?php

/*
 *  package: YZC - Open Graph plugin
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | YZCommunicatie
 *  license: GNU General Public License version 3 or later
 *  link: https://www.yzcommunicatie.nl
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(DatabaseInterface::class)) implements InstallerScriptInterface {
                private const MINIMUM_JOOMLA = '5.0.0';

                private const MINIMUM_PHP = '8.1.0';

                public function __construct(private DatabaseInterface $db)
                {
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
                        return $this->refuse(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', self::MINIMUM_PHP));
                    }

                    if (version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
                        return $this->refuse(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', self::MINIMUM_JOOMLA));
                    }

                    return true;
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    // Only on a fresh install: an update must not enable a plugin the site has disabled
                    if ($type === 'install' || $type === 'discover_install') {
                        $this->enablePlugin();
                    }

                    return true;
                }

                private function enablePlugin(): void
                {
                    $db    = $this->db;
                    $query = $db->getQuery(true)
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('enabled') . ' = 1')
                        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                        ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('opengraph'));

                    try {
                        $db->setQuery($query)->execute();
                    } catch (\RuntimeException $e) {
                        // The plugin itself is installed, it only has to be enabled by hand
                        Factory::getApplication()->enqueueMessage($e->getMessage(), 'warning');
                    }
                }

                private function refuse(string $message): bool
                {
                    Factory::getApplication()->enqueueMessage($message, 'error');

                    return false;
                }
            }
        );
    }
};
