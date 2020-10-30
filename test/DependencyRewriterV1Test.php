<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace LaminasTest\DependencyPlugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation;
use Composer\DependencyResolver\Request;
use Composer\Installer\InstallerEvent;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Repository\RepositoryManager;
use Laminas\DependencyPlugin\DependencyRewriterV1;
use Laminas\DependencyPlugin\DependencySolvingCapableInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;

use function get_class;
use function version_compare;

final class DependencyRewriterV1Test extends TestCase
{
    /**
     * @var Composer|MockObject
     * @psalm-var Composer&MockObject
     */
    private $composer;

    /**
     * @var IOInterface|MockObject
     * @psalm-var IOInterface&MockObject
     */
    private $io;

    /** @var DependencyRewriterV1 */
    private $plugin;

    public function setUp(): void
    {
        if (! version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', 'lt')) {
            $this->markTestSkipped('Only executing these tests for composer v1');
        }

        $this->composer = $this->createMock(Composer::class);
        $this->io       = $this->createMock(IOInterface::class);
        $this->plugin   = new DependencyRewriterV1();
    }

    public function activatePlugin(DependencyRewriterV1 $plugin): void
    {
        $this->io
            ->expects($this->once())
            ->method('write')
            ->with(
                $this->stringContains('Activating Laminas\DependencyPlugin\DependencyRewriterV1'),
                true,
                IOInterface::DEBUG
            );

        $plugin->activate($this->composer, $this->io);
    }

    public function testOnPreCommandRunDoesNothingIfCommandIsNotRequire(): void
    {
        $this->activatePlugin($this->plugin);

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with(
                $this->stringContains('In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'),
                true,
                IOInterface::DEBUG
            );

        $event = $this->createMock(PreCommandRunEvent::class);
        $event->expects($this->once())->method('getCommand')->willReturn('remove');
        $event->expects($this->never())->method('getInput');

        $this->assertNull($this->plugin->onPreCommandRun($event));
    }

    public function testOnPreCommandDoesNotRewriteNonZFPackageArguments(): void
    {
        $this->activatePlugin($this->plugin);

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with(
                $this->stringContains('In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'),
                true,
                IOInterface::DEBUG
            );

        $event = $this->createMock(PreCommandRunEvent::class);
        $eventi->expects($this->once())->method('getCommand')->willReturn('require');

        $input = $this->createMock(InputInterface::class);
        $input
            ->method('hasArgument')
            ->with('packages')
            ->willReturn(true);

        $input
            ->expects($this->once())
            ->method('getArgument')
            ->with('packages')
            ->willReturn(['symfony/console', 'phpunit/phpunit']);
        $input
            ->expects($this->once())
            ->method('setArgument')
            ->with(
                'packages',
                ['symfony/console', 'phpunit/phpunit']
            );

        $event->method('getInput')->willReturn($input);

        $this->assertNull($this->plugin->onPreCommandRun($event));
    }

