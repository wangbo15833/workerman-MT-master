<?php
error_reporting(E_ALL);
set_time_limit(0);
echo "<h2>TCP/IP Connection</h2>\n";

$port = 8480;
$ip = "127.0.0.1";

/*
 +-------------------------------
 *    @socket������������
 +-------------------------------
 *    @socket_create
 *    @socket_connect
 *    @socket_write
 *    @socket_read
 *    @socket_close
 +--------------------------------
 */

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket < 0) {
    echo "socket_create() failed: reason: " . socket_strerror($socket) . "\n";
}else {
    echo "OK.\n";
}

echo "try connect '$ip' port '$port'...\n";
$result = socket_connect($socket, $ip, $port);
if ($result < 0) {
    echo "socket_connect() failed.\nReason: ($result) " . socket_strerror($result) . "\n";
}else {
    echo "����OK\n";
}

$in = array('req'=>'0104','room_id'=>"1",'client_name'=>'admin','password'=>'admin');
$buffer = json_encode($in);
$total_length= 4+strlen($buffer);
$buffer_new=pack('N',$total_length).$buffer;
$out = '';

if(!socket_write($socket, $buffer_new)) {
    echo "socket_write() failed: reason: " . socket_strerror($socket) . "\n";
}else {
    echo "���͵���������Ϣ�ɹ���\n";
    echo "���͵�����Ϊ:<font color='red'>$in</font> <br>";
}

while($out = socket_read($socket, 8192)) {
    echo "���շ������ش���Ϣ�ɹ���\n";
    echo "���ܵ�����Ϊ:",$out;
}


echo "�ر�SOCKET...\n";
socket_close($socket);
echo "�ر�OK\n";
?>