<?php

declare(strict_types=1);

namespace app\service;

use app\core\Result;
use app\core\storage\Storage;
use app\facade\ChatroomService;
use app\facade\UserService;
use app\model\ChatMember as ChatMemberModel;
use app\model\ChatRequest as ChatRequestModel;
use app\model\ChatSession as ChatSessionModel;
use app\model\Chatroom as ChatroomModel;
use app\model\UserInfo as UserInfoModel;
use app\util\Redis as RedisUtil;
use app\util\Str as StrUtil;
use think\facade\Db;

class Chat
{
    /** 群聊人数已满 */
    const CODE_PEOPLE_NUM_FULL = 1;
    /** 附加消息过长 */
    const CODE_REASON_LONG = 2;
    /** 请求已被处理 */
    const CODE_REQUEST_HANDLED = 3;

    /** 附加消息最大长度 */
    const REASON_MAX_LENGTH = 50;

    /** 响应消息预定义 */
    const MSG = [
        self::CODE_PEOPLE_NUM_FULL => '聊天室人数已满！',
        self::CODE_REASON_LONG  => '附加消息长度不能大于' . self::REASON_MAX_LENGTH . '位字符',
        self::CODE_REQUEST_HANDLED => '该请求已被处理！'
    ];

    /**
     * 邀请好友入群
     *
     * @param integer $inviter 邀请人ID
     * @param integer $chatroomId 邀请进入的群聊ID
     * @param array $chatroomIdList 受邀人的私聊聊天室ID列表
     * @return Result
     */
    public function invite(int $inviter, int $chatroomId, array $chatroomIdList): Result
    {
        // 找到这个聊天室
        $chatroom = ChatroomModel::join('chat_member', 'chat_member.chatroom_id = chatroom.id')
            ->where([
                ['chatroom.id', '=', $chatroomId],
                ['chat_member.user_id', '=', $inviter],
                ['chat_member.role', '=', ChatMemberModel::ROLE_HOST]
            ])->field('chatroom.*')->find();

        if (!$chatroom) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        // 人数超出上限
        if (ChatroomService::isPeopleNumFull($chatroomId)) {
            return new Result(self::CODE_PEOPLE_NUM_FULL, self::MSG[self::CODE_PEOPLE_NUM_FULL]);
        }

        // 找到正确的私聊聊天室ID（防止客户端乱传ID）
        $chatroomIdList = ChatMemberModel::join('chatroom', 'chatroom.id = chat_member.chatroom_id')->where([
            ['chat_member.user_id', '=', $inviter],
            ['chatroom.id', 'IN', $chatroomIdList],
            ['chatroom.type', '=', ChatroomModel::TYPE_PRIVATE_CHAT],
        ])->column('chatroom.id');

        return Result::success($chatroomIdList);
    }


    /**
     * 申请加入聊天室
     *
     * @param integer $applicant 申请人ID
     * @param integer $chatroomId 聊天室ID
     * @param string $reason 申请原因
     * @return Result
     */
    public function request(int $applicant, int $chatroomId, string $reason = null): Result
    {
        // 如果已经是聊天室成员了
        if (ChatroomService::isMember($chatroomId, $applicant)) {
            return new Result(Result::CODE_ERROR_PARAM, '你已加入该聊天室！');
        }

        // 如果人数已满
        if (ChatroomService::isPeopleNumFull($chatroomId)) {
            return new Result(self::CODE_PEOPLE_NUM_FULL, '聊天室人数已满！');
        }

        // 如果剔除空格后长度为零，则直接置空
        if ($reason && mb_strlen(StrUtil::trimAll($reason), 'utf-8') == 0) {
            $reason = null;
        }

        // 如果附加消息长度超出
        if ($reason && mb_strlen($reason, 'utf-8') > self::REASON_MAX_LENGTH) {
            return new Result(self::CODE_REASON_LONG, self::MSG[self::CODE_REASON_LONG]);
        }

        // 先找找之前有没有申请过
        $request = ChatRequestModel::where([
            ['applicant_id', '=', $applicant],
            ['chatroom_id', '=', $chatroomId],
            ['status', '<>', ChatRequestModel::STATUS_AGREE]
        ])->find();

        $timestamp = time() * 1000;

        if ($request) {
            $request->status = ChatRequestModel::STATUS_WAIT;
            $request->request_reason = $reason;
            $request->reject_reason  = null;
            $request->readed_list    = [];
            $request->handler_id     = null;
            $request->update_time    = $timestamp;
            $request->save();
        } else {
            $request = ChatRequestModel::create([
                'chatroom_id'    => $chatroomId,
                'applicant_id'   => $applicant,
                'status'         => ChatRequestModel::STATUS_WAIT,
                'request_reason' => $reason,
                'readed_list'    => [],
                'create_time'    => $timestamp,
                'update_time'    => $timestamp,
            ]);
        }

        // 显示群主/管理员的聊天室通知会话
        ChatSessionModel::where('type', '=', ChatSessionModel::TYPE_CHATROOM_NOTICE)
            ->where('user_id', 'IN', function ($query) use ($chatroomId) {
                $query->table('chat_member')
                    ->where('chatroom_id', '=', $chatroomId)
                    ->where(function ($query) {
                        $query->whereOr([
                            ['role', '=', ChatMemberModel::ROLE_HOST],
                            ['role', '=', ChatMemberModel::ROLE_MANAGE],
                        ]);
                    })->field('user_id');
            })
            ->update([
                'chat_session.update_time' => time() * 1000,
                'chat_session.visible' => true
            ]);

        $storage = Storage::getInstance();

        $info = UserInfoModel::where('user_info.user_id', '=', $applicant)
            ->field([
                'user_info.nickname AS applicantNickname',
                'user_info.avatar AS applicantAvatarThumbnail'
            ])
            ->find()
            ->toArray();
        $info['applicantAvatarThumbnail'] = $storage->getThumbnailImageUrl($info['applicantAvatarThumbnail']);

        $chatroom = ChatroomModel::field('chatroom.name AS chatroomName')
            ->find($chatroomId)
            ->toArray();

        return Result::success($request->toArray() + $info + $chatroom);
    }

