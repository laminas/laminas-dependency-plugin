#!/bin/bash
INTEGRATION_TESTS_BY_TYPE_DIRECTORY="$PWD/integration"

EXIT_CODE=0

for INTEGRATION_TESTS_DIRECTORY in $(find $INTEGRATION_TESTS_BY_TYPE_DIRECTORY -maxdepth 1 -mindepth 1 -type d); do

    echo "Running $(basename $INTEGRATION_TESTS_DIRECTORY) integration tests"

    for INTEGRATION_TEST_DIRECTORY in $(find $INTEGRATION_TESTS_DIRECTORY -maxdepth 1 -mindepth 1 -type d); do

        echo "> Running $(basename $INTEGRATION_TEST_DIRECTORY) integration test"
        COMPOSER_SCRIPT=$INTEGRATION_TESTS_DIRECTORY/composer.sh

        # If there is an integration test specific composer.sh, use that!
        if [ -e "$INTEGRATION_TEST_DIRECTORY/composer.sh" ]; then
            COMPOSER_SCRIPT=$INTEGRATION_TEST_DIRECTORY/composer.sh;
        fi

        bash $COMPOSER_SCRIPT $INTEGRATION_TEST_DIRECTORY && diff $INTEGRATION_TEST_DIRECTORY/composer.json $INTEGRATION_TEST_DIRECTORY/composer.json.out

        if [ "$?" -ne 0 ]; then
            EXIT_CODE=1
        fi
    done
done

exit $EXIT_CODE
