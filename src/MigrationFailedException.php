<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use RuntimeException;
use function implode;

final class MigrationFailedException extends RuntimeException
{

    public static function lockFileUpdateFailed()
    {
        return new self('Migration failed. Could not update `composer.lock`!');
    }

    public static function packageRemovalFailed(array $packages)
    {
        return new self(
            sprintf(
                'Migration failed. Could not remove the following packages: %s',
                implode(', ', $packages)
            )
        );
    }
}
