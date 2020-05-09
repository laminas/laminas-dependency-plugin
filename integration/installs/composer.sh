#!/bin/bash
set -e
composer install --no-interaction --working-dir=$1
composer validate --no-check-all --working-dir=$1
