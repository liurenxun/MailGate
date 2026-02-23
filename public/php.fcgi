#!/usr/bin/sh
export PHP_FCGI_CHILDREN=0
export PHP_FCGI_MAX_REQUESTS=500
exec /usr/bin/php-fcgi8.3
