<?php
/**
 * 视频插入助手：在编辑器中一键插入响应式视频，并保证前台可播、移动端友好
 *
 * @package VideoInsert
 * @author  xiguacaizimi
 * @version 1.5.1
 * @link    https://www.xiguacaizimi.top
 */

namespace TypechoPlugin\VideoInsert;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Class Plugin
 *
 * 兼容性说明：
 * 1. 不占用 admin/write-post.php 的 richEditor 钩子（该钩子会替换整个编辑器，
 *    与 Markdown 增强类插件冲突概率最高），只在 bottom 钩子追加按钮与弹窗。
 * 2. 内容过滤同时注册新旧两种 Widget 句柄名。Typecho 1.3 内部句柄为
 *    Widget\Base\Contents，旧版为 Widget_Abstract_Contents，两者经
 *    Common::nativeClassName() 归一化后指向同一组件，重复注册不会重复输出
 *    （渲染函数是幂等的：短代码替换后即消失）。
 * 3. 前台样式只使用 .vi-video / .vi-embed 前缀，不覆盖主题的 video 选择器。
 */
class Plugin implements PluginInterface
{
    /** 插件版本 */
    const VERSION = '1.5.1';

    /** 短代码正则体（不含定界符），便于拼接块级与行内两种模式 */
    const SHORTCODE = '\[video\b([^\]\n]*)\]';

