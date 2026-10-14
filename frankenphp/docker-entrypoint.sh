#!/bin/sh
set -e

# Prod: like the linuxserver images, PUID/PGID pick the user.
# It gets the mounted folders (/data, /config) and var/, then the app runs under its name.
if [ "$(id -u)" = '0' ] && [ "$APP_ENV" = 'prod' ]; then
	PUID=${PUID:-33}
	PGID=${PGID:-33}
	chown -R "$PUID:$PGID" /data /config var
	exec setpriv --reuid="$PUID" --regid="$PGID" --clear-groups "$0" "$@"
fi

# The Mercure hub checks the app's tokens against the app's own secret, unless given its keys
export MERCURE_PUBLISHER_JWT_KEY="${MERCURE_PUBLISHER_JWT_KEY:-$MERCURE_JWT_SECRET}"
export MERCURE_SUBSCRIBER_JWT_KEY="${MERCURE_SUBSCRIBER_JWT_KEY:-$MERCURE_JWT_SECRET}"

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
