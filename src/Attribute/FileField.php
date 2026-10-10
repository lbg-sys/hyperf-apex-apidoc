<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Attribute;

use Attribute;

/**
 * 声明 multipart 表单里的文件字段。
 *
 * 文件不经过请求 DTO —— 它由 Service 直接从 RequestInterface::file() 取出，
 * 因此类型系统里没有它的痕迹，只能显式声明。
 *
 * 例：#[FileField('file', '待上传的文件', mediaType: 'image/*')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class FileField
{
    /**
     * @param string $name 表单字段名，与 $request->file() 取值时一致
     * @param null|string $mediaType 限定内容类型（如 image/png、image/*），null 表示不限
     * @param bool $multiple 是否允许一次上传多个文件
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $required = true,
        public readonly ?string $mediaType = null,
        public readonly bool $multiple = false,
    ) {
    }
}