    /**
     * 启用插件
     *
     * @return string
     */
    public static function activate()
    {
        // 前台内容渲染：新旧句柄各注册一次，确保在 1.1 ~ 1.3 全系生效
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = [__CLASS__, 'filterContent'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->contentEx = [__CLASS__, 'filterContent'];

        // 摘要里去掉短代码，避免列表页露出一串 [video ...]
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = [__CLASS__, 'filterExcerpt'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->excerptEx = [__CLASS__, 'filterExcerpt'];

        // 后台编辑器：文章 + 独立页面
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = [__CLASS__, 'renderEditorPanel'];
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = [__CLASS__, 'renderEditorPanel'];

        return _t('视频插入助手已启用，撰写文章时工具栏会出现「插入视频」按钮');
    }

    /**
     * 禁用插件
     *
     * 不删除任何配置与已插入的内容，重新启用即可恢复。
     *
     * @return string
     */
    public static function deactivate()
    {
        return _t('视频插入助手已禁用，已插入的视频不受影响');
    }

    /**
     * 插件配置
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $tag = new Text(
            'tag',
            null,
            'video',
            _t('短代码标签名'),
            _t('默认为 video。若与其他视频插件冲突，可改成 vi 等自定义名称（改后旧文章里的 [video] 将不再生效）')
        );
        $form->addInput($tag);

        $width = new Text(
            'defaultWidth',
            null,
            '100%',
            _t('默认宽度'),
            _t('填写 100% 或 640 这样的数值。留空则由视频原始尺寸决定，且始终不会超出容器')
        );
        $form->addInput($width);

        $preload = new Select(
            'preload',
            [
                'auto'     => _t('自动预加载（推荐，能画出首帧、不会黑屏）'),
                'metadata' => _t('仅元数据（省流量，但播放前可能是一块黑屏）'),
                'none'     => _t('不预加载（最省流量，播放前一定黑屏）'),
            ],
            'auto',
            _t('预加载策略'),
            _t('浏览器只在 auto 下才会解码并绘制首帧。选 metadata / none 时，点播放之前会看到一块黑色，'
                . '这是浏览器的正常行为，不是视频坏了。若想省流量又要好观感，建议给视频配一张封面图')
        );
        $form->addInput($preload);

        $https = new Radio(
            'autoHttps',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('自动升级为 HTTPS'),
            _t('站点为 HTTPS 时，自动把 http:// 的视频地址改写为 https://，避免浏览器按混合内容拦截（这是「视频黑屏/不播放」最常见的原因）')
        );
        $form->addInput($https);

        $inline = new Radio(
            'playsinline',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('移动端内联播放'),
            _t('输出 playsinline 属性，iOS 上不再强制全屏接管播放')
        );
        $form->addInput($inline);

        $responsive = new Radio(
            'responsive',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('响应式样式'),
            _t('输出插件自带样式。关闭后不输出任何 CSS，完全由主题控制外观')
        );
        $form->addInput($responsive);

        $style = new Select(
            'style',
            [
                'card'    => _t('卡片 —— 圆角 + 柔和投影，悬停加深（默认）'),
                'minimal' => _t('极简 —— 细描边、直角，说明文字左对齐'),
                'cinema'  => _t('影院 —— 深色画框、大圆角、字距拉开的字幕式说明'),
                'paper'   => _t('相纸 —— 白色留白边框，偏文档排版'),
                'native'  => _t('原生 —— 不加任何装饰，交给主题'),
                'custom'  => _t('自定义 —— 只用下面的自定义 CSS'),
            ],
            'card',
            _t('前台样式'),
            _t('每种样式都会在容器上加 vi-video--样式名 的类名，便于你在此基础上继续覆盖')
        );
        $form->addInput($style);

        $colors = new Select(
            'colors',
            [
                'neutral' => _t('中性 —— 白色进度条，最不抢眼'),
                'indigo'  => _t('靛蓝 —— #7D9BFF，清爽'),
                'teal'    => _t('青绿 —— #2FD4A7，清新'),
                'amber'   => _t('琥珀 —— #F5B544，暖调'),
                'rose'    => _t('玫红 —— #FF6B8A，活泼'),
                'violet'  => _t('紫罗兰 —— #A78BFA，沉静'),
            ],
            'neutral',
            _t('播放器配色'),
            _t('作用于进度条、播放按钮与悬停反馈。容器会输出 vi-video--color-配色名 类名，' .
                '也可以用 --vi-accent 变量在自己的 CSS 里改')
        );
        $form->addInput($colors);

        $player = new Select(
            'player',
            [
                'custom' => _t('插件自绘（推荐）—— 替换浏览器控制条，可按配色定制'),
                'native' => _t('浏览器原生 —— 使用系统控件，外观由浏览器决定'),
            ],
            'custom',
            _t('播放器控件'),
            _t('浏览器原生控制条跑在闭合的 Shadow DOM 里，早年可用的 ::-webkit-media-controls-* 钩子被逐步移除，' .
                '跨浏览器无法做到一致定制，所以换外观只能由插件自绘。自绘失败时会自动回退到原生控件，' .
                '不会出现没有控件的视频')
        );
        $form->addInput($player);

        $download = new Radio(
            'showDownload',
            ['1' => _t('显示'), '0' => _t('隐藏')],
            '1',
            _t('下载按钮'),
            _t('在控制条右侧显示下载按钮。关闭后访客只能在线观看（注意：跨域视频的下载行为由视频服务器的 ' .
                'Content-Disposition 决定，浏览器可能新开标签页播放而非直接保存）')
        );
        $form->addInput($download);

        $customCss = new Textarea(
            'customCss',
            null,
            '',
            _t('自定义 CSS'),
            _t('在这里粘贴自己的样式，会追加在预置样式之后，优先级更高。'
                . '可直接使用 .vi-video / .vi-video__box / .vi-video video / .vi-video__cap 这些选择器。'
                . '选择「自定义」样式时，这里就是全部样式')
        );
        $form->addInput($customCss);

        $customCssUrl = new Text(
            'customCssUrl',
            null,
            '',
            _t('外部样式表地址'),
            _t('可选。填你自己的 CSS 文件地址（如 https://你的域名/video.css），会在页面里插入一个 link 标签。'
                . '适合样式较多、想单独维护的情况')
        );
        $form->addInput($customCssUrl);

        $smart = new Radio(
            'smartEmbed',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('识别第三方平台'),
            _t('识别 B 站、YouTube、腾讯视频链接并自动转为官方 iframe 播放器')
        );
        $form->addInput($smart);

        $autoUrl = new Radio(
            'autoEmbedUrl',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('裸链接自动播放'),
            _t('正文中独占一行的视频直链（.mp4 / .webm / .ogv）自动转为播放器。适合直接把腾讯云 COS、对象存储的地址粘进文章的场景')
        );
        $form->addInput($autoUrl);

        $mode = new Radio(
            'insertMode',
            ['shortcode' => _t('短代码（推荐）'), 'html' => _t('原生 HTML')],
            'shortcode',
            _t('插入格式'),
            _t('短代码便于以后统一调整样式；原生 HTML 不依赖插件，禁用插件后仍可播放')
        );
        $form->addInput($mode);
    }

    /**
     * 个人用户配置（本插件无此需求）
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /* ---------------------------------------------------------------------
     * 后台：编辑器面板
     * ------------------------------------------------------------------ */

    /**
     * 在写文章 / 写页面的页面底部输出按钮与弹窗
     *
     * @param mixed $post
     */
    public static function renderEditorPanel($post = null)
    {
        $css = '';
        $js = '';

        $cssFile = __DIR__ . '/assets/admin.css';
        $jsFile = __DIR__ . '/assets/admin.js';

        if (is_readable($cssFile)) {
            $css = file_get_contents($cssFile);
        }
        if (is_readable($jsFile)) {
            $js = file_get_contents($jsFile);
        }

        $conf = [
            'tag'        => (string) self::opt('tag', 'video'),
            'width'      => (string) self::opt('defaultWidth', '100%'),
            'preload'    => (string) self::opt('preload', 'auto'),
            'autoHttps'  => (string) self::opt('autoHttps', '1') === '1',
            'playsinline'=> (string) self::opt('playsinline', '1') === '1',
            'mode'       => (string) self::opt('insertMode', 'shortcode'),
        ];

        echo "\n<style id=\"vi-admin-style\">\n" . $css . "\n</style>\n";
        echo "<script>window.VI_CONFIG = " . json_encode($conf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>\n";
        echo "<script>\n" . $js . "\n</script>\n";
    }

    /* ---------------------------------------------------------------------
     * 前台：内容渲染
     * ------------------------------------------------------------------ */

    /**
     * 渲染正文中的视频短代码
     *
     * @param string|null $content
     * @param mixed $widget
     * @return string|null
     */
    public static function filterContent($content, $widget = null)
    {
        if (!is_string($content) || $content === '' || (strpos($content, '[') === false && strpos($content, 'http') === false)) {
            return $content;
        }

        // 0) 独占一段的裸视频链接 → 直接变播放器（适配对象存储地址直接粘贴）
        if ((string) self::opt('autoEmbedUrl', '1') === '1') {
            $content = preg_replace_callback(
                '~<p>\s*(?:<a[^>]*>\s*)?(https?://[^\s<>"\']+?)\s*(?:</a>)?\s*</p>~i',
                function ($m) {
                    $url = $m[1];
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    if (!preg_match('~\.(mp4|m4v|webm|ogv|ogg)$~i', $path)) {
                        return $m[0];
                    }
                    return self::renderOne(['src' => $url]);
                },
                $content
            );
        }

        $tag = (string) self::opt('tag', 'video');
        $body = $tag === 'video'
            ? self::SHORTCODE
            : '\[' . preg_quote($tag, '/') . '\b([^\]\n]*)\]';

        // 1) 独占一段的短代码：连同包裹它的 <p> 一起替换，避免 <p> 内嵌 div 的非法结构
        $content = preg_replace_callback(
            '/<p>\s*' . $body . '\s*<\/p>/is',
            function ($m) {
                return self::renderOne(self::parseAttrs($m[1]));
            },
            $content
        );

        // 2) 行内残留的短代码
        $content = preg_replace_callback('/' . $body . '/i', function ($m) {
            return self::renderOne(self::parseAttrs($m[1]));
        }, $content);

        return $content;
    }

    /**
     * 摘要中剔除短代码
     *
     * @param string|null $excerpt
     * @param mixed $widget
     * @return string|null
     */
    public static function filterExcerpt($excerpt, $widget = null)
    {
        if (!is_string($excerpt) || $excerpt === '') {
            return $excerpt;
        }

        $tag = (string) self::opt('tag', 'video');
        $body = $tag === 'video'
            ? self::SHORTCODE
            : '\[' . preg_quote($tag, '/') . '\b([^\]\n]*)\]';

        return preg_replace('/' . $body . '/i', '', $excerpt);
    }

    /**
     * 解析短代码属性
     *
     * @param string $raw
     * @return array
     */
    private static function parseAttrs($raw)
    {
        $attrs = [];

        if (preg_match_all(
            '/([a-zA-Z][\w-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+)))?/',
            (string) $raw,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $item) {
                $key = strtolower($item[1]);
                if (isset($item[2]) && $item[2] !== '') {
                    $value = $item[2];
                } elseif (isset($item[3]) && $item[3] !== '') {
                    $value = $item[3];
                } elseif (isset($item[4]) && $item[4] !== '') {
                    $value = $item[4];
                } else {
                    $value = true;
                }
                $attrs[$key] = $value;
            }
        }

        return $attrs;
    }

    /**
     * 把一条短代码渲染为 HTML
     *
     * 该函数是幂等的：输出结果中不再包含短代码，重复调用不会叠加。
     *
     * @param array $attrs
     * @return string
     */
    private static function renderOne(array $attrs)
    {
        if (empty($attrs['src']) || !is_string($attrs['src'])) {
            return '';
        }

        $src = self::normalizeUrl($attrs['src']);
        if ($src === '') {
            return '';
        }

        // 第三方平台 → iframe
        if ((string) self::opt('smartEmbed', '1') === '1') {
            $embed = self::smartEmbed($src);
            if ($embed !== '') {
                return self::wrapFigure($embed, isset($attrs['caption']) ? (string) $attrs['caption'] : '', false);
            }
        }

        $poster = '';
        if (!empty($attrs['poster']) && is_string($attrs['poster'])) {
            $poster = self::normalizeUrl($attrs['poster']);
        }

        $flags = [];
        $flags[] = 'controls';

        if ((string) self::opt('playsinline', '1') === '1') {
            $flags[] = 'playsinline';
            $flags[] = 'webkit-playsinline';
        }

        // 预加载是站点级策略：忽略短代码里的 preload 属性。
        // 历史文章中被固化的 preload="metadata" 会覆盖站点配置，导致改配置也不生效、播放前一直是黑屏。
        $preload = (string) self::opt('preload', 'auto');
        if (!in_array($preload, ['metadata', 'auto', 'none'], true)) {
            $preload = 'auto';
        }
        $flags[] = 'preload="' . $preload . '"';

        // autoplay 必须伴随 muted，否则移动端与新版 Chrome 会直接拒绝播放
        $autoplay = !empty($attrs['autoplay']) || !empty($attrs['auto']);
        $muted = !empty($attrs['muted']) || $autoplay;
        if ($autoplay) {
            $flags[] = 'autoplay';
        }
        if ($muted) {
            $flags[] = 'muted';
        }
        if (!empty($attrs['loop'])) {
            $flags[] = 'loop';
        }

        if ($poster !== '') {
            $flags[] = 'poster="' . self::esc($poster) . '"';
        }

        // 宽度：百分比走 style，数值补 px，其余忽略
        $style = '';
        if (!empty($attrs['width']) && is_string($attrs['width'])) {
            $width = trim($attrs['width']);
            if (preg_match('/^\d+(\.\d+)?%$/', $width)) {
                $style = 'width:' . $width . ';';
            } elseif (preg_match('/^\d+(\.\d+)?$/', $width)) {
                $style = 'width:' . $width . 'px;';
            }
        } else {
            $defaultWidth = trim((string) self::opt('defaultWidth', '100%'));
            if (preg_match('/^\d+(\.\d+)?%$/', $defaultWidth)) {
                $style = 'width:' . $defaultWidth . ';';
            } elseif (preg_match('/^\d+(\.\d+)?$/', $defaultWidth)) {
                $style = 'width:' . $defaultWidth . 'px;';
            }
        }

        if (!empty($attrs['ratio']) && is_string($attrs['ratio']) && preg_match('/^\d+(\.\d+)?\/\d+(\.\d+)?$/', $attrs['ratio'])) {
            $style .= 'aspect-ratio:' . $attrs['ratio'] . ';';
        }

        $styleAttr = $style !== '' ? ' style="' . self::esc($style) . '"' : '';

        $type = '';
        if (!empty($attrs['type']) && is_string($attrs['type'])) {
            $type = $attrs['type'];
        } else {
            $type = self::guessType($src);
        }

        $video = '<video' . ($styleAttr ? $styleAttr : '') . ' ' . implode(' ', $flags) . '>'
            . '<source src="' . self::esc($src) . '"' . ($type !== '' ? ' type="' . self::esc($type) . '"' : '') . '>'
            . _t('您的浏览器不支持 HTML5 视频播放。')
            . '</video>';

        return self::wrapFigure($video, isset($attrs['caption']) ? (string) $attrs['caption'] : '', true);
    }

    /**
     * 包装为 figure，并在首次输出时附带一次前台样式
     *
     * @param string $inner
     * @param string $caption
     * @param bool $isVideo
     * @return string
     */
    private static function wrapFigure($inner, $caption = '', $isVideo = true)
    {
        static $stylePrinted = false;
        static $scriptPrinted = false;

        $class = 'vi-video vi-video--' . self::currentStyle()
            . ' vi-video--color-' . self::currentColor()
            . (self::showDownload() ? '' : ' vi-video--nodl')
            . ($isVideo ? '' : ' vi-video--embed');
        $html = '<div class="' . $class . '"><div class="vi-video__box">' . $inner . '</div>';

        if ($caption !== '') {
            $html .= '<span class="vi-video__cap">' . self::esc($caption) . '</span>';
        }

        $html .= '</div>';

        $head = '';
        $tail = '';

        $needCss = (string) self::opt('responsive', '1') === '1' || self::isCustomPlayer();
        if (!$stylePrinted && $needCss) {
            $stylePrinted = true;

            $cssUrl = trim((string) self::opt('customCssUrl', ''));
            if ($cssUrl !== '' && self::isSafeUrl($cssUrl)) {
                $head .= '<link rel="stylesheet" href="' . self::esc($cssUrl) . '" id="vi-front-link">';
            }

            $head .= '<style id="vi-front-style">' . self::frontCss() . '</style>';
        }

        if ($isVideo && !$scriptPrinted && self::isCustomPlayer()) {
            $scriptPrinted = true;
            $tail = self::playerScript();
        }

        return $head . $html . $tail;
    }

    /**
     * 前台响应式样式（带 .vi- 前缀，避免与 iLogs 主题冲突）
     *
     * @return string
     */
    /**
     * 当前生效的样式名（带白名单）
     *
     * @return string
     */
    private static function currentStyle()
    {
        $style = (string) self::opt('style', 'card');
        $allowed = ['native', 'card', 'minimal', 'cinema', 'paper', 'custom'];

        return in_array($style, $allowed, true) ? $style : 'card';
    }

    /**
     * 当前配色（带白名单）
     *
     * @return string
     */
    private static function currentColor()
    {
        $color = (string) self::opt('colors', 'neutral');
        $allowed = ['neutral', 'indigo', 'teal', 'amber', 'rose', 'violet'];

        return in_array($color, $allowed, true) ? $color : 'neutral';
    }

    /**
     * 是否显示下载按钮
     *
     * @return bool
     */
    private static function showDownload()
    {
        return (string) self::opt('showDownload', '1') === '1';
    }

    /**
     * 是否使用自绘播放器
     *
     * @return bool
     */
    private static function isCustomPlayer()
    {
        return (string) self::opt('player', 'custom') !== 'native';
    }

    /**
     * 组装最终 CSS：结构样式 + 预置风格 + 用户自定义
     *
     * @return string
     */
    private static function frontCss()
    {
        $css = self::baseCss() . self::styleCss(self::currentStyle()) . self::colorCss();

        if (self::isCustomPlayer()) {
            $css .= self::playerCss();
        }

        $custom = trim((string) self::opt('customCss', ''));
        if ($custom !== '') {
            $css .= $custom;
        }

        return $css;
    }

    /**
     * 配色变量
     *
     * @return string
     */
    private static function colorCss()
    {
        $css = '.vi-video{--vi-accent:#e9ecef;--vi-accent-soft:rgba(255,255,255,.20);'
            . '--vi-track:rgba(255,255,255,.26);--vi-fg:#fff}';

        $map = [
            'indigo' => ['#7d9bff', 'rgba(125,155,255,.24)'],
            'teal'   => ['#2fd4a7', 'rgba(47,212,167,.24)'],
            'amber'  => ['#f5b544', 'rgba(245,181,68,.24)'],
            'rose'   => ['#ff6b8a', 'rgba(255,107,138,.24)'],
            'violet' => ['#a78bfa', 'rgba(167,139,250,.24)'],
        ];

        foreach ($map as $key => $pair) {
            $css .= '.vi-video--color-' . $key . '{--vi-accent:' . $pair[0]
                . ';--vi-accent-soft:' . $pair[1] . '}';
        }

        return $css;
    }

    /**
     * 自绘播放器样式
     *
     * @return string
     */
    private static function playerCss()
    {
        return '.vi-video video{cursor:pointer}'
            . '.vi-player{position:absolute;left:0;right:0;top:0;bottom:0;z-index:3;opacity:0;'
            . 'pointer-events:none;transition:opacity .22s ease;color:var(--vi-fg);'
            . 'font:12px/1 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
            . '.vi-player.is-visible{opacity:1}'
            . '.vi-player__bar{position:absolute;left:0;right:0;bottom:0;display:flex;align-items:center;gap:8px;padding:24px 12px 10px;pointer-events:none;'
            . 'background:linear-gradient(to top,rgba(6,8,12,.92),rgba(6,8,12,.5) 62%,rgba(6,8,12,0))}'
            . '.vi-player__big{position:absolute;top:50%;left:50%;width:54px;height:54px;'
            . 'margin:-27px 0 0 -27px;display:flex;align-items:center;justify-content:center;'
            . 'border:0;border-radius:50%;cursor:pointer;background:var(--vi-accent);color:#0b0d12;'
            . 'box-shadow:0 8px 26px -8px rgba(0,0,0,.65);opacity:0;transition:transform .18s ease,opacity .2s ease}'
            . '.vi-player.is-visible .vi-player__bar,.vi-player.is-visible .vi-player__big{pointer-events:auto}'
            . '.vi-player.is-paused .vi-player__big{opacity:1}'
            . '.vi-player__big:hover{transform:scale(1.06)}'
            . '.vi-player__big svg{width:21px;height:21px;fill:currentColor;margin-left:2px}'
            . '.vi-player__btn{width:30px;height:30px;display:inline-flex;align-items:center;'
            . 'justify-content:center;border:0;background:none;padding:0;color:inherit;cursor:pointer;'
            . 'opacity:.85;border-radius:6px;transition:opacity .15s,background .15s}'
            . '.vi-player__btn:hover{opacity:1;background:rgba(255,255,255,.12)}'
            . '.vi-player__btn svg{width:18px;height:18px;fill:currentColor}'
            . '.vi-player__time{display:flex;gap:4px;align-items:baseline;min-width:74px;'
            . 'white-space:nowrap;font-variant-numeric:tabular-nums;color:rgba(255,255,255,.72)}'
            . '.vi-player__time b{font-weight:400;color:#fff}'
            . '.vi-player__time i{font-style:normal;opacity:.4}'
            . '.vi-player__progress{position:relative;flex:1 1 auto;min-width:44px;height:16px;display:flex;align-items:center;'
            . 'cursor:pointer;touch-action:none;outline:none}'
            . '.vi-player__progress:before{content:"";position:absolute;left:0;right:0;height:4px;'
            . 'border-radius:2px;background:var(--vi-track)}'
            . '.vi-player__buffer,.vi-player__fill{position:absolute;left:0;height:4px;border-radius:2px;width:0}'
            . '.vi-player__buffer{background:rgba(255,255,255,.3)}'
            . '.vi-player__fill{background:var(--vi-accent)}'
            . '.vi-player__thumb{position:absolute;top:50%;left:0;width:11px;height:11px;'
            . 'margin:-5.5px 0 0 -5.5px;border-radius:50%;background:var(--vi-accent);'
            . 'box-shadow:0 0 0 4px var(--vi-accent-soft);opacity:0;transition:opacity .15s}'
            . '.vi-player__progress:hover .vi-player__thumb,'
            . '.vi-player__progress.is-drag .vi-player__thumb{opacity:1}'
            . '.vi-player__vol{position:relative;width:62px;height:16px;display:none;align-items:center;cursor:pointer}'
            . '@media(hover:hover) and (pointer:fine){.vi-player__vol{display:flex}}'
            . '.vi-player__voltrack,.vi-player__volfill{position:absolute;left:0;height:4px;border-radius:2px}'
            . '.vi-player__voltrack{width:100%;background:var(--vi-track)}'
            . '.vi-player__volfill{width:100%;background:var(--vi-accent)}'
            . '.vi-player__wrap{position:relative;display:flex}'
            . '.vi-player__rate{width:auto;min-width:38px;padding:0 4px;font-size:11px;'
            . 'font-variant-numeric:tabular-nums;letter-spacing:.02em}'
            . '.vi-player__rate i{font-style:normal;opacity:.6;margin-left:1px}'
            . '.vi-player__menu{position:absolute;bottom:calc(100% + 8px);left:50%;'
            . 'transform:translateX(-50%);display:flex;flex-direction:column;gap:1px;'
            . 'min-width:76px;padding:4px;border-radius:9px;background:rgba(18,20,26,.96);'
            . 'box-shadow:0 12px 30px -10px rgba(0,0,0,.75),inset 0 0 0 1px rgba(255,255,255,.07)}'
            . '.vi-player__menu[hidden]{display:none}'
            . '.vi-player__menu button{border:0;background:none;color:rgba(255,255,255,.78);'
            . 'font:12px/1 inherit;padding:7px 10px;border-radius:6px;cursor:pointer;'
            . 'text-align:center;white-space:nowrap;transition:background .15s,color .15s}'
            . '.vi-player__menu button:hover{background:rgba(255,255,255,.1);color:#fff}'
            . '.vi-player__menu button.is-on{color:var(--vi-accent);background:rgba(255,255,255,.08)}'
            . '.vi-video--nodl .vi-player__dl{display:none}'
            . '.vi-player__err{position:absolute;top:0;left:0;right:0;bottom:0;z-index:4;'
            . 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;'
            . 'padding:24px 24px 64px;background:rgba(8,9,12,.9);color:#e8eaed;text-align:center;line-height:1.6}'
            . '.vi-player__err[hidden]{display:none}'
            . '.vi-player__err b{font-size:13px;font-weight:600}'
            . '.vi-player__err span{font-size:12px;color:rgba(255,255,255,.62);max-width:88%}'
            . '.vi-player__err a{font-size:12px;color:var(--vi-accent);text-decoration:none;'
            . 'border-bottom:1px solid currentColor;padding-bottom:1px}'
            . '.vi-player.is-error .vi-player__big{display:none}'
            . '.vi-video__box:fullscreen{width:100%;height:100%;background:#000;'
            . 'display:flex;align-items:center;justify-content:center}'
            . '.vi-video__box:fullscreen video{max-height:100vh;max-width:100vw}'
            . '@media(max-width:640px){.vi-player__bar{gap:6px;padding:20px 8px 8px}'
            . '.vi-player__time{min-width:64px;font-size:11px}'
            . '.vi-player__big{width:46px;height:46px;margin:-23px 0 0 -23px}'
            . '.vi-player__rate{min-width:34px;font-size:10px}}'
            . '@media(max-width:420px){.vi-player__dl{display:none}'
            . '.vi-player__time{min-width:0;font-size:10px}'
            . '.vi-player__time i,.vi-player__time span{display:none}'
            . '.vi-player__bar{gap:4px;padding:18px 8px 6px}}';
    }

    /**
     * 自绘播放器脚本（整个站点只输出一次）
     *
     * @return string
     */
    private static function playerScript()
    {
        $file = __DIR__ . '/assets/player.js';
        $js = is_readable($file) ? file_get_contents($file) : '';

        return $js === '' ? '' : '<script id="vi-player-script">' . $js . '</script>';
    }

    /**
     * 结构样式：只保证尺寸与布局正确，不做任何视觉装饰
     *
     * @return string
     */
    private static function baseCss()
    {
        return '.vi-video{display:block;max-width:100%;margin:1.6em auto;line-height:0}'
            . '.vi-video__box{position:relative;display:block;max-width:100%;overflow:hidden}'
            . '.vi-video video{display:block;width:auto;height:auto;max-width:100%;max-height:80vh;'
            . 'margin:0 auto;background:#000;border:0;outline:none;object-fit:contain}'
            . '.vi-video__cap{display:block;margin:.7em 0 0;font-size:.8em;line-height:1.7;'
            . 'text-align:center;color:#8b929c;letter-spacing:.02em}'
            . '.vi-video--embed .vi-video__box{width:100%;height:0;padding-top:56.25%}'
            . '.vi-video--embed iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:0}'
            . '@media(max-width:640px){.vi-video{margin:1.1em auto}'
            . '.vi-video video{max-height:72vh}.vi-video__cap{font-size:.75em}}';
    }

    /**
     * 各预置风格的视觉样式
     *
     * @param string $style
     * @return string
     */
    private static function styleCss($style)
    {
        switch ($style) {
            case 'card':
                return '.vi-video--card .vi-video__box{border-radius:14px;background:#0b0e13;'
                    . 'box-shadow:0 1px 2px rgba(16,24,40,.06),0 10px 28px -14px rgba(16,24,40,.22);'
                    . 'transition:box-shadow .25s ease}'
                    . '.vi-video--card .vi-video__box:hover{'
                    . 'box-shadow:0 2px 4px rgba(16,24,40,.08),0 18px 40px -16px rgba(16,24,40,.30)}'
                    . '@media(prefers-color-scheme:dark){'
                    . '.vi-video--card .vi-video__box{'
                    . 'box-shadow:0 0 0 1px rgba(255,255,255,.06),0 14px 34px -18px rgba(0,0,0,.85)}'
                    . '.vi-video--card .vi-video__cap{color:#9aa1ab}}';

            case 'minimal':
                return '.vi-video--minimal .vi-video__box{border-radius:3px;border:1px solid #e5e7eb;'
                    . 'background:#000;transition:border-color .2s ease}'
                    . '.vi-video--minimal .vi-video__box:hover{border-color:#c7ccd2}'
                    . '.vi-video--minimal .vi-video__cap{text-align:left;padding-left:1px;color:#9aa0a6}'
                    . '@media(prefers-color-scheme:dark){'
                    . '.vi-video--minimal .vi-video__box{border-color:#2b2f35}'
                    . '.vi-video--minimal .vi-video__cap{color:#8b929c}}';

            case 'cinema':
                return '.vi-video--cinema{margin:2em auto}'
                    . '.vi-video--cinema:not(.vi-video--embed) .vi-video__box{padding:10px;border-radius:18px;'
                    . 'background:#08090c;'
                    . 'box-shadow:inset 0 0 0 1px rgba(255,255,255,.07),0 20px 44px -28px rgba(0,0,0,.6)}'
                    . '.vi-video--cinema:not(.vi-video--embed) video{border-radius:9px}'
                    . '.vi-video--cinema.vi-video--embed .vi-video__box{border-radius:18px}'
                    . '.vi-video--cinema .vi-video__cap{margin-top:.9em;font-size:.72em;'
                    . 'letter-spacing:.18em;text-transform:uppercase;color:#7b828c}';

            case 'paper':
                return '.vi-video--paper:not(.vi-video--embed) .vi-video__box{padding:7px;border-radius:5px;'
                    . 'background:#fff;border:1px solid #e4e7eb}'
                    . '.vi-video--paper:not(.vi-video--embed) video{border-radius:2px}'
                    . '.vi-video--paper.vi-video--embed .vi-video__box{border-radius:5px;border:1px solid #e4e7eb}'
                    . '.vi-video--paper .vi-video__cap{text-align:left;padding-left:10px;'
                    . 'border-left:2px solid #dfe3e8;color:#7a8189}'
                    . '@media(prefers-color-scheme:dark){'
                    . '.vi-video--paper:not(.vi-video--embed) .vi-video__box{background:#15171a;border-color:#2a2e34}'
                    . '.vi-video--paper .vi-video__cap{border-left-color:#33383f;color:#8d949c}}';

            case 'native':
            case 'custom':
            default:
                return '';
        }
    }

    /* ---------------------------------------------------------------------
     * 工具方法
     * ------------------------------------------------------------------ */

    /**
     * 读取插件配置
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function opt($key, $default = null)
    {
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            try {
                if (class_exists('\Helper')) {
                    $plugin = \Helper::options()->plugin('VideoInsert');
                    if ($plugin) {
                        foreach (['tag', 'defaultWidth', 'preload', 'autoHttps', 'playsinline', 'responsive', 'smartEmbed', 'autoEmbedUrl', 'insertMode', 'style', 'customCss', 'customCssUrl', 'player', 'colors', 'showDownload'] as $name) {
                            $value = $plugin->{$name};
                            if ($value !== null && $value !== '') {
                                $cache[$name] = $value;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 配置不可用时静默降级为默认值
            }
        }

        return array_key_exists($key, $cache) ? $cache[$key] : $default;
    }

    /**
     * URL 是否安全可用（外部样式表用）
     *
     * @param string $url
     * @return bool
     */
    private static function isSafeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        return (bool) preg_match('~^(https?:)?//~i', $url) || $url[0] === '/';
    }

    /**
     * 规范化 URL：协议白名单 + HTTPS 自动升级
     *
     * @param string $url
     * @return string
     */
    private static function normalizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        // 协议白名单，杜绝 javascript: / data: 注入
        if (!preg_match('~^(https?:)?//~i', $url) && $url[0] !== '/') {
            return '';
        }

        if (
            (string) self::opt('autoHttps', '1') === '1'
            && stripos($url, 'http://') === 0
            && self::isHttps()
        ) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }

    /**
     * 当前页面是否为 HTTPS
     *
     * @return bool
     */
    private static function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    /**
     * 由扩展名推断 MIME
     *
     * @param string $url
     * @return string
     */
    private static function guessType($url)
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (substr($path, -4) === '.mp4' || substr($path, -4) === '.m4v') {
            return 'video/mp4';
        }
        if (substr($path, -5) === '.webm') {
            return 'video/webm';
        }
        if (substr($path, -4) === '.ogv' || substr($path, -4) === '.ogg') {
            return 'video/ogg';
        }
        if (substr($path, -5) === '.m3u8') {
            return 'application/vnd.apple.mpegurl';
        }

        return 'video/mp4';
    }

