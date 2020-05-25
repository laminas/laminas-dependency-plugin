<?php
/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace LaminasTest\DependencyPlugin;

use Composer\Composer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\Event;
use Generator;
use Laminas\DependencyPlugin\AbstractDependencyRewriter;
use Laminas\DependencyPlugin\AutoloadDumpCapableInterface;
use Laminas\DependencyPlugin\DependencyRewriterPluginDelegator;
use Laminas\DependencyPlugin\DependencySolvingCapableInterface;
use Laminas\DependencyPlugin\PoolCapableInterface;
use Laminas\DependencyPlugin\RewriterInterface;
use PHPUnit\Framework\TestCase;

final class DependencyRewriterPluginDelegatorTest extends TestCase
{
    public function testWillTriggerActivate() : void
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);

        $rewriter = $this->createMock(AbstractDependencyRewriter::class);
        $delegator = new DependencyRewriterPluginDelegator($rewriter);
        $rewriter
            ->expects($this->once())
            ->method('activate')
            ->with($composer, $io);

        $delegator->activate($composer, $io);
    }

    /**
     * @dataProvider eventsToForward
     *
     * @param string $event
     */
    public function testWillForwardEvents(string $rewriter, string $eventMethod, $event) : void
    {
        $event = $this->createMock($event);
        $rewriter = $this->createMock($rewriter);
        $rewriter
            ->expects($this->once())
            ->method($eventMethod)
            ->with($event);

        self::assertInstanceOf(RewriterInterface::class, $rewriter);
        $delegator = new DependencyRewriterPluginDelegator($rewriter);

        $delegator->$eventMethod($event);
    }

    public function eventsToForward() : Generator
    {
        yield 'onPreDependenciesSolving' => [
            DependencySolvingCapableInterface::class,
            'onPreDependenciesSolving',
            InstallerEvent::class,
        ];
        yield 'onPrePackageInstallOrUpdate' => [
            RewriterInterface::class,
            'onPrePackageInstallOrUpdate',
            PackageEvent::class,
        ];
        yield 'onPreCommandRun' => [
            RewriterInterface::class,
            'onPreCommandRun',
            PreCommandRunEvent::class,
        ];
        yield 'onPrePoolCreate' => [
            PoolCapableInterface::class,
            'onPrePoolCreate',
            PrePoolCreateEvent::class,
        ];
        yield 'onPostAutoloadDump' => [
            AutoloadDumpCapableInterface::class,
            'onPostAutoloadDump',
            Event::class,
        ];
    }
}
