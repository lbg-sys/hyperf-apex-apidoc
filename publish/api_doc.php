<?php

declare(strict_types=1);

return [
    /*
     * 目标 OpenAPI 版本，决定由哪个编译器落成文档（3.0.x / 3.1.x / 3.2.0）。
     *
     * 选 3.0.x 时，DTO 上声明的 3.1 专有词汇会在生成期直接报错：`#[PrefixItems]`、`#[Contains]`、
     * `#[PatternProperties]`、`#[DependentRequired]`、`#[DependentSchemas]`、`#[PropertyNames]`、
     * `#[Conditional]`、`#[UnevaluatedItems]`、`#[UnevaluatedProperties]`、`#[ContentMediaType]`、
     * `#[ContentEncoding]`。3.0 里没有对应表达，编译层会静默把它们丢掉 —— 报错是为了不产出一份
     * 「看起来正常、实际少了一条约束」的文档。
     *
     * 可以降级的那些不受影响：`#[ConstantValue]` → 单值 `enum`、类型数组 → `type + nullable`、
     * `#[Examples]` → `example`。
     */
    'version' => '3.1.1',

    'info' => [
        'title' => 'Apex API',
        'version' => '1.0.0',
    ],

    // 接口基础地址，留空则由文档消费方自行指定。
    'servers' => [
        // ['url' => 'https://api.example.com', 'description' => '生产环境'],
    ],

    /*
     * 生成命令的输入与输出位置，默认值就是 Hyperf 的常规布局，通常不需要改。
     *
     * - app_path：待扫描的目录。默认 `BASE_PATH . '/app'`；代码不放在 app/ 下的工程
     *   （例如 src/ 布局）指到实际目录，否则扫不到任何控制器、产出一份空文档。
     * - output_path：产物目录。默认 `BASE_PATH . '/runtime/api-doc'`；
     *   openapi.json 与 apifox.json 都落在它下面。
     *
     * 必须用绝对路径：相对路径会相对当前工作目录解析，从别的目录调用命令时结果不可预期。
     */
    'app_path' => null,
    'output_path' => null,

    /*
     * WebSocket 契约（Apifox 原生格式）的生成参数。
     *
     * WebSocket 不进 OpenAPI：OpenAPI 是「一次调用 = 一个 operation」的模型，表达不了
     * 「一次连接 + N 条双向消息」。契约改走 Apifox 原生格式的 webSocketCollection，
     * 由 api-doc:generate-apifox 生成，对外出口是 GET /api_doc/get_apifox。
     *
     * Apifox 侧接入方式（一次性配置）：项目设置 → 定时导入 → 数据源格式选「Apifox」→
     * URL 填 <接口地址>/api_doc/get_apifox。生成物顶层只含 apifoxProject / $schema /
     * webSocketCollection，不含 projectSetting 与 apiCollection，因此不可能覆盖现有 HTTP 接口。
     * 接口 id 由入口名派生，重复导入会匹配同一条；首次导入后请人工确认没有产生重复接口。
     */
    'apifox' => [
        // webSocketCollection 下的目录名。
        'folder' => 'WebSocket',
        // 导入到 Apifox 后的接口状态：developing / testing / released / deprecated。
        'status' => 'developing',
        'tags' => ['WebSocket'],
    ],

    /*
     * 不收录进对外文档的控制器，匹配完整控制器类名或「完整类名::方法名」，支持 * 通配符。
     *
     * 判据只有一条：**调用方是不是客户端**。是客户端（后台页面、租户端、集成方）就收录；
     * 调用方是运维脚本、第三方服务商或框架自身的都不收录。
     */
    'excluded' => [
        // 演示接口与测试夹具：只服务于 test/ 下的用例与本地联调，不是任何客户端的入口。
        // 'App\Controller\Test\*',
    ],
];
