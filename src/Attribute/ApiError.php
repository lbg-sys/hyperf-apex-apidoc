<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Attribute;

use Attribute;
use Hyperf\Apex\Enum\ErrorCode;

/**
 * 声明该接口除通用错误之外还会返回哪些业务错误。
 *
 * 通用错误（400 业务失败、422 参数校验失败、401/403 认证与权限、500 系统错误）
 * 由生成器根据异常处理器与访问声明自动补全，不需要标注 —— 补全的判据是**接口的访问声明**，
 * 不是「谁会抛这个错」（见 `ApiDoc\Builder\ResponseBuilder::errors()`）：
 * 401 只在接口需要认证时补；403 只在接口声明了权限码（`#[RequirePermission]`）时补。
 *
 * 这个 Attribute 只用于补充「只有本接口才会出现」的错误，例如资源不存在、状态冲突。
 *
 * **例外**：接口本身没有权限码（公开 / 仅登录 / 集成 Token）而 Service 层又直抛
 * `PERMISSION_DENIED` 时，403 推不出来，必须在这里显式声明。公开的七牛回调就是这种 ——
 * 它用回调签名校验代替权限码，签名不通过时 Service 抛 403。
 *
 * 例：#[ApiError(ErrorCode::RESOURCE_NOT_FOUND, ErrorCode::RESOURCE_CONFLICT)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiError
{
    /** @var list<ErrorCode> */
    public readonly array $codes;

    public function __construct(ErrorCode ...$codes)
    {
        $this->codes = array_values($codes);
    }
}
