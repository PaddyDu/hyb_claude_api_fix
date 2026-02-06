# hyb_claude_api_fix

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://php.net/)

> 🔧 修复黑与白公益站 Claude API 的 SSE 流式输出问题，使其完美兼容 VS Code Cline 扩展

## 📖 项目简介

黑与白公益站的 Claude 模型非常给力，还上了最新的 Claude 4.6！但由于接口包装不太符合 SSE 规范，使用 Visual Studio Code 的 Cline 扩展调用时会报错。

本项目提供了一个 PHP 代理脚本，对上传和下载数据进行标准化处理，完美解决兼容性问题。

## ✨ 功能特性

- 🔄 **SSE 流式输出修复** - 将非标准 SSE 响应转换为标准格式
- 🛠️ **Claude 工具格式修复** - 自动修复空描述、空 properties 等问题
- 📦 **非流式转流式** - 强制使用非流式请求上游，模拟流式输出，提高稳定性
- 🔐 **Authorization 透传** - 完整支持 Bearer Token 认证
- ⚡ **高性能** - 支持长连接，优化缓冲处理

## 📸 效果展示

<img width="1262" height="794" alt="Cline 运行效果" src="https://github.com/user-attachments/assets/5106e5b4-bdeb-44e2-8b1d-cffb7aabe1b9" />

## 🚀 快速开始

### 环境要求

- PHP >= 7.4
- Apache 服务器（启用 mod_rewrite）
- cURL 扩展

### 安装步骤

1. **克隆仓库**

```bash
git clone https://github.com/PaddyDu/hyb_claude_api_fix.git
cd hyb_claude_api_fix
```

2. **部署文件**

将 `proxy_oai.php` 复制到你的 Web 服务器目录。

3. **配置 Apache 重写规则**

在站点配置或 `.htaccess` 文件中添加：

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.+)$
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
RewriteRule ^v1/chat/completions$ /proxy_oai.php [L,QSA]
```

4. **配置 Cline 扩展**

在 VS Code 的 Cline 扩展设置中，将 API Base URL 设置为你的代理地址：

```
https://your-domain.com/v1
```

## ⚙️ 配置说明

脚本顶部提供了可配置的常量：

```php
define('UPSTREAM_URL', 'https://ai.hybgzs.com/v1/chat/completions');  // 上游 API 地址
define('CURL_TIMEOUT', 600);           // cURL 超时时间（秒）
define('CURL_CONNECT_TIMEOUT', 60);    // 连接超时时间（秒）
define('STREAM_CHUNK_SIZE', 200);      // 流式分片字符数
```

## 📁 项目结构

```
hyb_claude_api_fix/
├── proxy_oai.php    # 主代理脚本
├── README.md        # 项目说明文档
└── .gitignore       # Git 忽略文件
```

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

如果这个项目对你有帮助，请给个 ⭐ Star 支持一下！

## 📄 许可证

本项目采用 [MIT 许可证](https://opensource.org/licenses/MIT) 开源。

---

> 💡 **提示**：希望公益站长看到后可以直接优化接口，这样就不用中转了。
