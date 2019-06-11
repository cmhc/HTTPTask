<?php
error_reporting(E_ALL);
ini_set('display_errors', true);

// 先执行composer install
require __DIR__ . '/vendor/autoload.php';
$start = microtime(true);

$httpTask = new httptask\task();

function cb($content) {
    echo $content['body'];
    echo "任务完成\n";
}

$httpTask->addTask('http://imhuchao.com?i=1', array(), 'cb');
$httpTask->addTask('http://imhuchao.com?i=2', array(), 'cb');
$httpTask->addTask('http://imhuchao.com?i=3', array(), 'cb');
$httpTask->execute();
echo microtime(true) - $start;
echo "\n";


$start = microtime(true);
for ($i=1; $i<=3; $i++) {
    file_get_contents('http://imhuchao.com?i=' . $i);
    echo "任务{$i}完成";
}
echo microtime(true) - $start;