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
use Composer\Plugin\PluginInterface;
use ReflectionProperty;

class DependencyRewriterPlugin implements EventSubscriberInterface, PluginInterface
{
    /** Composer */
    private $composer;

    private $ignore = [
        'zfcampus/zf-console',
    ];

    /** IOInterface */
    private $io;

    public static function getSubscribedEvents() : array
    {
        return [
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => ['onPreDependenciesSolving', 1000],
            PackageEvents::PRE_PACKAGE_INSTALL        => ['onPrePackageInstall', 1000],
        ];
    }

    public function activate(Composer $composer, IOInterface $io) : void
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->io->write(sprintf('<info>Activating %s</info>', __CLASS__));
    }

    public function onPreDependenciesSolving(InstallerEvent $event) : void
    {
        $this->io->write(sprintf('<info>In %s</info>', __METHOD__));
        $request = $event->getRequest();
        $jobs    = $request->getJobs();
        $changes = false;

        foreach ($jobs as $index => $job) {
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

            $this->io->write(sprintf(
                '<info>Replacing package "%s" with package "%s"</info>',
                $name,
                $replacementName
            ));
            $job['packageName'] = $replacementName;
            $jobs[$index]       = $job;
            $changes            = true;
        }

        $this->updateProperty($request, 'jobs', $jobs);
    }

    public function onPrePackageInstall(PackageEvent $event) : void
    {
        $this->io->write(sprintf('<info>In %s</info>', __METHOD__));
        $operation = $event->getOperation();

        switch (true) {
            case ($operation instanceof Operation\InstallOperation):
                $package = $operation->getPackage();
                break;
            case ($operation instanceof Operation\UpdateOperation):
                $package = $operation->getTargetPackage();
                break;
            default:
                // Nothing to do
                $this->io->write(sprintf(
                    '<info>Exiting; operation of type %s not supported</info>',
                    get_class($operation)
                ));
                return;
        }

        $name = $package->getName();
        if (! $this->isZendPackage($name)) {
            // Nothing to do
            $this->io->write(sprintf(
                '<info>Exiting; package "%s" does not have a replacement</info>',
                $name
            ));
            return;
        }

        $replacementName = $this->transformPackageName($name);
        if ($replacementName === $name) {
            // Nothing to do
            $this->io->write(sprintf(
                '<info>Exiting; while package "%s" is a ZF package, it does not have a replacement</info>',
                $name
            ));
            return;
        }

        $version = $package->getVersion();
        $replacementPackage = $this->composer->getRepositoryManager()->findPackage($replacementName, $version);

        if (null === $replacementPackage) {
            // No matching replacement package found
            $this->io->write(sprintf(
                '<info>Exiting; no replacement package found for package "%s" with version %s</info>',
                $replacementName,
                $version
            ));
            return;
        }

        $this->io->write(sprintf(
            '<info>Replacing package %s with package %s, using version %s</info>',
            $name,
            $replacementName,
            $version
        ));

        $this->updatePackageFromReplacement($package, $replacementPackage);
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
}
