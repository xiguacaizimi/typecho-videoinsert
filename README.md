# VideoInsert — Typecho 视频插入助手

适用于 **Typecho 1.3.0**（向下兼容 1.2.x），为 **iLogs** 等现代主题优化。
在写文章时一键插入响应式视频，前台自动输出兼容桌面端与移动端的 HTML5 播放器。

---

## 一、功能范围

### 后台：编辑器内插入

- 在 Markdown 工具栏追加一个「▶ 插入视频」按钮（不替换原有编辑器）
- 弹窗填写地址即可预览参数：视频地址、封面图、宽度、预加载、自动播放、循环、静音、说明文字
- 输入 B 站 / YouTube / 腾讯视频页面链接时自动识别，切换为官方 iframe 播放器
- 自动把 `http://` 改写成 `https://`（HTTPS 站点下），从源头避免混合内容拦截
- 非 Markdown 模式（纯 HTML 编辑）下自动降级为编辑器上方的普通按钮

### 前台：渲染与播放

- 短代码 `[video ...]` → 标准 `<video>` + `<source>`，带 `controls` / `playsinline` / `preload`
- 响应式样式：最大宽度 100%，竖屏视频最高 `80vh` 自动居中，圆角，移动端收紧外边距
- 说明文字渲染为播放器下方的 `figcaption` 样式文本
- 摘要 / 列表页自动剔除短代码，不会露出 `[video ...]` 字样

### 明确不做的事

- 不上传、不转码视频（视频源需你自己托管或使用外链）
- 不接管 Typecho 附件系统，不与附件插件抢钩子
- 不修改数据库，不新增数据表，禁用后无残留

---

## 二、目录结构

```
usr/plugins/VideoInsert/
├── Plugin.php          # 插件入口（TypechoPlugin\VideoInsert\Plugin）
├── assets/
│   ├── admin.css       # 后台弹窗样式（全部 .vi- 前缀）
│   ├── admin.js        # 后台交互脚本（IIFE，不污染全局）
│   └── player.js       # 前台自绘播放器（替换浏览器原生控制条）
├── preview.html        # 五套样式的并排对比页（可直接用浏览器打开）
└── README.md
```

---

## 三、安装

**方式一：直接下载安装包**（推荐）

下载 [`dist/VideoInsert-1.5.1.zip`](dist/VideoInsert-1.5.1.zip)，解压后把 `VideoInsert`
整个目录放到 `usr/plugins/`。

**方式二：从源码安装**

1. 把 `VideoInsert` 整个目录上传到 `usr/plugins/`
2. 后台 → 控制台 → 插件 → 找到「视频插入助手」→ 启用
3. 需要调参数的话点「设置」

> 目录名必须是 `VideoInsert`，因为 Typecho 1.3 的类名映射规则是
> `TypechoPlugin\{目录名}\Plugin`，改名会导致启用时报「类不存在」。

---

## 四、配置项

| 配置 | 默认值 | 说明 |
|---|---|---|
| 短代码标签名 | `video` | 若与其他视频插件冲突可改成 `vi` 等。**改后旧文章的 `[video]` 会失效** |
| 默认宽度 | `100%` | 支持 `100%` 或 `640` 两种写法；`640` 会自动补 `px` |
| 预加载策略 | `auto` | 只有 `auto` 会让浏览器解码并绘制首帧；`metadata` / `none` 播放前会是一块黑色 |
| 自动升级为 HTTPS | 开启 | HTTPS 站点下把 `http://` 源改写为 `https://`，解决混合内容拦截 |
| 移动端内联播放 | 开启 | 输出 `playsinline`，iOS 不再强制全屏接管 |
| 响应式样式 | 开启 | 输出内置 CSS；关闭后完全由主题控制外观 |
| 前台样式 | 卡片 | card / minimal / cinema / paper / native / custom 六选一 |
| 播放器配色 | 中性 | neutral / indigo / teal / amber / rose / violet |
| 播放器控件 | 插件自绘 | 可切回浏览器原生控件 |
| 下载按钮 | 显示 | 关闭后访客只能在线观看 |
| 自定义 CSS | 空 | 追加在预置样式之后，优先级更高 |
| 外部样式表地址 | 空 | 可选，页面里插入一个 link 标签 |
| 识别第三方平台 | 开启 | B 站 / YouTube / 腾讯视频链接转官方 iframe |
| 裸链接自动播放 | 开启 | 正文中独占一行的 `.mp4/.webm/.ogv` 直链自动转播放器，适合直接粘贴 COS / OSS 地址 |
| 插入格式 | 短代码 | `短代码` 便于统一调整；`原生 HTML` 不依赖插件，禁用插件后仍可播放 |

---

## 五、使用方法

