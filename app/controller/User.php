<?php

declare(strict_types=1);

namespace app\controller;

use think\App;
use app\core\Result;
use app\service\User as UserService;

class User extends BaseController
{
    protected $service;

    public function __construct(App $app, UserService $service)
    {
        parent::__construct($app);
        $this->service = $service;
    }

    /**
     * 用户登录
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @return Result
     */
    public function login(string $username, string $password): Result
    {
        return $this->service->login($username, $password);
    }

    /**
     * 退出登录
     *
     * @return void
     */
    public function logout(): void
    {
        $this->service->logout();
    }

    /**
     * 检测用户是否已经登录
     * 如果已登录，则返回User；否则返回false
     *
     * @return Result
     */
    public function checkLogin(): Result
    {
        return $this->service->checkLogin();
    }

    /**
     * 注册账户
     *
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $email 邮箱
     * @param string $captcha 验证码
     * @return Result
     */
    public function register(string $username, string $password, string $email, string $captcha): Result
    {
        return $this->service->register($username,  $password,  $email,  $captcha);
    }

    /**
     * 验证邮箱是否可用
     *
     * @param string $email
     * @return Result
     */
    public function checkEmail(string $email): Result
    {
        return $this->service->checkEmail($email);
    }

    /**
     * 上传用户头像
     *
     * @return Result
     */
    public function avatar(): Result
    {
        return $this->service->avatar();
    }

    /**
     * 保存用户信息
     *
     * @return Result
     */
    public function saveUserInfo(): Result
    {
        return $this->service->saveUserInfo();
    }

    /**
     * 绑定电子邮箱
     *
     * @param string $email 邮箱
     * @param string $captcha 验证码
     * @return Result
     */
    public function bindEmail(string $email, string $captcha): Result
    {
        return $this->service->bindEmail($email, $captcha);
    }

    /**
     * 获取用户
     *
     * @return Result
     */
    public function getUserById($id): Result
    {
        return $this->service->getUserById((int) $id);
    }

    /**
     * 获取用户的聊天列表
     *
     * @return Result
     */
    public function getChatSessions(): Result
    {
        return $this->service->getChatSessions();
    }

    /**
     * 获取私聊聊天室列表
     *
     * @return Result
     */
    public function getPrivateChatrooms(): Result
    {
        return $this->service->getPrivateChatrooms();
    }

    /**
     * 获取群聊聊天室列表
     *
     * @return Result
     */
    public function getGroupChatrooms(): Result
    {
        return $this->service->getGroupChatrooms();
    }

    /**
     * 置顶聊天列表子项
     *
     * @param integer $id 聊天室成员表ID
     * @return Result
     */
    public function stickyChatSession(int $id): Result
    {
        return $this->service->stickyChatSession($id);
    }

    /**
     * 取消置顶聊天列表子项
     *
     * @param integer $id 聊天室成员表ID
     * @return Result
     */
    public function unstickyChatSession(int $id): Result
    {
        return $this->service->unstickyChatSession($id);
    }

    /**
     * 将聊天列表子项设置为已读
     *
     * @param integer $id 聊天室成员表ID
     * @return Result
     */
    public function readedChatSession(int $id): Result
    {
        return $this->service->readedChatSession($id);
    }

    /**
     * 将聊天列表子项设置为未读
     *
     * @param integer $id 聊天室成员表ID
     * @return Result
     */
    public function unreadChatSession(int $id): Result
    {
        return $this->service->unreadChatSession($id);
    }
}
