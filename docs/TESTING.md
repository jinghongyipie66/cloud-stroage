# 测试说明

测试仅在隔离环境运行，不加载生产数据库或邮件配置。`db_config.php` 不随仓库提供。

## 命令

在仓库根目录运行（PHP CLI 需启用 `pdo_sqlite`、`openssl`，上传集成测试另需 mbstring）：

```bash
php tests/notices.php
node tests/countdown.test.mjs
node tests/upload-sort/frontend.test.mjs
node tests/upload-sort/integration.test.mjs /absolute/path/to/php
node tests/repository-check.mjs
```

上传集成测试的 PHP 扩展默认从 PHP 可执行文件旁的 `ext` 目录加载，这是本地 Windows 验证环境的约定；其他平台需要按其 PHP 安装位置调整测试启动参数。它仅监听 `127.0.0.1`，结束后停止服务，在 `tests/upload-sort/.runtime-*` 生成模拟文件（已加入忽略规则）。没有真实 SMTP 投递或线上 MySQL 验证。

PHP 语法检查逐个对仓库 `.php` 源文件执行 `php -l`；JS 对 `assets/*.js` 执行 `node --check`。

## 测试覆盖与边界

- 邮箱逻辑：内存 SQLite、虚构账号、验证码、限流、绑定/注册/找回、队列和通知提示；不代表生产 SMTP 邮件送达。
- 倒计时：模拟时间推进、后台标签恢复、禁用/恢复按钮。
- 上传/排序：47 项后端/HTTP/分页/并发检查及 12 项前端检查；账号有效性使用测试替身，真实 Session/CSRF 仍启用。
- 仓库检查：禁入文件、公开配置示例、固定依赖路径、业务来源哈希和源码中的明显凭据模式；不能替代完整安全审计。
- 未测试生产 Web 服务、MySQL、真实邮件、真实手机、FPM 硬链接能力；没有执行数据库迁移。

`tests/upload-sort/baselines/` 仅包含两个公开源码基线，用于确认此次上传修改未意外改变其他前端处理逻辑；不是用户数据备份。
