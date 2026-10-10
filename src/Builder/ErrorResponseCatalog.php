<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Enum\HttpStatus;
use OpenApi\Spec as OA;
use RuntimeException;

/**
 * 由 ErrorCode 枚举推导错误响应组件。
 *
 * 项目的错误响应是完全统一的：AppExceptionHandler 一律输出 {code, message, data} 信封，
 * 并用 ErrorCode::getHttpStatus() 对应的真实 HTTP 状态码。因此错误响应可以整体从枚举生成，
 * 使用者不需要为通用错误写任何标注。
 *
 * 同一个状态码可能对应多个 ErrorCode（如 400 同时是业务失败与参数错误），
 * 因此 code 字段产出的是该状态码下所有可能取值的 enum。
 */
final class ErrorResponseCatalog
{
    /** 校验失败的 data 里额外带字段级错误信息，结构与其它错误不同。 */
    private const VALIDATION_STATUS = 422;

    /** @var array<int, OA\Response> 已被引用的响应组件，键为状态码 */
    private array $used = [];

    public function __construct(private readonly SchemaFragmentTranslator $translator)
    {
    }

    /**
     * 标记某个状态码被引用，并返回指向共享组件的引用。
     */
    public function reference(int $status): OA\Response
    {
        $this->used[$status] ??= $this->response($status);

        return new OA\Response(response: $status, ref: '#/components/responses/' . self::componentName($status));
    }

    /**
     * 已被引用的响应组件，供注册进 components.responses。
     *
     * @return list<OA\Response>
     */
    public function components(): array
    {
        ksort($this->used);

        return array_values($this->used);
    }

    public static function componentName(int $status): string
    {
        // 状态码全部来自 ErrorCode::getHttpStatus()，所以 tryFrom 不会失败；
        // 真失败了说明有人绕过枚举传了裸状态码，那是缺陷而不是需要兜底的情况。
        $case = HttpStatus::tryFrom($status)
            ?? throw new RuntimeException(sprintf('%d 不是 HttpStatus 枚举里的状态码。', $status));

        return str_replace('_', '', ucwords(strtolower($case->name), '_'));
    }

    private function response(int $status): OA\Response
    {
        $codes = self::codesFor($status);
        $descriptions = array_map(static fn (ErrorCode $code): string => sprintf('%d=%s', $code->value, $code->getMessage()), $codes);

        return new OA\Response(
            response: self::componentName($status),
            description: implode('；', $descriptions),
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: $this->translator->translate($this->envelope($codes, $status)),
            ),
        );
    }

    /**
     * @param list<ErrorCode> $codes
     */
    private function envelope(array $codes, int $status): SchemaFragment
    {
        $data = $status === self::VALIDATION_STATUS
            ? SchemaFragment::of([
                'type' => 'object',
                'properties' => [
                    'errors' => SchemaFragment::of([
                        'type' => 'object',
                        'description' => '字段级校验错误，键为字段名，值为错误消息列表',
                        'additionalProperties' => SchemaFragment::of([
                            'type' => 'array',
                            'items' => SchemaFragment::of(['type' => 'string']),
                        ]),
                    ]),
                ],
                'required' => ['errors'],
            ])
            : SchemaFragment::of([
                // 两种形态都真实存在：无诊断数据时 ApiResponseDTO 的默认值 [] 会序列化成
                // JSON 空数组，带诊断信息时 BusinessException::$data 是键值对象。
                // 只给 description 等于完全不约束，所以把两种形态都写出来。
                'description' => '错误诊断数据。无诊断信息时是空数组；部分业务错误会带上定位信息，例如不存在的 ID 列表。',
                'oneOf' => [
                    SchemaFragment::of([
                        'type' => 'object',
                        'description' => '带定位信息的诊断数据',
                        'additionalProperties' => true,
                    ]),
                    SchemaFragment::of([
                        'type' => 'array',
                        'description' => '无诊断信息',
                        'items' => SchemaFragment::empty(),
                        'maxItems' => 0,
                    ]),
                ],
            ]);

        return SchemaFragment::of([
            'type' => 'object',
            'properties' => [
                'code' => SchemaFragment::of([
                    'type' => 'integer',
                    'description' => '业务错误码',
                    'enum' => array_map(static fn (ErrorCode $code): int => $code->value, $codes),
                    'x' => [
                        'enum-descriptions' => array_map(static fn (ErrorCode $code): string => $code->getMessage(), $codes),
                        'enum-varnames' => array_map(static fn (ErrorCode $code): string => $code->name, $codes),
                    ],
                ]),
                'message' => SchemaFragment::of(['type' => 'string', 'description' => '错误说明']),
                'data' => $data,
            ],
            'required' => ['code', 'message', 'data'],
        ]);
    }

    /**
     * @return list<ErrorCode>
     */
    private static function codesFor(int $status): array
    {
        return array_values(array_filter(
            ErrorCode::cases(),
            static fn (ErrorCode $code): bool => $code !== ErrorCode::SUCCESS && $code->getHttpStatus()->value === $status,
        ));
    }
}
