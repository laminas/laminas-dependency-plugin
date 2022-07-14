#!/bin/bash

set -e

composer_version=$(composer --version | cut -d' ' -f3)

if [[ $composer_version =~ ^2\.(3|4|5|6|7|8|9) ]];then
    echo "Rolling Composer version back to 2.2 LTS"
    composer self-update --2.2
fi
