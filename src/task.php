<?php
/**
 * 一个高性能的http异步请求类
 * 使用ev实现
 */
namespace httptask;

use httptask\tools;

class task
{
    /**
     * 等待队列
     * @var array
     */
    protected $waitQueue = array();

    /**
     * 最大连接数
     */
    protected $maxConnections = 1;

    /**
     * 当前连接数
     * @var integer
     */
    protected $connections = 0;

    /**
     * 读取的内容buffer
     */
    protected $buffer = array();

    /**
     * event队列
     * @var array
     */
    protected $events = array();

    /**
     * debug日志
     * @var boolean
     */
    public $debug = true;

    /**
     * 添加任务，将任务放到等待队列中
     * @param string $url  
     * @param array $header HTTP头信息
     */
    public function addTask($url, $header = array(), $callback)
    {
        if (false == $task = tools::parseUrl($url)) {
            return false;
        }
        if (!empty($header)) {
            foreach($header as $key=>$val) {
                $task['header'] .= "{$key}:{$val}\r\n";
            }
        }
        $task['header'] .= "\r\n";
        $task['callback'] = $callback;
        return array_push($this->waitQueue, $task);
    }

    /**
     * 执行任务
     * @return boolean 返回false的时候表示当前已经没有任务了
     */
    public function execute()
    {
        $i = 0;
        while ($task = array_shift($this->waitQueue)) {
            $this->connect($task);
            $i++;
            if ($i > $this->maxConnections) {
                break;
            }
        }
        $this->addSignal();
        \Ev::run();
    }

