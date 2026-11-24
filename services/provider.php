<?php

/*
 *  package: YZC - Open Graph plugin
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | YZCommunicatie
 *  license: GNU General Public License version 3 or later
 *  link: https://www.yzcommunicatie.nl
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use YZCommunicatie\Plugin\System\Opengraph\Extension\Opengraph;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $factory = function (Container $container) {
            $config = (array) PluginHelper::getPlugin('system', 'opengraph');

            // Before Joomla 5.4 the dispatcher is a constructor argument, from then on passing it is deprecated
            $plugin = version_compare(JVERSION, '5.4', '<')
                ? new Opengraph($container->get(DispatcherInterface::class), $config)
                : new Opengraph($config);

            $plugin->setApplication(Factory::getApplication());
            $plugin->setDatabase($container->get(DatabaseInterface::class));

            return $plugin;
        };

        // Lazy services do not exist in the first Joomla 5 releases
        $container->set(
            PluginInterface::class,
            method_exists($container, 'lazy') ? $container->lazy(Opengraph::class, $factory) : $factory
        );
    }
};
