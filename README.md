# PHP Nav（虚拟主机版）

一个专门部署在传统虚拟主机上的 PHP + MySQL 导航系统。

项目面向共享虚拟主机、PHP 面板主机和普通 LAMP/LEMP 环境设计，不依赖 Cloudflare Workers 或 KV。导航卡片、分类管理、拖拽排序、主题换肤、响应式布局和私密链接等功能由 PHP API 与 MySQL 持久化数据。

本项目的数据结构兼容 Cloudflare Workers 版本，因此可以直接导入原项目导出的导航 JSON；但运行时以后端 PHP + MySQL 为准。项目长期用于部署在普通虚拟主机上，主题皮肤等 PHP 版功能也会保留。

## 功能

- 分类和链接管理
- 拖拽调整分类、链接顺序
- 私密链接和隐藏分类
- 管理员登录、Token 刷新、登出
- JSON 配置导入/导出
- Chrome / Edge Netscape 书签 HTML 导入
- 主题切换、主题保存和主题导入/导出
- 自动备份最近的数据版本
- 自动获取网站图标：优先使用 `api.xinac.net`，失败后再解析目标站点
- 图标全部获取失败时显示内置地球图标
- APP 视图、深色模式和本地记住设置
- PHP 版本的图标代理和主题代理

## 与 Cloudflare 版本保持一致的逻辑

- 图标代理默认优先请求 `https://api.xinac.net/icon/?url=...`。
- Xinac 代理不可用或返回无效图标时，继续尝试网页中的 `favicon`、`apple-touch-icon`、Web App Manifest、Open Graph 图片和常见 favicon 路径。
- 所有图标来源都失败时返回空结果，由前端显示原有的地球占位图，不生成域名首字母 SVG。
- 自动保存时，距离最近一次自动备份不足 10 分钟不会重复创建备份；超过 10 分钟才创建新备份，最多保留最近 10 份。
- 设置中的“备份数据”属于手动备份，会跳过时间限制并立即创建一份备份。

## 目录结构

```text
.
├── index.html     # 单页前端
├── api.php        # PHP API 路由
├── config.php     # 数据库和管理员配置（不提交到公开仓库）
├── config.example.php # 安全配置模板
└── .htaccess      # Apache URL 重写配置
```

## 环境要求

- PHP 8.0 或更高版本
- PDO MySQL 扩展
- cURL 扩展
- `mbstring` 扩展
- MySQL 5.7+ 或 MariaDB 10.3+
- Apache `mod_rewrite`（如果使用 `.htaccess` 路由）

如果主机使用 Nginx，需要把 `/api` 请求转发到 `api.php`，或直接使用前端当前兼容的 `api.php?path=/api/...` 路径。

## 部署

1. 将 `index.html`、`api.php`、`config.php` 和 `.htaccess` 上传到站点运行目录。
2. 复制 `config.example.php` 为 `config.php`，填写 MySQL 主机、端口、数据库名、账号和密码。
3. 修改管理员密码和 JWT 密钥。
4. 确认站点已经启用 HTTPS。
5. 浏览器打开站点首页，PHP 会在首次请求时自动创建数据表。

首次启动会创建以下表：

- `nav_data`：当前导航数据
- `nav_backups`：历史备份
- `nav_theme`：已发布主题
- `nav_meta`：Token 会话版本等元数据

## 配置示例

`config.php` 
```php
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'your_database';
const DB_USER = 'your_database_user';
const DB_PASS = 'your_database_password';

const NAV_ADMIN_PASSWORD = 'change-this-password';
const NAV_JWT_SECRET = 'change-this-to-a-random-string-of-at-least-32-chars';
const NAV_DEFAULT_USER = 'default';
const AUTO_BACKUP_INTERVAL = 600; // 自动备份间隔，单位：秒
```



## 导入 / 导出格式

“导入配置”和“导出配置”处理的是导航数据，不是主题数据。格式与 Cloudflare Workers 版本兼容：

```json
{
  "categories": {
    "常用": {
      "isHidden": false,
      "links": [
        {
          "name": "示例站点",
          "url": "https://example.com/",
          "tips": "说明文字",
          "icon": "",
          "isPrivate": false,
          "isDirect": false,
          "category": "常用"
        }
      ]
    }
  }
}
```

因此可以直接使用原 Cloudflare Worker 版本导出的 JSON 文件迁移到本项目。

## API 概览

主要接口如下：

```text
POST /api/login
POST /api/refreshToken
POST /api/logout
GET  /api/validateToken
GET  /api/getLinks
POST /api/saveData
POST /api/importData
POST /api/exportData
POST /api/backupData
GET  /api/getTheme
POST /api/saveTheme
GET  /api/icon?url=...
GET  /api/theme-proxy?id=...
```

如果服务器没有配置 URL 重写，也可以使用兼容形式：

```text
GET  /api.php?path=/api/getLinks
GET  /api.php?path=/api/icon&url=https%3A%2F%2Fexample.com%2F
```

除 `getLinks`、`getTheme` 和图标/主题代理外，管理接口需要在 `Authorization` 请求头中携带登录接口返回的 Bearer Token。

## 从 Cloudflare Workers 导入数据

1. 在原项目中导出 JSON 配置。
2. 登录 PHP 版本站点。
3. 打开“设置 → 导入配置”。
4. 选择导出的 JSON 文件。
5. 导入完成后刷新页面。

设置菜单中的“导入配置”和“导出配置”入口会始终显示；执行实际读写操作前仍需要管理员登录。未登录时点击入口会先打开登录提示。

导入后的数据可以继续从本项目导出，格式仍兼容 Cloudflare Workers 版本。


