# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 1.0.0 - 2019-12-31

### Added

- First stable release.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.2.0 - 2019-12-02

### Added

- Nothing.

### Changed

- [#3](https://github.com/laminas/laminas-dependency-plugin/pull/3) updates the tooling to rewrite Apigility packages to reference laminas-api-tools and api-tools.

- [#3](https://github.com/laminas/laminas-dependency-plugin/pull/3) updates the tooling to rewrite Expressive packages to reference Mezzio.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.1.3 - 2019-11-01

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#2](https://github.com/laminas/laminas-dependency-plugin/pull/2) fixes how package replacements are slip-streamed in, ensuring nested dependencies use the correct packages. Previously, Composer would report the replacement, but the original ZF package would actually be installed.

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
