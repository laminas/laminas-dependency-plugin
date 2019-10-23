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