    /**
     * 连接
     * @return
     */
    protected function connect($task)
    {
        $this->connections += 1;
        $fd = stream_socket_client($task['socket'], $errno, $errstr, 30, STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($fd, 0);
        $taskHash = md5(json_encode($task));
        $this->events[$taskHash] = array(
            'fd' => $fd,
            'task' => $task,
            'hash' => $taskHash
        );
        $this->addEvent($taskHash);
    }

    /**
     * 信号监听
     */
    protected function addSignal()
    {
        $this->signal = new \EvSignal(10, function($watcher) {
            if ($task = array_shift($this->waitQueue)) {
                $this->connect($task);
            }
        });
    }

    /**
     * 给taskHash添加事件
     * 包含可读可写和超时
     * @param resource $fd
     */
    protected function addEvent($taskHash)
    {
        $fd = $this->events[$taskHash]['fd'];
        $task = $this->events[$taskHash]['task'];
        $callback = $task['callback'];
        // 监听超时
        $this->events[$taskHash]['timer_watcher'] = new \EvTimer(10, 0, function() use ($taskHash) {
            $this->afterComplete($taskHash);
        });

        //监听写入
        $this->events[$taskHash]['write'] = new \EvIo($fd, \Ev::WRITE, function($watcher, $revents) use($taskHash) {
            $this->sendRequest($taskHash);
            $this->log('写入' . $this->events[$taskHash]['task']['url']);
            $watcher->stop();
        });

        // 监听读取
        $this->events[$taskHash]['read'] = new \EvIo($fd, \Ev::READ, function($watcher, $revents) use ($taskHash) {
            $this->log('读取' . $this->events[$taskHash]['task']['url']);
            $this->readContent($taskHash);
            if ($this->buffer[$taskHash]['end'] == 1) {
                $this->afterComplete($taskHash);
            }
        });
    }

    /**
     * 读取完成或者超时之后的处理逻辑
     * @return
     */
    protected function afterComplete($taskHash)
    {
        $callback = $this->events[$taskHash]['task']['callback'];
        $this->events[$taskHash]['timer_watcher']->stop();
        $this->events[$taskHash]['read']->stop();
        unset($this->events[$taskHash]);
        posix_kill(posix_getpid(), 10);
        call_user_func_array($callback, array($this->buffer[$taskHash]));
        $this->connections -= 1;
        if ($this->connections <= 0 && count($this->waitQueue) == 0) {
            \Ev::stop();
        }
    }

    /**
     * 发出请求
     * @param  string $taskHash 
     * @return 
     */
    protected function sendRequest($taskHash)
    {
        $fd = $this->events[$taskHash]['fd'];
        $task = $this->events[$taskHash]['task'];
        fwrite($fd, $task['start'] . $task['header']);
        $this->buffer[$taskHash] = array(
            'tmp' => '',
            'end' => 0,
            'e_times' => 0,
            'body' => '',
        );
    }

    /**
     * 读取网页内容放到缓冲区
     * @return void
     */
    protected function readContent($taskHash)
    {
        if (empty($this->buffer[$taskHash]['header'])) {
            $this->readHeader($taskHash);
        } else {
            $this->readBody($taskHash);
        }
    }

    /**
     * 读取响应头
     * @return
     */
    protected function readHeader($taskHash)
    {
        $fd = $this->events[$taskHash]['fd'];
        while($tmp = stream_get_contents($fd)){
            $this->buffer[$taskHash]['tmp'] .= $tmp;
        }
        if (false !== $headerPosition = strpos($this->buffer[$taskHash]['tmp'], "\r\n\r\n")) {
            $this->buffer[$taskHash]['raw_header'] = substr($this->buffer[$taskHash]['tmp'], 0, $headerPosition);
        }

        if (empty($this->buffer[$taskHash]['raw_header'])) {
            return false;
        }

        $header = tools::parseResponseHeader($this->buffer[$taskHash]['raw_header']);
        $this->buffer[$taskHash]['header'] = $header;
        $this->buffer[$taskHash]['tmp'] = substr($this->buffer[$taskHash]['tmp'], $headerPosition + 4);
        //unset($this->buffer[$taskHash]['raw_header']);
        // 避免在读取header过程中就把body读取完了
        $this->readBody($taskHash);
    }

    /**
     * 获取分块的长度
     * @param  string $chunk 一个有效或者无效的分块
     * @return
     */
    protected function getChunkLength($chunk)
    {
        if (false === $boundry = strpos($chunk, "\r\n")) {
            return false;
        }
        // 验证是否是一个十六进制
        $hexLength = substr($chunk, 0, $boundry);
        if (!ctype_xdigit($hexLength)) {
            return false;
        }
        return (int) base_convert($hexLength, 16, 10);
    }

    /**
     * 读取响应体
     * @return
     */
    protected function readBody($taskHash)
    {
        $header = $this->buffer[$taskHash]['header'];
        if (isset($header['transfer-encoding']) && $header['transfer-encoding'] == 'chunked') {
            $this->readChunk($taskHash);
        } else if (isset($header['content-length'])) {
            $this->readFull($taskHash);
        }
    }

    /**
     * 读取整个网页块
     * 如果网页不是分块编码的话，根据content-length读取整个网页
     * @param  string $taskHash 任务hash
     * @return array
     */
    protected function readFull($taskHash)
    {
        $buffer = $this->buffer[$taskHash];
        $fd = $this->events[$taskHash]['fd'];
        while($tmp = stream_get_contents($fd)) {
            $buffer['tmp'] .= $tmp;
        }

        if (strlen($buffer['tmp']) >= $buffer['header']['content-length']) {
            $buffer['end'] = 1;
            $buffer['body'] = $buffer['tmp'];
            unset($buffer['tmp']);
        }
        $this->buffer[$taskHash] = $buffer;
    }


    /**
     * 读取chunk
     * @param  string $taskHash 任务hash
     * @return 
     */
    protected function readChunk($taskHash)
    {
        $buffer = $this->buffer[$taskHash];
        $fd = $this->events[$taskHash]['fd'];
        while($tmp = stream_get_contents($fd)) {
            $buffer['tmp'] .= $tmp;
        }

        // 当读取到分块的长度的时候
        while (false !== $chunkLength = $this->getChunkLength($buffer['tmp'])) {
            $chunkSizeLength = strlen(base_convert($chunkLength, 10, 16));
            if (strlen($buffer['tmp']) < $chunkSizeLength + $chunkLength + 4) {
                break;
            }
            $buffer['body'] .= substr($buffer['tmp'], $chunkSizeLength + 2, $chunkLength);
            // 分块内容开始是在当前的size长度之后的\r\n后
            $buffer['tmp'] = substr($buffer['tmp'], $chunkSizeLength + $chunkLength + 4);
            $buffer['current_chunk_length'] = $chunkLength;
        }

        // 当前块大小为0，表示已经是最后一个分块
        if (isset($buffer['current_chunk_length']) && $buffer['current_chunk_length'] === 0) {
            $buffer['end'] = 1;
        }
        $this->buffer[$taskHash] = $buffer;
    }

    /**
     * 日志打印
     * @param  string $line
     * @return 
     */
    protected function log($line)
    {
        if ($this->debug) {
            echo $line . "\n";
        }
    }
}