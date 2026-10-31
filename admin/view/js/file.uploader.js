/**
 +----------------------------------------------------------
 * 后台文件上传引擎（file 模块 · 相册 / 附件 / 编辑器插图）
 +----------------------------------------------------------
 * 入口只有一个命名空间对象，不存在任何全局函数：
 *
 *   douFileUploader.init(root)              扫描并初始化 root 下的上传容器
 *   douFileUploader.pick(container)         打开文件选择器
 *   douFileUploader.destroy(container, no)  删除指定编号并回填列表
 *   douFileUploader.remove(number)          只删除不回填（单图控件用）
 *   douFileUploader.abort(container)        中止该容器正在进行的上传
 *
 * 容器协议与前台 theme/default/js/file.js 完全一致，但实现独立：
 *   data-file-type        gallery | attach | content | main
 *   data-file-prefix      DOM 前缀，派生 #{prefix}List 列表节点
 *   data-file-module      落盘模块名（后台即 $cur）
 *   data-file-item-id     编辑场景业务主键；新建场景为空
 *   data-file-draft-token 页面全局草稿 token
 *   data-file-img-width   图片最大宽度（空串由服务端兜底）
 *   data-file-editor      type=content 专用：vditor / ueditor（允许写 cur_editor 由本脚本解析）
 *   data-crop-ratio       裁剪比例 "W/H"（空串 = 自由比例，仍可在勾选后裁剪）
 *
 * 删除按钮协议：`data-file-del` + `data-number`（相册/附件行由 core 渲染）。
 * 刷新目标不靠行上的 `data-list`（仅供自描述），而是按容器 `data-file-prefix` 派生 `#{prefix}List`。
 *
 * 后台特有分支：
 *   - type=content 的响应是 `<img>` 片段，交给 douEditor 插入，不渲染列表；
 *   - 入口对 AJAX 异常的 DomainException 会回 200 + 整页提示，需提取 <h2> 文案弹窗；
 *   - 裁剪走 admin 的 window.douCrop，产物直接进 FormData（不写回 input.files）。
 *
 * 与前台同日：单个文件独立请求、并发 2、失败不连坐、事件与前台同名。
 */
