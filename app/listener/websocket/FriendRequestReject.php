<?php

declare(strict_types=1);

namespace app\listener\websocket;

use app\core\Result;
use app\service\User as UserService;
use app\util\Redis as RedisUtil;
use app\service\Friend as FriendService;

class FriendRequestReject extends SocketEventHandler
{

    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle($event, FriendService $friendService)
    {
        ['requestId' => $requestId, 'reason' => $reason] = $event;

        $user = $this->getUser();

        $result = $friendService->reject($requestId, $user['id'], $reason);

        $this->websocket->emit('friend_request_reject', $result);

        // 如果成功拒绝申请，则尝试给申请人推送消息
        if ($result->code !== Result::CODE_SUCCESS) {
            return false;
        }

        // 拿到申请人的FD
        $selfFd = RedisUtil::getFdByUserId($result->data['selfId']);
        $selfFd && $this->websocket->setSender($selfFd)->emit('friend_request_reject', $result);
    }
}