    /**
     * 第三方平台识别
     *
     * @param string $url
     * @return string
     */
    private static function smartEmbed($url)
    {
        // B 站
        if (preg_match('~bilibili\.com/video/(BV[0-9A-Za-z]+|av\d+)~i', $url, $m)) {
            $param = stripos($m[1], 'BV') === 0 ? 'bvid' : 'aid';
            $id = $param === 'aid' ? substr($m[1], 2) : $m[1];
            return '<iframe src="https://player.bilibili.com/player.html?' . $param . '=' . self::esc($id)
                . '&page=1&high_quality=1&danmaku=0" scrolling="no" frameborder="no" framespacing="0" '
                . 'allowfullscreen="true" loading="lazy"></iframe>';
        }

        // YouTube
        if (preg_match('~youtu\.be/([\w-]{11})~i', $url, $m)
            || preg_match('~youtube\.com/(?:watch\?.*v=|embed/|shorts/)([\w-]{11})~i', $url, $m)) {
            return '<iframe src="https://www.youtube.com/embed/' . self::esc($m[1])
                . '" frameborder="0" allowfullscreen loading="lazy" '
                . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>';
        }

        // 腾讯视频
        if (preg_match('~v\.qq\.com/x/(?:page|cover)/(\w+)~i', $url, $m)) {
            return '<iframe src="https://v.qq.com/txp/iframe/player.html?vid=' . self::esc($m[1])
                . '" frameborder="0" allowfullscreen loading="lazy"></iframe>';
        }

        return '';
    }

    /**
     * HTML 转义
     *
     * @param string $value
     * @return string
     */
    private static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
