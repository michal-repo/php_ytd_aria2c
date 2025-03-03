# php_ytd_aria2c

## If you liked it you can support my work
[!["Buy Me A Coffee"](https://raw.githubusercontent.com/michal-repo/random_stuff/refs/heads/main/bmac_small.png)](https://buymeacoffee.com/michaldev)

Simple PHP wrapper for [youtube-dl/yt-dlp](https://github.com/yt-dlp/yt-dlp) and [aria2c](https://github.com/aria2/aria2) running in daemon mode.
Useful for low speed connections if files have expiration date after download link is generated.
This tool will generate download link using [youtube-dl/yt-dlp](https://github.com/yt-dlp/yt-dlp) only if there is free slot in aria2c.



THIS APP IS NOT GOING TO WORK WITH YOUTUBE. Youtube is using multiple files (separate audio and video), Aria2c have no option to merge such sources. You need to use command line youtube-dl/yt-dlp to download and merge yt videos.
There are tons of other pages [that you can still download from](https://github.com/yt-dlp/yt-dlp/tree/master/yt_dlp/extractor).

# cron
Set-up cron job to process links from internal queue.
    */5 * * * * curl -X POST -d 'processOneFromInternalQueue=yes' -d 'uselockfile=yes' http://server.home/php_ytd_aria2c/

# app update
New update changes DB structure, remove old db file before using new version.

Set DB directory path in config file.
