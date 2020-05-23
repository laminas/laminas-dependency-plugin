<?php
/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace LaminasTest\DependencyPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Generator;
use Laminas\DependencyPlugin\DependencyRewriterV2;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

use function array_combine;
use function array_keys;
use function array_map;
use function get_class;
use function in_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function version_compare;

use const JSON_THROW_ON_ERROR;

final class DependencyRewriterV2Test extends TestCase
{
    use ProphecyTrait;

    /** @var Composer|ObjectProphecy */
    private $composer;

    /** @var IOInterface|ObjectProphecy */
    private $io;

    /** @var DependencyRewriterV2 */
    private $plugin;

    /**
     * @var InstallationManager|ObjectProphecy
     */
    private $installationManager;

    /**
     * @var ObjectProphecy|RepositoryManager
     */
    private $repositoryManager;

    /**
     * @var InstalledFilesystemRepository|ObjectProphecy
     */
    private $localRepository;

    public function setUp() : void
    {
        if (! version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0.0', 'ge')) {
            $this->markTestSkipped('Only executing these tests for composer v2');
        }

        $this->composer = $this->prophesize(Composer::class);
        $this->installationManager = $this->prophesize(InstallationManager::class);
        $this->composer
             ->getInstallationManager()
             ->willReturn($this->installationManager->reveal());

        $this->repositoryManager = $this->prophesize(RepositoryManager::class);
        $this->localRepository = $this->prophesize(InstalledFilesystemRepository::class);
        $this->repositoryManager
             ->getLocalRepository()
             ->willReturn($this->localRepository->reveal());

        $this->composer
             ->getRepositoryManager()
             ->willReturn($this->repositoryManager->reveal());

        $this->io = $this->prophesize(IOInterface::class);

        $this->plugin = new DependencyRewriterV2();
    }

    public function activatePlugin(DependencyRewriterV2 $plugin) : void
    {
        $this->io
             ->write(
                 Argument::containingString('Activating Laminas\DependencyPlugin\DependencyRewriterV2'),
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
                     'In Laminas\DependencyPlugin\DependencyRewriterV2::onPreCommandRun'
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
                     'In Laminas\DependencyPlugin\DependencyRewriterV2::onPreCommandRun'
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
                     'In Laminas\DependencyPlugin\DependencyRewriterV2::onPreCommandRun'
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

    public function testPrePackageInstallExitsEarlyForUnsupportedOperations() : void
    {
        $this->activatePlugin($this->plugin);

        $event = $this->prophesize(PackageEvent::class);
        $operation = $this->prophesize(Operation\UninstallOperation::class);

        $event->getOperation()->will([$operation, 'reveal'])->shouldBeCalled();

        $this->io
             ->write(
                 Argument::containingString(
                     'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate'
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
                     'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate'
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
                     'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate'
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
                     'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate'
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
                     'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate'
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
                     'Could replace package zendframework/zend-mvc with package laminas/laminas-mvc,'
                     . ' using version 3.1.1'
                 ),
                 true,
                 IOInterface::VERBOSE
             )
             ->shouldBeCalled();

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event->reveal()));
        $this->assertSame($package, $operation->getTargetPackage());
        $this->assertTrue(
            in_array($package, $this->plugin->getZendPackagesInstalled(), true),
            'Plugin did not remembered zend package!'
        );
    }

    public function testAutoloadDumpExitsEarlyIfNoZFPackageDetected() : void
    {
        $event = $this->prophesize(Event::class);
        $event
            ->getComposer()
            ->shouldNotBeCalled();

        $this->plugin->onPostAutoloadDump($event->reveal());
    }