### 方式一：工具栏按钮（推荐）

1. 写文章页面，工具栏最右侧点 **▶**
2. 填入视频地址，例如 `https://你的域名/usr/uploads/2026/11/demo.mp4`
3. 需要的话补封面图、宽度、说明文字
4. 点「插入」（或 `Ctrl + Enter`）

插入结果：

```
[video src="https://example.com/demo.mp4" width="100%" preload="metadata"]
```

### 方式二：手写短代码

单独成段（推荐，前后留空行）：

```
[video src="https://example.com/demo.mp4" poster="https://example.com/p.jpg" caption="运动会"]
```

也支持写在段落中间，插件会就地替换。

### 方式三：直接粘贴链接

把视频直链**单独放一行**（前后各留一个空行）粘贴进正文，插件会自动转成播放器：

```
https://cdn.example.com/%E5%90%8C%E5%AD%A6%E8%B7%B3%E7%BB%B3.mp4
```

适用于腾讯云 COS、阿里云 OSS、七牛等对象存储地址；带签名参数的链接（`?sign=…`）会完整保留。
注意必须**独占一段**，混在文字中间不会转换（有「裸链接自动播放」开关可关掉此行为）。

### 短代码属性

| 属性 | 必填 | 说明 |
|---|---|---|
| `src` | 是 | 视频直链或第三方平台页面链接 |
| `poster` | 否 | 封面图，留空则显示视频首帧 |
| `width` | 否 | `100%` 或 `640` |
| `autoplay` | 否 | 自动播放（会强制加 `muted`，否则浏览器拒绝） |
| `loop` | 否 | 循环播放 |
| `muted` | 否 | 静音 |
| `caption` | 否 | 播放器下方的说明文字 |
| `ratio` | 否 | 如 `16/9`，用于预留高度、减少布局抖动 |
| `type` | 否 | 手动指定 MIME，默认按扩展名推断 |

> 预加载策略是**站点级设置**（见「配置项」），不通过短代码控制。
> 早期版本会把 `preload` 写进短代码，导致改了站点配置也不生效，1.5.1 起该属性被忽略。

### 输出示例

输入：

```
[video src="https://example.com/demo.mp4" poster="https://example.com/p.jpg" caption="运动会"]
```

输出：

```html
<style id="vi-front-style">…只输出一次…</style>
<div class="vi-video">
  <div class="vi-video__box">
    <video style="width:100%;" controls playsinline webkit-playsinline preload="metadata" poster="https://example.com/p.jpg">
      <source src="https://example.com/demo.mp4" type="video/mp4">
      您的浏览器不支持 HTML5 视频播放。
    </video>
  </div>
  <span class="vi-video__cap">运动会</span>
</div>
```

外层 `.vi-video` 只负责外边距，`.vi-video__box` 负责圆角 / 投影 / 裁切，
说明文字放在盒子外面，所以不会被圆角切掉。

---

## 六、前台样式

内置 5 套风格 + 自定义，后台「设置 → 前台样式」切换。每套都会在容器上输出
`vi-video--样式名` 类名，方便在主题 CSS 里继续覆盖。

| 样式 | 类名 | 特点 |
|---|---|---|
| 卡片 | `vi-video--card` | 圆角 14px + 柔和投影，悬停加深。默认，最通用 |
| 极简 | `vi-video--minimal` | 1px 细描边、近乎直角，说明文字左对齐 |
| 影院 | `vi-video--cinema` | 深色画框 + 18px 圆角，说明文字大写字距拉开 |
| 相纸 | `vi-video--paper` | 白色留白边框，说明文字带左侧竖线，偏文档排版 |
| 原生 | `vi-video--native` | 不加任何装饰，只保证尺寸正确 |
| 自定义 | `vi-video--custom` | 只用你自己写的 CSS |

打开插件目录下的 `preview.html` 可以并排对比这五套样式。

### 播放器与配色

浏览器原生控制条（就是那条灰白进度条）跑在闭合的 Shadow DOM 里，早年可用的
`::-webkit-media-controls-*` 钩子被逐步移除，Firefox 干脆不支持，所以跨浏览器
没法把原生控件做成一致的风格。插件改为**自绘控制条**，默认开启，可以完整套用配色。

| 配置 | 默认 | 说明 |
|---|---|---|
| 播放器控件 | 插件自绘 | 自绘失败会自动回退到原生控件，不会出现没有控件的视频 |
| 播放器配色 | 中性 | 作用于进度条、大播放按钮、音量条与悬停反馈 |

配色可选：**中性**（白）、**靛蓝** `#7D9BFF`、**青绿** `#2FD4A7`、**琥珀** `#F5B544`、
**玫红** `#FF6B8A`、**紫罗兰** `#A78BFA`，容器上对应 `vi-video--color-{配色名}`。

