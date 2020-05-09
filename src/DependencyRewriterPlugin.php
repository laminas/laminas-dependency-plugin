<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RootPackageRepository;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\ConstraintInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use function array_filter;
use function array_flip;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_unique;
use function array_values;
use function assert;
use function class_exists;
use function count;
use function get_class;
use function getcwd;
use function implode;
use function in_array;
use function preg_match;
use function preg_split;
use function sprintf;

class DependencyRewriterPlugin implements EventSubscriberInterface, PluginInterface
{
    /** @var Composer */
    private $composer;

    /** @var string[] */
    private $ignore = [
        'zendframework/zend-debug',
        'zendframework/zend-version',
        'zendframework/zendservice-apple-apns',
        'zendframework/zendservice-google-gcm',
        'zfcampus/zf-apigility-example',
        'zfcampus/zf-angular',
        'zfcampus/zf-console',
        'zfcampus/zf-deploy',
    ];

    /** @var IOInterface */
    private $io;

    /**
     * @var array
     */
    private $packageReplacementsFound = [];

    /**
     * @var callable
     */
    private $applicationFactory;

    /**
     * @var null|RepositoryInterface
     */
    private $installedRepo;

    /**
     * @var ComposerFile
     */
    private $composerFile;

    /**
     * @var array
     * @psalm-var array<string,list<string>>
     */
    private $nonMigratedPackageRemovals = [];

    /**
     * @var array
     * @psalm-var array<string,string>
     */
    private $laminasPackageRemovals = [];

    /**
     * @var array
     * @psalm-var array<string,PackageInterface>
     */
    private $nonMigratedPackageUpdates = [];

    public function __construct(callable $applicationFactory = null)
    {
        $this->applicationFactory = $applicationFactory ?: static function () {
            return new Application();
        };
    }

    /**
     * @return array Returns in following format:
     *     <string> => array<string, int>
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::PRE_COMMAND_RUN => ['onPreCommandRun', 1000],
            PackageEvents::PRE_PACKAGE_INSTALL => ['onPrePackageInstall', 1000],
            PackageEvents::PRE_PACKAGE_UPDATE => ['onPrePackageInstall', 1000],
            ScriptEvents::POST_AUTOLOAD_DUMP => ['onPostAutoloadDump', -1000],
            PackageEvents::POST_PACKAGE_UNINSTALL => ['onPostPackageUninstall', 1000],
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->output(sprintf('<info>Activating %s</info>', __CLASS__), IOInterface::DEBUG);
    }

    /**
     * When a ZF package is requested, replace with the Laminas variant.
     *
     * When a `require` operation is requested, and a ZF package is detected,
     * this listener will replace the argument with the equivalent Laminas
     * package. This ensures that the `composer.json` file is written to
     * reflect the package installed.
     */
    public function onPreCommandRun(PreCommandRunEvent $event)
    {
        if ($event->getCommand() !== 'require') {
            // Nothing to do here.
            return;
        }

        $input = $event->getInput();
        $input->setArgument(
            'packages',
            array_map([$this, 'updatePackageArgument'], $input->getArgument('packages'))
        );
    }

    /**
     * Ensure nested dependencies on ZF packages install equivalent Laminas packages.
     *
     * When a 3rd party package has dependencies on ZF packages, this method
     * will detect the request to install a ZF package, and rewrite it to use a
     * Laminas variant at the equivalent version, if one exists.
     */
    public function onPrePackageInstall(PackageEvent $event)
    {
        if (! $event->isDevMode()) {
            return;
        }

        $this->output(sprintf('<info>In %s</info>', __METHOD__), IOInterface::DEBUG);
        $operation = $event->getOperation();

        switch (true) {
            case $operation instanceof Operation\InstallOperation:
                $package = $operation->getPackage();
                break;
            case $operation instanceof Operation\UpdateOperation:
                $package = $operation->getTargetPackage();
                break;
            default:
                // Nothing to do
                $this->output(sprintf(
                    '<info>Exiting; operation of type %s not supported</info>',
                    get_class($operation)
                ), IOInterface::DEBUG);

                return;
        }

        $composerFile = $this->createComposerFile();
        $zendPackages = $composerFile->getZendPackagesWhichWereReplacedByLaminasPackages($package);

        if ($zendPackages) {
            $this->nonMigratedPackageUpdates[$package->getName()] = $package;
        }

        $replacementName = $this->extractReplacementName($package);
        if ($replacementName === '') {
            // Nothing to do
            $this->output(sprintf(
                '<info>Exiting; package "%s" does not have a replacement</info>',
                $package->getName()
            ), IOInterface::DEBUG);

            return;
        }

        $constraint = $package->getPrettyVersion();
        $this->output(sprintf(
            '<info>Found replacement for package %s (%s), using version %s</info>',
            $package->getName(),
            $replacementName,
            $constraint
        ), IOInterface::VERBOSE);

        $this->packageReplacementsFound[$package->getName()] = [$replacementName, $constraint];
    }

