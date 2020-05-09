#!/bin/bash
set -e
composer install --no-interaction --working-dir=$1
composer require bar/baz:^2.0 --no-interaction --working-dir=$1
composer validate --no-check-all --working-dir=$1
