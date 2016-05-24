<?php
    /*

    All Emoncms code is released under the GNU Affero General Public License.
    See COPYRIGHT.txt and LICENSE.txt.

    ---------------------------------------------------------------------
    Emoncms - open source energy visualisation
    Part of the OpenEnergyMonitor project:
    http://openenergymonitor.org

    */

    require "config.php";
    include "lib/metadata.php";
    include "lib/mysql.php";
    include "lib/phptimeseries.php";
    include "lib/phptimestore.php";
    include "lib/phpfiwa.php";
    include "lib/phpfina.php";
    include "lib/inputs.php";

    // Locate where script is installed

    $cdir = realpath(dirname(__FILE__)); // Current File Directory
    $tdir = "temp_data";                 // Temporary data Directory
    chdir ("$cdir");                     // Move current working directory

    // On first run enter dropbox configuration

    if (!file_exists("/home/pi/.dropbox_uploader")) {
    echo "\n\n\n";
    echo "Before using this script, it is necessary to\n";
    echo "configure your Dropbox API to allow backups\n";
    echo "to be uploaded.\n";
    echo "To configure the script, run;\n\n";
    echo "$cdir/lib/./dropbox_uploader.sh\n\n";
    echo "and follow the prompts.\n\n\n";
    die;
    }

    // Create temp Directory structure

    $Createdir = array(
      "$tdir/phpfina",
      "$tdir/phpfiwa",
      "$tdir/phptimeseries",
      "$tdir/phptimestore",
      "$tdir/mysql",
      "$tdir/nodered"
    );
    $permissions = 0755;
    foreach ($Createdir as $dir) {
      mkdir($dir, $permissions, TRUE);
    }

    if (!file_exists("backups")) {
    mkdir("backups", 0777, true);
    }

    $date = date("d-m-Y_Hi");

    //Set up emoncms  backup directory array

    $engines = array(
        'phpfiwa'=>array(
            'datadir'=> "$tdir/phpfiwa/"
         ),
        'phpfina'=>array(
            'datadir'=> "$tdir/phpfina/"
         ),
        'phptimeseries'=>array(
            'datadir'=> "$tdir/phptimeseries/"
         ),
        'phptimestore'=>array(
            'datadir'=> "$tdir/timestore/"
         )
    );

    $mysqli = false;
    $redis = false;

    // Fetch remote server feed list

    $feeds = file_get_contents($emoncms_server."/feed/list.json?apikey=$emoncms_apikey");
    $feeds = json_decode($feeds);

    $number_of_feeds = count($feeds);
    echo $number_of_feeds." Emoncms feeds found\n";

    if ($number_of_feeds==0) {
        echo "No feeds found at remote account\n";
        die;
    }

    foreach ($feeds as $feed)
    {

        if ($feed->engine==0 && $mysqli) {
            import_mysql($feed,$emoncms_server,$emoncms_apikey,$mysqli);
        }

        if ($feed->engine==1 && $feed->datatype==1) {
            import_phptimestore($feed->id,$emoncms_server,$emoncms_apikey,$engines['phptimestore']['datadir']);
        }

        if ($feed->engine==2) {
            import_phptimeseries($feed->id,$emoncms_server,$emoncms_apikey,$engines['phptimeseries']['datadir']);
        }

        if ($feed->engine==5) {
            import_phpfina($feed->id,$emoncms_server,$emoncms_apikey,$engines['phpfina']['datadir']);
        }

        if ($feed->engine==6) {
            import_phpfiwa($feed->id,$emoncms_server,$emoncms_apikey,$engines['phpfiwa']['datadir']);
        }

        if ($feed->engine==4 && $feed->datatype==1) {
            import_phptimestore($feed->id,$emoncms_server,$emoncms_apikey,$engines['phptimestore']['datadir']);
        }
    }


    // Dump MYSQL data

    echo "Dumping MYSQL data\n";
    $backup_file = $dbname . '_' . date("d-m-Y_His") . '.sql';
    exec("mysqldump --lock-tables -u $dbuser -p$dbpass ". "$dbname > $tdir/mysql/$backup_file");

    // Backup nodered

    if (file_exists("$NRdir") && ($nodered == "Y")) {
    echo "Backing up node-red data\n";
    foreach (
    $iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($NRdir, \RecursiveDirectoryIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::SELF_FIRST) as $item
    ) {
    if ($item->isDir()) {
    mkdir("$tdir/nodered" . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
    } else {
    copy($item, "$tdir/nodered" . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
        }
      }
    }

    // Create archive of temp_data in backups directory

    try {
    //make sure the script has enough time to run (600 seconds  = 10 minutes)
    ini_set('max_execution_time', '600');
    ini_set('set_time_limit', '0');
    $target = isset($_GET["targetname"]) ? $_GET["targetname"] : 'backups/archive.tar';
    $dir = isset($_GET["dir"]) ? $_GET["dir"] : "$tdir/."; //source is temp dir
    //setup phar
    $phar = new PharData($target);
    $phar->buildFromDirectory(dirname(__FILE__) . '/'.$dir);
    echo "Now compressing archive...\n";
    file_put_contents("backups/archive_$date.tar.gz" , gzencode(file_get_contents('backups/archive.tar')));
    unlink('backups/archive.tar');
    } catch (Exception $e) {
    // handle errors
    echo 'An error has occured, details:';
    echo $e->getMessage();
    }

    // Remove temporary directory and contents

    $it = new RecursiveDirectoryIterator($tdir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it,
             RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
    if ($file->isDir()){
        rmdir($file->getRealPath());
    } else {
        unlink($file->getRealPath());
      }
    }
    rmdir($tdir);

    // Remove archives older than x days from backups directory

    if (file_exists("backups")) {
    echo "Checking for expired archives...\n";
    foreach (new DirectoryIterator("backups") as $fileInfo) {
        if ($fileInfo->isDot()) {
        continue;
        }

        if (time() - $fileInfo->getCTime() >= ("$store"*60*60*24)-(60*60)) {
           echo "Deleting expired archive - $fileInfo\n";
            unlink($fileInfo->getRealPath());
        }
      }
    }

    // Delete cloud expired archives

    $output = exec("lib/./sync.sh");

    // Upload archive to Dropbox

    echo "Uploading new archive to Dropbox, this may take a while...\n";
    $output = exec("lib/./dropbox_uploader.sh -sf /home/pi/.dropbox_uploader upload backups/ /");