自绘控制条包含：播放 / 暂停、进度拖拽（支持键盘左右键，按住 Shift 加速）、
时间显示、**倍速（0.5× ~ 2.0× 菜单）**、**下载**、静音与音量、全屏、
播放中自动隐藏、暂停时居中大按钮。

倍速选择会记住，同页其他视频自动沿用。下载按钮点击后用 `<a download>` 触发保存；
**跨域视频**浏览器会忽略 `download` 属性，改为新标签打开（是否保存取决于视频服务器的
`Content-Disposition`，腾讯云 COS 默认 `inline` 会直接播放）。
不想提供下载时，后台把「下载按钮」设为隐藏即可。

想换颜色又不想动配色选项，直接覆盖 CSS 变量即可：

```css
.vi-video{--vi-accent:#ff4d6d;--vi-accent-soft:rgba(255,77,109,.24)}
```

### 用自己做的样式

三种方式，前两种可以叠加：

**1. 后台粘贴 CSS**（设置 → 自定义 CSS）

追加在预置样式之后，优先级更高，适合小改：

```css
/* 换成主题色的投影 */
.vi-video__box{box-shadow:0 12px 30px -16px rgba(0,120,255,.45)}
/* 说明文字右对齐 */
.vi-video__cap{text-align:right}
```

**2. 引用外部样式表**（设置 → 外部样式表地址）

把 CSS 放到主题目录或附件里，填地址即可，适合样式较多的场景：

```
https://www.example.com/usr/themes/ilogs/assets/video.css
```

**3. 完全自己来**

「前台样式」选「自定义」，插件只输出结构样式（尺寸、16:9 嵌入、竖屏限高），
视觉部分全部由你的 CSS 决定。

### 可用的选择器

| 选择器 | 说明 |
|---|---|
| `.vi-video` | 整块容器（含说明文字） |
| `.vi-video--{风格}` | 当前风格的修饰类 |
| `.vi-video--embed` | 第三方平台 iframe 时追加 |
| `.vi-video__box` | 包裹视频的盒子，圆角 / 投影 / 边框都作用在它上面 |
| `.vi-video video` | 视频元素本身 |
| `.vi-video__cap` | 说明文字 |
| `.vi-player` | 自绘控制层（铺满视频区域） |
| `.vi-player__bar` | 底部控制条 |
| `.vi-player__progress` | 进度条轨道 |
| `.vi-player__big` | 居中的大播放按钮 |

---

## 七、兼容性处理

### 与 Typecho 1.3.0

- 类命名空间为 `TypechoPlugin\VideoInsert`，符合 1.3 的 `PLUGIN_NAMESPACE` 加载规则
- 实现 `Typecho\Plugin\PluginInterface` 的四个方法：`activate` / `deactivate` / `config` / `personalConfig`
- 内容过滤同时注册 `Widget\Base\Contents` 与 `Widget_Abstract_Contents` 两个句柄。
  1.3 内部用前者，旧版用后者，二者经 `Common::nativeClassName()` 归一化后指向同一组件；
  渲染函数是幂等的（短代码替换后即消失），即使两个句柄同时命中也不会输出两遍
- 不写数据库、不改表结构，禁用即彻底退出

### 与 iLogs 主题

- 前台只输出 `.vi-video` / `.vi-video__cap` / `.vi-embed` 三个自有类名，
  不重置 `video`、`figure`、`p` 等主题可能用到的选择器
- 样式默认随内容输出一次（`<style id="vi-front-style">`），不依赖主题是否调用
  `$this->header()`，换主题也不会丢样式
- 视频用 `max-width:100%` 而非固定宽度，不会被主题的内容栏挤变形
- 支持主题的懒加载环境：不依赖任何 JS，纯 CSS 自适应

### 与其他插件

- **不占用 `richEditor` 钩子**。该钩子会整体替换编辑器，是视频/编辑器类插件冲突的重灾区；
  本插件只在 `bottom` 钩子追加按钮，与 Markdown 增强、代码高亮类插件可共存
- 后台 JS 全量包在 IIFE 内，仅读一个 `window.VI_CONFIG`，不覆盖 `Typecho.*`、不重定义 `$`
- 按钮挂载做了兜底：找不到 `#wmd-button-row` 时等 3 秒，仍未出现则退化为编辑器上方的普通按钮
- 弹窗用 `#vi-mask` 独立层，`z-index: 99999`，不参与后台其他浮层的事件冒泡

### 桌面端与移动端

