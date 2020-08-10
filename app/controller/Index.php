<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\ChatMember;
use app\model\User;
use app\model\UserInfo;
use app\model\ChatRecord;
use app\model\Chatroom;
use think\captcha\facade\Captcha;
use think\facade\Db;
use think\Response;
use app\core\util\Sql as SqlUtil;

use app\core\handler\Chatroom as ChatroomHandler;
use app\core\handler\User as UserHandler;
use app\core\handler\Friend as FriendHandler;
use app\model\FriendRequest;
use app\core\identicon\generator\ImageMagickGenerator;
use app\core\oss\Client;
use Identicon\Generator\SvgGenerator;
use think\facade\Cache;
use OSS\OssClient;
use OSS\Core\OssException;
use app\core\util\Date as DateUtil;

class Index extends BaseController
{
    public function addChatroom($name)
    {
        Db::transaction(function () use ($name) {
            $timestamp = SqlUtil::rawTimestamp();
            // 创建一个聊天室
            $chatroom = Chatroom::create([
                'name'        => $name,
                'type'        => 0,
                'create_time' => $timestamp,
                'update_time' => $timestamp,
            ]);

            Db::execute("
                CREATE TABLE IF NOT EXISTS chat_record_" . $chatroom->id . " (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    chatroom_id INT UNSIGNED NOT NULL          COMMENT '聊天室ID',
                    user_id     INT UNSIGNED NULL              COMMENT '消息发送者ID',
                    type        TINYINT(1) UNSIGNED NOT NULL   COMMENT '消息类型',
                    data        JSON NOT NULL                  COMMENT '消息数据体',
                    reply_id    INT UNSIGNED NULL              COMMENT '回复消息的消息记录ID',
                    create_time BIGINT UNSIGNED NOT NULL,
                    FOREIGN KEY (chatroom_id) REFERENCES chatroom(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    FOREIGN KEY (user_id)     REFERENCES user(id)     ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 添加聊天成员
            User::find(1)->chatrooms()->attach($chatroom->id, [
                'role' => 0,
                'nickname' => 'HyperLife1119',
                'create_time' => $timestamp,
                'update_time' => $timestamp,
            ]);

            // // 添加聊天成员
            // User::find(2)->chatrooms()->attach($chatroom->id, [
            //     'role' => 0,
            //     'nickname' => '12345'
            // ]);
        });
    }


    public function index()
    {
        // $identicon = new \Identicon\Identicon(new ImageMagickGenerator());

        // // 存储空间名称
        // $bucket = "onchat";
        // $ossClient = Client::getInstance();
        // $user = User::select()->toArray();
        // foreach ($user as $item) {
        //     $object = (env('app_debug', false) ? 'dev/' : '') . 'avatar/' . $item['id'] . '/' . md5((string) DateUtil::now()) . '.png';
        //     $content = $identicon->getImageData($item['id'], 128, null, '#f5f5f5');
        //     try {
        //         $ossClient->putObject($bucket, $object, $content);
        //         UserInfo::create([
        //             'user_id' => $item['id'],
        //             'nickname' => $item['username'],
        //             'login_time' => $item['update_time'],
        //             'avatar' => $object,
        //             'background_image' => 'http://static.hypergo.net/img/rkph.jpg',
        //         ]);
        //     } catch (OssException $e) {
        //         printf(__FUNCTION__ . ": FAILED\n");
        //         printf($e->getMessage() . "\n");
        //     }
        // }
    }

    /**
     * 验证码
     *
     * @return Response
     */
    public function captcha(): Response
    {
        return Captcha::create();
    }
}
