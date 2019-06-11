<?php
/**
 * 一些工具
 */
namespace httptask;

class tools
{
    /**
     * 解析url
     * @param  string $url
     * @return array
     */
    public static function parseUrl($url)
    {
        $info = parse_url($url);
        $request = array();
        if (!isset($info['host'])) {
            return false;
        }

        $request['url'] = $url;

        switch ($info['scheme']) {
            case 'http':
                $protocol = 'tcp';
                $port = '80';
            break;
            case 'https':
                $protocol = 'ssl';
                $port = '443';
            break;
            default:
                $protocol = 'tcp';
                $port = '80';
            break;
        }

        // 包含端口
        if (isset($info['port'])) {
            $port = $info['port'];
        }

        // 默认的path
        if (empty($info['path'])) {
            $path = '/';
        } else {
            $path = $info['path'];
        }
        if (isset($info['query'])) {
            $path .= '?' . $info['query'];
        }

        $request = array(
            'url' => $url,
            'socket' => sprintf("%s://%s:%d", $protocol, $info['host'], $port),
            'start' => sprintf("GET %s HTTP/1.1\r\n", $path),
            'header' => sprintf("Host:%s\r\n", $info['host'])
        );

        return $request;
    }

    /**
     * 解析响应头头
     * @param  string $header
     * @return array
     */
    public static function parseResponseHeader($header)
    {
        $headerList = explode("\r\n", $header);
        $headers = array();
        foreach ($headerList as $list) {
            $item = explode(':', $list);
            if (!isset($item[1])) {
                $item[1] = '';
            }
            $headers[strtolower(trim($item[0]))] = strtolower(trim($item[1]));
        }
        return $headers;
    }
}