    /**
     * @dataProvider composerDefinitionWithZFPackageRequirement
     */
    public function testAutoloadDumpReplacesRootRequirementInComposerJson(
        array $packages,
        array $definition,
        array $expectedDefinition
    ) : void {
        $factory = $this->createApplicationFactory();
        $directory = vfsStream::setup();
        $composerJson = vfsStream::newFile('composer.json')
            ->withContent(json_encode($definition, JSON_THROW_ON_ERROR));
        $directory->addChild($composerJson);

        $this->io
             ->isDebug()
             ->willReturn(false)
             ->shouldBeCalled();

        $zendPackageNames = array_keys($packages);
        $zendPackages = array_combine(
            $zendPackageNames,
            array_map(
                function (string $packageName, string $version) : PackageInterface {
                    $package = $this->createMock(PackageInterface::class);
                    $package
                        ->expects($this->any())
                        ->method('getName')
                        ->willReturn($packageName);

                    $package
                        ->expects($this->any())
                        ->method('getVersion')
                        ->willReturn($version);

                    return $package;
                },
                $zendPackageNames,
                $packages
            )
        );

        $plugin = new DependencyRewriterV2($factory, $composerJson->url());
        $reflection = new ReflectionProperty($plugin, 'zendPackagesInstalled');
        $reflection->setAccessible(true);
        $reflection->setValue($plugin, $zendPackages);
        $this->activatePlugin($plugin);

        foreach ($zendPackages as $packageName => $package) {
            $this->io
                 ->write(
                     Argument::containingString(
                         sprintf(
                             'Package %s is a root requirement. laminas-dependency-plugin changes your composer.json'
                             . ' to require laminas equivalent directly!',
                             $packageName
                         )
                     ),
                     true,
                     IOInterface::NORMAL
                 )
                 ->shouldBeCalled();

            $this->installationManager
                 ->uninstall(
                     Argument::exact($this->localRepository),
                     Argument::that(
                         static function (Operation\UninstallOperation $operation) use ($package) : bool {
                             return $operation->getPackage() === $package;
                         }
                     )
                 )
                 ->shouldBeCalled();
        }

        $event = $this->prophesize(Event::class);
        $event
            ->getComposer()
            ->willReturn($this->composer->reveal());

        $plugin->onPostAutoloadDump($event->reveal());

        $decoded = json_decode($composerJson->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($expectedDefinition, $decoded);
    }

    private function createApplicationFactory() : callable
    {
        return function () : Application {
            $mock = $this->createMock(Application::class);
            $mock
                ->expects($this->once())
                ->method('setAutoExit')
                ->with(false);

            $mock
                ->expects($this->once())
                ->method('run')
                ->with($this->callback(static function (ArrayInput $input) : bool {
                    self::assertEquals('update', $input->getParameterOption('command'));
                    self::assertTrue($input->getParameterOption('--lock'), '--lock should be passed');
                    self::assertTrue($input->getParameterOption('--no-scripts'), '--no-scripts should be passed');
                    self::assertIsString($input->getParameterOption('--working-dir'), 'Missing working-dir argument');
                    return true;
                }));

            return $mock;
        };
    }

    public function testPrePoolCreateEarlyExitsIfNoZendPackageIsInstalled() : void
    {
        $this->activatePlugin($this->plugin);
        $this->io
             ->write(
                 Argument::containingString('In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'),
                 true,
                 IOInterface::NORMAL
             )
             ->shouldBeCalled();

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $this->composer
             ->getPackage()
             ->willReturn($rootPackage)
             ->shouldBeCalled();

        $vendor = vfsStream::setup();
        $installed = vfsStream::newFile('installed.json')
            ->withContent(<<<TXT
{
    "packages": [
        {
            "name": "foo/bar",
            "version": "1.0",
            "version_normalized": "1.0.0.0",
            "type": "metapackage",
            "install-path": null
        }
    ],
    "dev": true
}
TXT
            );
        $composer = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->prophesize(Config::class);
        $config
            ->get('vendor-dir')
            ->willReturn($vendor->url())
            ->shouldBeCalled();

        $this->composer
             ->getConfig()
             ->willReturn($config->reveal());

        $this->io
            ->isDebug()
            ->willReturn(false)
            ->shouldBeCalled();

        $event = $this->prophesize(PrePoolCreateEvent::class);
        $event
            ->getUnacceptableFixedPackages()
            ->shouldNotBeCalled();

        $this->plugin->onPrePoolCreate($event->reveal());
    }

    public function testPrePoolCreateWillIgnoreIgnoredPackages() : void
    {
        $this->activatePlugin($this->plugin);
        $this->io
             ->write(
                 Argument::containingString('In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'),
                 true,
                 IOInterface::NORMAL
             )
             ->shouldBeCalled();

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $this->composer
             ->getPackage()
             ->willReturn($rootPackage)
             ->shouldBeCalled();

        $vendor = vfsStream::setup();
        $installed = vfsStream::newFile('installed.json')
            ->withContent(<<<TXT
{
    "packages": [
        {
            "name": "zendframework/zend-debug",
            "version": "1.0",
            "version_normalized": "1.0.0.0",
            "type": "metapackage",
            "install-path": null
        }
    ],
    "dev": true
}
TXT
            );
        $composer = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->prophesize(Config::class);
        $config
            ->get('vendor-dir')
            ->willReturn($vendor->url())
            ->shouldBeCalled();

        $this->composer
             ->getConfig()
             ->willReturn($config->reveal());

        $event = $this->prophesize(PrePoolCreateEvent::class);
        $event
            ->getUnacceptableFixedPackages()
            ->willReturn([])
            ->shouldBeCalled();

        $package = $this->createMock(PackageInterface::class);
        $package
            ->expects($this->any())
            ->method('getName')
            ->willReturn('zendframework/zend-debug');

        $packages = [
            $package,
        ];

        $event
            ->getPackages()
            ->willReturn($packages)
            ->shouldBeCalled();

        $event
            ->setUnacceptableFixedPackages([])
            ->shouldBeCalled();

        $event
            ->setPackages($packages)
            ->shouldBeCalled();

        $this->repositoryManager
             ->findPackage()
             ->shouldNotBeCalled();

        $this->io->isDebug()->willReturn(false)->shouldBeCalled();

        $this->plugin->onPrePoolCreate($event->reveal());
    }

    public function testPrePoolCreateWillIgnoreUnavailablePackages() : void
    {
        $this->activatePlugin($this->plugin);
        $this->io
             ->write(
                 Argument::containingString('In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'),
                 true,
                 IOInterface::NORMAL
             )
             ->shouldBeCalled();

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $this->composer
             ->getPackage()
             ->willReturn($rootPackage)
             ->shouldBeCalled();

        $vendor = vfsStream::setup();
        $installed = vfsStream::newFile('installed.json')
            ->withContent(<<<TXT
{
    "packages": [
        {
            "name": "zendframework/zend-stdlib",
            "version": "1.0",
            "version_normalized": "1.0.0.0",
            "type": "metapackage",
            "install-path": null
        }
    ],
    "dev": true
}
TXT
            );
        $composer = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->prophesize(Config::class);
        $config
            ->get('vendor-dir')
            ->willReturn($vendor->url())
            ->shouldBeCalled();

        $this->composer
             ->getConfig()
             ->willReturn($config->reveal());

        $event = $this->prophesize(PrePoolCreateEvent::class);
        $event
            ->getUnacceptableFixedPackages()
            ->willReturn([])
            ->shouldBeCalled();

        $package = $this->createMock(PackageInterface::class);
        $package
            ->expects($this->any())
            ->method('getName')
            ->willReturn('zendframework/zend-stdlib');

        $package
            ->expects($this->any())
            ->method('getVersion')
            ->willReturn('1.0');

        $packages = [
            $package,
        ];

        $event
            ->getPackages()
            ->willReturn($packages)
            ->shouldBeCalled();

        $event
            ->setUnacceptableFixedPackages([])
            ->shouldBeCalled();

        $event
            ->setPackages($packages)
            ->shouldBeCalled();

        $this->repositoryManager
             ->findPackage('laminas/laminas-stdlib', '1.0')
             ->willReturn(null)
             ->shouldBeCalled();

        $this->io->isDebug()->willReturn(false)->shouldBeCalled();

        $this->plugin->onPrePoolCreate($event->reveal());
    }

    public function testPrePoolCreateWillSlipstreamPackage() : void
    {
        $this->activatePlugin($this->plugin);
        $this->io
             ->write(
                 Argument::containingString('In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'),
                 true,
                 IOInterface::NORMAL
             )
             ->shouldBeCalled();

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $this->composer
             ->getPackage()
             ->willReturn($rootPackage)
             ->shouldBeCalled();

        $vendor = vfsStream::setup();
        $installed = vfsStream::newFile('installed.json')
            ->withContent(<<<TXT
{
    "packages": [
        {
            "name": "zendframework/zend-stdlib",
            "version": "1.0",
            "version_normalized": "1.0.0.0",
            "type": "metapackage",
            "install-path": null
        }
    ],
    "dev": true
}
TXT
            );
        $composer = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->prophesize(Config::class);
        $config
            ->get('vendor-dir')
            ->willReturn($vendor->url())
            ->shouldBeCalled();

        $this->composer
             ->getConfig()
             ->willReturn($config->reveal());

        $event = $this->prophesize(PrePoolCreateEvent::class);
        $event
            ->getUnacceptableFixedPackages()
            ->willReturn([])
            ->shouldBeCalled();

        $package = $this->createMock(PackageInterface::class);
        $package
            ->expects($this->any())
            ->method('getName')
            ->willReturn('zendframework/zend-stdlib');

        $package
            ->expects($this->any())
            ->method('getVersion')
            ->willReturn('1.0');

        $packages = [
            $package,
        ];

        $event
            ->getPackages()
            ->willReturn($packages)
            ->shouldBeCalled();

        $replacement = $this->createMock(PackageInterface::class);

        $this->repositoryManager
             ->findPackage('laminas/laminas-stdlib', '1.0')
             ->willReturn($replacement)
             ->shouldBeCalled();

        $event
            ->setUnacceptableFixedPackages([$package])
            ->shouldBeCalled();

        $event
            ->setPackages([$replacement])
            ->shouldBeCalled();

        $this->io->isDebug()->willReturn(false)->shouldBeCalled();
        $this->io
             ->write(
                 Argument::containingString(
                     'Slipstreaming zendframework/zend-stdlib => laminas/laminas-stdlib',
                 ),
                 true,
                 IOInterface::NORMAL
             )
             ->shouldBeCalled();

        $this->plugin->onPrePoolCreate($event->reveal());
    }

    public function composerDefinitionWithZFPackageRequirement() : Generator
    {
        yield 'require' => [
            [
                'zendframework/zend-stdlib' => '3.1.0',
            ],
            [
                'require' => [
                    'zendframework/zend-stdlib' => '^3.1',
                ],
            ],
            [
                'require' => [
                    'laminas/laminas-stdlib' => '^3.1',
                ],
            ],
        ];

        yield 'require-dev' => [
            [
                'zendframework/zend-stdlib' => '3.1.0',
            ],
            [
                'require-dev' => [
                    'zendframework/zend-stdlib' => '^3.1',
                ],
            ],
            [
                'require-dev' => [
                    'laminas/laminas-stdlib' => '^3.1',
                ],
            ],
        ];

        yield 'require with sort packages' => [
            [
                'zendframework/zend-stdlib' => '3.1.0',
            ],
            [
                'config' => [
                    'sort-packages' => true,
                ],
                'require' => [
                    'psr/log' => '^1.0',
                    'zendframework/zend-stdlib' => '^3.1',
                ],
            ],
            [
                'config' => [
                    'sort-packages' => true,
                ],
                'require' => [
                    'laminas/laminas-stdlib' => '^3.1',
                    'psr/log' => '^1.0',
                ],
            ],
        ];

        yield 'require-dev with sort packages' => [
            [
                'zendframework/zend-stdlib' => '3.1.0',
            ],
            [
                'config' => [
                    'sort-packages' => true,
                ],
                'require-dev' => [
                    'psr/log' => '^1.0',
                    'zendframework/zend-stdlib' => '^3.1',
                ],
            ],
            [
                'config' => [
                    'sort-packages' => true,
                ],
                'require-dev' => [
                    'laminas/laminas-stdlib' => '^3.1',
                    'psr/log' => '^1.0',
                ],
            ],
        ];
    }
}