- `playsinline` + `webkit-playsinline`：iOS Safari 不再强制全屏接管
- `preload="metadata"`：只取时长和首帧，弱网下不会一进页面就拉完整个文件
- 竖屏视频 `max-height: 80vh` 且水平居中，避免竖屏素材在桌面端撑出超长滚动
- 自动播放强制补 `muted`，符合 Chrome / Safari 的自动播放策略

### 安全

- URL 协议白名单，仅放行 `http/https/协议相对/站内绝对路径`，
  `javascript:`、`data:` 等一律丢弃
- 所有属性经 `htmlspecialchars(ENT_QUOTES)` 转义
- 属性值内的引号会截断解析，恶意片段无法进入输出

---

## 八、常见问题

**点播放之前是一块黑色**
浏览器只在 `preload="auto"` 时才会解码首帧，`metadata` / `none` 下播放前必然是黑色。
后台把「预加载策略」设为 `auto`，或给视频配一张封面图（`poster`）即可。
自 1.5.0 起默认已是 `auto`。

**视频加载失败，显示「打开原文件」**
1.5.0 起播放器会在视频源报错（404 / 403 / 编码不支持 / 防盗链）时给出明确提示，不再是无声黑屏。
点提示里的链接可以直接打开原始视频地址排查。

**视频黑屏、只有声音 / 完全不动**
优先检查码率。手机能播、电脑不能播，多半是源文件码率过高（例如 1080p 20 Mbps）或带旋转元数据。
建议转成 H.264 Main、2–4 Mbps、并把旋转烤进像素：

```bash
ffmpeg -i input.mp4 -vf "scale=720:-2" -c:v libx264 -profile:v main -level 4.0 \
  -pix_fmt yuv420p -crf 26 -preset medium -c:a aac -b:a 96k -movflags +faststart output.mp4
```

**控制台报 Mixed Content**
视频源是 `http://` 而站点是 `https://`。开启「自动升级为 HTTPS」即可，
前提是视频服务器本身支持 HTTPS。

**iOS 上点了就全屏**
确认「移动端内联播放」为开启状态。

**B 站视频显示空白**
部分视频禁止站外嵌入，或需要用 `bvid` 形式的新版链接。

**粘贴了链接却没有变成播放器**
先确认链接是**独占一段**的。若正文里还有上一版插件插入的短代码，需要重新保存一次文章才会重新渲染。
另外检查链接是否是对象存储的**临时签名地址**（带 `?sign=` 且有效期很短），过期后会 403。

**换主题后样式乱了**
把「响应式样式」关掉，让主题自己控制，插件只负责输出标签。

---

## 九、卸载

后台禁用即可。已插入的短代码会以 `[video ...]` 原文显示在正文里；
若希望禁用后仍能播放，请先把「插入格式」改成「原生 HTML」再写文章。

---

## 十、作者与版本

- 作者：xiguacaizimi
- 博客：https://www.xiguacaizimi.top

更新记录：

- 1.5.1 — 修复「改了预加载配置仍黑屏」：短代码里固化的 `preload` 属性不再覆盖站点配置，
  历史文章无需修改即可生效；后台弹窗移除预加载选项，统一由设置页控制
- 1.5.0 — 默认预加载改为 `auto`，解决「播放前一片黑」；
  新增视频加载失败提示（可点击打开原文件），不再是无提示黑屏；
  视频改用 `object-fit:contain`，容器高度受限时不再拉伸变形
- 1.4.0 — 控制条新增倍速菜单（0.5× ~ 2.0×）与下载按钮，倍速可跨视频记忆；
  新增「下载按钮」开关；补充 420px 以下小屏布局（隐藏下载、精简时间显示）
- 1.3.0 — 自绘播放器：替换浏览器原生控制条，内置进度拖拽、音量、全屏、自动隐藏；
  新增 6 套播放器配色（中性 / 靛蓝 / 青绿 / 琥珀 / 玫红 / 紫罗兰），
  可用 `--vi-accent` 变量自行调色；自绘失败自动回退原生控件
- 1.2.0 — 新增 5 套可切换的前台样式（卡片 / 极简 / 影院 / 相纸 / 原生）与自定义样式；
  支持后台粘贴 CSS 与引用外部样式表；容器输出 `vi-video--{风格}` 类名便于二次覆盖；
  附带 `preview.html` 样式对比页
- 1.1.0 — 前台样式改为卡片式（圆角 + 投影 + 悬停反馈 + 深色模式适配）；
  新增「裸链接自动播放」，独占一行的视频直链直接转播放器；
  地址粘贴时自动清理零宽字符与全角空格；校验失败时回显实际内容
- 1.0.0 — 首版：编辑器按钮 + 短代码渲染 + 响应式 + 平台识别 + HTTPS 升级

---

## 十一、许可证

[MIT](LICENSE) © 2026 xiguacaizimi
