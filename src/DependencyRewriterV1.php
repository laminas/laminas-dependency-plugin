<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\DependencyResolver\Operation;
use Composer\Installer\InstallerEvent;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

use function get_class;
use function in_array;
use function sprintf;

final class DependencyRewriterV1 extends AbstractDependencyRewriter implements DependencySolvingCapableInterface
{
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
    public function onPrePackageInstallOrUpdate(PackageEvent $event)
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
        // @phpcs:ignore WebimpressCodingStandard.PHP.StaticCallback.Static
        (function ($object, $property, $value) {
            $object->$property = $value;
        })->bindTo($object, $object)($object, $property, $value);
    }
}
