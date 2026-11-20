#!/bin/sh
# Runs at every container start: prepares /data, creates or upgrades the database,
# prints the first-run setup code if no admin exists yet, then starts Apache.
set -e

if [ ! -w /data ]; then
    echo "ERROR: /data is not writable by $(id -un) (uid $(id -u))." >&2
    echo "With a bind mount, run on the host:  sudo chown -R 33:33 <your data folder>" >&2
    exit 1
fi
mkdir -p /data/sessions
php /app/bin/migrate.php

exec "$@"
