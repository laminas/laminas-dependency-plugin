<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Script\Event;

interface AutoloadDumpCapableInterface extends RewriterInterface
{
    /**
     * @return void
     */
    public function onPostAutoloadDump(Event $event);
}
