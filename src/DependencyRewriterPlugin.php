<?php
/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

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
    /** Composer */
    private $composer;

    /** @var string[] */
    private $ignore = [
        'zfcampus/zf-console',
    ];

    /** IOInterface */
    private $io;

    public static function getSubscribedEvents() : array
    {
        return [
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => ['onPreDependenciesSolving', 1000],
            PackageEvents::PRE_PACKAGE_INSTALL => ['onPrePackageInstall', 1000],
            PluginEvents::PRE_COMMAND_RUN => ['onPreCommandRun', 1000],
        ];
    }

    public function activate(Composer $composer, IOInterface $io) : void
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
    public function onPreCommandRun(PreCommandRunEvent $event) : void
    {
        if ('require' !== $event->getCommand()) {
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
    public function onPreDependenciesSolving(InstallerEvent $event) : void
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

        $this->updateProperty($request, 'jobs', $jobs);
    }

    /**
     * Ensure nested dependencies on ZF packages install equivalent Laminas packages.
     *
     * When a 3rd party package has dependencies on ZF packages, this method
     * will detect the request to install a ZF package, and rewrite it to use a
     * Laminas variant at the equivalent version, if one exists.
     */
    public function onPrePackageInstall(PackageEvent $event) : void
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

        if (null === $replacementPackage) {
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

        $this->updatePackageFromReplacement($package, $replacementPackage);
    }

    /**
     * Parses a package argument from the command line, replacing it with the
     * Laminas variant if it exists.
     */
    private function updatePackageArgument(string $package) : string
    {
        $result = preg_split('/[ :=]/', $package, 2);
        if (false === $result) {
            return $package;
        }
        $name = array_shift($result);

        if (! $this->isZendPackage($name)) {
            return $package;
        }

        $replacementName = $this->transformPackageName($name);
        $version = count($result) ? array_shift($result) : null;

        if (null === $version) {
            return $replacementName;
        }

        return sprintf('%s:%s', $replacementName, $version);
    }

    private function isZendPackage(string $name) : bool
    {
        if (! preg_match('#^(zendframework|zfcampus)/#', $name)
            || in_array($name, $this->ignore, true)
        ) {
            return false;
        }

        return true;
    }

    private function transformPackageName(string $name) : string
    {
        switch ($name) {
            case 'zendframework/zenddiagnostics':
                return 'laminas/laminas-diagnostics';
            case 'zendframework/zendoauth':
                return 'laminas/laminas-oauth';
            case 'zendframework/zendservice-apple-apns':
                return 'laminas/laminas-apple-apns';
            case 'zendframework/zendservice-google-gcm':
                return 'laminas/laminas-google-gcm';
            case 'zendframework/zendservice-recaptcha':
                return 'laminas/laminas-recaptcha';
            case 'zendframework/zendservice-twitter':
                return 'laminas/laminas-twitter';
            case 'zendframework/zendxml':
                return 'laminas/laminas-xml';
            case 'zendframework/zend-expressive':
                return 'expressive/expressive';
            case 'zendframework/zend-problem-details':
                return 'expressive/expressive-problem-details';
            case 'zfcampus/zf-apigilty':
                return 'apigility/apigility';
            case 'zfcampus/zf-composer-autoloading':
                return 'laminas/laminas-composer-autoloading';
            case 'zfcampus/zf-deploy':
                return 'laminas/laminas-deploy';
            case 'zfcampus/zf-development-mode':
                return 'laminas/laminas-development-mode';
            default:
                if (preg_match('#^zendframework/zend-expressive-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('expressive/expressive-%s', $matches['name']);
                }
                if (preg_match('#^zfcampus/zf-apigility-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('apigility/apigility-%s', $matches['name']);
                }
                if (preg_match('#^zfcampus/zf-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('apigility/apigility-%s', $matches['name']);
                }
                if (preg_match('#^zendframework/zend-(?<name>.*)$#', $name, $matches)) {
                    return sprintf('laminas/laminas-%s', $matches['name']);
                }
                return $name;
        }
    }

    private function updatePackageFromReplacement(PackageInterface $original, PackageInterface $replacement)
    {
        $this->updateProperty($original, 'name', $replacement->getName());
        $this->updateProperty($original, 'prettyName', $replacement->getPrettyName());
        $original->replaceVersion($replacement->getVersion(), $replacement->getPrettyVersion());
    }

    /**
     * @param mixed $value
     */
    private function updateProperty(object $object, string $property, $value) : void
    {
        $r = new ReflectionProperty($object, $property);
        $r->setAccessible(true);
        $r->setValue($object, $value);
    }

    private function output(string $message, int $verbosity = IOInterface::NORMAL) : void
    {
        $this->io->write($message, $newline = true, $verbosity);
    }
}
