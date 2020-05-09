<?php
/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace LaminasTest\DependencyPlugin;

use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Script\Event;
use Laminas\DependencyPlugin\DependencyRewriterPlugin;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;

use function current;
use function get_class;
use function key;

final class DependencyRewriterPluginTest extends TestCase
{
    /** @var Composer|ObjectProphecy */
    private $composer;

    /** @var IOInterface|ObjectProphecy */
    private $io;

    /** @var DependencyRewriterPlugin */
    private $plugin;

    /**
     * @var Application|ObjectProphecy
     */
    private $application;

    protected function setUp() : void
    {
        $this->composer = $this->prophesize(Composer::class);
        $this->io = $this->prophesize(IOInterface::class);
        $this->application = $this->prophesize(Application::class);
        $this->plugin = new DependencyRewriterPlugin([$this->application, 'reveal']);

        parent::setUp();
    }

    protected function tearDown() : void
    {
        parent::tearDown();
        vfsStreamWrapper::unregister();
    }

    public function activatePlugin(DependencyRewriterPlugin $plugin) : void
    {
        $this->io
             ->write(
                 Argument::containingString('Activating Laminas\DependencyPlugin\DependencyRewriterPlugin'),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $plugin->activate($this->composer->reveal(), $this->io->reveal());
    }

    public function testOnPreCommandRunDoesNothingIfCommandIsNotRequire() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PreCommandRunEvent::class);
        $event->getCommand()->willReturn('remove')->shouldBeCalled();
        $event->getInput()->shouldNotBeCalled();

        self::assertNull($this->plugin->onPreCommandRun($event->reveal()));
    }

    public function testOnPreCommandDoesNotRewriteNonZfPackageArguments() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PreCommandRunEvent::class);
        $event->getCommand()->willReturn('require')->shouldBeCalled();

        $input = $this->prophesize(InputInterface::class);
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

        self::assertNull($this->plugin->onPreCommandRun($event->reveal()));
    }

    public function testOnPreCommandRewritesZfPackageArguments() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PreCommandRunEvent::class);
        $event->getCommand()->willReturn('require')->shouldBeCalled();

        $input = $this->prophesize(InputInterface::class);
        $input
            ->getArgument('packages')
            ->willReturn([
                'zendframework/zend-form',
                'zfcampus/zf-content-negotiation',
                'zendframework/zend-expressive-hal',
                'zendframework/zend-expressive-zendviewrenderer'
            ])
            ->shouldBeCalled();
        $input
            ->setArgument(
                'packages',
                [
                    'laminas/laminas-form',
                    'laminas-api-tools/api-tools-content-negotiation',
                    'mezzio/mezzio-hal',
                    'mezzio/mezzio-laminasviewrenderer'
                ]
            )
            ->shouldBeCalled();

        $event->getInput()->will([$input, 'reveal']);

        self::assertNull($this->plugin->onPreCommandRun($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyIfNonDevMode() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->isDevMode()
            ->shouldBeCalledOnce()
            ->willReturn(false);

        self::assertNull($this->plugin->onPrePackageInstall($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyForUnsupportedOperations()
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->isDevMode()
            ->willReturn(true);

        $operation = $this->prophesize(Operation\UninstallOperation::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterPlugin::class . '::onPrePackageInstall'
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

        self::assertNull($this->plugin->onPrePackageInstall($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyForNonZfPackages()
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->isDevMode()
            ->willReturn(true);

        $operation = $this->prophesize(Operation\InstallOperation::class);
        $package = $this->prophesize(PackageInterface::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();
        $operation->getPackage()->will([$package, 'reveal'])->shouldBeCalled();
        $package->getName()->willReturn('symfony/console')->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterPlugin::class . '::onPrePackageInstall'
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

        self::assertNull($this->plugin->onPrePackageInstall($event->reveal()));
    }

    public function testPrePackageInstallExitsEarlyForZfPackagesWithoutReplacements()
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->isDevMode()
            ->willReturn(true);

        $operation = $this->prophesize(Operation\UpdateOperation::class);
        $package = $this->prophesize(PackageInterface::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();
        $operation->getTargetPackage()->will([$package, 'reveal'])->shouldBeCalled();
        $package->getName()->willReturn('zendframework/zend-version')->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterPlugin::class . '::onPrePackageInstall'
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
             ->shouldBeCalled();

        self::assertNull($this->plugin->onPrePackageInstall($event->reveal()));
    }

    public function testPrePackageUpdatesPackageNameWhenReplacementExists()
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $event
            ->isDevMode()
            ->willReturn(true);

        $original = new Package('zendframework/zend-mvc', '3.1.0', '3.1.1');
        $package = new Package('zendframework/zend-mvc', '3.1.1', '3.1.1');
        $operation = new Operation\UpdateOperation($original, $package);

        $event->getOperation()->willReturn($operation)->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterPlugin::class . '::onPrePackageInstall'
                 ),
                 true,
                 IOInterface::DEBUG
             )
             ->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'Found replacement for package zendframework/zend-mvc (laminas/laminas-mvc), using version 3.1.1'
                 ),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldBeCalled();

        self::assertNull($this->plugin->onPrePackageInstall($event->reveal()));
        $property = new ReflectionProperty($this->plugin, 'packageReplacementsFound');
        $property->setAccessible(true);
        $packageReplacementsFound = $property->getValue($this->plugin);
        self::assertNotEmpty($packageReplacementsFound);
        self::assertSame('zendframework/zend-mvc', key($packageReplacementsFound));
        self::assertSame('laminas/laminas-mvc', current($packageReplacementsFound)[0]);
    }

    public function testPostAutoloadDumpEarlyExitsWhenNoReplacementAvailable() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(Event::class);
        $this->io
             ->askConfirmation(Argument::any(), true)
             ->shouldNotBeCalled();

        $this->plugin->onPostAutoloadDump($event->reveal());
    }

    public function testPostAutoloadDumpEarlyExitsWhenConfirmationFailed() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(Event::class);

        $this->io
             ->write(
                 Argument::containingString(
                     'Found 1 zend packages which can be replaced with laminas packages.'
                 ),
                 true,
                 IOInterface::NORMAL
             )
             ->shouldBeCalled();

        $this->io
             ->askConfirmation(
                 Argument::containingString(
                     'Do you want to proceed? (Y/n)'
                 )
             )
             ->willReturn(false)
             ->shouldBeCalled();

        $this->setReplacementPackages($this->plugin, [
            [
                new Package('zendframework/zend-mvc', '3.1.1', '3.1.1'),
                new Package('laminas/laminas-mvc', '3.1.1', '3.1.1'),
            ],
        ]);

        $this->plugin->onPostAutoloadDump($event->reveal());
    }

    private function setReplacementPackages(DependencyRewriterPlugin $plugin, array $packageReplacementsFound)
    {
        $property = new ReflectionProperty($plugin, 'packageReplacementsFound');
        $property->setAccessible(true);
        $property->setValue($plugin, $packageReplacementsFound);
    }
}
