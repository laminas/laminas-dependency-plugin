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
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;

use function get_class;
use function version_compare;

final class DependencyRewriterV1Test extends TestCase
{
    use ProphecyTrait;

    /** @var Composer|ObjectProphecy */
    private $composer;

    /** @var IOInterface|ObjectProphecy */
    private $io;

    /** @var DependencyRewriterV1 */
    private $plugin;

    public function setUp() : void
    {
        if (! version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', 'lt')) {
            $this->markTestSkipped('Only executing these tests for composer v1');
        }

        $this->composer = $this->prophesize(Composer::class);
        $this->io = $this->prophesize(IOInterface::class);
        $this->plugin = new DependencyRewriterV1();
    }

    public function activatePlugin(DependencyRewriterV1 $plugin) : void
    {
        $this->io
             ->write(
                 Argument::containingString('Activating Laminas\DependencyPlugin\DependencyRewriterV1'),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $plugin->activate($this->composer->reveal(), $this->io->reveal());
    }

    public function testOnPreCommandRunDoesNothingIfCommandIsNotRequire() : void
    {
        $this->activatePlugin($this->plugin);

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $event = $this->prophesize(PreCommandRunEvent::class);
        $event->getCommand()->willReturn('remove')->shouldBeCalled();
        $event->getInput()->shouldNotBeCalled();

        $this->assertNull($this->plugin->onPreCommandRun($event->reveal()));
    }

    public function testOnPreCommandDoesNotRewriteNonZFPackageArguments() : void
    {
        $this->activatePlugin($this->plugin);

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $event = $this->prophesize(PreCommandRunEvent::class);
        $event->getCommand()->willReturn('require')->shouldBeCalled();

        $input = $this->prophesize(InputInterface::class);
        $input
            ->hasArgument('packages')
            ->willReturn(true);

        $input
            ->getArgument('packages')
            ->willReturn(['symfony/console', 'phpunit/phpunit'])
            ->shouldBeCalled();
        $input
            ->setArgument(
                'packages',
                ['symfony/console', 'phpunit/phpunit']
            )
            ->shouldBeCalled();

        $event->getInput()->will([$input, 'reveal']);

        $this->assertNull($this->plugin->onPreCommandRun($event->reveal()));
    }

    public function testOnPreCommandRewritesZFPackageArguments() : void
    {
        $this->activatePlugin($this->plugin);

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreCommandRun'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $event = $this->prophesize(PreCommandRunEvent::class);
        $event->getCommand()->willReturn('require')->shouldBeCalled();

        $input = $this->prophesize(InputInterface::class);
        $input
            ->hasArgument('packages')
            ->willReturn(true);

        $input
            ->getArgument('packages')
            ->willReturn([
                'zendframework/zend-form',
                'zfcampus/zf-content-negotiation',
                'zendframework/zend-expressive-hal',
                'zendframework/zend-expressive-zendviewrenderer',
            ])
            ->shouldBeCalled();
        $input
            ->setArgument(
                'packages',
                [
                    'laminas/laminas-form',
                    'laminas-api-tools/api-tools-content-negotiation',
                    'mezzio/mezzio-hal',
                    'mezzio/mezzio-laminasviewrenderer',
                ]
            )
            ->shouldBeCalled();

        $event->getInput()->will([$input, 'reveal']);

        $this->io
             ->write(
                 Argument::containingString(
                     'Changing package in current command from zendframework/zend-form to laminas/laminas-form'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Changing package in current command from zfcampus/zf-content-negotiation to'
                     . ' laminas-api-tools/api-tools-content-negotiation'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Changing package in current command from zendframework/zend-expressive-hal to mezzio/mezzio-hal'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Changing package in current command from zendframework/zend-expressive-zendviewrenderer'
                     . ' to mezzio/mezzio-laminasviewrenderer'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPreCommandRun($event->reveal()));
    }

    public function testOnPreDependenciesSolvingIgnoresNonInstallUpdateJobs() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(InstallerEvent::class);
        $request = $this->prophesize(Request::class);
        $event->getRequest()->will([$request, 'reveal'])->shouldBeCalled();

        $request
            ->getJobs()
            ->willReturn([
                ['cmd' => 'remove'],
            ])
            ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString('Replacing package'),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldNotBeCalled();

        $this->assertNull($this->plugin->onPreDependenciesSolving($event->reveal()));
    }

    public function testOnPreDependenciesSolvingIgnoresJobsWithoutPackageNames() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(InstallerEvent::class);
        $request = $this->prophesize(Request::class);
        $event->getRequest()->will([$request, 'reveal'])->shouldBeCalled();

        $request
            ->getJobs()
            ->willReturn([
                ['cmd' => 'install'],
            ])
            ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString('Replacing package'),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldNotBeCalled();

        $this->assertNull($this->plugin->onPreDependenciesSolving($event->reveal()));
    }

    public function testOnPreDependenciesSolvingIgnoresNonZFPackages() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(InstallerEvent::class);
        $request = $this->prophesize(Request::class);
        $event->getRequest()->will([$request, 'reveal'])->shouldBeCalled();

        $request
            ->getJobs()
            ->willReturn([
                [
                    'cmd' => 'install',
                    'packageName' => 'symfony/console',
                ],
            ])
            ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString('Replacing package'),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldNotBeCalled();

