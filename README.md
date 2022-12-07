# php_ytd_aria2c
Simple PHP wrapper for youtube-dl and aria2c running in daemon mode

# cron
    5 * * * * curl -X POST -d 'processOneFromInternalQueue=yes' -d 'consoleOut=yes' http://server.home/php_ytd_aria2c/