<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\ConstraintInterface;
use function array_keys;
use function array_map;
use function array_replace_recursive;
use function in_array;
use function ksort;

final class ComposerFile
{
    /**
     * @var array
     */
    private $composerDefinition;

    /**
     * @var JsonFile
     */
    private $file;

    /**
     * Flag to see if we have to write the file back to filesystem or not.
     * @var bool
     */
    private $dirty = false;

    public function __construct(JsonFile $file)
    {
        $this->file = $file;
        $definition = $file->read();
        assert(is_array($definition));
        $this->composerDefinition = $definition;
    }

    /**
     * @param string $package
     * @param string $packageReplacement
     * @param string $packageWhyZendPackageIsInstalled
     * @parma string $versionConstraint
     * @return self
     */
    public function withZendPackageReplacement(
        $package,
        $packageReplacement,
        $packageWhyZendPackageIsInstalled,
        $versionConstraint
    ) {
        $instance = clone $this;

        $dev = isset($instance->composerDefinition['require-dev'][$packageWhyZendPackageIsInstalled]);

        $key = $dev ? 'require-dev' : 'require';
        $instance->composerDefinition[$key][$packageReplacement] = $versionConstraint;
        unset($instance->composerDefinition[$key][$package]);

        return $instance;
    }

    public function store()
    {
        if (!$this->dirty) {
            return;
        }

        $definition = $this->sortPackages($this->composerDefinition);
        $definition = $this->cleanup($definition);
        $this->file->write($definition);
        $this->dirty = false;
    }

    /**
     * @return array
     */
    private function sortPackages(array $composerDefinition)
    {
        if (! isset($composerDefinition['config']['sort-packages'])
            || $composerDefinition['config']['sort-packages'] === false
        ) {
            return $composerDefinition;
        }

        if (isset($composerDefinition['extra']['laminas-migration']['nonMigratedPackages'])) {
            $nonMigratedPackages = $composerDefinition['extra']['laminas-migration']['nonMigratedPackages'];
            foreach ($nonMigratedPackages as $package => $packages) {
                ksort($packages);
                $nonMigratedPackages[$package] = $packages;
            }

            ksort($nonMigratedPackages);
            $composerDefinition['extra']['laminas-migration']['nonMigratedPackages'] = $nonMigratedPackages;
        }

        if (isset($composerDefinition['require'])) {
            ksort($composerDefinition['require']);
        }

        if (isset($composerDefinition['require-dev'])) {
            ksort($composerDefinition['require-dev']);
        }

        return $composerDefinition;
    }

    /**
     * @param Link[] $packageLinksWhichRequireZendPackage
     * @return self
     */
    public function rememberNonMigratedPackages(array $packageLinksWhichRequireZendPackage)
    {
        $instance = clone $this;
        $composerDefinition = $this->composerDefinition;

        foreach ($packageLinksWhichRequireZendPackage as $packageLink) {
            $composerDefinition = $this->rememberPackage(
                $composerDefinition,
                $packageLink->getSource(),
                $packageLink->getTarget(),
                $packageLink->getPrettyConstraint()
            );
        }

        $instance->composerDefinition = $composerDefinition;

        return $instance;
    }

    /**
     * @param array  $composerDefinition
     * @param string $packageNameWhichRequiresZendPackage
     * @param string $mostOuterZendPackageReplacement
     * @param string $versionConstraint
     * @return array
     */
    private function rememberPackage(
        array $composerDefinition,
        $packageNameWhichRequiresZendPackage,
        $mostOuterZendPackageReplacement,
        $versionConstraint
    ) {
        $definition = [
            'extra' => [
                'laminas-migration' => [
                    'nonMigratedPackages' => [
                        $packageNameWhichRequiresZendPackage => [
                            $mostOuterZendPackageReplacement => $versionConstraint,
                        ],
                    ],
                ],
            ],
        ];

        return array_replace_recursive($composerDefinition, $definition);
    }

    /**
     * Returns all packages which were added to composer.json requirements to replace zend-packages.
     * @return array
     */
    public function getZendPackagesWhichWereReplacedByLaminasPackages(PackageInterface $package)
    {
        $packages = $this->extractNonMigratedPackages($this->composerDefinition);
        if (!isset($packages[$package->getName()])) {
            return [];
        }

        return array_keys($packages[$package->getName()]);
    }

    /**
     * In case a package is being uninstalled, we can just forget it.
     * @param string $package
     * @return self
     */
    public function forgetNonMigratedPackage($package)
    {
        $instance = clone $this;
        unset($instance->composerDefinition['extra']['laminas-migration']['nonMigratedPackages'][$package]);

        return $instance;
    }

