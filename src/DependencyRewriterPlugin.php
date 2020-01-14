<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use ReflectionProperty;

use function array_map;
use function array_shift;
use function count;
use function get_class;
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
     * @return array Returns in following format:
     *     <string> => array<string, int>
     */
    public static function getSubscribedEvents()
    {
        return [
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => ['onPreDependenciesSolving', 1000],
            PackageEvents::PRE_PACKAGE_INSTALL => ['onPrePackageInstall', 1000],
            PluginEvents::PRE_COMMAND_RUN => ['onPreCommandRun', 1000],
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
     * Replace ZF packages present in the composer.json during install or
     * update operations.
     *
     * When the `composer.json` has references to ZF packages, and the user
     * requests an `install` or `update`, this method will rewrite any such
     * packages to their Laminas equivalents prior to attempting to resolve
     * dependencies, ensuring the Laminas versions are installed.
     */
    public function onPreDependenciesSolving(InstallerEvent $event)
    {
        $this->output(sprintf('<info>In %s</info>', __METHOD__), IOInterface::DEBUG);
        $request = $event->getRequest();
        $jobs = $request->getJobs();
        $changes = false;

        foreach ($jobs as $index => $job) {
            if (! isset($job['cmd']) || ! in_array($job['cmd'], ['install', 'update'], true)) {
                continue;
            }

            if (! isset($job['packageName'])) {
                continue;
            }

            $name = $job['packageName'];
            if (! $this->isZendPackage($name)) {
                continue;
            }

            $replacementName = $this->transformPackageName($name);
            if ($replacementName === $name) {
                continue;
            }

            $this->output(sprintf(
                '<info>Replacing package "%s" with package "%s"</info>',
                $name,
                $replacementName
            ), IOInterface::VERBOSE);

            $job['packageName'] = $replacementName;
            $jobs[$index] = $job;
            $changes = true;
        }

        if (! $changes) {
            return;
        }

        $this->updateProperty($request, 'jobs', $jobs);
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

        $name = $package->getName();
        if (! $this->isZendPackage($name)) {
            // Nothing to do
            $this->output(sprintf(
                '<info>Exiting; package "%s" does not have a replacement</info>',
                $name
            ), IOInterface::DEBUG);
            return;
        }

        $replacementName = $this->transformPackageName($name);
        if ($replacementName === $name) {
            // Nothing to do
            $this->output(sprintf(
                '<info>Exiting; while package "%s" is a ZF package, it does not have a replacement</info>',
                $name
            ), IOInterface::DEBUG);
            return;
        }

        $version = $package->getVersion();
        $replacementPackage = $this->composer->getRepositoryManager()->findPackage($replacementName, $version);

        if ($replacementPackage === null) {
            // No matching replacement package found
            $this->output(sprintf(
                '<info>Exiting; no replacement package found for package "%s" with version %s</info>',
                $replacementName,
                $version
            ), IOInterface::DEBUG);
            return;
        }

        $this->output(sprintf(
            '<info>Replacing package %s with package %s, using version %s</info>',
            $name,
            $replacementName,
            $version
        ), IOInterface::VERBOSE);

        $this->replacePackageInOperation($replacementPackage, $operation);
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

    private function replacePackageInOperation(PackageInterface $replacement, Operation\OperationInterface $operation)
    {
        $this->updateProperty(
            $operation,
            $operation instanceof Operation\UpdateOperation ? 'targetPackage' : 'package',
            $replacement
        );
    }

    /**
     * @param object $object
     * @param string $property
     * @param mixed $value
     */
    private function updateProperty($object, $property, $value)
    {
        $r = new ReflectionProperty($object, $property);
        $r->setAccessible(true);
        $r->setValue($object, $value);
    }

    /**
     * @param string $message
     * @param int $verbosity
     */
    private function output($message, $verbosity = IOInterface::NORMAL)
    {
        $this->io->write($message, $newline = true, $verbosity);
    }
}
