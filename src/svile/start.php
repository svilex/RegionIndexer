<?php

namespace svile {


    use svile\regionindexer\Indexer;
    use svile\regionindexer\utils\console\Console;


    set_time_limit(0);
    error_reporting(E_ALL);
    ini_set('allow_url_fopen', 1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('memory_limit', -1);
    date_default_timezone_set('UTC');
    ini_set('date.timezone', 'UTC');

    if (php_sapi_name() !== 'cli') {
        echo 'You must use the CLI.' . PHP_EOL;
        exit(1);
    }

    @define('PATH', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

    if (version_compare('7.0', PHP_VERSION) > 0) {
        echo 'You must use PHP >= 7.0' . PHP_EOL;
        exit(1);
    }

    spl_autoload_register(function ($class) {
        require_once PATH . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    });

    Console::init();
    Console::input('World folder absolute path: ');
    $worldFolderPath = Console::getInput('world');

    $path = realpath($worldFolderPath);
    if (!(is_dir($path) && is_file($path . '/level.dat'))) {
        $path = realpath(PATH . $worldFolderPath);
        if (!(is_dir($path) && is_file($path . '/level.dat'))) {
            Console::error('§cCouldn\'t find the world folder §a' . PATH . $worldFolderPath);
            exit(0);
        }
    }

    $regions = [];
    foreach (scandir($path . '/region') as $region) {
        $rp = realpath($path . '/region/' . $region);
        $exp = explode('.', $region);
        if ($region != '.' && $region != '..' && is_file($rp) && count($exp) == 4 && $exp[0] == 'r' && $exp[3] == 'mcr')
            $regions[] = [(int)$exp[1], (int)$exp[2], $rp];
    }

    if (count($regions) < 1) {
        Console::error('§cCouldn\'t find *.mcr files in the world folder');
        exit(0);
    }

    new Indexer($path, $regions);

    exit(0);

// /home/giovanni/Desktop/PHP7/php7/bin/php /home/giovanni/Desktop/RegionIndexer/src/svile/start.php
}