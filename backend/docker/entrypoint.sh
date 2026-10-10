#!/bin/sh
# Backend container entrypoint.
#
# Purpose: warm the opcode cache before real traffic arrives.
#
# Measured problem: the app tree is a bind mount (9p/drvfs from the Windows host)
# holding ~15.5k PHP files. On a cold FPM pool the first request compiles the
# framework into the opcode cache, which took ~10.9s. opcache is shared between
# FPM workers so that cost is paid once per container start -- but it lands on
# whichever unlucky user request happens to arrive first.
#
# So a few cheap requests are fired in the background right after FPM binds,
# moving that cost to boot time where nobody waits for it. The warmed routes
# touch the framework, HTTP kernel, router and Eloquent layer -- most of what a
# real API request needs -- and failures are ignored on purpose, because the
# database may not be accepting connections yet at boot.
#
# These have to be real FastCGI requests: FPM speaks the binary FastCGI protocol
# on port 9000, so plain HTTP written to that port is discarded without ever
# running index.php. cgi-fcgi (libfcgi-bin) does it properly. An earlier version
# of this script wrote raw HTTP to the socket and left the cold start at 10.9s.

set -e

(
    # Wait for FPM to accept connections.
    i=0
    while [ "$i" -lt 150 ]; do
        if php -r '$f=@fsockopen("127.0.0.1",9000,$e,$s,1); if($f){fclose($f); exit(0);} exit(1);' 2>/dev/null; then
            break
        fi
        i=$((i + 1))
        sleep 0.2
    done

    # Laravel boots its service providers on the first request; a couple of hits
    # also spread the work across the pool's workers. These are fired concurrently
    # because the cost is dominated by stat() calls on the bind mount, which
    # overlap fine, and sequential warm-up would leave real traffic contending
    # with the compile for twice as long.
    for uri in "/up" "/api/properties?per_page=1" "/up" "/api/properties?per_page=1"; do
        (
            SCRIPT_FILENAME=/var/www/public/index.php \
            SCRIPT_NAME=/index.php \
            REQUEST_METHOD=GET \
            REQUEST_URI="$uri" \
            QUERY_STRING= \
            DOCUMENT_ROOT=/var/www/public \
            SERVER_PROTOCOL=HTTP/1.1 \
            GATEWAY_INTERFACE=CGI/1.1 \
            SERVER_SOFTWARE=warmup \
            REMOTE_ADDR=127.0.0.1 \
            SERVER_NAME=localhost \
            SERVER_PORT=80 \
            HTTP_HOST=localhost \
            CONTENT_LENGTH=0 \
            cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null 2>&1
        ) &
    done
    wait
) &

# FPM stays PID 1 in the foreground so `docker stop` delivers SIGTERM to it.
exec php-fpm -F