    /**
     * @return string
     */
    private function extractReplacementName(PackageInterface $package)
    {
        $name = $package->getName();
        if (! $this->isZendPackage($name)) {
            return '';
        }

        if ($package instanceof CompletePackageInterface) {
            $replacementName = $package->getReplacementPackage();
            if ($replacementName !== null) {
                return $replacementName;
            }
        }

        $replacementName = $this->transformPackageName($name);
        if ($replacementName !== $name) {
            return $replacementName;
        }

        return '';
    }

    public function onPostAutoloadDump(Event $event)
    {
        $composerFile = $this->createComposerFile();
        try {
            $composerFile = $this->handlePackageInstallationsAndUpdates(
                $composerFile,
                $this->packageReplacementsFound
            );
            $composerFile = $this->handlePackageUpdates(
                $composerFile,
                $this->nonMigratedPackageUpdates
            );

            $composerFile = $this->handlePackageUninstallations(
                $composerFile,
                $this->nonMigratedPackageRemovals,
                $this->laminasPackageRemovals
            );
        } catch (MigrationFailedException $exception) {
            $this->output(sprintf('<error>%s</error>', $exception->getMessage()));
            return;
        }

        $composerFile->store();
    }

    public function onPostPackageUninstall(PackageEvent $event)
    {
        if (! $event->isDevMode()) {
            return;
        }

        $operation = $event->getOperation();
        if (!$operation instanceof Operation\UninstallOperation) {
            return;
        }

        $package = $operation->getPackage();

        $composerFile = $this->createComposerFile();

        $laminasPackages = $composerFile->getZendPackagesWhichWereReplacedByLaminasPackages($package);

        if ($laminasPackages) {
            $this->nonMigratedPackageRemovals[$package->getName()] = $laminasPackages;
            return;
        }

        $zendEquivalent = $this->extractZendEquivalent($package);
        if ($zendEquivalent === '') {
            return;
        }

        $this->laminasPackageRemovals[$package->getName()] = $zendEquivalent;
    }

    /**
     * Parses a package argument from the command line, replacing it with the
     * Laminas variant if it exists.
     *
     * @param string $package Package specification from command line
     * @return string Modified package specification containing Laminas
     *     substitution, or original if no changes required.
     */
    private function updatePackageArgument($package)
    {
        $result = preg_split('/[ :=]/', $package, 2);
        if ($result === false) {
            return $package;
        }
        $name = array_shift($result);

        if (! $this->isZendPackage($name)) {
            return $package;
        }

        $replacementName = $this->transformPackageName($name);
        $version = count($result) ? array_shift($result) : null;

        if ($version === null) {
            return $replacementName;
        }

        return sprintf('%s:%s', $replacementName, $version);
    }

