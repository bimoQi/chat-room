基于sockets的在线聊天程序(需开启php的sockets扩展)

部署服务器使用:
  将server.php的
    $ws = new WS('localhost', 4000);
  改为:
    $ws = new WS('内网ip地址', 4000);
    
  将index.html的
    var ws = new WebSocket("ws://localhost:4000");
  改为:
    var ws = new WebSocket("ws://"+document.domain+":4000"); //或者直接ip或域名
    
  在服务器上命令行执行 php server.php
  访问index.html即可
