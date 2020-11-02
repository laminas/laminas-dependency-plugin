<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Plugin\PrePoolCreateEvent;

interface PoolCapableInterface extends RewriterInterface
{
    /**
     * If a ZF package is being installed, ensure the pool is modified to install the laminas equivalent instead.
     *
     * @return void
     */
    public function onPrePoolCreate(PrePoolCreateEvent $event);
}
