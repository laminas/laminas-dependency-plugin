<?php

declare(strict_types=1);

namespace LaminasTest\DependencyPlugin;

use Composer\Composer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\Event;
use Laminas\DependencyPlugin\AbstractDependencyRewriter;
use Laminas\DependencyPlugin\AutoloadDumpCapableInterface;
use Laminas\DependencyPlugin\DependencyRewriterPluginDelegator;
use Laminas\DependencyPlugin\DependencySolvingCapableInterface;
use Laminas\DependencyPlugin\PoolCapableInterface;
use Laminas\DependencyPlugin\RewriterInterface;
use PHPUnit\Framework\TestCase;

final class DependencyRewriterPluginDelegatorTest extends TestCase
{
    public function testWillTriggerActivate(): void
    {
        $composer = $this->createMock(Composer::class);
        $io       = $this->createMock(IOInterface::class);

        $rewriter  = $this->createMock(AbstractDependencyRewriter::class);
        $delegator = new DependencyRewriterPluginDelegator($rewriter);
        $rewriter
            ->expects($this->once())
            ->method('activate')
            ->with($composer, $io);

        $delegator->activate($composer, $io);
    }

    /**
     * @dataProvider eventsToForward
     * @psalm-param class-string $rewriter
     * @psalm-param class-string $event
     */
    public function testWillForwardEvents(string $rewriter, string $eventMethod, string $event): void
    {
        $event    = $this->createMock($event);
        $rewriter = $this->createMock($rewriter);
        $rewriter
            ->expects($this->once())
            ->method($eventMethod)
            ->with($event);

        self::assertInstanceOf(RewriterInterface::class, $rewriter);
        $delegator = new DependencyRewriterPluginDelegator($rewriter);

        $delegator->$eventMethod($event);
    }

    /**
     * @psalm-return iterable<array-key, array{0: class-string, 1: string, 2: class-string}>
     */
    public function eventsToForward(): iterable
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
