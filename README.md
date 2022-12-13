# php_ytd_aria2c
Simple PHP wrapper for youtube-dl and aria2c running in daemon mode.
Useful for low speed connections if files have expiration date after download link is generated.
This tool will generate download link using youtube-dl only if there is free slot in aria2c.

# cron
Set-up cron job to process links from internal queue.
    */5 * * * * curl -X POST -d 'processOneFromInternalQueue=yes' -d 'uselockfile=yes' http://server.home/php_ytd_aria2c/
