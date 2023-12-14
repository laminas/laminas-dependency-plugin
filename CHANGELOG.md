# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 2.6.0 - 2023-12-14


-----

### Release Notes for [2.6.0](https://github.com/laminas/laminas-dependency-plugin/milestone/17)

Feature release (minor)

### 2.6.0

- Total issues resolved: **0**
- Total pull requests resolved: **1**
- Total contributors: **1**

#### Enhancement

 - [57: Update dependency php to ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0](https://github.com/laminas/laminas-dependency-plugin/pull/57) thanks to @renovate[bot]

## 2.5.0 - 2022-10-16


-----

### Release Notes for [2.5.0](https://github.com/laminas/laminas-dependency-plugin/milestone/15)

Feature release (minor)

### 2.5.0

- Total issues resolved: **0**
- Total pull requests resolved: **2**
- Total contributors: **2**

#### Enhancement

 - [50: Drop support php 7, add php 8.2 support](https://github.com/laminas/laminas-dependency-plugin/pull/50) thanks to @fezfez
 - [48: Apply PHP 7.4 syntax and typed property](https://github.com/laminas/laminas-dependency-plugin/pull/48) thanks to @samsonasik

## 2.4.0 - 2022-09-12


-----

### Release Notes for [2.4.0](https://github.com/laminas/laminas-dependency-plugin/milestone/13)

Feature release (minor)

### 2.4.0

- Total issues resolved: **0**
- Total pull requests resolved: **4**
- Total contributors: **2**

 - [47: Update minimum PHP to 7.4, update `laminas/laminas-coding-standard` to `~2.4.0`, restrict `composer` `&lt;2.3.0` in renovate](https://github.com/laminas/laminas-dependency-plugin/pull/47) thanks to @internalsystemerror

#### renovate

 - [45: Lock file maintenance](https://github.com/laminas/laminas-dependency-plugin/pull/45) thanks to @renovate[bot]
 - [43: Update dependency psalm/plugin-phpunit to ^0.17.0](https://github.com/laminas/laminas-dependency-plugin/pull/43) thanks to @renovate[bot]
 - [42: Configure Renovate](https://github.com/laminas/laminas-dependency-plugin/pull/42) thanks to @renovate[bot]

## 2.3.0 - 2022-07-14


-----

### Release Notes for [2.3.0](https://github.com/laminas/laminas-dependency-plugin/milestone/11)

Feature release (minor)

### 2.3.0

- Total issues resolved: **0**
- Total pull requests resolved: **2**
- Total contributors: **2**

#### Enhancement

 - [41: Mark package security-only](https://github.com/laminas/laminas-dependency-plugin/pull/41) thanks to @weierophinney
 - [39: Prepare for Renovate with reusable workflows](https://github.com/laminas/laminas-dependency-plugin/pull/39) thanks to @ghostwriter

## 2.2.0 - 2021-09-08


-----

### Release Notes for [2.2.0](https://github.com/laminas/laminas-dependency-plugin/milestone/7)

### Added

- This release adds support for PHP 8.1.

### 2.2.0

- Total issues resolved: **0**
- Total pull requests resolved: **2**
- Total contributors: **2**

#### Enhancement

 - [37: Provide PHP 8.1 support](https://github.com/laminas/laminas-dependency-plugin/pull/37) thanks to @weierophinney

#### dependencies

 - [35: build(deps-dev): bump composer/composer from 2.0.9 to 2.0.13](https://github.com/laminas/laminas-dependency-plugin/pull/35) thanks to @dependabot[bot]

## 2.1.2 - 2021-02-15


-----

### Release Notes for [2.1.2](https://github.com/laminas/laminas-dependency-plugin/milestone/8)

2.1.x bugfix release (patch)

### 2.1.2

- Total issues resolved: **0**
- Total pull requests resolved: **1**
- Total contributors: **1**

#### Bug,Enhancement

 - [32: bugfix: use proper methods to receive input option informations](https://github.com/laminas/laminas-dependency-plugin/pull/32) thanks to @boesing

## 2.1.1 - 2021-02-15

### Fixed

- [#29](https://github.com/laminas/laminas-dependency-plugin/pull/29) Pass `ignore-platform-reqs` **and/or** `ignore-platform-req=<requirement>` options to the `composer update --lock` command when these were originally passed to the composer command aswell.


-----

### Release Notes for [2.1.1](https://github.com/laminas/laminas-dependency-plugin/milestone/6)

2.1.x bugfix release (patch)

### 2.1.1

- Total issues resolved: **1**
- Total pull requests resolved: **1**
- Total contributors: **1**

#### Bug

 - [29: bugfix: pass `--ignore-platform-reqs` and `--ignore-platform-req` to `composer update --lock`](https://github.com/laminas/laminas-dependency-plugin/pull/29) thanks to @boesing

## 2.1.0 - 2020-11-02

### Added

- [#25](https://github.com/laminas/laminas-dependency-plugin/pull/25) adds support for PHP 8.

### Removed

- [#25](https://github.com/laminas/laminas-dependency-plugin/pull/25) removes support for PHP versions prior to 7.3.


-----

### Release Notes for [2.1.0](https://github.com/laminas/laminas-dependency-plugin/milestone/4)

Feature release (minor)

### 2.1.0

- Total issues resolved: **1**
- Total pull requests resolved: **2**
- Total contributors: **2**

#### Enhancement

 - [26: Add psalm integration](https://github.com/laminas/laminas-dependency-plugin/pull/26) thanks to @weierophinney and @boesing
 - [25: Add support for PHP 8](https://github.com/laminas/laminas-dependency-plugin/pull/25) thanks to @weierophinney

## 2.0.0 - 2020-10-30

### Added

- Adds support for Composer version 2 releases.


-----

### Release Notes for [2.0.0](https://github.com/laminas/laminas-dependency-plugin/milestone/2)



### 2.0.0

- Total issues resolved: **1**
- Total pull requests resolved: **1**
- Total contributors: **1**

#### Bug

 - [24: Fix: DependencyRewriterV1 should implement DependencySolvingCapableInterface](https://github.com/laminas/laminas-dependency-plugin/pull/24) thanks to @rieschl

## 2.0.0beta1 - 2020-07-01

### Added

- [#18](https://github.com/laminas/laminas-dependency-plugin/pull/18) adds support for Composer version 2 releases.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.0.4 - 2020-05-20

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#17](https://github.com/laminas/laminas-dependency-plugin/pull/17) fixes how the various Expressive packages referencing Zend Framework components are detected and rewritten, so that they now properly reference Laminas instead of Zend in the rewritten names.

## 1.0.3 - 2020-01-14

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#12](https://github.com/laminas/laminas-dependency-plugin/pull/12) adds exclusion for zendframework/zend-debug as it was not migrated to Laminas.

## 1.0.2 - 2020-01-07

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#10](https://github.com/laminas/laminas-dependency-plugin/pull/10) fixes a bad comparison in the `DependecyRewriterPlugin`.

## 1.0.1 - 2020-01-07

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#7](https://github.com/laminas/laminas-dependency-plugin/pull/7) adds exclusions for zendframework/zend-version, zendframework/zendservice-apple-apns, zendframework/zendservice-google-gcm, zfcampus/zf-apigility-example, zfcampus/zf-angular, and zfcampus/zf-deploy, as none of these packages were migrated to Laminas.

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
