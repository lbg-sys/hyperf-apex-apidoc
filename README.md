# hyperf-apex/apidoc

基于 DTO 反射生成 **OpenAPI 3.1** 文档：请求 Schema 由方法参数上的请求 DTO 推导，响应 `data`
的 Schema 由控制器返回类型推导，认证与通用错误响应由访问声明自动补全。

- **依赖**：`hyperf-apex/core`、`zircote/swagger-php`、`symfony/yaml`
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)

## 安装

```bash
composer require hyperf-apex/apidoc
php bin/hyperf.php vendor:publish hyperf-apex/apidoc --id=config
```

## 命令与出口

```bash
php bin/hyperf.php api-doc:generate        # 生成 runtime/api-doc/openapi.json
```

| 项 | 值 |
| :--- | :--- |
| 产物 | `api_doc.output_path` 下的 `openapi.json`（默认 `BASE_PATH/runtime/api-doc`） |
| 出口 | `GET /api_doc/get_openapi`（未生成时回 404 并提示该跑哪条命令） |
| 生成失败 | 规格校验的任何 error 或 warning 都会让命令失败（不产出半成品文档） |

## 配置项

| 键 | 说明 |
| :--- | :--- |
| `api_doc.version` | 目标 OpenAPI 版本（默认 3.1） |
| `api_doc.info.title` / `api_doc.info.version` | 文档标题与版本 |
| `api_doc.servers` | 接口地址列表 |
| `api_doc.app_path` | 扫描的应用目录（默认 `BASE_PATH/app`）；目录不存在时报出这个配置键 |
| `api_doc.output_path` | 产物目录 |
| `api_doc.excluded` | 排除的控制器/方法，支持 `类名` 或 `类名::方法名` 与 `*` 通配 |

## 标注

| 标注 | 作用 |
| :--- | :--- |
| `#[ApiOperation(summary:, description:, tags:)]` | **接口方法必须写**：接口名与分组属于文档契约 |
| `#[ApiFolder]` | 类级目录分组 |
| `#[ApiError(...)]` | 生成器推不出来的特有错误（404 资源不存在、409 状态冲突、无权限码接口上 Service 直抛的 403 等） |
| `#[ApiHeader]` | **真正的业务请求头**；认证头（`Authorization`、`X-Api-Key`、集成 Token、`X-Integration-Token`）不要写在这里，它们由访问声明推导成 `securityScheme` |

## 生成期校验（失败即命令失败）

1. 接口方法漏写 `#[ApiOperation]` —— 不退回英文方法名当 summary；
2. `#[ApiError]` 里声明 `SUCCESS` —— 成功响应本来就由返回类型推导；
3. `#[ApiHeader]` 声明认证头 —— 会与 `securityScheme` 重复声明，调用方不知道该按哪份填；
4. 散装数组请求参数（`#[FromQuery] array $codes`）—— 它只能产出没有 `items` 的 `type: array`，请改用查询 DTO + `#[ArrayOf]`；
5. 悬空 `$ref` —— `#[Discriminator]` 的 mapping 不会注册组件，mapping 里的类必须同时写进 `#[OneOf]`。

另外：组件重名（不同命名空间的同名 DTO）、`operationId` 重复、内层只写短类名、与 `type` 不匹配的
死约束（`#[ArrayOf('string')]` + `#[ItemMinimum]`）也都会在生成期报错。

## 错误响应自动补全

| 状态 | 触发条件 |
| :--- | :--- |
| 400 / 422 / 500 | 所有接口 |
| 401 | 声明了认证口径（含只写 `#[RequireRole]`，角色要求隐含已登录） |
| 403 | 声明了权限码或角色 |
| 429 | 声明了 `#[RateLimit]` |

未声明 `#[PublicAccess]` / `#[Authenticated]` 等访问口径的接口由 `hyperf-apex/core` 的启动检查拦下 ——
否则文档会把它描述成公开接口。

## 版本差异

目标 OpenAPI 3.1，版本差异（可空类型、`const`、`prefixItems`、`if/then/else` 等）由编译层承担，
业务代码只描述语义：DTO 上照常写 `?Type` 与约束 Attribute 即可。
