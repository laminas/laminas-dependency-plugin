<?php

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
use LaminasTest\DependencyPlugin\TestAsset\IOWriteExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;

use function array_unshift;
use function get_class;
use function sprintf;
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
        $plugin->activate($this->composer, $this->io);
    }

    public function prepareIOWriteExpectations(string ...$messages): IOWriteExpectations
    {
        array_unshift($messages, 'Activating Laminas\DependencyPlugin\DependencyRewriterV1');

        $ioWriteExpectations = new IOWriteExpectations($messages);

        $this->io
            ->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function (string $message) use ($ioWriteExpectations): void {
                if (! $ioWriteExpectations->matches($message)) {
                    throw new InvalidArgumentException('IO::write received unexpected message: ' . $message);
                }
            }));

        return $ioWriteExpectations;
    }

    public function assertIOWriteReceivedAllExpectedMessages(IOWriteExpectations $ioWriteExpectations): void
    {
        $this->assertTrue(
            $ioWriteExpectations->foundAll(),
            sprintf(
                "The following expected messages were not emitted:\n%s",
                (string) $ioWriteExpectations
            )
        );
    }

    public function testOnPreCommandRunDoesNothingIfCommandIsNotRequire(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'
        );

        $event = $this->createMock(PreCommandRunEvent::class);
        $event->expects($this->once())->method('getCommand')->willReturn('remove');
        $event->expects($this->never())->method('getInput');

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreCommandRun($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreCommandDoesNotRewriteNonZFPackageArguments(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'
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
            ->willReturn(['symfony/console', 'phpunit/phpunit']);
        $input
            ->expects($this->once())
            ->method('setArgument')
            ->with(
                'packages',
                ['symfony/console', 'phpunit/phpunit']
            );

        $event->method('getInput')->willReturn($input);

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreCommandRun($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreCommandRewritesZFPackageArguments(): void
    {
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

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun',
            'Changing package in current command from zendframework/zend-form to laminas/laminas-form',
            'Changing package in current command from zfcampus/zf-content-negotiation to'
                . ' laminas-api-tools/api-tools-content-negotiation',
            'Changing package in current command from zendframework/zend-expressive-hal to mezzio/mezzio-hal',
            'Changing package in current command from zendframework/zend-expressive-zendviewrenderer'
                . ' to mezzio/mezzio-laminasviewrenderer'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreCommandRun($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreDependenciesSolvingIgnoresNonInstallUpdateJobs(): void
    {
        $event   = $this->createMock(InstallerEvent::class);
        $request = $this->createMock(Request::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $request
            ->expects($this->once())
            ->method('getJobs')
            ->willReturn([
                ['cmd' => 'remove'],
            ]);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreDependenciesSolvingIgnoresJobsWithoutPackageNames(): void
    {
        $event   = $this->createMock(InstallerEvent::class);
        $request = $this->createMock(Request::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $request
            ->expects($this->once())
            ->method('getJobs')
            ->willReturn([
                ['cmd' => 'install'],
            ]);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreDependenciesSolvingIgnoresNonZFPackages(): void
    {
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

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreDependenciesSolvingIgnoresZFPackagesWithoutSubstitutions(): void
    {
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

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreDependenciesSolvingReplacesZFPackagesWithSubstitutions(): void
    {
        $request = new Request();
        $request->install('zendframework/zend-form');
        $request->update('zendframework/zend-expressive-template');
        $request->update('zfcampus/zf-apigility');

        $event = $this->createMock(InstallerEvent::class);
        $event->expects($this->once())->method('getRequest')->willReturn($request);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving',
            'Replacing package "zendframework/zend-form" with package "laminas/laminas-form"',
            'Replacing package "zendframework/zend-expressive-template" with'
            . ' package "mezzio/mezzio-template"',
            'Replacing package "zfcampus/zf-apigility" with package "laminas-api-tools/api-tools"'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreDependenciesSolving($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);

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
        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\UninstallOperation::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate',
            'Exiting; operation of type ' . get_class($operation) . ' not supported'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePackageInstallExitsEarlyForNonZFPackages(): void
    {
        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\InstallOperation::class);
        $package   = $this->createMock(PackageInterface::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $operation->expects($this->once())->method('getPackage')->willReturn($package);
        $package->expects($this->once())->method('getName')->willReturn('symfony/console');

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate',
            'Exiting; package "symfony/console" does not have a replacement'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacements(): void
    {
        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\UpdateOperation::class);
        $package   = $this->createMock(PackageInterface::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $operation->expects($this->once())->method('getTargetPackage')->willReturn($package);
        $package->expects($this->once())->method('getName')->willReturn('zendframework/zend-version');

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate',
            'Exiting; while package "zendframework/zend-version" is a ZF package,'
            . ' it does not have a replacement'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacementsForSpecificVersion(): void
    {
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

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate',
            'Exiting; no replacement package found for package "laminas/laminas-mvc" with version 4.0.0'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePackageUpdatesPackageNameWhenReplacementExists(): void
    {
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

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate',
            'Replacing package zendframework/zend-mvc with package laminas/laminas-mvc, using version 3.1.1'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertSame($replacementPackage, $operation->getTargetPackage());
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testRewriterImplementsDependencySolvingCapableInterface(): void
    {
        self::assertInstanceOf(DependencySolvingCapableInterface::class, $this->plugin);
    }
}
