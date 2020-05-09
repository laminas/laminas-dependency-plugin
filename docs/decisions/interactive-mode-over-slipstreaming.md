# Interactive mode over slipstreaming

## Status

accepted

## Context

With `composer` in v2.0, slipstreaming into the installation flow of composer does not work anymore. It is not possible to change the packages to be installed, why the initial method of this package does not work anymore.

## Decision

We change the way how this package interacts with projects. The project lets install any package but gathers, which of these have to be replaced with laminas packages.

After composer finishes with dumping the autoloader, this plugin asks the user (in case there were packages installed which had to be replaced) if he wants to replace zendframework packages.

The default answer is "yes" (in case, `composer install` was called with `--no-interaction`). 

If the answer is "yes", the following flow is executed:

1. Add laminas replacements to `composer.json` (with the locked version of the currently installed zend pendant)
2. Update `composer.lock` (which uninstalls zend and installs laminas packages)
3. Add laminas replacements to `composer.json` (with previous constraint of that package which required the zendframework package)
4. Update `composer.lock` (with `composer update --lock`) to synchronize `composer.lock` with `composer.json`

## Consequences

The user now has to interact with this plugin. We are not auto-replacing zend-packgages anymore.
This takes some more i/o as we have to write the `composer.json` and `composer.lock` multiple times.

This only happens once for the project OR if you add a non-migrated 3rd-party library, which contains not already replaced zend packages.

If the dependency is added to `composer.json`, there is no guarantee if the user will need the package replacement for upcoming releases of the 3rd party library. This is why we need to keep track on why a laminas replacement was added to the `composer.json`.
