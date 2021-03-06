<?php

declare(strict_types=1);

namespace app\listener\websocket;

use app\util\Sql as SqlUtil;
use app\util\Redis as RedisUtil;
use app\model\UserInfo as UserInfoModel;
use app\service\User as UserService;

class Init extends SocketEventHandler
{
    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle($event, UserService $userService)
    {
        RedisUtil::setFdUserPair($this->fd, $event['sessId']);

        $user = $this->getUser();
        $chatrooms = $userService->getChatrooms($user['id']);

        // 储存uid - fd
        RedisUtil::setUserIdFdPair($user['id'], $this->fd);

        // 批量加入所有房间
        foreach ($chatrooms as $chatroom) {
            $this->websocket->join(parent::ROOM_CHATROOM . $chatroom['id']);
        }

        // 加入好友请求房间
        $this->websocket->join(parent::ROOM_FRIEND_REQUEST . $user['id']);
        // 加入群聊申请房间
        $this->websocket->join(parent::ROOM_CHAT_REQUEST . $user['id']);

        $this->websocket->emit('init');

        UserInfoModel::update([
            'login_time' => time() * 1000,
            'id' => $user['id']
        ]);
    }
}