    public function testOnPreCommandRewritesZFPackageArguments(): void
    {
        $this->activatePlugin($this->plugin);

        $this->io
            ->expects($this->once())
            ->method('write')
            ->with(
                $this->stringContains(
                    'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'
                ),
                true,
                IOInterface::DEBUG
            );

        $event = $this->createMock(PreCommandRunEvent::class);
        $event->expects($this->once())->method('getCommand')->willReturn('require');

        $input = $this->createMock(InputInterface::class);
        $input
            ->method('hasArgument')
            ->with('packages')
            ->willReturn(true);

        $input
            ->expects($this->once())
            ->method('getArgument')
            ->with('packages')
            ->willReturn([
                'zendframework/zend-form',
                'zfcampus/zf-content-negotiation',
                'zendframework/zend-expressive-hal',
                'zendframework/zend-expressive-zendviewrenderer',
            ]);
        $input
            ->expects($this->once())
            ->method('setArgument')
            ->with(
                'packages',
                [
                    'laminas/laminas-form',
                    'laminas-api-tools/api-tools-content-negotiation',
                    'mezzio/mezzio-hal',
                    'mezzio/mezzio-laminasviewrenderer',
                ]
            );

        $event->method('getInput')->willReturn($input);

        $this->io
            ->expects($this->exactly(4))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'Changing package in current command from zendframework/zend-form to laminas/laminas-form'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Changing package in current command from zfcampus/zf-content-negotiation to'
                        . ' laminas-api-tools/api-tools-content-negotiation'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Changing package in current command from zendframework/zend-expressive-hal'
                        . ' to mezzio/mezzio-hal'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Changing package in current command from zendframework/zend-expressive-zendviewrenderer'
                        . ' to mezzio/mezzio-laminasviewrenderer'
                    ),
                    true,
                    IOInterface::DEBUG,
                ]
            );

        $this->assertNull($this->plugin->onPreCommandRun($event));
    }

    public function testOnPreDependenciesSolvingIgnoresNonInstallUpdateJobs(): void
    {
        $this->activatePlugin($this->plugin);

        $event   = $this->createMock(InstallerEvent::class);
        $request = $this->createMock(Request::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $request
            ->expects($this->once())
            ->method('getJobs')
            ->willReturn([
                ['cmd' => 'remove'],
            ]);

        $this->io
            ->expects($this->once())
            ->write(
                $this->stringContains(
                    'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                ),
                true,
                IOInterface::DEBUG
            );

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
    }

    public function testOnPreDependenciesSolvingIgnoresJobsWithoutPackageNames(): void
    {
        $this->activatePlugin($this->plugin);

        $event   = $this->createMock(InstallerEvent::class);
        $request = $this->createMock(Request::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $request
            ->expects($this->once())
            ->method('getJobs')
            ->willReturn([
                ['cmd' => 'install'],
            ]);

        $this->io
            ->expects($this->once())
            ->write(
                $this->stringContains(
                    'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                ),
                true,
                IOInterface::DEBUG
            );

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
    }

    public function testOnPreDependenciesSolvingIgnoresNonZFPackages(): void
    {
        $this->activatePlugin($this->plugin);

        $event   = $this->createMock(InstallerEvent::class);
        $request = $this->createMock(Request::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $request
            ->expects($this->once())
            ->method('getJobs')
            ->willReturn([
                [
                    'cmd'         => 'install',
                    'packageName' => 'symfony/console',
                ],
            ]);

        $this->io
            ->expects($this->once())
            ->write(
                $this->stringContains(
                    'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                ),
                true,
                IOInterface::DEBUG
            );

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
    }

    public function testOnPreDependenciesSolvingIgnoresZFPackagesWithoutSubstitutions(): void
    {
        $this->activatePlugin($this->plugin);

        $event   = $this->createMock(InstallerEvent::class);
        $request = $this->createMock(Request::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $request
            ->expects($this->once())
            ->method('getJobs')
            ->willReturn([
                [
                    'cmd'         => 'install',
                    'packageName' => 'zendframework/zend-version',
                ],
            ]);

        $this->io
            ->expects($this->once())
            ->write(
                $this->stringContains(
                    'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                ),
                true,
                IOInterface::DEBUG
            );

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
    }

    public function testOnPreDependenciesSolvingReplacesZFPackagesWithSubstitutions(): void
    {
        $this->activatePlugin($this->plugin);

        $request = new Request();
        $request->install('zendframework/zend-form');
        $request->update('zendframework/zend-expressive-template');
        $request->update('zfcampus/zf-apigility');

        $event = $this->createMock(InstallerEvent::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $this->io
            ->expects($this->exactly(4))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Replacing package "zendframework/zend-form" with package "laminas/laminas-form"'
                    ),
                    true,
                    IOInterface::VERBOSE,
                ],
                [
                    $this->stringContains(
                        'Replacing package "zendframework/zend-expressive-template" with'
                        . ' package "mezzio/mezzio-template"'
                    ),
                    true,
                    IOInterface::VERBOSE,
                ],
                [
                    $this->stringContains(
                        'Replacing package "zfcampus/zf-apigility" with package "laminas-api-tools/api-tools"'
                    ),
                    true,
                    IOInterface::VERBOSE,
                ]
            );

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));

        $r = new ReflectionProperty($request, 'jobs');
        $r->setAccessible(true);
        $jobs = $r->getValue($request);

        $this->assertSame(
            [
                [
                    'cmd'         => 'install',
                    'packageName' => 'laminas/laminas-form',
                    'constraint'  => null,
                    'fixed'       => false,
                ],
                [
                    'cmd'         => 'update',
                    'packageName' => 'mezzio/mezzio-template',
                    'constraint'  => null,
                    'fixed'       => false,
                ],
                [
                    'cmd'         => 'update',
                    'packageName' => 'laminas-api-tools/api-tools',
                    'constraint'  => null,
                    'fixed'       => false,
                ],
            ],
            $jobs
        );
    }

    public function testPrePackageInstallExitsEarlyForUnsupportedOperations(): void
    {
        $this->activatePlugin($this->plugin);

        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\UninstallOperation::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);

        $this->io
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Exiting; operation of type ' . get_class($operation->reveal()) . ' not supported'
                    ),
                    true,
                    IOInterface::DEBUG,
                ]
            );

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
    }

    public function testPrePackageInstallExitsEarlyForNonZFPackages(): void
    {
        $this->activatePlugin($this->plugin);

        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\InstallOperation::class);
        $package   = $this->createMock(PackageInterface::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $operation->expects($this->once())->method('getPackage')->willReturn($package);
        $package->expects($this->once())->method('getName')->willReturn('symfony/console');

        $this->io
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Exiting; package "symfony/console" does not have a replacement'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
            );

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacements(): void
    {
        $this->activatePlugin($this->plugin);

        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\UpdateOperation::class);
        $package   = $this->createMock(PackageInterface::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $operation->expects($this->once())->method('getTargetPackage')->willReturn($package);
        $package->expects($this->once())->method('getName')->willReturn('zendframework/zend-version');

        $this
            ->io
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Exiting; while package "zendframework/zend-version" is a ZF package,'
                        . ' it does not have a replacement'
                    ),
                    true,
                    IOInterface::DEBUG,
                ]
            );

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacementsForSpecificVersion(): void
    {
        $this->activatePlugin($this->plugin);

        $event             = $this->createMock(PackageEvent::class);
        $operation         = $this->createMock(Operation\UpdateOperation::class);
        $package           = $this->createMock(PackageInterface::class);
        $repositoryManager = $this->createMock(RepositoryManager::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $operation->expects($this->once())->method('getTargetPackage')->willReturn($package);
        $package->expects($this->once())->method('getName')->willReturn('zendframework/zend-mvc');
        $package->expects($this->once())->method('getVersion')->willReturn('4.0.0');
        $this->composer->expects($this->once())->method('getRepositoryManager')->willReturn($repositoryManager);
        $repositoryManager
            ->expects($this->once())
            ->method('findPackage')
            ->with('laminas/laminas-mvc', '4.0.0')
            ->willReturn(null);

        $this->io
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Exiting; no replacement package found for package "laminas/laminas-mvc" with version 4.0.0'
                    ),
                    true,
                    IOInterface::DEBUG,
                ]
            );

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
    }

    public function testPrePackageUpdatesPackageNameWhenReplacementExists(): void
    {
        $this->activatePlugin($this->plugin);

        $event     = $this->createMock(PackageEvent::class);
        $original  = new Package('zendframework/zend-mvc', '3.1.0', '3.1.1');
        $package   = new Package('zendframework/zend-mvc', '3.1.1', '3.1.1');
        $operation = new Operation\UpdateOperation($original, $package);

        $replacementPackage = new Package('laminas/laminas-mvc', '3.1.1', '3.1.1');
        $repositoryManager  = $this->createMock(RepositoryManager::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $this->composer->expects($this->once())->method('getRepositoryManager')->willReturn($repositoryManager);
        $repositoryManager
            ->expects($this->once())
            ->method('findPackage')
            ->with('laminas/laminas-mvc', '3.1.1')
            ->willReturn($replacementPackage);

        $this
            ->io
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(
                [
                    $this->stringContains(
                        'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                    ),
                    true,
                    IOInterface::DEBUG,
                ],
                [
                    $this->stringContains(
                        'Replacing package zendframework/zend-mvc with package laminas/laminas-mvc, using version 3.1.1'
                    ),
                    true,
                    IOInterface::VERBOSE,
                ]
            );

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertSame($replacementPackage, $operation->getTargetPackage());
    }

    public function testRewriterImplementsDependencySolvingCapableInterface(): void
    {
        self::assertInstanceOf(DependencySolvingCapableInterface::class, $this->plugin);
    }
}
