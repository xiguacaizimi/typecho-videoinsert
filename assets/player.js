/**
 * VideoInsert 自绘播放器
 * - 替换浏览器原生控制条（原生控件无法用 CSS 定制）
 * - 失败时自动回退：只有在 UI 构建成功后才移除 controls 属性
 * - 暴露 window.ViPlayer.init()，方便 PJAX / 动态插入内容后重新初始化
 */
(function () {
    'use strict';

    var ICONS = {
        play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.14v13.72c0 .8.87 1.3 1.56.9l10.8-6.86a1.05 1.05 0 0 0 0-1.8L9.56 4.24A1.05 1.05 0 0 0 8 5.14z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="4.5" width="4" height="15" rx="1.2"/><rect x="14" y="4.5" width="4" height="15" rx="1.2"/></svg>',
        volume: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 9.2h3.2L11.6 5a.8.8 0 0 1 1.3.63v12.74a.8.8 0 0 1-1.3.63l-4.9-4.2H3.5a.8.8 0 0 1-.8-.8v-4.4c0-.44.36-.8.8-.8z"/><path d="M16.2 8.4a5.1 5.1 0 0 1 0 7.2M18.7 5.6a8.8 8.8 0 0 1 0 12.8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        muted: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 9.2h3.2L11.6 5a.8.8 0 0 1 1.3.63v12.74a.8.8 0 0 1-1.3.63l-4.9-4.2H3.5a.8.8 0 0 1-.8-.8v-4.4c0-.44.36-.8.8-.8z"/><path d="M16.4 9.4l5 5.2M21.4 9.4l-5 5.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        expand: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4.6c0-.33.27-.6.6-.6H9M15 4h4.4c.33 0 .6.27.6.6V9M20 15v4.4c0 .33-.27.6-.6.6H15M9 20H4.6a.6.6 0 0 1-.6-.6V15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        shrink: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4v4.4c0 .33-.27.6-.6.6H4M20 9h-4.4a.6.6 0 0 1-.6-.6V4M15 20v-4.4c0-.33.27-.6.6-.6H20M4 15h4.4c.33 0 .6.27.6.6V20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        download: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v10.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M7.6 10.6 12 15l4.4-4.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 18.4h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
    };

    var RATES = [0.5, 0.75, 1, 1.25, 1.5, 2];
    var lastRate = 1;

    /**
     * 触发下载：优先用 a[download]
     * 跨域地址浏览器会忽略 download 属性，改为在新标签打开（取决于服务端 Content-Disposition）
     */
    function download(video) {
        var src = video.currentSrc;
        if (!src) {
            var source = video.querySelector('source');
            src = source ? source.src : '';
        }
        if (!src) {
            return;
        }

        var name = 'video.mp4';
        try {
            var clean = src.split('?')[0].split('#')[0];
            name = decodeURIComponent(clean.substring(clean.lastIndexOf('/') + 1)) || name;
        } catch (e) {
            // 保留默认文件名
        }

        var a = document.createElement('a');
        a.href = src;
        a.download = name;
        a.rel = 'noopener';
        a.target = '_blank';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function fmt(sec) {
        if (!isFinite(sec) || sec < 0) {
            sec = 0;
        }
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function el(tag, cls, html) {
        var node = document.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        if (html) {
            node.innerHTML = html;
        }
        return node;
    }

    function build(video, box) {
        var ui = el('div', 'vi-player is-visible is-paused');
        ui.innerHTML = ''
            + '<button type="button" class="vi-player__big" aria-label="播放">' + ICONS.play + '</button>'
            + '<div class="vi-player__bar">'
            + '  <button type="button" class="vi-player__btn vi-player__play" aria-label="播放">' + ICONS.play + '</button>'
            + '  <span class="vi-player__time"><b>0:00</b><i>/</i><span>0:00</span></span>'
            + '  <div class="vi-player__progress" role="slider" tabindex="0" aria-label="播放进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">'
            + '    <i class="vi-player__buffer"></i>'
            + '    <i class="vi-player__fill"></i>'
            + '    <i class="vi-player__thumb"></i>'
            + '  </div>'
            + '  <div class="vi-player__wrap">'
            + '    <button type="button" class="vi-player__btn vi-player__rate" aria-label="播放速度">1.0<i>×</i></button>'
            + '    <div class="vi-player__menu" hidden></div>'
            + '  </div>'
            + '  <button type="button" class="vi-player__btn vi-player__dl" aria-label="下载视频">' + ICONS.download + '</button>'
            + '  <button type="button" class="vi-player__btn vi-player__mute" aria-label="静音">' + ICONS.volume + '</button>'
            + '  <div class="vi-player__vol" role="slider" tabindex="0" aria-label="音量" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100">'
            + '    <i class="vi-player__voltrack"></i><i class="vi-player__volfill"></i>'
            + '  </div>'
            + '  <button type="button" class="vi-player__btn vi-player__fs" aria-label="全屏">' + ICONS.expand + '</button>'
            + '</div>'
            + '<div class="vi-player__err" hidden>'
            + '  <b>视频加载失败</b>'
            + '  <span>可能是网络中断、视频服务器限制或链接已失效</span>'
            + '  <a target="_blank" rel="noopener">打开原文件</a>'
            + '</div>';

        box.appendChild(ui);

        var big = ui.querySelector('.vi-player__big');
        var btnPlay = ui.querySelector('.vi-player__play');
        var timeCur = ui.querySelector('.vi-player__time b');
        var timeAll = ui.querySelector('.vi-player__time span');
        var bar = ui.querySelector('.vi-player__progress');
        var fill = ui.querySelector('.vi-player__fill');
        var buffer = ui.querySelector('.vi-player__buffer');
        var thumb = ui.querySelector('.vi-player__thumb');
        var btnMute = ui.querySelector('.vi-player__mute');
        var vol = ui.querySelector('.vi-player__vol');
        var volFill = ui.querySelector('.vi-player__volfill');
        var btnFs = ui.querySelector('.vi-player__fs');
        var btnRate = ui.querySelector('.vi-player__rate');
        var menu = ui.querySelector('.vi-player__menu');
        var btnDl = ui.querySelector('.vi-player__dl');
        var idleTimer = null;

        for (var ri = 0; ri < RATES.length; ri++) {
            var rateBtn = el('button', '', (RATES[ri] % 1 === 0 ? RATES[ri].toFixed(1) : String(RATES[ri])) + '×');
            rateBtn.type = 'button';
            rateBtn.setAttribute('data-rate', String(RATES[ri]));
            menu.appendChild(rateBtn);
        }

        function ratio() {
            return video.duration > 0 ? video.currentTime / video.duration : 0;
        }

        function paint() {
            var r = Math.min(1, Math.max(0, ratio()));
            var pct = (r * 100).toFixed(3) + '%';
            fill.style.width = pct;
            thumb.style.left = pct;
            bar.setAttribute('aria-valuenow', Math.round(r * 100));
            timeCur.textContent = fmt(video.currentTime);
        }

        function paintVolume() {
            var v = video.muted ? 0 : video.volume;
            volFill.style.width = (v * 100).toFixed(1) + '%';
            vol.setAttribute('aria-valuenow', Math.round(v * 100));
            btnMute.innerHTML = v === 0 ? ICONS.muted : ICONS.volume;
            btnMute.setAttribute('aria-label', v === 0 ? '取消静音' : '静音');
        }

        function setPlaying(playing) {
            ui.classList.toggle('is-paused', !playing);
            btnPlay.innerHTML = playing ? ICONS.pause : ICONS.play;
            big.innerHTML = ICONS.play;
            btnPlay.setAttribute('aria-label', playing ? '暂停' : '播放');
        }

        function idle() {
            clearTimeout(idleTimer);
            if (video.paused || !menu.hidden) {
                return;
            }
            idleTimer = setTimeout(function () {
                ui.classList.remove('is-visible');
            }, 2600);
        }

        function wake() {
            ui.classList.add('is-visible');
            idle();
        }

        function toggle() {
            if (video.paused) {
                var p = video.play();
                if (p && typeof p.catch === 'function') {
                    p.catch(function () {});
                }
            } else {
                video.pause();
            }
        }

        function seekTo(clientX) {
            var rect = bar.getBoundingClientRect();
            var r = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
            if (video.duration > 0) {
                video.currentTime = r * video.duration;
                paint();
            }
        }

        big.addEventListener('click', toggle);
        btnPlay.addEventListener('click', toggle);
        video.addEventListener('click', toggle);

        video.addEventListener('play', function () {
            setPlaying(true);
            wake();
        });
        video.addEventListener('pause', function () {
            setPlaying(false);
            wake();
        });
        video.addEventListener('ended', function () {
            setPlaying(false);
            wake();
        });
        video.addEventListener('timeupdate', paint);
        video.addEventListener('durationchange', function () {
            timeAll.textContent = fmt(video.duration);
            paint();
        });
        video.addEventListener('progress', function () {
            if (video.buffered.length && video.duration > 0) {
                var end = video.buffered.end(video.buffered.length - 1);
                buffer.style.width = ((end / video.duration) * 100).toFixed(2) + '%';
            }
        });
        video.addEventListener('volumechange', paintVolume);

        var dragging = false;
        bar.addEventListener('pointerdown', function (e) {
            dragging = true;
            bar.classList.add('is-drag');
            bar.setPointerCapture(e.pointerId);
            seekTo(e.clientX);
        });
        bar.addEventListener('pointermove', function (e) {
            if (dragging) {
                seekTo(e.clientX);
            }
        });
        bar.addEventListener('pointerup', function (e) {
            dragging = false;
            bar.classList.remove('is-drag');
            try {
                bar.releasePointerCapture(e.pointerId);
            } catch (err) {}
        });
        bar.addEventListener('keydown', function (e) {
            var step = e.shiftKey ? 10 : 5;
            if (e.key === 'ArrowRight') {
                video.currentTime = Math.min(video.duration || 0, video.currentTime + step);
                e.preventDefault();
            } else if (e.key === 'ArrowLeft') {
                video.currentTime = Math.max(0, video.currentTime - step);
                e.preventDefault();
            }
        });

        btnMute.addEventListener('click', function () {
            video.muted = !video.muted;
            if (!video.muted && video.volume === 0) {
                video.volume = 0.8;
            }
            paintVolume();
        });

        vol.addEventListener('pointerdown', function (e) {
            var rect = vol.getBoundingClientRect();
            var r = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
            video.volume = r;
            video.muted = r === 0;
            paintVolume();
        });

        btnFs.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (box.requestFullscreen) {
                box.requestFullscreen();
            } else if (video.webkitEnterFullscreen) {
                video.webkitEnterFullscreen();
            }
        });

        document.addEventListener('fullscreenchange', function () {
            var isFs = document.fullscreenElement === box;
            btnFs.innerHTML = isFs ? ICONS.shrink : ICONS.expand;
            ui.classList.add('is-visible');
        });

        function paintRate() {
            var r = video.playbackRate || 1;
            btnRate.innerHTML = (r % 1 === 0 ? r.toFixed(1) : String(r)) + '<i>×</i>';
            var items = menu.children;
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('is-on', parseFloat(items[i].getAttribute('data-rate')) === r);
            }
        }

        btnRate.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.hidden = !menu.hidden;
            wake();
        });

        menu.addEventListener('click', function (e) {
            var target = e.target;
            if (!target || target.nodeName !== 'BUTTON') {
                return;
            }
            var rate = parseFloat(target.getAttribute('data-rate'));
            video.playbackRate = rate;
            lastRate = rate;
            menu.hidden = true;
            paintRate();
        });

        document.addEventListener('click', function () {
            if (!menu.hidden) {
                menu.hidden = true;
            }
        });

        btnDl.addEventListener('click', function () {
            download(video);
        });

        video.addEventListener('ratechange', paintRate);

        var errBox = ui.querySelector('.vi-player__err');
        var errLink = errBox.querySelector('a');

        function showError() {
            var link = video.currentSrc;
            if (!link) {
                var first = video.querySelector('source');
                link = first ? first.src : '';
            }
            if (link) {
                errLink.href = link;
            }
            errBox.hidden = false;
            box.style.minHeight = '220px';
            ui.classList.add('is-visible');
            ui.classList.add('is-error');
        }

        // 视频源不可用（404 / 403 / 编码不支持）时不再是无声黑屏
        video.addEventListener('error', showError);
        var sources = video.querySelectorAll('source');
        for (var si = 0; si < sources.length; si++) {
            sources[si].addEventListener('error', showError);
        }

        if (lastRate && lastRate !== 1) {
            video.playbackRate = lastRate;
        }
        paintRate();

        box.addEventListener('pointermove', wake);
        box.addEventListener('pointerleave', idle);

        setPlaying(!video.paused);
        paintVolume();
        paint();
        timeAll.textContent = fmt(video.duration);
        if (!video.paused) {
            idle();
        }
    }

    function init() {
        var videos = document.querySelectorAll('.vi-video video');
        for (var i = 0; i < videos.length; i++) {
            var video = videos[i];
            if (video.dataset.viPlayer) {
                continue;
            }
            var box = video.parentNode;
            while (box && box.className && box.className.indexOf('vi-video__box') === -1) {
                box = box.parentNode;
            }
            if (!box || box.className.indexOf('vi-video__box') === -1) {
                continue;
            }
            try {
                build(video, box);
                video.dataset.viPlayer = '1';
                video.removeAttribute('controls');
            } catch (e) {
                // 构建失败则保留原生控件
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.ViPlayer = { init: init };
})();