        $this->assertNull($this->plugin->onPreDependenciesSolving($event->reveal()));
    }

    public function testOnPreDependenciesSolvingIgnoresZFPackagesWithoutSubstitutions() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(InstallerEvent::class);
        $request = $this->prophesize(Request::class);
        $event->getRequest()->will([$request, 'reveal'])->shouldBeCalled();

        $request
            ->getJobs()
            ->willReturn([
                [
                    'cmd' => 'install',
                    'packageName' => 'zendframework/zend-version',
                ],
            ])
            ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString('Replacing package'),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldNotBeCalled();

        $this->assertNull($this->plugin->onPreDependenciesSolving($event->reveal()));
    }

    public function testOnPreDependenciesSolvingReplacesZFPackagesWithSubstitutions() : void
    {
        $this->activatePlugin($this->plugin);

        $request = new Request();
        $request->install('zendframework/zend-form');
        $request->update('zendframework/zend-expressive-template');
        $request->update('zfcampus/zf-apigility');

        $event = $this->prophesize(InstallerEvent::class);
        $event->getRequest()->willReturn($request)->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In Laminas\DependencyPlugin\DependencyRewriterV1::onPreDependenciesSolving'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Replacing package "zendframework/zend-form" with package "laminas/laminas-form"'
                 ),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Replacing package "zendframework/zend-expressive-template" with'
                     . ' package "mezzio/mezzio-template"'
                 ),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Replacing package "zfcampus/zf-apigility" with package "laminas-api-tools/api-tools"'
                 ),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPreDependenciesSolving($event->reveal()));

        $r = new ReflectionProperty($request, 'jobs');
        $r->setAccessible(true);
        $jobs = $r->getValue($request);

        $this->assertSame(
            [
                [
                    'cmd' => 'install',
                    'packageName' => 'laminas/laminas-form',
                    'constraint' => null,
                    'fixed' => false,
                ],
                [
                    'cmd' => 'update',
                    'packageName' => 'mezzio/mezzio-template',
                    'constraint' => null,
                    'fixed' => false,
                ],
                [
                    'cmd' => 'update',
                    'packageName' => 'laminas-api-tools/api-tools',
                    'constraint' => null,
                    'fixed' => false,
                ],
            ],
            $jobs
        );
    }

    public function testPrePackageInstallExitsEarlyForUnsupportedOperations() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $operation = $this->prophesize(Operation\UninstallOperation::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; operation of type ' . get_class($operation->reveal()) . ' not supported'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyForNonZFPackages() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $operation = $this->prophesize(Operation\InstallOperation::class);
        $package = $this->prophesize(PackageInterface::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();
        $operation->getPackage()->will([$package, 'reveal'])->shouldBeCalled();
        $package->getName()->willReturn('symfony/console')->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; operation of type ' . get_class($operation->reveal()) . ' not supported'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; package "symfony/console" does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacements() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $operation = $this->prophesize(Operation\UpdateOperation::class);
        $package = $this->prophesize(PackageInterface::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();
        $operation->getTargetPackage()->will([$package, 'reveal'])->shouldBeCalled();
        $package->getName()->willReturn('zendframework/zend-version')->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; operation of type ' . get_class($operation->reveal()) . ' not supported'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; package "zendframework/zend-version" does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; while package "zendframework/zend-version" is a ZF package,'
                     . ' it does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacementsForSpecificVersion() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $operation = $this->prophesize(Operation\UpdateOperation::class);
        $package = $this->prophesize(PackageInterface::class);
        $repositoryManager = $this->prophesize(RepositoryManager::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();
        $operation->getTargetPackage()->will([$package, 'reveal'])->shouldBeCalled();
        $package->getName()->willReturn('zendframework/zend-mvc')->shouldBeCalled();
        $package->getVersion()->willReturn('4.0.0')->shouldBeCalled();
        $this->composer->getRepositoryManager()->will([$repositoryManager, 'reveal'])->shouldBeCalled();
        $repositoryManager
            ->findPackage('laminas/laminas-mvc', '4.0.0')
            ->willReturn(null)
            ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; operation of type ' . get_class($operation->reveal()) . ' not supported'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; package "zendframework/zend-version" does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; while package "zendframework/zend-version" is a ZF package,'
                     . ' it does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; no replacement package found for package "laminas/laminas-mvc" with version 4.0.0'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event->reveal()));
    }

    public function testPrePackageUpdatesPackageNameWhenReplacementExists() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $original = new Package('zendframework/zend-mvc', '3.1.0', '3.1.1');
        $package = new Package('zendframework/zend-mvc', '3.1.1', '3.1.1');
        $operation = new Operation\UpdateOperation($original, $package);

        $replacementPackage = new Package('laminas/laminas-mvc', '3.1.1', '3.1.1');
        $repositoryManager = $this->prophesize(RepositoryManager::class);

        $event->getOperation()->willReturn($operation)->shouldBeCalled();
        $this->composer->getRepositoryManager()->will([$repositoryManager, 'reveal'])->shouldBeCalled();
        $repositoryManager
            ->findPackage('laminas/laminas-mvc', '3.1.1')
            ->willReturn($replacementPackage)
            ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterV1::class . '::onPrePackageInstallOrUpdate'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; operation of type ' . get_class($operation) . ' not supported'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; package "zendframework/zend-version" does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; while package "zendframework/zend-version" is a ZF package,'
                     . ' it does not have a replacement'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Exiting; no replacement package found for package "laminas/laminas-mvc"'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldNotBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Replacing package zendframework/zend-mvc with package laminas/laminas-mvc, using version 3.1.1'
                 ),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event->reveal()));
        $this->assertSame($replacementPackage, $operation->getTargetPackage());
    }

    public function testRewriterImplementsDependencySolvingCapableInterface() : void
    {
        self::assertInstanceOf(DependencySolvingCapableInterface::class, $this->plugin);
    }
}
