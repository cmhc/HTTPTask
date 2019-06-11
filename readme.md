httptask
========

一个异步的http请求类，能够并发请求多个资源，支持每个资源自定义回调函数。

需要php的Ev和posix扩展支持

例子

    $httpTask = new httptask\task();
        
    function cb($content) {
        echo $content['body'];
        echo "任务完成\n";
    }
        
    $httpTask->addTask('http://imhuchao.com?i=1', array(), 'cb');
    $httpTask->addTask('http://imhuchao.com?i=2', array(), 'cb');
    $httpTask->addTask('http://imhuchao.com?i=3', array(), 'cb');
    $httpTask->execute();

