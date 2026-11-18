#!/bin/sh
# O script nativo roda mesmo quando origin/branch já aponta HEAD.
exec node scripts/quality/pre-push.mjs bash scripts/quality/with_local_config.sh composer check:all
