# laminas-dependency-plugin

This Composer plugin, when enabled in a project, intercepts requests to install
packages from the zendframework and zfcampus vendors, and will replace them with
the equivalents from the Laminas Project.

## Installation

```bash
$ composer require laminas/laminas-dependency-plugin
```

## Removal

If you no longer want to use the plugin (e.g., if no packages you use or plan to
use will have dependencies on legacy Zend Framework packages):

```bash
$ composer remove laminas/laminas-dependency-plugin
```

## What it does

### zendframework and zfcampus packages listed in the composer.json

For zendframework and zfcampus packages listed in the `composer.json` file, the
plugin will identify the equivalent package from the Laminas Project, and
slip-stream it in during when the user performs either a `composer install` or
`composer update`.

When this occurs, `composer show` will list the package installed. However, the
`composer.json` will still list the zendframework or zfcampus package(s)
originally present. To correct this situation, we recommend using the
laminas-transfer `migrate` tooling.

If you do not want this behavior, use the `--no-plugins` option when running
your `composer install` or `composer update` operations.

### zendframework and zfcampus packages requested via require operations

When a user runs `composer require` and lists a zendframework or zfcampus
package, the plugin will replace the package requested with the Laminas Project
equivalent. The replacement package will both be installed as well as added to
the `composer.json`.

If you do not want this behavior, use the `--no-plugins` option when running
your `composer require` operation.

### zendframework and zfcampus packages installed as dependencies of other packages

When a third-party package has dependencies on zendframework or zfcampus
packages, the plugin will detect request for these packages and replace them
with the Laminas Project equivalents prior to installation. Since these use the
laminas-zendframework-bridge, all code should continue to work without issues.

If you do not want this behavior, use the `--no-plugins` option when running
your `composer require` operation.