(function (window, document) {
    'use strict';

    var CONCURRENCY = 2;
    var MAX_RETRY = 1;
    var STYLE_ID = 'douFileUploaderStyle';

    /* ------------------------------------------------------------------
     * 小工具
     * ------------------------------------------------------------------ */

    function attr(el, name, fallback) {
        if (!el || !el.getAttribute) {
            return fallback;
        }
        var value = el.getAttribute(name);

        return value === null || value === '' ? fallback : value;
    }

    function closest(el, selector) {
        while (el && el !== document) {
            if (el.nodeType === 1) {
                var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
                if (fn && fn.call(el, selector)) {
                    return el;
                }
            }
            el = el.parentNode;
        }

        return null;
    }

    function token() {
        var input = document.querySelector('#dou-post-form input[name=token]')
            || document.querySelector('input[name=token]');

        return input ? input.value || '' : '';
    }

    function emit(el, name, detail) {
        if (!el || !el.dispatchEvent) {
            return;
        }
        var event;
        try {
            event = new window.CustomEvent(name, { bubbles: true, detail: detail || {} });
        } catch (e) {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, false, detail || {});
        }
        el.dispatchEvent(event);
    }

    function changed(container, action) {
        emit(document, 'dou.file.changed', {
            prefix: attr(container, 'data-file-prefix', ''),
            module: attr(container, 'data-file-module', ''),
            type: attr(container, 'data-file-type', ''),
            action: action
        });
    }

    function tr(key, fallback) {
        return typeof window.lang === 'function' ? window.lang(key, fallback) : fallback;
    }

    /**
     * 失败文案：入口对 AJAX 异常返回 200 + dou_msg.htm 整页提示（如上传超限），
     * 提取 <h2> 文案；JSON envelope 取 message；其余剥标签截断。
     */
    function errorText(xhr) {
        if (xhr && typeof xhr.responseText === 'string' && xhr.responseText !== '') {
            var html = xhr.responseText;
            if (/<html[\s>]|<!DOCTYPE/i.test(html)) {
                var heading = html.match(/<h2[^>]*>([\s\S]*?)<\/h2>/i);
                if (heading) {
                    var text = heading[1].replace(/<[^>]*>/g, '').replace(/^\s+|\s+$/g, '');
                    if (text) {
                        return text;
                    }
                }
            }
            try {
                var json = JSON.parse(html);
                if (json && json.message) {
                    return String(json.message);
                }
            } catch (e) {
                // 非 JSON，走剥标签
            }
            var plain = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
            if (plain) {
                return plain.slice(0, 100);
            }
        }

        return tr('upload_failed', '上传失败');
    }

    /* ------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------ */

    function send(options, handlers) {
        var xhr = new window.XMLHttpRequest();
        var retries = 0;

        function fire(name, payload) {
            if (typeof handlers[name] === 'function') {
                handlers[name](payload);
            }
        }

        function post() {
            xhr.open('POST', options.url, true);
            xhr.timeout = options.timeout || 120000;
            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable) {
                    fire('progress', { loaded: event.loaded, total: event.total, ratio: event.loaded / event.total });
                }
            };
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    fire('done', xhr.responseText);
                    return;
                }
                if (xhr.status >= 500 && retries < MAX_RETRY) {
                    retries++;
                    post();
                    return;
                }
                fire('error', errorText(xhr));
            };
            xhr.onerror = function () {
                if (retries < MAX_RETRY) {
                    retries++;
                    post();
                    return;
                }
                fire('error', errorText(null));
            };
            xhr.ontimeout = function () {
                fire('error', errorText(null));
            };
            xhr.onabort = function () {
                fire('abort', null);
            };
            xhr.send(options.body);
        }

        post();

        return xhr;
    }

    /* ------------------------------------------------------------------
     * Renderer
     * ------------------------------------------------------------------ */

    function listOf(container) {
        var prefix = attr(container, 'data-file-prefix', '');
        var el = prefix === '' ? null : document.getElementById(prefix + 'List');

        return el || container.querySelector('.file-list');
    }

    function editorKindOf(container) {
        var raw = attr(container, 'data-file-editor', '');
        if (raw === '' || raw === 'cur_editor') {
            return typeof window.cur_editor === 'string' ? window.cur_editor : '';
        }

        return raw;
    }

    function renderContent(container, html) {
        if (/<html[\s>]|<!DOCTYPE/i.test(html)) {
            window.alert(errorText({ responseText: html }));
            return;
        }
        if (html.indexOf('<img') < 0) {
            window.alert(html);
            return;
        }
        var target = attr(container, 'data-file-prefix', '');
        var editor = window.douEditor ? window.douEditor[target] : null;
        if (!editor) {
            window.alert(tr('upload_failed', '上传失败'));
            return;
        }
        if (editorKindOf(container) === 'vditor') {
            editor.insertValue(html);
            return;
        }
        editor.execCommand('insertHtml', html);
    }

    function numberSet(container) {
        var list = listOf(container);
        var map = {};
        if (!list) {
            return map;
        }
        var items = list.querySelectorAll('[data-number]');
        for (var i = 0; i < items.length; i++) {
            map[items[i].getAttribute('data-number')] = 1;
        }

        return map;
    }

    function render(container, html) {
        var type = attr(container, 'data-file-type', '');
        if (type === 'content') {
            renderContent(container, html);
            return '';
        }
        if (/<html[\s>]|<!DOCTYPE/i.test(html)) {
            var heading = String(html).match(/<h2[^>]*>([\s\S]*?)<\/h2>/i);

            return heading ? heading[1].replace(/<[^>]*>/g, '').replace(/^\s+|\s+$/g, '') : '';
        }

        var before = numberSet(container);
        var list = listOf(container);
        if (!list) {
            return '';
        }
        list.innerHTML = html;

        // 新上传项按 data-number 差集判定，替代老的「按条目数切片」（整表替换后旧口径会误判）
        var after = numberSet(container);
        for (var number in after) {
            if (!Object.prototype.hasOwnProperty.call(after, number) || before[number]) {
                continue;
            }
            var li = list.querySelector('[data-number="' + number + '"]');
            if (li && typeof window.flashUpdated === 'function') {
                window.flashUpdated(window.jQuery ? window.jQuery(li) : li);
            }
        }

        return '';
    }

    function showError(container, message) {
        var list = listOf(container);
        var text = String(message || '').replace(/^\s+|\s+$/g, '');
        if (!list || text === '') {
            window.alert(text || tr('upload_failed', '上传失败'));
            return;
        }

        var item = document.createElement('li');
        item.className = 'file-item-error';
        item.textContent = text;
        list.insertBefore(item, list.firstChild);
    }

    function setBusy(container, busy) {
        var status = container.querySelector('.file-status');
        var button = container.querySelector('.btn-file');
        if (status) {
            status.style.display = busy ? '' : 'none';
        }
        if (button) {
            button.style.display = busy ? 'none' : '';
        }
    }

    function setProgress(container, ratio) {
        var bar = container.querySelector('[data-file-progress]');
        if (!bar) {
            return;
        }
        bar.style.display = '';
        bar.style.width = Math.max(0, Math.min(100, Math.round(ratio * 100))) + '%';
    }

    /* ------------------------------------------------------------------
     * 上传主体
     * ------------------------------------------------------------------ */

    function boxUrl(container) {
        return window.route('admin.file.box', {}, {
            query: {
                module: attr(container, 'data-file-module', ''),
                target: attr(container, 'data-file-prefix', '')
            }
        });
    }

    function formDataOf(container, item) {
        var prefix = attr(container, 'data-file-prefix', '');
        var body = new window.FormData();

        // 字段名必须与 UploadedFile::allFromGlobals($target . '_file') 对齐
        body.append(prefix + '_file[]', item.file, item.name);
        body.append('type', attr(container, 'data-file-type', ''));
        body.append('item_id', attr(container, 'data-file-item-id', '0'));
        body.append('draft_token', attr(container, 'data-file-draft-token', ''));
        body.append('token', token());
        body.append('img_width', attr(container, 'data-file-img-width', ''));
        body.append('language_pack', attr(container, 'data-file-language-pack', ''));

        return body;
    }

    function stateOf(container) {
        if (!container.__douFileUploader) {
            container.__douFileUploader = { queue: [], running: 0, xhrs: [], aborted: false };
        }

        return container.__douFileUploader;
    }

    function pump(container) {
        var state = stateOf(container);
        if (state.aborted) {
            return;
        }
        while (state.running < CONCURRENCY && state.queue.length) {
            uploadOne(container, state.queue.shift());
        }
        if (state.running === 0 && state.queue.length === 0) {
            setBusy(container, false);
            emit(container, 'file:all-done', {});
        }
    }

    function uploadOne(container, item) {
        var state = stateOf(container);
        state.running++;
        setBusy(container, true);
        emit(container, 'file:uploading', { name: item.name });

        var xhr = null;

        // 请求结束后必须从 xhrs 里摘掉：否则长时编辑页会一直持有已完成的 XHR 与响应体
        function release() {
            for (var i = 0; i < state.xhrs.length; i++) {
                if (state.xhrs[i] === xhr) {
                    state.xhrs.splice(i, 1);
                    return;
                }
            }
        }

        xhr = send({
            url: boxUrl(container),
            body: formDataOf(container, item)
        }, {
            progress: function (event) {
                setProgress(container, event.ratio);
                emit(container, 'file:progress', { name: item.name, loaded: event.loaded, total: event.total });
            },
            done: function (html) {
                state.running--;
                release();
                var message = render(container, html);
                if (message) {
                    showError(container, message);
                    emit(container, 'file:error', { name: item.name, message: message });
                } else {
                    emit(container, 'file:done', { name: item.name });
                    changed(container, 'upload');
                }
                pump(container);
            },
            error: function (message) {
                state.running--;
                release();
                showError(container, message);
                emit(container, 'file:error', { name: item.name, message: message });
                pump(container);
            },
            abort: function () {
                state.running--;
                release();
                pump(container);
            }
        });

        state.xhrs.push(xhr);
    }

    function enqueue(container, items) {
        if (!items.length) {
            return;
        }
        var state = stateOf(container);
        state.aborted = false;
        for (var i = 0; i < items.length; i++) {
            state.queue.push(items[i]);
        }
        emit(container, 'file:queued', { count: items.length });
        pump(container);
    }

    /* ------------------------------------------------------------------
     * 裁剪（复用 admin 的 window.douCrop）
     * ------------------------------------------------------------------ */

    function cropOn(container) {
        if (attr(container, 'data-file-type', '') === 'content') {
            return false;
        }
        var checkbox = container.querySelector('.file-crop-pref');

        // 比例为空（站点未配缩略图尺寸）不当作「不能裁」，而是自由比例；
        // 否则勾选框仍然展示却毫无反应，比不展示更困惑
        return !!(checkbox && checkbox.checked && window.douCrop);
    }

    function cropFiles(container, files, done) {
        var ratio = attr(container, 'data-crop-ratio', '');
        var result = [];
        var index = 0;

        function next() {
            if (index >= files.length) {
                done(result);
                return;
            }
            var file = files[index++];
            if (!window.douCrop.editableFile || !window.douCrop.editableFile(file)) {
                result.push({ file: file, name: file.name });
                next();
                return;
            }
            window.douCrop.editFile(file, ratio, function (blob) {
                if (blob) {
                    result.push({
                        file: blob,
                        name: window.blobToNamedFile ? window.blobToNamedFile(blob, file.name).name : file.name
                    });
                }
                next();
            });
        }

        next();
    }

    /* ------------------------------------------------------------------
     * 文件选择器
     * ------------------------------------------------------------------ */

    function inputOf(container) {
        var input = container.querySelector('[data-file-field]');
        if (input) {
            return input;
        }

        input = document.createElement('input');
        input.type = 'file';
        input.setAttribute('data-file-field', '1');
        input.style.position = 'absolute';
        input.style.left = '-9999px';
        input.style.top = '0';
        input.style.width = '1px';
        input.style.height = '1px';
        input.style.opacity = '0';
        input.style.pointerEvents = 'none';
        container.appendChild(input);

        input.addEventListener('change', function () {
            var files = [];
            for (var i = 0; i < input.files.length; i++) {
                files.push(input.files[i]);
            }
            // 立刻清空，便于再次选择同一文件
            input.value = '';
            if (!files.length) {
                return;
            }

            if (!cropOn(container)) {
                var plain = [];
                for (var k = 0; k < files.length; k++) {
                    plain.push({ file: files[k], name: files[k].name });
                }
                enqueue(container, plain);
                return;
            }

            cropFiles(container, files, function (items) {
                enqueue(container, items);
            });
        });

        return input;
    }

    /* ------------------------------------------------------------------
     * 命名空间 API
     * ------------------------------------------------------------------ */

    var douFileUploader = {
        /**
         * 初始化 root 下的上传容器。幂等，可对 AJAX 局部刷新后的节点重复调用。
         *
         * @param {Element|Document} root
         */
        init: function (root) {
            var scope = root || document;
            var nodes = scope.querySelectorAll('[data-file-type]');
            for (var i = 0; i < nodes.length; i++) {
                var container = nodes[i];
                if (container.getAttribute('data-file-ready') === '1') {
                    continue;
                }
                container.setAttribute('data-file-ready', '1');
                inputOf(container).multiple = attr(container, 'data-file-type', '') !== 'main';
            }
        },

        /**
         * @param {Element} container
         */
        pick: function (container) {
            if (!container) {
                return;
            }
            this.init(container.parentNode || document);
            var input = inputOf(container);
            input.multiple = attr(container, 'data-file-type', '') !== 'main';
            input.click();
        },

        /**
         * 删除并用服务端返回的列表 HTML 回填。
         *
         * @param {Element} container
         * @param {string} number
         */
        destroy: function (container, number) {
            if (!container || !number) {
                return;
            }

            var body = new window.FormData();
            body.append('number', number);
            body.append('token', token());

            send({
                url: window.route('admin.file.destroy'),
                body: body
            }, {
                done: function (html) {
                    var message = render(container, html);
                    if (message) {
                        showError(container, message);
                        return;
                    }
                    changed(container, 'destroy');
                },
                error: function (message) {
                    showError(container, message);
                },
                abort: function () {}
            });
        },

        /**
         * 只删除不回填：单图控件（.file-image）删掉已存图后由调用方自行清空预览。
         *
         * @param {string} number
         */
        remove: function (number) {
            if (!number) {
                return;
            }
            var body = new window.FormData();
            body.append('number', number);
            body.append('token', token());

            send({
                url: window.route('admin.file.destroy'),
                body: body
            }, {
                done: function () {},
                error: function (message) {
                    window.alert(message);
                },
                abort: function () {}
            });
        },

        /**
         * @param {Element} container
         */
        abort: function (container) {
            if (!container) {
                return;
            }
            var state = stateOf(container);
            state.aborted = true;
            state.queue.length = 0;
            // 先取快照再清空：abort 会同步触发 onabort，回调里的 release() 会对同一个数组做 splice
            var pending = state.xhrs.slice();
            state.xhrs.length = 0;
            for (var i = 0; i < pending.length; i++) {
                try {
                    pending[i].abort();
                } catch (e) {
                    // 已结束的请求 abort 会抛，忽略
                }
            }
            state.running = 0;
            setBusy(container, false);
        }
    };

    window.douFileUploader = douFileUploader;

    /* ------------------------------------------------------------------
     * 全局事件委托：一次 document 监听覆盖后续动态插入的容器
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (event) {
        var stage = closest(event.target, '.file-upload-stage, [data-file-pick]');
        if (stage) {
            var stageBox = closest(stage, '[data-file-type]');
            if (stageBox) {
                event.preventDefault();
                douFileUploader.pick(stageBox);
            }
            return;
        }

        var remove = closest(event.target, '[data-file-del]');
        if (!remove) {
            return;
        }
        event.preventDefault();
        var container = closest(remove, '[data-file-type]');
        if (!container) {
            return;
        }
        var number = attr(remove, 'data-number', '') || attr(closest(remove, '[data-number]'), 'data-number', '');
        if (number) {
            douFileUploader.destroy(container, number);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            douFileUploader.init(document);
        });
    } else {
        douFileUploader.init(document);
    }
})(window, document);
