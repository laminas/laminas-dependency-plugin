# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 0.1.2 - 2019-10-29

### Added

- [#1](https://github.com/laminas/laminas-dependency-plugin/pull/1) adds support for PHP 5.6 and 7.0.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.1.1 - 2019-10-28

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Adds rewrite rules for known archived packages, ensuring the plugin will not attempt to rewrite those packages to Laminas variants.

## 0.1.0 - 2019-10-23

### Added

- Adds a pre-command-run listener in order to rewrite requests to install zendframework and zfcampus packages to their Laminas Project equivalents.

- Adds a pre-dependencies-solving listener in order to replace requests for zendframework and zfcampus packages with their Laminas Project equivalents.

- Adds a pre-package-install listener to intercept install requests for zendframework and zfcampus packages and replace them with Laminas Project equivalents.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.
