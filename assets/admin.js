/**
 * VideoInsert - 后台编辑器面板
 * 说明：全量包在 IIFE 内，只向外暴露一个 window.VI_CONFIG 读取入口，
 *      不覆盖 Typecho 的任何全局函数，避免与其他后台插件冲突。
 */
(function () {
    'use strict';

    var CFG = window.VI_CONFIG || {};
    var mounted = false;
    var tries = 0;
    var modal = null;

    var PLATFORMS = [
        { re: /bilibili\.com\/video\/(BV[0-9A-Za-z]+|av\d+)/i, name: 'B 站' },
        { re: /youtu\.be\/[\w-]{11}|youtube\.com\/(watch\?.*v=|embed\/|shorts\/)/i, name: 'YouTube' },
        { re: /v\.qq\.com\/x\/(page|cover)\//i, name: '腾讯视频' }
    ];

    function $(id) {
        return document.getElementById(id);
    }

    function quote(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // 去掉粘贴时可能混入的零宽字符、全角空格与首尾空白
    function cleanUrl(value) {
        return String(value).replace(/[\u200b-\u200d\ufeff\u3000]/g, '').trim();
    }

    function detectPlatform(url) {
        for (var i = 0; i < PLATFORMS.length; i++) {
            if (PLATFORMS[i].re.test(url)) {
                return PLATFORMS[i].name;
            }
        }
        return '';
    }

    function guessType(url) {
        var path = url.split('?')[0].toLowerCase();
        if (/\.webm$/.test(path)) { return 'video/webm'; }
        if (/\.ogv$/.test(path) || /\.ogg$/.test(path)) { return 'video/ogg'; }
        return 'video/mp4';
    }

    /* ---------------------------------------------------------------
     * 插入到编辑器
     * ------------------------------------------------------------ */
    function insertText(text) {
        var ta = $('text');
        if (!ta) { return false; }

        var jq = window.jQuery;
        try {
            if (jq && typeof jq(ta).getSelection === 'function') {
                var sel = jq(ta).getSelection();
                jq(ta).replaceSelection(text);
                var pos = (sel ? sel.start : 0) + text.length;
                jq(ta).setSelection(pos, pos);
                jq(ta).trigger('input');
                return true;
            }
        } catch (e) {
            /* 退回原生实现 */
        }

        var start = ta.selectionStart || 0;
        var end = ta.selectionEnd || 0;
        ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
        var cursor = start + text.length;
        ta.selectionStart = ta.selectionEnd = cursor;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.focus();
        return true;
    }

    /* ---------------------------------------------------------------
     * 生成插入内容
     * ------------------------------------------------------------ */
    function buildShortcode(data) {
        var parts = ['[video src="' + quote(data.src) + '"'];
        if (data.poster) { parts.push('poster="' + quote(data.poster) + '"'); }
        if (data.width) { parts.push('width="' + quote(data.width) + '"'); }
        if (data.autoplay) { parts.push('autoplay'); }
        if (data.loop) { parts.push('loop'); }
        if (data.muted || data.autoplay) { parts.push('muted'); }
        if (data.caption) { parts.push('caption="' + quote(data.caption) + '"'); }
        return parts.join(' ') + ']';
    }

    function buildHtml(data) {
        var style = '';
        if (data.width) {
            style = ' style="width:' + (/^\d+(\.\d+)?%$/.test(data.width) ? data.width : data.width + 'px') + '"';
        }

        var attrs = ' controls playsinline preload="' + (CFG.preload || 'auto') + '"';
        if (data.poster) { attrs += ' poster="' + quote(data.poster) + '"'; }
        if (data.autoplay) { attrs += ' autoplay'; }
        if (data.loop) { attrs += ' loop'; }
        if (data.muted || data.autoplay) { attrs += ' muted'; }

        return '<video' + attrs + style + '>'
            + '<source src="' + quote(data.src) + '" type="' + guessType(data.src) + '">'
            + '您的浏览器不支持 HTML5 视频播放。'
            + '</video>';
    }

    /* ---------------------------------------------------------------
     * 弹窗
     * ------------------------------------------------------------ */
    function buildModal() {
        if (modal) { return modal; }

        var wrap = document.createElement('div');
        wrap.className = 'vi-mask';
        wrap.id = 'vi-mask';
        wrap.innerHTML = ''
            + '<div class="vi-dialog" role="dialog" aria-modal="true" aria-label="插入视频">'
            + '  <div class="vi-dialog__head"><b>插入视频</b>'
            + '    <span class="vi-dialog__x" id="vi-close" role="button" tabindex="0">×</span>'
            + '  </div>'
            + '  <div class="vi-dialog__body">'
            + '    <div class="vi-field">'
            + '      <label for="vi-src">视频地址 <em>*</em></label>'
            + '      <input type="text" id="vi-src" placeholder="https://example.com/video.mp4 或 B 站/YouTube 链接" autocomplete="off">'
            + '      <p class="vi-hint" id="vi-hint">支持 mp4 / webm / ogv 直链（含腾讯云 COS、阿里云 OSS 等对象存储地址），也支持 B 站、YouTube、腾讯视频页面链接</p>'
            + '    </div>'
            + '    <div class="vi-field">'
            + '      <label for="vi-poster">封面图（可选）</label>'
            + '      <input type="text" id="vi-poster" placeholder="https://example.com/poster.jpg，留空则显示视频首帧" autocomplete="off">'
            + '    </div>'
            + '    <div class="vi-row">'
            + '      <div class="vi-field">'
            + '        <label for="vi-width">宽度</label>'
            + '        <input type="text" id="vi-width" placeholder="100%" autocomplete="off">'
            + '      </div>'
            + '    </div>'
            + '    <div class="vi-field">'
            + '      <label for="vi-caption">说明文字（可选）</label>'
            + '      <input type="text" id="vi-caption" placeholder="显示在播放器下方" autocomplete="off">'
            + '    </div>'
            + '    <div class="vi-checks">'
            + '      <label><input type="checkbox" id="vi-autoplay"> 自动播放</label>'
            + '      <label><input type="checkbox" id="vi-loop"> 循环</label>'
            + '      <label><input type="checkbox" id="vi-muted"> 静音</label>'
            + '    </div>'
            + '    <p class="vi-tip">提示：浏览器要求自动播放必须静音，勾选自动播放时会自动加上 muted。</p>'
            + '    <p class="vi-error" id="vi-error"></p>'
            + '  </div>'
            + '  <div class="vi-dialog__foot">'
            + '    <button type="button" class="btn" id="vi-cancel">取消</button>'
            + '    <button type="button" class="btn primary" id="vi-insert">插入</button>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(wrap);

        $('vi-close').onclick = close;
        $('vi-cancel').onclick = close;
        $('vi-insert').onclick = submit;
        wrap.onclick = function (e) {
            if (e.target === wrap) { close(); }
        };

        document.addEventListener('keydown', function (e) {
            if (!modal || modal.style.display === 'none') { return; }
            if (e.key === 'Escape') { close(); }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { submit(); }
        });

        $('vi-src').addEventListener('input', onSrcChange);

        modal = wrap;
        return wrap;
    }

    function onSrcChange() {
        var url = $('vi-src').value.trim();
        var platform = detectPlatform(url);
        var hint = $('vi-hint');
        var videoOnly = ['vi-poster', 'vi-autoplay', 'vi-loop', 'vi-muted'];

        if (platform) {
            hint.innerHTML = '已识别为 <b>' + platform + '</b>，将使用官方播放器嵌入，下方的播放器选项不生效';
            hint.className = 'vi-hint vi-hint--ok';
            videoOnly.forEach(function (id) { $(id).disabled = true; });
        } else {
            hint.innerHTML = '支持 mp4 / webm / ogv 直链（含腾讯云 COS、阿里云 OSS 等对象存储地址），也支持 B 站、YouTube、腾讯视频页面链接';
            hint.className = 'vi-hint';
            videoOnly.forEach(function (id) { $(id).disabled = false; });
        }
    }

    function open() {
        buildModal();

        $('vi-error').textContent = '';
        $('vi-src').value = '';
        $('vi-poster').value = '';
        $('vi-caption').value = '';
        $('vi-width').value = CFG.width || '100%';
        $('vi-autoplay').checked = false;
        $('vi-loop').checked = false;
        $('vi-muted').checked = false;

        onSrcChange();

        modal.style.display = 'flex';
        document.body.classList.add('vi-lock');

        setTimeout(function () { $('vi-src').focus(); }, 30);
    }

    function close() {
        if (!modal) { return; }
        modal.style.display = 'none';
        document.body.classList.remove('vi-lock');
    }

    function submit() {
        var src = cleanUrl($('vi-src').value);
        var error = $('vi-error');

        if (!src) {
            error.textContent = '请填写视频地址';
            return;
        }

        if (!/^(https?:)?\/\//i.test(src)) {
            error.textContent = '地址需要以 http:// 或 https:// 开头，当前是：' + src.slice(0, 40);
            return;
        }

        // HTTPS 页面下自动把 http 源升级为 https，避免混合内容被浏览器拦截
        if (CFG.autoHttps && /^http:\/\//i.test(src) && location.protocol === 'https:') {
            src = src.replace(/^http:\/\//i, 'https://');
        }

        var data = {
            src: src,
            poster: $('vi-poster').value.trim(),
            width: $('vi-width').value.trim(),
            autoplay: $('vi-autoplay').checked,
            loop: $('vi-loop').checked,
            muted: $('vi-muted').checked,
            caption: $('vi-caption').value.trim()
        };

        var code = CFG.mode === 'html' ? buildHtml(data) : buildShortcode(data);
        var payload = '\n\n' + code + '\n\n';

        if (!insertText(payload)) {
            error.textContent = '未找到编辑器输入框，请刷新页面后重试';
            return;
        }

        close();
    }

    /* ---------------------------------------------------------------
     * 挂载按钮
     * ------------------------------------------------------------ */
    function addToolbarButton(row) {
        var li = document.createElement('li');
        li.className = 'wmd-button vi-tool';
        li.id = 'vi-open';
        li.title = '插入视频';
        li.setAttribute('role', 'button');
        li.setAttribute('tabindex', '0');
        li.innerHTML = '<span class="vi-tool__icon">▶</span>';
        li.onclick = open;
        li.onkeydown = function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        };
        row.appendChild(li);
    }

    function addFallbackBar(textarea) {
        var bar = document.createElement('div');
        bar.className = 'vi-bar';
        bar.innerHTML = '<button type="button" class="btn btn-xs" id="vi-open">▶ 插入视频</button>';
        textarea.parentNode.insertBefore(bar, textarea);
        $('vi-open').onclick = open;
    }

    function mount() {
        if (mounted) { return; }

        var row = $('wmd-button-row');
        if (row) {
            addToolbarButton(row);
            mounted = true;
            return;
        }

        // Markdown 编辑器初始化在 DOM ready 之后，等它最多约 3 秒
        if (tries++ < 10) {
            setTimeout(mount, 300);
            return;
        }

        var ta = $('text');
        if (ta && !$('vi-open')) {
            addFallbackBar(ta);
            mounted = true;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(mount, 200); });
    } else {
        setTimeout(mount, 200);
    }
})();
