<?php
/**
 * 聊天室服务端  直接在后台运行 window使用cmd 
 * 使用 socket 函数
 * 需要在php.ini中开启sockets扩展
 */
error_reporting(E_ALL);
ob_implicit_flush();

$ws = new WS('localhost', 4000);

Class WS {
    public $users = array();  // 二维数组 用户组 每个用户有两个属性（accept套接字用来传输信息的、和handshake是否握手）
    public $sockets = array(); // 连接池、包括一个主连接$master、和多个子套接字 socket_accept (一个代表连接一个用户)
    public $master; //当前主机 主连接 只有一个
 
    function __construct($address, $port){
        // 建立一个 socket 套接字
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)   
            or die("socket_create() failed");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1)  
            or die("socket_option() failed");
        socket_bind($this->master, $address, $port)                    
            or die("socket_bind() failed");
        socket_listen($this->master, 2)                               
            or die("socket_listen() failed");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, TRUE); //允许使用本地地址

        $this->sockets[] = $this->master;  //只初始化一次
        while(true) {

            //！！！必须重新获取 $this->sockets;   因为下面重新增加了 $this->sockets  直接使用它不会变化的
            $changes = $this->sockets;
            //自动选择来消息的 socket 如果是握手 自动选择主机
            $write = NULL;
            $except = NULL;
            //阻塞用，有新连接时才会结束   
            // socket_select(array &$read,...) 函数理解：获取$read数组中活动的socket套接字，并把不活跃的从数组中移除，这是个同步的方法必须响应后才去执行下面的语句。如果客户端关闭了连接，必须手动关闭服务端的连接
            socket_select($changes, $write, $except, NULL);
            foreach ($changes as $socket) {
                //连接主机的 如果请求来自监听端口那个套接字，则创建一个新的套接字用于通信
                if ($socket === $this->master){
                    $accept = socket_accept($this->master);
                    if ($accept == false) {
                        echo "socket_accept() failed";
                        continue;
                    } else {
                        //添加用户
                        $this->createAccept($accept);
                    }
                } else {
                    $key = null;
                    //查找在users里存的socket套接字的键值key  以便在后面用
                    foreach($this->users as $k=>$v) {
                        if($v['accept'] === $socket) {
                            $key = $k;
                            break;
                        }
                    }
                    //接受客户端的信息  $buffer 为websocket发送过来的数据
                    $bytes = @socket_recv($socket, $buffer, 2048, 0);
                    //如果客户端信息没有值 返回
                    if(!$bytes || !$buffer) {
                        $this->close($socket, $key);
                        continue;
                    }
                    
                    if (!$this->users[$key]['handshake']) {
                        // 如果没有握手，先握手回应
                        $this->doHandShake($socket, $buffer, $key);
                        //发送消息-->在线人数
                        $this->personNum();
                    } else {
                        $buffer = $this->uncode($buffer);
                        // 如果已经握手，直接接受数据，并处理
                        //发送给所有人
                        $this->sendMsg($socket, $buffer);
                    }
                }
            }
        }
    }

    //创建进程 一个用户
    function createAccept($accept) {
        $this->sockets[] = $accept;  //添加到主监听列表
        $key = uniqid();
        $this->users[$key] = array(
            'accept' => $accept,
            'handshake' => false
        );
    }

    //关闭socket
    function close($socket, $key) {
        socket_close($socket);//关闭与此客户端的连接
        $accept = $this->users[$key]['accept'];
        $k = array_search($accept, $this->sockets);
        unset($this->sockets[$k]); //注销掉所有监听列表中的这个客户端
        unset($this->users[$key]);
        $this->personNum();
    }

    function doHandShake($socket, $buffer, $index) {
        //提取 Sec-WebSocket-Key信息
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $buffer, $match)) { 
            $key = $match[1]; 
            //加密 Sec-WebSocket-Key
            $mask = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";  //固定的
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                       "Upgrade: websocket\r\n" .
                       "Connection: Upgrade\r\n" .
                       "Sec-WebSocket-Accept: " . $acceptKey . "\r\n" .
                       "\r\n";
            //写入socket
            socket_write($socket, $upgrade, strlen($upgrade));
            $this->users[$index]['handshake'] = true;//握手成功
        }
    }

    // 发送在线人数
    function personNum() {
        $data['person_num'] = count($this->users);
        $data = json_encode($data);
        $msg = $this->code($data);   //发送客户端数据侦
        //给每个在线用户发送数据
        foreach($this->users as $v) {
            socket_write($v['accept'], $msg, strlen($msg));
        }
    }

    // 发送消息
    function sendMsg($socket, $buffer) {
        $buffer = json_decode($buffer, true);
        //这些可以处理数据   先预留着  以后再用
        $data['name'] = $buffer['name'];
        $data['msg'] = $buffer['msg'];
        $data['time'] = $buffer['time'];
        $data = json_encode($data);
        $msg = $this->code($data); //给每个在线用户发送数据
        foreach($this->users as $v) {
            socket_write($v['accept'], $msg, strlen($msg));
        }
    }
    function uncode($str){
        $mask = array();  
        $data = '';  
        $msg = unpack('H*',$str);  
        $head = substr($msg[1],0,2);  
        if (hexdec($head{1}) === 8) {  
            $data = false;  
        }else if (hexdec($head{1}) === 1){  
            $mask[] = hexdec(substr($msg[1],4,2));  
            $mask[] = hexdec(substr($msg[1],6,2));  
            $mask[] = hexdec(substr($msg[1],8,2));  
            $mask[] = hexdec(substr($msg[1],10,2));  
          
            $s = 12;  
            $e = strlen($msg[1])-2;  
            $n = 0;  
            for ($i=$s; $i<= $e; $i+= 2) {  
                $data .= chr($mask[$n%4]^hexdec(substr($msg[1],$i,2)));  
                $n++;  
            }  
        }  
        return $data;
    }
    
    
    function code($msg){
        $msg = preg_replace(array('/\r$/','/\n$/','/\r\n$/',), '', $msg);
        $frame = array();  
        $frame[0] = '81';  
        $len = strlen($msg);  
        $frame[1] = $len<16?'0'.dechex($len):dechex($len);  
        $frame[2] = $this->ord_hex($msg);  
        $data = implode('',$frame);  
        return pack("H*", $data);  
    }
    
    function ord_hex($data)  {  
        $msg = '';  
        $l = strlen($data);  
        for ($i= 0; $i<$l; $i++) {  
            $msg .= dechex(ord($data{$i}));  
        }  
        return $msg;  
    }
}
