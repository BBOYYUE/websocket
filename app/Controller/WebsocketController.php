<?php
declare(strict_types=1);

namespace App\Controller;

use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\Websocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

/**
 * 连接 map 和 user map.
 */
$room_connection_map = array();
$room_user_map = array();


class WebsocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    public function onMessage($server, Frame $frame): void
    {
        $data = json_decode($frame->data, true);
        if (is_array($data) && isset($data['method'])) {
            switch ($data['method']) {
                // 订阅房间
                case 'subscribe':
                    $room = $data['room'];
                    $userid = $data['user_id'] ?? 0;
                    $this->subscribe($room, $userid, $server, $frame);
                    break;
                // 向某个房间发布消息
                case 'publish':
                    $room = $data['room'];
                    $event = $data['event'];
                    $data = $data['data'];
                    $this->publish($room, $event, $data, $server, $frame);
                    break;
                case 'query':
                    $room = $data['room'];
                    $this->query($room, $server, $frame);
            }
        }
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $this->destroy_connection($server, $fd);
    }

    public function onOpen($server, Request $request): void
    {
        $server->rooms = [];
        $this->successRespond($server, $request->fd, [], '连接成功');
    }

    public function successRespond($server, $fd, $data, $msg = '请求成功')
    {
        $server->push($fd, json_encode([
            'code' => '200',
            'msg' => $msg,
            'data' => $data
        ]));
    }

    public function query($room, $server, $frame)
    {
        global $room_user_map;
        $data = $room_user_map[$room] ?? [];
        $this->successRespond($server, $frame->fd, $data);
    }

    /**
     * 订阅时会往房间假如一个 websocket 连接
     * @param $room
     * @param $server
     * @param $frame
     */
    public function subscribe($room, $userid, $server, $frame)
    {
        global $room_connection_map, $room_user_map;
        /**
         * $server->rooms 数组主要是为了在server关闭时, 可以回收全局变量中房间信息对应的server信息.
         */
        $server->rooms[$room] = $room;
        $room_connection_map[$room][$frame->fd] = $server;
        $room_user_map[$room][$frame->fd] = $userid;
        $server->push($frame->fd, "订阅成功!");
    }

    /**
     * 发布消息
     * @param $room
     * @param $event
     * @param $data
     * @param $exclude
     * @param $frame
     */
    public function publish($room, $event, $data, $exclude, $frame)
    {
        global $room_connection_map;
        if (empty($room_connection_map[$room])) {
            return;
        }
        foreach ($room_connection_map[$room] as $fd => $server) {
            if ($fd == $frame->fd) {
                continue;
            }
            $exclude->push(
                $fd,
                json_encode(
                    array(
                        'method' => 'publish',
                        'event' => $event,
                        'data' => $data
                    )
                )
            );
        }
    }

    public function unsubscribe($room, $fd)
    {
        global $room_connection_map, $room_user_map;
        if (isset($room_connection_map[$room][$fd])) {
            unset($room_connection_map[$room][$fd]);
        }
        if (isset($room_user_map[$room][$fd])) {
            unset($room_user_map[$room][$fd]);
        }
    }

    // 清理当前连接映射数组的房间数组
    public function destroy_connection($server, $fd)
    {
        foreach ($server->rooms as $room) {
            $this->unsubscribe($room, $fd);
        }
    }

}