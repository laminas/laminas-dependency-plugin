<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Installer\InstallerEvent;

interface DependencySolvingCapableInterface extends RewriterInterface
{
    /**
     * If a ZF package is being installed, modify the incoming request to slip-stream laminas packages.
     *
     * @return void
     */
    public function onPreDependenciesSolving(InstallerEvent $event);
}