    /**
     * @param string $name Original package name
     * @return bool
     */
    private function isZendPackage($name)
    {
        if (! preg_match('#^(zendframework|zfcampus)/#', $name)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $name Original package name
     * @return string Transformed (or original) package name
     */
    private function transformPackageName($name)
    {
        switch ($name) {
            // Packages without replacements:
            case in_array($name, $this->ignore, true):
                return $name;
            // Packages with non-standard naming:
            case 'zendframework/zenddiagnostics':
                return 'laminas/laminas-diagnostics';
            case 'zendframework/zendoauth':
                return 'laminas/laminas-oauth';
            case 'zendframework/zendservice-recaptcha':
                return 'laminas/laminas-recaptcha';
            case 'zendframework/zendservice-twitter':
                return 'laminas/laminas-twitter';
            case 'zendframework/zendxml':
                return 'laminas/laminas-xml';
            case 'zendframework/zend-expressive':
                return 'mezzio/mezzio';
            case 'zendframework/zend-problem-details':
                return 'mezzio/mezzio-problem-details';
            case 'zfcampus/zf-apigility':
                return 'laminas-api-tools/api-tools';
            case 'zfcampus/zf-composer-autoloading':
                return 'laminas/laminas-composer-autoloading';
            case 'zfcampus/zf-development-mode':
                return 'laminas/laminas-development-mode';
            // All other packages:
            default:
                if (preg_match('#^zendframework/zend-expressive-zend(?<name>.*)$#', $name, $matches)) {
                    return sprintf('mezzio/mezzio-laminas%s', $matches['name']);
                }
                if (preg_match('#^zendframework/zend-expressive-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('mezzio/mezzio-%s', $matches['name']);
                }
                if (preg_match('#^zfcampus/zf-apigility-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('laminas-api-tools/api-tools-%s', $matches['name']);
                }
                if (preg_match('#^zfcampus/zf-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('laminas-api-tools/api-tools-%s', $matches['name']);
                }
                if (preg_match('#^zendframework/zend-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('laminas/laminas-%s', $matches['name']);
                }

                return $name;
        }
    }

    /**
     * @param string $message
     * @param int $verbosity
     */
    private function output($message, $verbosity = IOInterface::NORMAL)
    {
        $this->io->write($message, $newline = true, $verbosity);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @param string $question
     * @return bool
     */
    private function confirm($question)
    {
        return $this->io->askConfirmation(sprintf('<question>%s</question>', $question));
    }

    /**
     * @param bool $lockPackageVersion
     * @param array $packageReplacements
     * @return ComposerFile
     */
    private function replacePackagesInDefinition(
        ComposerFile $composerFile,
        array $packageReplacements,
        $lockPackageVersion
    ) {
        $packageAlreadyReplaced = [];
        $packageReplacementMap = [];

        foreach ($packageReplacements as $package => list($mostOuterZendPackageReplacement)) {
            $packageReplacementMap[$mostOuterZendPackageReplacement] = $package;
            $packageAlreadyReplaced[$package] = false;
        }

        foreach ($packageReplacements as $package => list($packageReplacement, $installedPackageVersion)) {
            if ($packageAlreadyReplaced[$package]) {
                continue;
            }

            $details = $this->askComposerWhyPackageIsInstalled(
                $package,
                $installedPackageVersion,
                $lockPackageVersion
            );

            list($mostOuterZendPackageReplacement) = $packageReplacements[$details->mostOuterZendPackageName];

            $composerFile = $composerFile->withZendPackageReplacement(
                $package,
                $mostOuterZendPackageReplacement,
                $details->packageLinksWhichRequireZendPackage[0]->getSource(),
                $details->versionConstraint
            );

            $packageAlreadyReplaced[$details->mostOuterZendPackageName] = true;

            foreach ($details->packagesWhichWillBeReplaced as $packageWhichWillBeReplacedAswell) {
                if (! isset($packageReplacementMap[$packageWhichWillBeReplacedAswell])) {
                    continue;
                }

                $packageName = $packageReplacementMap[$packageWhichWillBeReplacedAswell];
                $packageAlreadyReplaced[$packageName] = true;
            }

            if (!$lockPackageVersion) {
                $this->output(sprintf(
                    'Adding "%s" to `composer.json` dependencies to replace "%s"',
                    $mostOuterZendPackageReplacement,
                    $details->mostOuterZendPackageName
                ));
            }

            $composerFile = $composerFile->rememberNonMigratedPackages(
                $details->packageLinksWhichRequireZendPackage
            );
        }

        return $composerFile;
    }

    /**
     * @param string[] $packagesToUpdate
     * @return int
     */
    private function updateLockFile(array $packagesToUpdate)
    {
        $applicationFactory = $this->applicationFactory;
        $application = $applicationFactory();
        $output = ! $this->io->isVerbose() ? new NullOutput() : null;
        $application->setAutoExit(false);

        $input = [
            'command' => 'update',
            '--lock' => empty($packagesToUpdate),
            '--no-plugins' => true,
            '--no-scripts' => true,
            '--working-dir' => getcwd(),
            'packages' => $packagesToUpdate,
        ];

        return $application->run(new ArrayInput($input), $output);
    }

    /**
     * In case of `lock`, we want to provide a constraint to ensure we update to the most recent laminas package
     * matching the current version.
     * There are some packages which received version fixes (no changes, just some fixes) after migrated to laminas.
     * Those packages have `p1` postfix.
     * {@see https://github.com/laminas/laminas-diactoros/compare/2.2.1...2.2.1p1 Example for fixed version}
     *
     * @param string $package
     * @param string $version
     * @param bool $lock
     * @return ComposerInstallationDetails
     * @throws RuntimeException if no dependencies could be found.
     */
    private function askComposerWhyPackageIsInstalled($package, $version, $lock)
    {
        $composer = $this->composer;
        $repository = $this->createInstalledPackagesRepository($composer);
        $rootPackage = $composer->getPackage();
        $devRequires = $rootPackage->getDevRequires();

        $versionParser = new VersionParser();
        $constraint = $versionParser->parseConstraints($version);
        $this->output(sprintf('Searching for %s', $package), IOInterface::DEBUG);

        $results = $repository->getDependents(
            $package,
            $constraint
        );

        if (! $results) {
            throw new RuntimeException(sprintf(
                'Could not determine dependency graph for package %s;'
                . ' need that graph to put it into the proper `require` or `require-dev` requirements...',
                $package
            ));
        }

        $packagesWhichWillBeReplaced = $this->generatePackagesWhichWillBeReplaced($results);
        $mostOuterZendPackageLink = $this->extractMostOuterZendPackage($results);
        $mostOuterZendPackageName = $package;
        $mostOuterZendPackageVersion = $version;

        if ($mostOuterZendPackageLink !== null) {
            $mostOuterZendPackageName = $mostOuterZendPackageLink->getSource();
        }

        $this->output(sprintf(
            'Found the following packages which will be replaced by requiring %s: %s',
            $mostOuterZendPackageName,
            implode(', ', $packagesWhichWillBeReplaced)
        ), IOInterface::DEBUG);

        $packageLinksWhichRequireZendPackage = array_map(
            static function (array $result) {
                return $result[1];
            },
            $repository->getDependents($mostOuterZendPackageName)
        );

        $packageWhichRequiresZendPackage = $packageLinksWhichRequireZendPackage[0];

        assert($packageWhichRequiresZendPackage instanceof Link);

        $this->output(sprintf(
            'Package "%s" requires zend-package "%s" with constraint "%s"',
            $packageWhichRequiresZendPackage->getSource(),
            $mostOuterZendPackageName,
            $packageWhichRequiresZendPackage->getPrettyConstraint()
        ), IOInterface::DEBUG);

        $mostOuterZendPackageConstraint = $packageWhichRequiresZendPackage->getConstraint();
        assert($mostOuterZendPackageConstraint instanceof ConstraintInterface);
        if ($mostOuterZendPackageName !== $package) {
            $mostOuterZendPackageVersion = $repository
                ->findPackage(
                    $mostOuterZendPackageName,
                    $packageWhichRequiresZendPackage->getConstraint()
                )
                ->getPrettyVersion();
        }

        $packageNameWhichRequiresZendPackage = $packageWhichRequiresZendPackage->getSource();
        $dev = isset($devRequires[$packageNameWhichRequiresZendPackage]);

        return ComposerInstallationDetails::create(
            $dev,
            $mostOuterZendPackageName,
            $lock ? sprintf('~%s.0', $mostOuterZendPackageVersion) : $mostOuterZendPackageConstraint->getPrettyString(),
            $packagesWhichWillBeReplaced,
            $packageLinksWhichRequireZendPackage
        );
    }

    /**
     * @return null|Link
     */
    private function extractMostOuterZendPackage(array $results)
    {
        $latest = null;

        /**
         * @var PackageInterface $package
         * @var Link $link
         * @var array $children
         */
        foreach ($results as list($package, $link, $children)) {
            $latest = $this->extractMostOuterZendPackage($children);
            if (! $this->extractReplacementName($package)) {
                continue;
            }

            if ($latest !== null) {
                return $latest;
            }

            return $link;
        }

        return $latest;
    }

    /**
     * @return string[]
     */
    private function generatePackagesWhichWillBeReplaced(array $results)
    {
        $packages = [];

        /**
         * @var PackageInterface $package
         * @var Link $link
         * @var array $children
         */
        foreach ($results as list($package, $link, $children)) {
            $packages[] = $this->generatePackagesWhichWillBeReplaced($children);
            $packageName = $package->getName();
            if (! $this->isZendPackage($packageName)) {
                continue;
            }

            $replacement = $this->transformPackageName($packageName);
            if ($packageName === $replacement) {
                continue;
            }

            $packages[] = [$replacement];
        }

        /** @link https://github.com/kalessil/phpinspectionsea/blob/dbdf0e2fea67c8ec102dcef7e49c72f458385030/docs/performance.md#slow-array-function-used-in-loop */
        $packages = array_merge([], ...$packages);

        return array_unique($packages);
    }

    /**
     * @return RepositoryInterface
     */
    private function createInstalledPackagesRepository(Composer $composer)
    {
        if ($this->installedRepo instanceof RepositoryInterface) {
            return $this->installedRepo;
        }

        $rootPackage = $composer->getPackage();
        $config = $rootPackage->getConfig();
        $platformOverrides = [];
        if (isset($config['platform'])) {
            $platformOverrides = $config['platform'];
        }

        if (! class_exists(InstalledRepository::class)) {
            $this->installedRepo = new CompositeRepository([
                new ArrayRepository([$composer->getPackage()]),
                $composer->getRepositoryManager()->getLocalRepository(),
                new PlatformRepository([], $platformOverrides),
            ]);

            return $this->installedRepo;
        }

        $this->installedRepo = new InstalledRepository([
            new RootPackageRepository($rootPackage),
            $composer->getRepositoryManager()->getLocalRepository(),
            new PlatformRepository([], $platformOverrides),
        ]);

        return $this->installedRepo;
    }

    /**
     * @return ComposerFile
     */
    private function createComposerFile()
    {
        if ($this->composerFile) {
            return $this->composerFile;
        }
        $this->composerFile = new ComposerFile(new JsonFile(Factory::getComposerFile()));
        return $this->composerFile;
    }

    /**
     * @param ComposerFile $composerFile
     *
     * @return ComposerFile
     */
    private function handlePackageInstallationsAndUpdates(ComposerFile $composerFile, array $packageReplacements)
    {
        $numberOfPackageReplacements = count($packageReplacements);
        if ($numberOfPackageReplacements === 0) {
            return $composerFile;
        }

        $this->output(sprintf(
            '<info>Found %d zend packages which can be replaced with laminas packages.</info>',
            $numberOfPackageReplacements
        ));

        if (! $this->confirm('Do you want to proceed? (Y/n) ')) {
            return $composerFile;
        }

        $this->output('<info>Replacing zend packages with laminas packages in `composer.json`...</info>');
        $composerFile = $this->replacePackagesInDefinition(
            $composerFile,
            $packageReplacements,
            true
        );
        $composerFile->store();

        $this->output('<info>Updating `composer.lock` to remove zend packages and install laminas pendants...</info>');
        $exitCode = $this->updateLockFile(
            array_merge(
                array_keys($packageReplacements),
                array_map(
                    static function (array $data) {
                        return $data[0];
                    },
                    array_values($packageReplacements)
                )
            )
        );
        if ($exitCode > 0) {
            throw MigrationFailedException::lockFileUpdateFailed();
        }

        $this->output(
            '<info>Update `composer.json` to to restore zend package constraints from 3rd-party package...</info>'
        );
        $composerFile = $this->replacePackagesInDefinition(
            $composerFile,
            $packageReplacements,
            false
        );

        $composerFile->store();

        $this->output('<info>Update `composer.lock` to synchronize with `composer.json`...</info>');
        $exitCode = $this->updateLockFile([]);

        if ($exitCode > 0) {
            $this->output('<error>Migration failed. Could not update `composer.lock`!</error>');
            throw MigrationFailedException::lockFileUpdateFailed();
        }

        return $composerFile;
    }

    /**
     * @param ComposerFile $composerFile
     * @param array        $nonMigratedPackageRemovals
     * @param array        $laminasPackageRemovals
     *
     * @return ComposerFile
     */
    private function handlePackageUninstallations(
        ComposerFile $composerFile,
        array $nonMigratedPackageRemovals,
        array $laminasPackageRemovals
    ) {
        $packagesToRemove = array_flip($laminasPackageRemovals);
        foreach ($nonMigratedPackageRemovals as $package => $zendPackages) {
            $composerFile = $composerFile->forgetNonMigratedPackage($package);
            foreach ($zendPackages as $zendPackage) {
                $laminasPackage = $this->transformPackageName($zendPackage);
                if (in_array( $laminasPackage, $packagesToRemove, true)) {
                    continue;
                }

                if (!$this->confirmThatPackageMayBeRemoved($laminasPackage, $package)) {
                    continue;
                }

                $packagesToRemove[$zendPackage] = $laminasPackage;
            }
        }

        if (!$packagesToRemove) {
            $composerFile->store();

            return $composerFile;
        }

        $laminasPackagesToRemove = array_values($packagesToRemove);

        $this->output(
            sprintf(
                'Removing the following packages from composer.json: %s',
                implode(', ', $laminasPackagesToRemove)
            ),
            IOInterface::DEBUG
        );

        $exitCode = $this->removePackages($laminasPackagesToRemove);
        if ($exitCode > 0) {
            throw MigrationFailedException::packageRemovalFailed($laminasPackagesToRemove);
        }

        $composerFile = $composerFile
            ->withoutPackageRequirements($packagesToRemove);

        $composerFile->store();
        $this->output('<info>Updating `composer.lock` to synchronize with `composer.json`...</info>');
        $this->updateLockFile([]);

        return $composerFile;
    }

    /**
     * Uses composer to remove dependencies AND updating the lock-file in the same run.
     * @return int
     */
    private function removePackages(array $packagesToRemove)
    {
        $applicationFactory = $this->applicationFactory;
        $application = $applicationFactory();
        $output = ! $this->io->isVerbose() ? new NullOutput() : null;
        $application->setAutoExit(false);

        $input = [
            'command' => 'remove',
            '--no-plugins' => true,
            '--no-scripts' => true,
            '--working-dir' => getcwd(),
            'packages' => $packagesToRemove,
        ];

        return $application->run(new ArrayInput($input), $output);
    }

    private function extractZendEquivalent(PackageInterface $package)
    {
        foreach ($package->getReplaces() as $replacement) {
            if ($this->isZendPackage($replacement->getTarget())) {
                return $replacement->getTarget();
            }
        }

        return '';
    }

    /**
     * @param ComposerFile $composerFile
     * @param array        $nonMigratedPackageUpdates
     *
     * @return ComposerFile
     */
    private function handlePackageUpdates(ComposerFile $composerFile, array $nonMigratedPackageUpdates)
    {
        if (!$nonMigratedPackageUpdates) {
            return $composerFile;
        }

        foreach ($nonMigratedPackageUpdates as $packageName => $package) {
            $zendDependencies = $composerFile->getZendPackagesWhichWereReplacedByLaminasPackages($package);
            $composerFile = $composerFile->updateNonMigratedPackages($package);
            $zendDependenciesLeft = $this->extractZendDependencies($package);
            $zendPackagesToRemove = array_filter(
                $zendDependencies,
                static function ($packageName) use ($zendDependenciesLeft) {
                    return ! in_array($packageName, $zendDependenciesLeft, true);
                }
            );

            foreach ($zendPackagesToRemove as $zendPackage) {
                $laminasReplacement = $this->transformPackageName($zendPackage);
                $nextConstraint = $composerFile->getNextConstraintFromNonMigratedPackages($zendPackage);
                // There is no other (non-migrated) package which had a dependency on the zend package
                if ($nextConstraint === '') {
                    if ($this->confirmThatPackageMayBeRemoved($laminasReplacement, $packageName)) {
                        $this->laminasPackageRemovals[$laminasReplacement] = $zendPackage;
                    }
                    continue;
                }

                list($packageName, $versionConstraint) = explode(':', $nextConstraint, 2);

                $composerFile = $composerFile->withZendPackageReplacement(
                    $zendPackage,
                    $laminasReplacement,
                    $packageName,
                    $versionConstraint
                );
            }
        }

        $composerFile->store();

        return $composerFile;
    }

    private function extractZendDependencies(PackageInterface $package)
    {
        $packages = [];
        foreach ($package->getRequires() as $link) {
            if (!$this->isZendPackage($link->getTarget())) {
                continue;
            }

            $packages[] = $link->getTarget();
        }

        return $packages;
    }

    private function confirmThatPackageMayBeRemoved($laminasPackage, $dependant)
    {
        $this->output(
            sprintf(
                '<info>Laminas migration added package %s which can (probably) be removed'
                . ' due to uninstallation or update of the package %s.</info>',
                $laminasPackage,
                $dependant
            )
        );
        $this->output(
            '<error>WARNING! Please verify, that you are not using the laminas dependency'
            . ' in your project directly. If the package is removed, your code may break.</error>'
        );

        return $this->confirm(
            sprintf(
                'Do you want to remove %s from your dependencies? You will not be prompted again! (Y/n)', $laminasPackage
            )
        );
    }
}