    /**
     * 同意入群申请
     *
     * @param integer $id 请求ID
     * @param integer $handler 处理人ID
     * @return Result
     */
    public function agree(int $id, int $handler): Result
    {
        $request = ChatRequestModel::join('chat_member', 'chat_request.chatroom_id = chat_member.chatroom_id')
            ->join('user_info applicant', 'chat_request.applicant_id = applicant.user_id')
            ->join('chatroom', 'chatroom.id = chat_request.chatroom_id')
            ->where([
                'chat_request.id' => $id,
                'chat_member.user_id' => $handler
            ])
            ->where(function ($query) {
                $query->whereOr([
                    ['chat_member.role', '=', ChatMemberModel::ROLE_HOST],
                    ['chat_member.role', '=', ChatMemberModel::ROLE_MANAGE],
                ]);
            })
            ->field([
                'applicant.nickname AS applicantNickname',
                'applicant.avatar AS applicantAvatarThumbnail',
                'chatroom.name AS chatroomName',
                'chatroom.avatar AS chatroomAvatarThumbnail',
                'chat_request.*'
            ])
            ->find();

        if (!$request) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        // 已被处理
        if ($request->handler_id) {
            return new Result(self::CODE_REQUEST_HANDLED, self::MSG[self::CODE_REQUEST_HANDLED]);
        }

        $chatroomId = $request->chatroom_id;

        // 人数超出上限
        if (ChatroomService::isPeopleNumFull($chatroomId)) {
            return new Result(self::CODE_PEOPLE_NUM_FULL, self::MSG[self::CODE_PEOPLE_NUM_FULL]);
        }

        // 启动事务
        Db::startTrans();
        try {
            // 如果自己还未读
            if (!in_array($handler, $request->readed_list)) {
                $readedList = $request->readed_list;
                $readedList[] = $handler;
                $request->readed_list = $readedList;
            }

            $request->handler_id  = $handler;
            $request->status      = ChatRequestModel::STATUS_AGREE;
            $request->update_time = time() * 1000;
            $request->save();

            $result = ChatroomService::addMember($chatroomId, $request->applicant_id, $request->applicantNickname);
            if ($result->code !== Result::CODE_SUCCESS) {
                Db::rollback();
                return $result;
            }

            $storage = Storage::getInstance();

            $chatSession = $result->data;

            // 补充一些信息
            $chatSession['title'] = $request->chatroomName;
            $chatSession['avatarThumbnail'] = $storage->getThumbnailImageUrl($request->chatroomAvatarThumbnail);
            $chatSession['data']['chatroomType'] = ChatroomModel::TYPE_GROUP_CHAT;

            $request->applicantAvatarThumbnail = $storage->getThumbnailImageUrl($request->applicantAvatarThumbnail);

            $request = $request->toArray();

            $request['handlerNickname'] = RedisUtil::getUserByUserId($handler)['username'];

            unset($request['chatroomAvatarThumbnail']);

            Db::commit();
            return Result::success([$request, $chatSession]);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return new Result(Result::CODE_ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * 拒绝入群申请
     *
     * @param integer $id 请求ID
     * @param integer $handler 处理人ID
     * @param string $reason 拒绝原因
     * @return Result
     */
    public function reject(int $id, int $handler, ?string $reason): Result
    {
        // 如果剔除空格后长度为零，则直接置空
        if ($reason && mb_strlen(StrUtil::trimAll($reason), 'utf-8') == 0) {
            $reason = null;
        }

        // 如果附加消息长度超出
        if ($reason && mb_strlen($reason, 'utf-8') > self::REASON_MAX_LENGTH) {
            return new Result(self::CODE_REASON_LONG, self::MSG[self::CODE_REASON_LONG]);
        }

        $request = ChatRequestModel::join('chat_member', 'chat_request.chatroom_id = chat_member.chatroom_id')
            ->join('user_info applicant', 'chat_request.applicant_id = applicant.user_id')
            ->join('chatroom', 'chatroom.id = chat_request.chatroom_id')
            ->where([
                'chat_request.id' => $id,
                'chat_member.user_id' => $handler
            ])
            ->where(function ($query) {
                $query->whereOr([
                    ['chat_member.role', '=', ChatMemberModel::ROLE_HOST],
                    ['chat_member.role', '=', ChatMemberModel::ROLE_MANAGE],
                ]);
            })
            ->field([
                'applicant.nickname AS applicantNickname',
                'applicant.avatar AS applicantAvatarThumbnail',
                'chatroom.name AS chatroomName',
                'chatroom.avatar AS chatroomAvatarThumbnail',
                'chat_request.*'
            ])
            ->find();

        if (!$request) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        // 已被处理
        if ($request->handler_id) {
            return new Result(self::CODE_REQUEST_HANDLED, self::MSG[self::CODE_REQUEST_HANDLED]);
        }

        // 如果自己还未读
        if (!in_array($handler, $request->readed_list)) {
            // 这里需要拿个变量存起来先，否则错误
            $readedList = $request->readed_list;
            $readedList[] = $handler;
            $request->readed_list = $readedList;
        }
        $request->reject_reason = $reason;
        $request->status        = ChatRequestModel::STATUS_REJECT;
        $request->handler_id    = $handler;
        $request->update_time   = time() * 1000;
        $request->save();

        $storage = Storage::getInstance();

        $request = $request->toArray();
        $request['applicantAvatarThumbnail'] = $storage->getThumbnailImageUrl($request['applicantAvatarThumbnail']);
        $request['chatroomAvatarThumbnail']  = $storage->getThumbnailImageUrl($request['chatroomAvatarThumbnail']);
        $request['handlerNickname'] = RedisUtil::getUserByUserId($handler)['username'];

        return Result::success($request);
    }

    /**
     * 已读所有入群请求
     *
     * @return Result
     */
    public function readed(): Result
    {
        $userId = UserService::getId();
        ChatRequestModel::whereRaw("!JSON_CONTAINS(readed_list, JSON_ARRAY({$userId}))")
            ->update([
                'readed_list' => Db::raw("JSON_ARRAY_APPEND(readed_list, '$', {$userId})")
            ]);

        ChatSessionModel::update(['unread' => 0], [
            'user_id' => $userId,
            'type' => ChatSessionModel::TYPE_CHATROOM_NOTICE
        ]);

        return Result::success();
    }

    /**
     * 通过请求ID获取我收到的入群请求
     *
     * @param integer $id
     * @return Result
     */
    public function getReceiveRequestById(int $id): Result
    {
        $userId = UserService::getId();

        $request = ChatRequestModel::join('chat_member', 'chat_request.chatroom_id = chat_member.chatroom_id')
            ->join('user_info applicant', 'chat_request.applicant_id = applicant.user_id')
            ->leftJoin('user_info handler', 'chat_request.handler_id = handler.user_id')
            ->join('chatroom', 'chatroom.id = chat_request.chatroom_id')
            ->where([
                'chat_request.id' => $id,
                'chat_member.user_id' => $userId
            ])
            ->where(function ($query) {
                $query->whereOr([
                    ['chat_member.role', '=', ChatMemberModel::ROLE_HOST],
                    ['chat_member.role', '=', ChatMemberModel::ROLE_MANAGE],
                ]);
            })
            ->field([
                'applicant.nickname AS applicantNickname',
                'applicant.avatar AS applicantAvatarThumbnail',
                'handler.nickname AS handlerNickname',
                'chatroom.name AS chatroomName',
                'chat_request.*'
            ])
            ->find();

        if (!$request) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        $storage = Storage::getInstance();
        $request->applicantAvatarThumbnail = $storage->getThumbnailImageUrl($request->applicantAvatarThumbnail);

        return Result::success($request->toArray());
    }

    /**
     * 获取我收到的入群申请
     *
     * @return Result
     */
    public function getReceiveRequests(): Result
    {
        $userId = UserService::getId();
        $storage = Storage::getInstance();

        $data = ChatRequestModel::join('chat_member', 'chat_request.chatroom_id = chat_member.chatroom_id')
            ->join('user_info applicant', 'chat_request.applicant_id = applicant.user_id')
            ->leftJoin('user_info handler', 'chat_request.handler_id = handler.user_id')
            ->join('chatroom', 'chatroom.id = chat_request.chatroom_id')
            ->where('chat_member.user_id', '=', $userId)
            ->where(function ($query) {
                $query->whereOr([
                    ['chat_member.role', '=', ChatMemberModel::ROLE_HOST],
                    ['chat_member.role', '=', ChatMemberModel::ROLE_MANAGE],
                ]);
            })
            ->field([
                'applicant.nickname AS applicantNickname',
                'applicant.avatar AS applicantAvatarThumbnail',
                'handler.nickname AS handlerNickname',
                'chatroom.name AS chatroomName',
                'chat_request.*'
            ])
            ->select()
            ->toArray();

        foreach ($data as $key => $value) {
            $data[$key]['applicantAvatarThumbnail'] = $storage->getThumbnailImageUrl($value['applicantAvatarThumbnail']);
        }

        return Result::success($data);
    }
}
