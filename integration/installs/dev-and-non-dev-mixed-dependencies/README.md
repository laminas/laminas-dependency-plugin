# laminas/project-with-mixed-dependencies-on-the-same-package

This package depends on both a `require` and a `dev-require` package.
As at least one `require` package is present, the dependency plugin has to put the `laminas-mvc` dependency to the `require` dependencies.
