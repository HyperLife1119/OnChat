<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 入群申请
 */
class ChatRequest extends Model
{
    protected $json = ['readed_list'];

    /** 状态：等待 */
    const STATUS_WAIT = 0;
    /** 状态：同意 */
    const STATUS_AGREE = 1;
    /** 状态：拒绝 */
    const STATUS_REJECT = 2;
    /** 状态：删除 */
    const STATUS_DELETE = 3;
    /** 状态：忽略 */
    const STATUS_IGNORE = 4;


    /** 申请人 */
    public function applicant()
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function chatroom()
    {
        return $this->belongsTo(Chatroom::class, 'chatroom_id');
    }

    /**
     * ID字段获取器
     *
     * @param string|integer $value
     * @return integer
     */
    public function getIdAttr($value): int
    {
        return (int) $value;
    }
}
