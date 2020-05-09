# Removing unit-testing dev-dependencies

## Status

proposed

## Context

With `composer` in v2.0, a removal of packages without existing `composer.lock` is not allowed.

```
Cannot update only a partial set of packages without a lock file present.
```

## Decision

We remove the dev-dependencies for unit-testing by default and require them in travis builds.

## Consequences

Every contributor has to manually add the dependencies if he wants to execute unit tests.
