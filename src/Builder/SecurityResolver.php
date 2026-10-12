<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\Http\Middleware\IntegrationTokenMiddleware;
use Hyperf\Apex\Security\AccessDeclarationReader;
use OpenApi\Spec as OA;
use ReflectionMethod;

/**
 * 由已有的访问声明推导 OpenAPI security。
 *
 * 项目已经有一套机器可读的授权声明（#[PublicAccess] / #[Authenticated] /
 * #[IntegrationAccess] / #[RequirePermission] 等），并且 PermissionDeclarationCheckListener
 * 在启动时强制每个接口方法都必须声明，因此不需要再让开发者写一份文档专用的安全标注 ——
 * 直接复用同一份声明，两者不会漂移。
 *
 * AccessDeclarationReader 的「方法级优先、回退类级」语义与 OpenAPI 中
 * operation 覆盖全局默认的语义一致，混合型控制器（如登录接口公开、登出需认证）能正确处理。
 */
final class SecurityResolver
{
    public const BEARER = 'bearerAuth';

    public const INTEGRATION = 'integrationToken';

    public const API_KEY = 'apiKeyAuth';

    /** Bearer 方案对应的请求头。 */
    public const BEARER_HEADER = 'Authorization';

    /** API 密钥方案对应的请求头。 */
    public const API_KEY_HEADER = 'X-Api-Key';

    /** @var array<string, OA\Security\Scheme> 已被引用的安全方案 */
    private array $used = [];

    public function __construct(private readonly AccessDeclarationReader $declarations)
    {
    }

    /**
     * 公开接口返回 null（省略 security）。
     *
     * 之所以不产出空数组，是因为编译器会过滤掉空值、`security: []` 无法表达；
     * 改为根级不声明默认 security，省略即等价于「无要求」。
     *
     * @return null|list<OA\Security\Requirement>
     */
    public function requirementsFor(ReflectionMethod $method): ?array
    {
        $declaration = $this->declarations->read($method);

        if ($declaration->integration) {
            return [$this->requirement(self::INTEGRATION)];
        }

        // 密钥接入优先于 Bearer：`#[ApiKeyAccess]` 叠加权限码时，凭证仍然只有一种，
        // 文档要让调用方看到的是「用哪把密钥」而不是「先登录再拿 token」。
        if ($declaration->apiKey) {
            return [$this->requirement(self::API_KEY)];
        }

        /*
         * 角色要求隐含「已登录」：没有身份就无从判角色。所以只写 #[RequireRole] 的接口
         * 同样必须声明 security —— 否则文档会把它描述成公开可调。
         */
        if ($declaration->authenticated || $declaration->requiresPermission() || $declaration->roles !== []) {
            return [$this->requirement(self::BEARER)];
        }

        return null;
    }

    /**
     * 接口要求的角色，作为厂商扩展暴露给文档消费方。
     *
     * @return list<string>
     */
    public function requiredRoles(ReflectionMethod $method): array
    {
        return $this->declarations->read($method)->roles;
    }

    /**
     * 是否声明了权限码或角色：两者都会让 403 成为可达的失败路径。
     */
    public function requiresAuthorization(ReflectionMethod $method): bool
    {
        $declaration = $this->declarations->read($method);

        return $declaration->permissions !== [] || $declaration->roles !== [];
    }

    /**
     * 接口需要的权限码，作为厂商扩展暴露给文档消费方。
     *
     * @return list<string>
     */
    public function requiredPermissions(ReflectionMethod $method): array
    {
        return $this->declarations->read($method)->requiredCodeValues();
    }

    /**
     * 已被引用的安全方案，供注册进 components.securitySchemes。
     *
     * @return list<OA\Security\Scheme>
     */
    public function components(): array
    {
        ksort($this->used);

        return array_values($this->used);
    }

    private function requirement(string $scheme): OA\Security\Requirement
    {
        $this->used[$scheme] ??= $this->scheme($scheme);

        return new OA\Security\Requirement(scheme: $scheme, scopes: []);
    }

    private function scheme(string $name): OA\Security\Scheme
    {
        /*
         * 组件键用 `component:` 而不是 `securityScheme:`：swagger-php 6.12 起后者作为组件键已弃用
         * （会触发 deprecation）并将在 8.0 移除 —— 与 `Schema::schema` 是同一件事。
         * `securityScheme` 属性本身仍在，只是组件的键改由 `component` 表达。
         */
        return match ($name) {
            self::INTEGRATION => new OA\Security\Scheme(
                component: self::INTEGRATION,
                type: 'apiKey',
                description: '客户端集成 Token，直接放在请求头里，不加 Bearer 前缀。',
                name: IntegrationTokenMiddleware::HEADER,
                in: 'header',
            ),
            self::API_KEY => new OA\Security\Scheme(
                component: self::API_KEY,
                type: 'apiKey',
                description: '用户 API 密钥，直接放在请求头里，不加 Bearer 前缀；由用户在各端后台自助创建，可设有效期、可单独吊销。',
                name: self::API_KEY_HEADER,
                in: 'header',
            ),
            default => new OA\Security\Scheme(
                component: self::BEARER,
                type: 'http',
                description: sprintf('登录后获得的访问令牌，放在 %s 请求头，格式为 Bearer <token>。', self::BEARER_HEADER),
                scheme: 'bearer',
                bearerFormat: 'at_<64 位十六进制>',
            ),
        };
    }
}
