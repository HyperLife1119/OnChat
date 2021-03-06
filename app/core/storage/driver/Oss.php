<?php

declare(strict_types=1);

namespace app\core\storage\driver;

use OSS\OssClient;
use app\core\Result;
use app\core\storage\Driver;
use think\Config;
use think\File;

/**
 * 阿里云OSS对象存储服务 存储驱动
 */
class Oss extends Driver
{
    private $client;

    private $config;

    public function __construct(Config $config)
    {
        $accessKeyId     = $config->get('oss.access_key_id');
        $accessKeySecret = $config->get('oss.access_key_secret');
        $endpoint        = $config->get('oss.endpoint');

        $this->client = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
        $this->config = $config;
    }

    public function getRootPath(): string
    {
        return env('APP_DEBUG') ? 'dev/' : '';
    }

    public function save(string $path, string $file, $data): Result
    {
        $bucket = $this->getBucket();
        $filename =  $path . $file;

        switch (true) {
            case $data instanceof File:
                $this->uploadFile($bucket, $filename, $data->getRealPath());
                break;

            case is_string($data):
                $this->putObject($bucket, $path . $file, $data);
                break;
        }

        return Result::success();
    }

    public function clear(string $path, int $count): void
    {
        $bucket = $this->getBucket();

        $options = [
            'prefix'   => $path, // 文件路径前缀
            'max-keys' => 15,    // 最大数量
        ];

        // 列举用户所有头像
        $list = $this->listObjects($bucket, $options)->getObjectList();
        $num = count($list);
        // 如果文件冗余
        if ($num > $count) {
            // 按照时间进行升序
            usort($list, function ($a, $b) {
                return strtotime($a->getLastModified()) - strtotime($b->getLastModified());
            });

            // 需要删除的OBJ
            $objects = [];

            $num -= $count;
            for ($i = 0; $i < $num; $i++) {
                $objects[] = $list[$i]->getKey();
            }

            $this->deleteObjects($bucket, $objects);
        }
    }

    public function delete(): Result
    {
        return Result::success();
    }

    public function getOriginalImageUrl(string $filename): string
    {
        return $this->signImageUrl($filename, $this->getOriginalImgStylename());
    }

    public function getThumbnailImageUrl(string $filename): string
    {
        return $this->signImageUrl($filename);
    }

    /**
     * 获取	Bucket 域名
     *
     * @return string
     */
    public function getDomain(): string
    {
        return $this->config->get('oss.domain');
    }

    /**
     * 获取	Bucket 名字
     *
     * @return string
     */
    public function getBucket(): string
    {
        return $this->config->get('oss.bucket');
    }

    /**
     * 获取图片样式名：原图
     *
     * @return string
     */
    public function getOriginalImgStylename(): string
    {
        return $this->config->get('oss.img_stylename_original');
    }

    /**
     * 获取图片样式名：缩略图
     *
     * @return string
     */
    public function getThumbnailImgStylename(): string
    {
        return $this->config->get('oss.img_stylename_thumbnail');
    }

    /**
     * 签名图像URL
     *
     * @param string $object
     * @param string|null $stylename 默认为缩略图样式
     * @return string
     */
    private function signImageUrl(string $object, string $stylename = null): string
    {
        return $this->signUrl($this->getBucket(), $object, 86400, 'GET', [
            OssClient::OSS_PROCESS => 'style/' . ($stylename ?: $this->getThumbnailImgStylename())
        ]);
    }

    public function __call($method, $args)
    {
        return $this->client->$method(...$args);
    }
}
