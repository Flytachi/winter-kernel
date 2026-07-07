#!/bin/sh
# FPM runtime entrypoint.
# Re-own the volatile store to winter (0700) before services boot, then hand off
# to runit which supervises syslog + php-fpm + nginx.
# Path = /tmp/flytachi.winter.volatile.<basename WORKDIR>.
VOL=/tmp/flytachi.winter.volatile.html
mkdir -p "$VOL"
chown -R winter:winter "$VOL"
chmod 0700 "$VOL"
exec runsvdir /etc/service
