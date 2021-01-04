<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;

use app\core\service\Chat as ChatService;
use app\core\service\User as UserService;
use app\core\Result;

class Chat extends BaseController
{

    /**
     * 获取我收到的入群申请
     *
     * @return Result
     */
    public function getReceiveRequests(): Result
    {
        return ChatService::getReceiveRequests();
    }
}
