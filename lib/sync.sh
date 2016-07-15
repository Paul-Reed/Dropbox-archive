#!/bin/bash

date=$(date +"%d_%m_%Y_%H%M")
DIR="$(dirname $(readlink -f $0))"
cd $DIR
pwd

# Import user settings
eval `egrep '^([a-zA-Z_]+)="(.*)"' ../settings.conf`

# Dump DB file
mysqldump --lock-tables --user=$dbuser --password=$dbpass $dbname > ../$tdir/mysql/$dbname-$date.sql

# Archive node-red
if [ -d $NRdir -a $nodered = "Y" ];
then
cp -pr $NRdir $DIR/../$tdir/nodered
fi

# Prepare emoncms data archive and delete original
tar -czf ../backups/archive_$date.tar.gz -C ../$tdir/ .
rm -rf ../$tdir

# Upload the file to Dropbox
./dropbox_uploader.sh -sf /home/pi/.dropbox_uploader upload ../backups/ /

# Delete expired local archive files
cd ../backups
let keep=(24*60*$store)+30
find ./*.tar.gz -type f -mmin +$keep -exec rm {} \;

# Remove old Dropbox backups
pwd
backups=($(find *.gz)) # Array of current backups

dropboxfiles=($(../lib/./dropbox_uploader.sh -f /home/pi/.dropbox_uploader list /backups/ | awk 'NR!=1{ print $3 }')) # Array of Dropbox files

in_array() {
    local hay needle=$1
    shift
    for hay; do
        [[ $hay == $needle ]] && return 0
    done
    return 1
}

for i in "${dropboxfiles[@]}"
do
	in_array $i "${backups[@]}" && echo 'Keeping ' $i || ../lib/./dropbox_uploader.sh -f /home/pi/.dropbox_uploader delete /backups/$i
done
