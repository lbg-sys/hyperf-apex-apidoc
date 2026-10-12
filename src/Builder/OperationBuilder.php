<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\RateLimit\RateLimitRuleReader;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Mapping;
use OpenApi\Spec as OA;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * 把单个控制器方法装配成一个或多个 Operation（同一路径的多个 HTTP 方法各算一个）。
 */
final class OperationBuilder
{
    public function __construct(
        private readonly RequestBuilder $requests,
        private readonly ResponseBuilder $responses,
        private readonly ControllerScanner $scanner,
        private readonly SecurityResolver $security,
        private readonly RateLimitRuleReader $rateLimits,
    ) {
    }

    /**
     * @param ReflectionClass<object> $controller
     * @return list<OA\Operation>
     */
    public function build(ReflectionClass $controller): array
    {
        $prefix = rtrim($controller->getAttributes(Controller::class)[0]->newInstance()->prefix, '/');
        if ($prefix === '') {
            throw new RuntimeException(sprintf(
                '%s 的 #[Controller] 必须显式写出 prefix（Hyperf 会按类名推导，而文档不复刻这条规则）。',
                $controller->getName()
            ));
        }

        $folder = ($controller->getAttributes(ApiFolder::class)[0] ?? null)?->newInstance()->path;

        $operations = [];
        foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->scanner->isExcluded($controller->getName() . '::' . $method->getName())) {
                continue;
            }

            foreach ($method->getAttributes(Mapping::class, ReflectionAttribute::IS_INSTANCEOF) as $mappingAttribute) {
                $mapping = $mappingAttribute->newInstance();
                $path = $prefix . '/' . ltrim($this->relativePath($mapping->path, $controller, $method), '/');
                foreach ($mapping->methods as $httpMethod) {
                    $operations[] = $this->operation($method, $path, strtolower((string) $httpMethod), $folder, $controller);
                }
            }
        }

        return $operations;
    }

    /**
     * 校验 mapping 的 path 能被这里的拼装规则正确表达。
     *
     * Hyperf 的 DispatcherFactory 对空 path 会补上方法名的蛇形写法、对绝对路径会
     * 忽略 prefix —— 本生成器两种都不复刻，所以直接拒绝这些写法（AGENTS.md 本来也禁止），
     * 而不是产出一个和真实路由不一致的 URL。
     *
     * @param ReflectionClass<object> $controller
     */
    private function relativePath(?string $path, ReflectionClass $controller, ReflectionMethod $method): string
    {
        $where = $controller->getName() . '::' . $method->getName() . '()';

        if ($path === null || $path === '') {
            throw new RuntimeException(sprintf(
                '%s 的 mapping 必须显式写出动作路径（Hyperf 会补上方法名，而文档不复刻这条规则）。',
                $where
            ));
        }

        if (str_starts_with($path, '/')) {
            throw new RuntimeException(sprintf(
                '%s 的 mapping path %s 是绝对路径，Hyperf 会忽略控制器 prefix 而文档不会，两者会不一致。',
                $where,
                $path
            ));
        }

        return $path;
    }

    /**
     * 由 URL path 与 HTTP 方法确定性派生 operationId。
     *
     * OpenAPI 要求同一文档中 (path, method) 组合唯一，因此只要 URL 不冲突，派生出的
     * operationId 就不会重复；不同命名空间下的同名控制器也不再互相干扰。产物形如
     * post_api_user_profile_update，与真实 URL 一一对应、可直接反查。
     */
    private static function operationIdFromUrl(string $path, string $httpMethod): string
    {
        $path = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $path), '_');

        return strtolower($httpMethod) . '_' . $path;
    }

    /**
     * @param ReflectionClass<object> $controller
     */
    private function operation(
        ReflectionMethod $method,
        string $path,
        string $httpMethod,
        ?string $folder,
        ReflectionClass $controller,
    ): OA\Operation {
        $operation = ($method->getAttributes(ApiOperation::class)[0] ?? null)?->newInstance();
        $summary = $operation->summary ?? $method->getName();

        $requirements = $this->security->requirementsFor($method);
        $permissions = $this->security->requiredPermissions($method);

        // 成功响应先于请求装配，以保持 components.schemas 的注册顺序稳定。
        $success = $this->responses->success($method);
        $request = $this->requests->build($method);
        $parameters = array_merge($this->requests->headerParameters($controller, $method), $request['parameters']);
        $errors = $this->responses->errors(
            $method,
            $requirements !== null,
            $permissions !== [],
            $parameters !== [] || $request['requestBody'] !== null,
            $this->rateLimits->read($method) !== [],
        );

        $extensions = ['apifox-name' => $summary];
        if ($folder !== null && $folder !== '') {
            $extensions['apifox-folder'] = $folder;
        }
        if ($permissions !== []) {
            $extensions['required-permissions'] = $permissions;
        }

        return new OA\Operation(
            path: $path,
            method: $httpMethod,
            // 唯一性只依赖 (path, method)：OpenAPI 允许同路径多动词、却要求每个 operationId
            // 全文档唯一。按「短类名_方法名」拼会让不同命名空间的同名控制器互相撞掉，
            // 且类名一改文档 id 就变；改成从 URL path 派生后，
            // 只要 URL 不冲突 operationId 就不重复，客户端标识也跟随 URL 契约走。
            operationId: self::operationIdFromUrl($path, $httpMethod),
            summary: $summary,
            description: ($operation->description ?? '') !== '' ? $operation->description : null,
            tags: $operation?->tags !== [] ? $operation?->tags : null,
            parameters: $parameters !== [] ? $parameters : null,
            requestBody: $request['requestBody'],
            responses: [$success, ...$errors],
            security: $requirements,
            x: $extensions,
        );
    }
}
