<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Package\Link;

final class ComposerInstallationDetails
{
    /**
     * Marker to see if the package is required by a dev dependency.
     *
     * @var bool
     */
    public $dev;

    /**
     * Zend package name which is required by a 3rd party library.
     * This package probably replaces other packages if required.
     *
     * @var string
     */
    public $mostOuterZendPackageName;

    /**
     * Version constraint which we have to use to put into composer.json
     *
     * @var string
     */
    public $versionConstraint;

    /**
     * Packages which are also replaced if we require the package inside
     * {@see ComposerInstallationDetails::$mostOuterZendPackageName}
     *
     * @var array
     */
    public $packagesWhichWillBeReplaced;

    /**
     * Contains the package name of the 3rd-party library which requires the
     * {@see ComposerInstallationDetails::$mostOuterZendPackageName}.
     *
     * @var Link[]
     */
    public $packageLinksWhichRequireZendPackage;

    /**
     * @param bool $dev
     * @param string $mostOuterZendPackageName
     * @param string $versionConstraint
     * @param array  $packagesWhichWillBeReplaced
     * @param Link[] $packageLinksWhichRequireZendPackage
     */
    private function __construct(
        $dev,
        $mostOuterZendPackageName,
        $versionConstraint,
        array $packagesWhichWillBeReplaced,
        array $packageLinksWhichRequireZendPackage
    ) {
        $this->dev = $dev;
        $this->mostOuterZendPackageName = $mostOuterZendPackageName;
        $this->versionConstraint = $versionConstraint;
        $this->packagesWhichWillBeReplaced = $packagesWhichWillBeReplaced;
        $this->packageLinksWhichRequireZendPackage = $packageLinksWhichRequireZendPackage;
    }

    /**
     * @param bool $dev
     * @param string $mostOuterZendPackageName
     * @param string $versionConstraint
     * @param array  $packagesWhichWillBeReplaced
     * @param Link[] $packageLinksWhichRequireZendPackage
     * @return self
     */
    public static function create(
        $dev,
        $mostOuterZendPackageName,
        $versionConstraint,
        array $packagesWhichWillBeReplaced,
        array $packageLinksWhichRequireZendPackage
    ) {
        return new self(
            $dev,
            $mostOuterZendPackageName,
            $versionConstraint,
            $packagesWhichWillBeReplaced,
            $packageLinksWhichRequireZendPackage
        );
    }
}
