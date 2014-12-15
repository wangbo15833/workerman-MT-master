<?php
//error_reporting(0);
/**
 * 聊天逻辑，使用的协议是 文本+回车
 * 测试方法 运行
 * telnet ip 8480
 * 可以开启多个telnet窗口，窗口间可以互相聊天
 * 
 * websocket协议的聊天室见workerman-chat及workerman-todpole
 * 
 * @author walkor <walkor@workerman.net>
 */

use \Lib\Context;
use \Lib\Gateway;
use \Lib\StatisticClient;
use \Lib\Store;
use \Lib\Db;
use \Protocols\GatewayProtocol;
use \Protocols\TextProtocol;
use \Protocols\JsonProtocol;


class Event
{
    /**
     * 当网关有客户端链接上来时触发，每个客户端只触发一次，如果不许要任何操作可以不实现此方法
     * 这里当客户端一连上来就给客户端发送输入名字的提示
     */
    public static function onGatewayConnect($client_id)
    {
        Gateway::sendToCurrentClient(TextProtocol::encode("type in your name:"));
    }
    
    /**
     * 网关有消息时，判断消息是否完整
     */
    public static function onGatewayMessage($buffer)
    {
        return JsonProtocol::check($buffer);
    }
    
   /**
    * 有消息时触发该方法
    * @param int $client_id 发消息的client_id
    * @param string $message 消息
    * @return void
    */
   public static function onMessage($client_id, $message)
   {
        $message_data = JsonProtocol::decode($message);

        $req=$message_data['req'];
        switch($req){
            case '0101':
                $result=Db::instance('one_demo')->query('SELECT * FROM user WHERE uid>0');
                return Gateway::sendToClient($client_id,var_export($result,true));

            case '0102':
                $client_name=$message_data['client_name'];
                $password=$message_data['password'];
                $sql="insert into user(username,password) values('".$client_name."','".$password."')";
                echo $sql;
                $result=Db::instance('one_demo')->query($sql);
                return Gateway::sendToClient($client_id,'insert success!');
            case '0103':
                echo "0103method";
                $content=$message_data['content'];
                echo $content;
                return Gateway::sendToAll(TextProtocol::encode($content));
            //登录操作
            case '0104':
                echo "0104method";
                var_dump($message_data);
                if(!isset($message_data['room_id']))
                {
                    throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                
                // 把房间号昵称放到session中
                $room_id = $message_data['room_id'];
                $client_name = htmlspecialchars($message_data['client_name']);
                $_SESSION['room_id'] = $room_id;
                $_SESSION['client_name'] = $client_name;
                
                // 存储到当前房间的客户端列表
                $all_clients = self::addClientToRoom($room_id, $client_id, $client_name);
                
                // 整理客户端列表以便显示
                $client_list = self::formatClientsData($all_clients);
                
                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $new_message = array('type'=>$message_data['req'], 'client_id'=>$client_id, 'client_name'=>htmlspecialchars($client_name), 'client_list'=>$client_list, 'time'=>date('Y-m-d H:i:s'));
                $client_id_array = array_keys($all_clients);
                Gateway::sendToAll(json_encode($new_message), $client_id_array);
                return;


            default:
                echo "no req\n";
        }
        

   }
   
   /**
    * 当用户断开连接时触发的方法
    * @param integer $client_id 断开连接的用户id
    * @return void
    */
   public static function onClose($client_id)
   {
       // 广播 xxx 退出了
       GateWay::sendToAll(TextProtocol::encode("{$_SESSION['name']}[$client_id] logout"));
   }


   /**
    * 格式化客户端列表数据
    * @param array $all_clients
    */
   public static function formatClientsData($all_clients)
   {
       $client_list = array();
       if($all_clients)
       {
           foreach($all_clients as $tmp_client_id=>$tmp_name)
           {
               $client_list[] = array('client_id'=>$tmp_client_id, 'client_name'=>$tmp_name);
           }
       }
       return $client_list;
   }


   /**
    * 获得客户端列表
    * @todo 保存有限个
    */
   public static function getClientListFromRoom($room_id)
   {
       $key = "ROOM_CLIENT_LIST-$room_id";
       $store = Store::instance('room');
       $ret = $store->get($key);
       if(false === $ret)
       {
           if(get_class($store) == 'Memcached')
           {
               if($store->getResultCode() == \Memcached::RES_NOTFOUND)
               {
                   return array();
               }
               else 
               {
                   throw new \Exception("getClientListFromRoom($room_id)->Store::instance('room')->get($key) fail " . $store->getResultMessage());
               }
           }
           return array();
       }
       return $ret;
   }
   
   /**
    * 从客户端列表中删除一个客户端
    * @param int $client_id
    */
   public static function delClientFromRoom($room_id, $client_id)
   {
       $key = "ROOM_CLIENT_LIST-$room_id";
       $store = Store::instance('room');
       // 存储驱动是memcached
       if(get_class($store) == 'Memcached')
       {
           $cas = 0;
           $try_count = 3;
           while($try_count--)
           {
               $client_list = $store->get($key, null, $cas);
               if(false === $client_list)
               {
                   if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                   {
                       return array();
                   }
                   else
                   {
                        throw new \Exception("Memcached->get($key) return false and memcache errcode:" .$store->getResultCode(). " errmsg:" . $store->getResultMessage());
                   }
               }
               if(isset($client_list[$client_id]))
               {
                   unset($client_list[$client_id]);
                   if($store->cas($cas, $key, $client_list))
                   {
                       return $client_list;
                   }
               }
               else 
               {
                   return true;
               }
           }
           throw new \Exception("delClientFromRoom($room_id, $client_id)->Store::instance('room')->cas($cas, $key, \$client_list) fail" . $store->getResultMessage());
       }
       // 存储驱动是memcache或者file
       else
       {
           $handler = fopen(__FILE__, 'r');
           flock($handler,  LOCK_EX);
           $client_list = $store->get($key);
           if(isset($client_list[$client_id]))
           {
               unset($client_list[$client_id]);
               $ret = $store->set($key, $client_list);
               flock($handler, LOCK_UN);
               return $client_list;
           }
           flock($handler, LOCK_UN);
       }
       return $client_list;
   }


   /**
    * 添加到客户端列表中
    * @param int $client_id
    * @param string $client_name
    */
   public static function addClientToRoom($room_id, $client_id, $client_name)
   {
       $key = "ROOM_CLIENT_LIST-$room_id";
       $store = Store::instance('room');
       // 获取所有所有房间的实际在线客户端列表，以便将存储中不在线用户删除
       $all_online_client_id = Gateway::getOnlineStatus();
       // 存储驱动是memcached
       if(get_class($store) == 'Memcached')
       {
           $cas = 0;
           $try_count = 3;
           while($try_count--)
           {
               $client_list = $store->get($key, null, $cas);
               if(false === $client_list)
               {
                   if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                   {
                       $client_list = array();
                   }
                   else
                   {
                       throw new \Exception("Memcached->get($key) return false and memcache errcode:" .$store->getResultCode(). " errmsg:" . $store->getResultMessage());
                   }
               }
               if(!isset($client_list[$client_id]))
               {
                   // 将存储中不在线用户删除
                   if($all_online_client_id && $client_list)
                   {
                       $all_online_client_id = array_flip($all_online_client_id);
                       $client_list = array_intersect_key($client_list, $all_online_client_id);
                   }
                   // 添加在线客户端
                   $client_list[$client_id] = $client_name;
                   // 原子添加
                   if($store->getResultCode() == \Memcached::RES_NOTFOUND)
                   {
                       $store->add($key, $client_list);
                   }
                   // 置换
                   else
                   {
                       $store->cas($cas, $key, $client_list);
                   }
                   if($store->getResultCode() == \Memcached::RES_SUCCESS)
                   {
                       return $client_list;
                   }
               }
               else 
               {
                   return $client_list;
               }
           }
           throw new \Exception("addClientToRoom($room_id, $client_id, $client_name)->cas($cas, $key, \$client_list) fail .".$store->getResultMessage());
       }
       // 存储驱动是memcache或者file
       else
       {
           $handler = fopen(__FILE__, 'r');
           flock($handler,  LOCK_EX);
           $client_list = $store->get($key);
           if(!isset($client_list[$client_id]))
           {
               // 将存储中不在线用户删除
               if($all_online_client_id && $client_list)
               {
                   $all_online_client_id = array_flip($all_online_client_id);
                   $client_list = array_intersect_key($client_list, $all_online_client_id);
               }
               // 添加在线客户端
               $client_list[$client_id] = $client_name;
               $ret = $store->set($key, $client_list);
               flock($handler, LOCK_UN);
               return $client_list;
           }
           flock($handler, LOCK_UN);
       }
       return $client_list;
   }
}
