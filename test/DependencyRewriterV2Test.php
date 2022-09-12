<?php

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
use InvalidArgumentException;
use Laminas\DependencyPlugin\DependencyRewriterV2;
use LaminasTest\DependencyPlugin\TestAsset\IOWriteExpectations;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamContent;
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unshift;
use function count;
use function get_class;
use function in_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function version_compare;

use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;

/**
 * @template TComposerOptions of array<non-empty-string,list<non-empty-string>|non-empty-string|true>
 */
final class DependencyRewriterV2Test extends TestCase
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

    private DependencyRewriterV2 $plugin;

    /**
     * @var InstallationManager|MockObject
     * @psalm-var InstallationManager&MockObject
     */
    private $installationManager;

    /**
     * @var RepositoryManager|MockObject
     * @psalm-var RepositoryManager&MockObject
     */
    private $repositoryManager;

    /**
     * @var InstalledFilesystemRepository|MockObject
     * @psalm-var InstalledFilesystemRepository&MockObject
     */
    private $localRepository;

    /** @var InputInterface&MockObject */
    private $input;

    public function setUp(): void
    {
        if (! version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0.0', 'ge')) {
            $this->markTestSkipped('Only executing these tests for composer v2');
        }

        $this->composer            = $this->createMock(Composer::class);
        $this->installationManager = $this->createMock(InstallationManager::class);
        $this->composer
            ->method('getInstallationManager')
            ->willReturn($this->installationManager);

        $this->repositoryManager = $this->createMock(RepositoryManager::class);
        $this->localRepository   = $this->createMock(InstalledFilesystemRepository::class);
        $this->repositoryManager
            ->method('getLocalRepository')
            ->willReturn($this->localRepository);

        $this->composer
            ->method('getRepositoryManager')
            ->willReturn($this->repositoryManager);

        $this->io    = $this->createMock(IOInterface::class);
        $this->input = $this->createMock(InputInterface::class);

        $this->plugin = new DependencyRewriterV2();
    }

    public function activatePlugin(DependencyRewriterV2 $plugin): void
    {
        $plugin->activate($this->composer, $this->io);
    }

    public function prepareIOWriteExpectations(string ...$messages): IOWriteExpectations
    {
        array_unshift($messages, 'Activating Laminas\DependencyPlugin\DependencyRewriterV2');

        $ioWriteExpectations = new IOWriteExpectations($messages);

        $this->io
            ->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(static function (string $message) use ($ioWriteExpectations): void {
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
                /** @psalm-suppress InvalidCast */
                (string) $ioWriteExpectations
            )
        );
    }

    public function testOnPreCommandRunDoesNothingIfCommandIsNotRequire(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPreCommandRun'
        );

        $event = $this->createMock(PreCommandRunEvent::class);
        $event->expects($this->once())->method('getCommand')->willReturn('remove');
        $event->method('getInput')->willReturn($this->input);

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPreCommandRun($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testOnPreCommandDoesNotRewriteNonZFPackageArguments(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPreCommandRun'
        );

        $event = $this->createMock(PreCommandRunEvent::class);
        $event->expects($this->once())->method('getCommand')->willReturn('require');

        $input = $this->input;
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

        $input = $this->input;
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
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPreCommandRun',
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

    public function testPrePackageInstallExitsEarlyForUnsupportedOperations(): void
    {
        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\UninstallOperation::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate',
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
            'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate',
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
            'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate',
            'Exiting; while package "zendframework/zend-version" is a ZF package,'
            . ' it does not have a replacement'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePackageInstallExitsEarlyForZFPackagesWithoutReplacementsForSpecificVersion(): void
    {
        $event     = $this->createMock(PackageEvent::class);
        $operation = $this->createMock(Operation\UpdateOperation::class);
        $package   = $this->createMock(PackageInterface::class);

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $operation->expects($this->once())->method('getTargetPackage')->willReturn($package);
        $package->expects($this->once())->method('getName')->willReturn('zendframework/zend-mvc');
        $package->expects($this->once())->method('getVersion')->willReturn('4.0.0');
        $this->repositoryManager
            ->expects($this->once())
            ->method('findPackage')
            ->with('laminas/laminas-mvc', '4.0.0')
            ->willReturn(null);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate',
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

        $event->expects($this->once())->method('getOperation')->willReturn($operation);
        $this->repositoryManager
            ->expects($this->once())
            ->method('findPackage')
            ->with('laminas/laminas-mvc', '3.1.1')
            ->willReturn($replacementPackage);

        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In ' . DependencyRewriterV2::class . '::onPrePackageInstallOrUpdate',
            'Could replace package zendframework/zend-mvc with package laminas/laminas-mvc,'
            . ' using version 3.1.1'
        );

        $this->activatePlugin($this->plugin);

        $this->assertNull($this->plugin->onPrePackageInstallOrUpdate($event));
        $this->assertSame($package, $operation->getTargetPackage());
        $this->assertTrue(
            in_array($package, $this->plugin->zendPackagesInstalled, true),
            'Plugin did not remembered zend package!'
        );

        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testAutoloadDumpExitsEarlyIfNoZFPackageDetected(): void
    {
        $event = $this->createMock(Event::class);
        $event
            ->expects($this->never())
            ->method('getComposer');

        $this->plugin->onPostAutoloadDump($event);
    }

    /**
     * @dataProvider composerDefinitionWithZFPackageRequirement
     */
    public function testAutoloadDumpReplacesRootRequirementInComposerJson(
        array $packages,
        array $definition,
        array $expectedDefinition
    ): void {
        $factory   = $this->createApplicationFactory();
        $directory = vfsStream::setup();

        /** @psalm-var vfsStreamFile $composerJson */
        $composerJson = vfsStream::newFile('composer.json')
            ->withContent(json_encode($definition, JSON_THROW_ON_ERROR));
        $directory->addChild($composerJson);

        $this->io
            ->expects($this->once())
            ->method('isDebug')
            ->willReturn(false);

        /** @psalm-var array<array-key, string> $zendPackageNames */
        $zendPackageNames = array_keys($packages);
        $zendPackages     = array_combine(
            $zendPackageNames,
            array_map(
                function (string $packageName, string $version): PackageInterface {
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

        $plugin = new DependencyRewriterV2(
            $factory,
            $composerJson->url()
        );

        $plugin->zendPackagesInstalled = $zendPackages;

        $stringsToWrite        = [];
        $uninstallExpectations = [];
        foreach ($zendPackages as $packageName => $package) {
            $stringsToWrite[] = sprintf(
                'Package %s is a root requirement. laminas-dependency-plugin changes your composer.json'
                . ' to require laminas equivalent directly!',
                $packageName
            );

            $uninstallExpectations[] = [
                $this->localRepository,
                $this->callback(
                    static fn(Operation\UninstallOperation $operation): bool => $operation->getPackage() === $package
                ),
            ];
        }

        $ioWriteExpectations = $this->prepareIOWriteExpectations(...$stringsToWrite);
        $this->installationManager
            ->expects($this->exactly(count($zendPackages)))
            ->method('uninstall')
            ->withConsecutive(...$uninstallExpectations);

        $event = $this->createMock(Event::class);
        $event
            ->method('getComposer')
            ->willReturn($this->composer);

        $this->activatePlugin($plugin);
        $plugin->onPostAutoloadDump($event);

        /** @psalm-var array<string,mixed> $decoded */
        $decoded = json_decode($composerJson->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals($expectedDefinition, $decoded);

        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    /**
     * @psalm-param TComposerOptions $additionalUpdateOptions
     * @psalm-return Closure(): Application
     */
    private function createApplicationFactory(array $additionalUpdateOptions = []): callable
    {
        return function () use ($additionalUpdateOptions): Application {
            $mock = $this->createMock(Application::class);
            $mock
                ->expects($this->once())
                ->method('setAutoExit')
                ->with(false);

            $mock
                ->expects($this->once())
                ->method('run')
                ->with($this->callback(static function (ArrayInput $input) use ($additionalUpdateOptions): bool {
                    self::assertEquals('update', $input->getParameterOption('command'));
                    self::assertTrue($input->getParameterOption('--lock'), '--lock should be passed');
                    self::assertTrue($input->getParameterOption('--no-scripts'), '--no-scripts should be passed');
                    self::assertIsString($input->getParameterOption('--working-dir'), 'Missing working-dir argument');

                    foreach ($additionalUpdateOptions as $option => $expectedValue) {
                        self::assertEquals($input->getParameterOption($option), $expectedValue);
                    }
                    return true;
                }));

            return $mock;
        };
    }

    public function testPrePoolCreateEarlyExitsIfNoZendPackageIsInstalled(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'
        );

        $rootPackage = $this->createMock(RootPackageInterface::class);
        $this->composer
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($rootPackage);

        $vendor = vfsStream::setup();

        /** @psalm-var vfsStreamContent $installed */
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
            TXT);
        $composer  = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->createMock(Config::class);
        $config
            ->expects($this->once())
            ->method('get')
            ->with('vendor-dir')
            ->willReturn($vendor->url());

        $this->composer
            ->method('getConfig')
            ->willReturn($config);

        $this->io
            ->expects($this->once())
            ->method('isDebug')
            ->willReturn(false);

        $event = $this->createMock(PrePoolCreateEvent::class);
        $event
            ->expects($this->never())
            ->method('getUnacceptableFixedPackages');

        $this->activatePlugin($this->plugin);
        $this->plugin->onPrePoolCreate($event);

        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePoolCreateWillIgnoreIgnoredPackages(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'
        );

        $rootPackage = $this->createMock(RootPackageInterface::class);
        $this->composer
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($rootPackage);

        $vendor = vfsStream::setup();

        /** @psalm-var vfsStreamContent $installed */
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
            TXT);
        $composer  = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->createMock(Config::class);
        $config
            ->expects($this->once())
            ->method('get')
            ->with('vendor-dir')
            ->willReturn($vendor->url());

        $this->composer
            ->method('getConfig')
            ->willReturn($config);

        $event = $this->createMock(PrePoolCreateEvent::class);
        $event
            ->expects($this->once())
            ->method('getUnacceptableFixedPackages')
            ->willReturn([]);

        $package = $this->createMock(PackageInterface::class);
        $package
            ->expects($this->any())
            ->method('getName')
            ->willReturn('zendframework/zend-debug');

        $packages = [
            $package,
        ];

        $event
            ->expects($this->once())
            ->method('getPackages')
            ->willReturn($packages);

        $event
            ->expects($this->once())
            ->method('setUnacceptableFixedPackages');

        $event
            ->expects($this->once())
            ->method('setPackages')
            ->with($packages);

        $this->repositoryManager
            ->expects($this->never())
            ->method('findPackage');

        $this->io->expects($this->once())->method('isDebug')->willReturn(false);

        $this->activatePlugin($this->plugin);
        $this->plugin->onPrePoolCreate($event);

        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePoolCreateWillIgnoreUnavailablePackages(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate'
        );

        $rootPackage = $this->createMock(RootPackageInterface::class);
        $this->composer
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($rootPackage);

        $vendor = vfsStream::setup();

        /** @psalm-var vfsStreamContent $installed */
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
            TXT);
        $composer  = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->createMock(Config::class);
        $config
            ->expects($this->once())
            ->method('get')
            ->with('vendor-dir')
            ->willReturn($vendor->url());

        $this->composer
            ->method('getConfig')
            ->willReturn($config);

        $event = $this->createMock(PrePoolCreateEvent::class);
        $event
            ->expects($this->once())
            ->method('getUnacceptableFixedPackages')
            ->willReturn([]);

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
            ->expects($this->once())
            ->method('getPackages')
            ->willReturn($packages);

        $event
            ->expects($this->once())
            ->method('setUnacceptableFixedPackages')
            ->with([]);

        $event
            ->expects($this->once())
            ->method('setPackages')
            ->with($packages);

        $this->repositoryManager
            ->expects($this->once())
            ->method('findPackage')
            ->with('laminas/laminas-stdlib', '1.0')
            ->willReturn(null);

        $this->io->expects($this->once())->method('isDebug')->willReturn(false);

        $this->activatePlugin($this->plugin);
        $this->plugin->onPrePoolCreate($event);

        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    public function testPrePoolCreateWillSlipstreamPackage(): void
    {
        $ioWriteExpectations = $this->prepareIOWriteExpectations(
            'In Laminas\DependencyPlugin\DependencyRewriterV2::onPrePoolCreate',
            'Slipstreaming zendframework/zend-stdlib => laminas/laminas-stdlib'
        );

        $rootPackage = $this->createMock(RootPackageInterface::class);
        $this->composer
            ->expects($this->once())
            ->method('getPackage')
            ->willReturn($rootPackage);

        $vendor = vfsStream::setup();

        /** @psalm-var vfsStreamContent $installed */
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
            TXT);
        $composer  = vfsStream::newDirectory('composer');
        $vendor->addChild($composer);
        $composer->addChild($installed);

        $config = $this->createMock(Config::class);
        $config
            ->expects($this->once())
            ->method('get')
            ->with('vendor-dir')
            ->willReturn($vendor->url());

        $this->composer
             ->method('getConfig')
             ->willReturn($config);

        $event = $this->createMock(PrePoolCreateEvent::class);
        $event
            ->expects($this->once())
            ->method('getUnacceptableFixedPackages')
            ->willReturn([]);

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
            ->expects($this->once())
            ->method('getPackages')
            ->willReturn($packages);

        $replacement = $this->createMock(PackageInterface::class);

        $this->repositoryManager
            ->expects($this->once())
            ->method('findPackage')
            ->with('laminas/laminas-stdlib', '1.0')
            ->willReturn($replacement);

        $event
            ->expects($this->once())
            ->method('setUnacceptableFixedPackages')
            ->with([$package]);

        $event
            ->expects($this->once())
            ->method('setPackages')
            ->with([$replacement]);

        $this->io->expects($this->atLeastOnce())->method('isDebug')->willReturn(false);

        $this->activatePlugin($this->plugin);
        $this->plugin->onPrePoolCreate($event);

        $this->assertIOWriteReceivedAllExpectedMessages($ioWriteExpectations);
    }

    /**
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-return iterable<string, array{
     *     0: array<string, string>,
     *     1: array<string, array<string, string>>,
     *     2: array<string, array<string, string>>,
     * }>
     */
    public function composerDefinitionWithZFPackageRequirement(): iterable
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
                'config'  => [
                    'sort-packages' => true,
                ],
                'require' => [
                    'psr/log'                   => '^1.0',
                    'zendframework/zend-stdlib' => '^3.1',
                ],
            ],
            [
                'config'  => [
                    'sort-packages' => true,
                ],
                'require' => [
                    'laminas/laminas-stdlib' => '^3.1',
                    'psr/log'                => '^1.0',
                ],
            ],
        ];

        yield 'require-dev with sort packages' => [
            [
                'zendframework/zend-stdlib' => '3.1.0',
            ],
            [
                'config'      => [
                    'sort-packages' => true,
                ],
                'require-dev' => [
                    'psr/log'                   => '^1.0',
                    'zendframework/zend-stdlib' => '^3.1',
                ],
            ],
            [
                'config'      => [
                    'sort-packages' => true,
                ],
                'require-dev' => [
                    'laminas/laminas-stdlib' => '^3.1',
                    'psr/log'                => '^1.0',
                ],
            ],
        ];
    }

    /**
     * @param TComposerOptions $optionsPassedToComposer
     * @param TComposerOptions $expectedOptionsToBePassedToComposerLockUpdate
     * @dataProvider composerUpdateLockArguments
     */
    public function testComposerOptionsArePassedToUpdateLockCommand(
        array $optionsPassedToComposer,
        array $expectedOptionsToBePassedToComposerLockUpdate
    ): void {
        $input = $this->createMock(InputInterface::class);

        /** @psalm-var list<array{0:non-empty-string}> $consecutiveHasOptionArguments */
        $consecutiveHasOptionArguments = [];
        /** @psalm-var list<bool> $consecutiveHasOptionReturnValues */
        $consecutiveHasOptionReturnValues = [];
        /** @psalm-var list<array{0:non-empty-string}> $consecutiveGetOptionArguments */
        $consecutiveGetOptionArguments = [];
        /** @psalm-var list<mixed> $consecutiveGetOptionReturnValues */
        $consecutiveGetOptionReturnValues = [];

        foreach (DependencyRewriterV2::COMPOSER_LOCK_UPDATE_OPTIONS as $optionName) {
            $option                             = sprintf('--%s', $optionName);
            $consecutiveHasOptionArguments[]    = [$option, true];
            $passed                             = array_key_exists($optionName, $optionsPassedToComposer);
            $consecutiveHasOptionReturnValues[] = $passed;
            if (! $passed) {
                continue;
            }

            $consecutiveGetOptionArguments[]    = [$option, false, true];
            $consecutiveGetOptionReturnValues[] = $optionsPassedToComposer[$optionName];
        }

        $input
            ->expects(self::exactly(count(DependencyRewriterV2::COMPOSER_LOCK_UPDATE_OPTIONS)))
            ->method('hasParameterOption')
            ->withConsecutive(...$consecutiveHasOptionArguments)
            ->willReturnOnConsecutiveCalls(...$consecutiveHasOptionReturnValues);

        $input
            ->expects(self::exactly(count($consecutiveGetOptionArguments)))
            ->method('getParameterOption')
            ->withConsecutive(...$consecutiveGetOptionArguments)
            ->willReturnOnConsecutiveCalls(...$consecutiveGetOptionReturnValues);

        $factory = $this->createApplicationFactory($expectedOptionsToBePassedToComposerLockUpdate);

        $directory = vfsStream::setup();

        /** @psalm-var vfsStreamFile $composerJson */
        $composerJson = vfsStream::newFile('composer.json')
            ->withContent(json_encode([], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
        $directory->addChild($composerJson);

        $this->io
            ->expects($this->once())
            ->method('isDebug')
            ->willReturn(false);

        $plugin = new DependencyRewriterV2(
            $factory,
            $composerJson->url(),
            $input
        );

        $package                       = $this->createMock(PackageInterface::class);
        $plugin->zendPackagesInstalled = [
            $package,
        ];
        $this->activatePlugin($plugin);

        $event = $this->createMock(Event::class);
        $event
            ->expects(self::once())
            ->method('getComposer')
            ->willReturn($this->composer);

        $plugin->onPostAutoloadDump($event);
    }

    /**
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-return iterable<non-empty-string,array{0:TComposerOptions,1:TComposerOptions}>
     */
    public function composerUpdateLockArguments(): iterable
    {
        yield '--ignore-platform-reqs' => [
            ['ignore-platform-reqs' => true],
            /** Using {@see ArrayInput} notation here. */
            ['--ignore-platform-reqs' => true],
        ];

        yield '--ignore-platform-req=php' => [
            ['ignore-platform-req' => ['php']],
            /** Using {@see ArrayInput} notation here. */
            ['--ignore-platform-req' => ['php']],
        ];

        yield '--ignore-platform-req=php --ignore-platform-req=json' => [
            ['ignore-platform-req' => ['php', 'json'], 'ignore-platform-reqs' => true],
            /** Using {@see ArrayInput} notation here. */
            ['--ignore-platform-req' => ['php', 'json'], '--ignore-platform-reqs' => true],
        ];
    }
}
