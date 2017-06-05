#!/bin/sh

BACKUPDIR="/var/www/maf/app/backups"
LOGDIR="/var/www/maf/app/logs"
APP="/var/www/maf/app/console"
DAY=`date +%a%H`

pg_dump -Fc -C maf | gzip > $BACKUPDIR/maf-$DAY.sql.gz

# So, this isn't nested in a user directory anymore, so let's find out if this is actually still needed.
# this permission system is so fucked up that just to be sure I have to run this every time or I get exceptions
# sudo setfacl -R -m u:www-data:rwX -m u:maf:rwX /home/maf/symfony/app/cache /home/maf/symfony/app/logs /home/maf/symfony/app/spool
# sudo setfacl -dR -m u:www-data:rwX -m u:maf:rwX /home/maf/symfony/app/cache /home/maf/symfony/app/logs /home/maf/symfony/app/spool

php $APP maf:process:expires --env=prod 2>&1 > $LOGDIR/turn-$DAY.log
php $APP maf:run -t -d turn --env=prod 2>&1 >> $LOGDIR/turn-$DAY.log
php $APP maf:process:economy --env=prod -t 2>&1 >> $LOGDIR/turn-$DAY.log
php $APP maf:cleanup --env=prod -d 2>&1 >> $LOGDIR/turn-$DAY.log
echo "----- turn done -----" >> $LOGDIR/turn-$DAY.log

# If you're on a server where you want mails, you may want to setup the below as appropriate.
# mail you@hostname.stuff -s 'MaF Turn' < $LOGDIR/turn-$DAY.log


php $APP maf:stats:turn --env=prod -d 2>&1 > $LOGDIR/stats.log

# map generation
curl -so ~/qgis/maps/allrealms.png "http://maps.mightandfealty.com/qgis?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=0,0,512000,512000&CRS=EPSG:4326&WIDTH=2048&HEIGHT=2048&LAYERS=water,blocked,AllRealms&FORMAT=image/png&map=MapWithRealms.qgs"
curl -so ~/qgis/maps/2ndrealms.png "http://maps.mightandfealty.com/qgis?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=0,0,512000,512000&CRS=EPSG:4326&WIDTH=2048&HEIGHT=2048&LAYERS=water,blocked,2ndLevelRealms&FORMAT=image/png&map=MapWithRealms.qgs"
curl -so ~/qgis/maps/majorrealms.png "http://maps.mightandfealty.com/qgis?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&BBOX=0,0,512000,512000&CRS=EPSG:4326&WIDTH=2048&HEIGHT=2048&LAYERS=water,blocked,MajorRealms&FORMAT=image/png&map=MapWithRealms.qgs"
convert ~/qgis/maps/allrealms.png -resize 256x256 ~/qgis/maps/allrealms-thumb.png
convert ~/qgis/maps/2ndrealms.png -resize 256x256 ~/qgis/maps/2ndrealms-thumb.png
convert ~/qgis/maps/majorrealms.png -resize 256x256 ~/qgis/maps/majorrealms-thumb.png

# For backup purposes, it may be super handy to download them backups! Or upload them, as is the case below.
# scp $BACKUPDIR/maf-$DAY.sql.gz your.host.org:~/backups/