    public function withoutPackageRequirements(array $packagesToRemove)
    {
        if (!$packagesToRemove) {
            return $this;
        }

        $instance = clone $this;
        $composerDefinition = $instance->composerDefinition;

        $nonMigratedPackages = $instance->extractNonMigratedPackages($composerDefinition);
        foreach ($packagesToRemove as $zendPackageName => $laminasPackageName) {
            unset(
                $composerDefinition['require'][$laminasPackageName],
                $composerDefinition['require-dev'][$laminasPackageName]
            );

            foreach ($nonMigratedPackages as $nonMigratedPackage => $nonMigratedPackageDependencies) {
                if (!isset($nonMigratedPackageDependencies[$zendPackageName])) {
                    continue;
                }

                unset($nonMigratedPackageDependencies[$zendPackageName]);
                if (empty($nonMigratedPackageDependencies)) {
                    unset($nonMigratedPackages[$nonMigratedPackage]);
                }
            }
        }

        $instance->composerDefinition = $composerDefinition;
        $instance->setNonMigratedPackages($nonMigratedPackages);

        return $instance;
    }

    /**
     * @return array
     */
    private function extractNonMigratedPackages(array $definition)
    {
        if (!isset($definition['extra']['laminas-migration']['nonMigratedPackages'])) {
            return [];
        }

        return $definition['extra']['laminas-migration']['nonMigratedPackages'];
    }

    private function setNonMigratedPackages(array $nonMigratedPackages)
    {
        $this->composerDefinition['extra']['laminas-migration']['nonMigratedPackages'] = $nonMigratedPackages;
    }

    private function cleanup(array $composerDefinition)
    {
        $nonMigratedPackages = $this->extractNonMigratedPackages($composerDefinition);
        if (!$nonMigratedPackages) {
            unset($composerDefinition['extra']['laminas-migration']);
        }

        if (empty($composerDefinition['extra'])) {
            unset($composerDefinition['extra']);
        }

        return $composerDefinition;
    }

    public function updateNonMigratedPackages(PackageInterface $package)
    {
        $nonMigratedPackages = $this->extractNonMigratedPackages($this->composerDefinition);
        if (!isset($nonMigratedPackages[$package->getName()])) {
            return $this;
        }

        $instance = clone $this;
        $zendPackages = $nonMigratedPackages[$package->getName()];

        $packageRequirements = array_map(static function (Link $link) {
            return $link->getTarget();
        }, $package->getRequires());


        foreach ($zendPackages as $zendPackage => $constraint) {
            if (in_array($zendPackages, $packageRequirements, true)) {
                continue;
            }

            unset($zendPackages[$zendPackage]);
        }

        if (!$zendPackages) {
            unset($nonMigratedPackages[$package->getName()]);
            $instance->setNonMigratedPackages($nonMigratedPackages);
            return $instance;
        }

        $nonMigratedPackages[$package->getName()] = $nonMigratedPackages;
        $instance->setNonMigratedPackages($nonMigratedPackages);
        return $instance;
    }

    public function getNextConstraintFromNonMigratedPackages($zendPackage)
    {
        $nonMigratedPackages = $this->extractNonMigratedPackages($this->composerDefinition);

        $constraints = [];
        foreach ($nonMigratedPackages as $packageName => $zendPackages) {
            if (!isset($zendPackages[$zendPackage])) {
                continue;
            }

            $constraints[$packageName] = $zendPackages[$zendPackage];
        }

        if (!$constraints) {
            return '';
        }

        $parser = new VersionParser();
        /**
         * @var ConstraintInterface[] $constraints
         * @psalm-var array<string,ConstraintInterface> $constraints
         */
        $constraints = array_map([$parser, 'parseConstraints'], $constraints);
        $lowest = key($constraints);
        foreach ($constraints as $packageName => $constraint) {
            if ($packageName === $lowest) {
                continue;
            }

            $lowestConstraint = $constraints[$lowest];

            $from = $constraint->getLowerBound()->getVersion();
            $to = $lowestConstraint->getLowerBound()->getVersion();
            if (VersionParser::isUpgrade($from, $to)) {
                continue;
            }

            $lowest = $packageName;
        }

        return sprintf('%s:%s', $lowest, $constraints[$lowest]->getPrettyString());
    }

    public function __clone()
    {
        $this->dirty = true;
    }
}
