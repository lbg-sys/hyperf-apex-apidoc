<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\ApiDoc\Attribute\ApiError;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Attribute\ResponsePayload;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use OpenApi\Spec as OA;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * 构造成功响应：外层是 ApiResponseDTO 信封，data 由方法返回类型推导后注入。
 */
final class ResponseBuilder
{
    public function __construct(
        private readonly DTOSchemaFactory $schemas,
        private readonly SchemaFragmentTranslator $translator,
        private readonly ReturnTypeResolver $returnTypes,
        private readonly ResponseEncoderResolver $encoders,
        private readonly ErrorResponseCatalog $errors,
        /** @var class-string<ApiResponseInterface> */
        private readonly string $responseDtoClass,
    ) {
    }

    /**
     * 自动推导的错误响应。
     *
     * 状态码严格对应真实可达的失败路径：
     * - 400 业务异常，任何接口都可能抛；500 未捕获异常，任何接口都可能发生
     * - 422 只在接口有请求参数时可达（校验发生在参数水合阶段）
     * - 401 只在接口需要认证时可达（AuthenticationMiddleware 与 IntegrationTokenMiddleware 都抛这个）
     * - 403 只在接口声明了权限码时可达（AuthorizationMiddleware 才会抛），
     *   仅要求登录、或走集成 Token 的接口不会出现 403
     * - 429 只在接口声明了 #[RateLimit] 时可达（RateLimitMiddleware 抛 TOO_MANY_REQUESTS）
     *
     * 只有本接口特有的错误（资源不存在、状态冲突等）由方法级 #[ApiError] 声明。
     *
     * @return list<OA\Response>
     */
    public function errors(
        ReflectionMethod $method,
        bool $requiresAuthentication,
        bool $requiresPermission,
        bool $hasRequestInput,
        bool $rateLimited,
    ): array {
        $statuses = [ErrorCode::BUSINESS_ERROR->getHttpStatus()->value];
        if ($hasRequestInput) {
            $statuses[] = ErrorCode::VALIDATION_FAILED->getHttpStatus()->value;
        }
        if ($requiresAuthentication) {
            $statuses[] = ErrorCode::UNAUTHENTICATED->getHttpStatus()->value;
        }
        if ($requiresPermission) {
            $statuses[] = ErrorCode::PERMISSION_DENIED->getHttpStatus()->value;
        }
        if ($rateLimited) {
            $statuses[] = ErrorCode::TOO_MANY_REQUESTS->getHttpStatus()->value;
        }
        foreach ($method->getAttributes(ApiError::class) as $attribute) {
            foreach ($attribute->newInstance()->codes as $code) {
                $statuses[] = $code->getHttpStatus()->value;
            }
        }
        $statuses[] = ErrorCode::INTERNAL_ERROR->getHttpStatus()->value;
        $statuses = array_values(array_unique($statuses));
        sort($statuses);

        $responses = [];
        foreach ($statuses as $status) {
            $responses[] = $this->errors->reference($status);
        }

        return $responses;
    }

    public function success(ReflectionMethod $method): OA\Response
    {
        $envelope = $this->schemas->objectFragment(
            $this->responseDtoClass,
            [$this->payloadProperty() => $this->returnTypes->fragmentFor($method)]
        );

        return new OA\Response(
            response: 200,
            description: '成功',
            content: new OA\MediaType(
                mediaType: $this->encoders->resolve($method)->contentType(),
                schema: $this->translator->translate($envelope),
            ),
        );
    }

    /**
     * 响应信封中唯一标记 #[ResponsePayload] 的属性名。
     */
    private function payloadProperty(): string
    {
        $names = [];
        foreach ((new ReflectionClass($this->responseDtoClass))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(ResponsePayload::class) !== []) {
                $names[] = $property->getName();
            }
        }

        if (count($names) !== 1) {
            throw new RuntimeException(sprintf(
                'Response DTO %s must have exactly one property marked with %s.',
                $this->responseDtoClass,
                ResponsePayload::class
            ));
        }

        return $names[0];
    }
}
