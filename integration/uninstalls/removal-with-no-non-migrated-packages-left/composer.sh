#!/bin/bash
set -e
# Install all packages before removing
composer install --no-interaction --working-dir=$1

composer remove foo/bar --no-interaction --working-dir=$1
composer validate --no-check-all --working-dir=$1
