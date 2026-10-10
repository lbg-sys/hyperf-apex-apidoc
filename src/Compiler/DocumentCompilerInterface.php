<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Compiler;

use OpenApi\Specification;

/**
 * 把收集好的 OpenAPI 规格编译成可序列化的文档数组。
 *
 * 这一层的存在意义是隔离「文档序列化与版本差异」的实现选择：
 * 目前由 swagger-php 承担，其 Spec 管线上游标注为尚未 production ready，
 * 因此保持单实现接口，替换或降级时改动面限于一个类。
 *
 * 注意：本接口保护的边界是 Attribute 契约与业务 DTO（它们完全不知道 swagger-php 存在），
 * 而非文档构建器本身 —— 构建器直接使用 Spec 对象模型正是借用它的目的。
 */
interface DocumentCompilerInterface
{
    /**
     * 目标 OpenAPI 版本，例如 3.0.3 或 3.1.1。
     */
    public function version(): string;

    /**
     * @return array<string, mixed>
     */
    public function compile(Specification $specification): array;

    /**
     * 校验规格完整性。
     *
     * @return list<array{level: string, message: string}>
     */
    public function validate(Specification $specification): array;
}
