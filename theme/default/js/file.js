/**
 +----------------------------------------------------------
 * 前台文件盒子（file 模块 · 通用上传组件）
 +----------------------------------------------------------
 * 入口只有一个命名空间对象，不存在任何全局函数：
 *
 *   douFileBox.init(root)              扫描并初始化 root 下的上传容器
 *   douFileBox.pick(container)         打开文件选择器
 *   douFileBox.destroy(container, no)  删除指定编号并回填列表
 *   douFileBox.abort(container)        中止该容器正在进行的上传
 *
 * 容器协议（全部落在 `#{prefix}File` 上，结构见 inc/file_image.tpl 等片段）：
 *   data-file-type        main | gallery | attach
 *   data-file-prefix      DOM 前缀，派生 #{prefix}List 列表节点
 *   data-file-module      落盘模块名
 *   data-file-item-id     编辑场景业务主键；空串即草稿
 *   data-file-draft-token 页面全局草稿 token
 *   data-file-extensions  允许扩展名（逗号分隔，空串走平台基线）
 *   data-file-max-size    单文件上限 KB（0 走平台基线）
 *   data-file-img-width   图片最大宽度（0 不缩放）
 *
 * 删除按钮协议（由服务端渲染，见 GalleryQuery / FileService）：
 *   data-file-del + data-number（`data-list` 仅供自描述，刷新目标按容器 prefix 派生）
 *
 * 分层：
 *   Transport  XMLHttpRequest + FormData，支持进度 / abort / timeout / 重试一次
 *   Queue      并发 2、保持选择顺序、单个文件独立请求（一张失败不影响其余）
 *   Events     容器上派发 file:queued / uploading / progress / done / error / all-done，
 *              页面级派发 dou.file.changed（detail: prefix / module / type / action）
 *   Renderer   服务端返回整表 HTML 后替换列表；失败信息以 .file-error 追加到容器末尾（落在上传区下方）
 *
 * 与后台 admin/view/js/file.uploader.js 是两份独立实现，只共享上面的协议，
 * 不共享代码、不共享资源；主题之间也不允许互相依赖。
 */
(function (window, document) {
    'use strict';

    /** 同时进行的上传请求数 */
    var CONCURRENCY = 2;
    /** 单个文件失败后的重试次数（网络层失败才重试，业务拒绝不重试） */
    var MAX_RETRY = 1;

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

    function matches(el, selector) {
        var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;

        return fn ? fn.call(el, selector) : false;
    }

    function closest(el, selector) {
        while (el && el !== document) {
            if (el.nodeType === 1 && matches(el, selector)) {
                return el;
            }
            el = el.parentNode;
        }

        return null;
    }

    function pageToken() {
        var input = document.querySelector('input[name="token"]');
        if (input && input.value) {
            return input.value;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    function emit(el, name, detail) {
        if (!el || !el.dispatchEvent) {
            return;
        }
        var event;
        try {
            event = new CustomEvent(name, { bubbles: true, detail: detail || {} });
        } catch (e) {
            // 老浏览器（IE11 / 部分国产内核）没有 CustomEvent 构造器
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

    /**
     * 失败文案：422 是 ApiResponse 的 JSON envelope，直接取 message；
     * 其余（413 / 500 错误页）剥标签截断，保证不把整页 HTML 塞进列表。
     */
    function errorText(xhr) {
        if (xhr && typeof xhr.responseText === 'string' && xhr.responseText !== '') {
            try {
                var json = JSON.parse(xhr.responseText);
                if (json && json.message) {
                    return String(json.message);
                }
            } catch (e) {
                // 不是 JSON，走下面的剥标签分支
            }
            var plain = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
            if (plain) {
                return plain.slice(0, 100);
            }
        }

        return typeof window.lang === 'function' ? window.lang('upload_failed') : '上传失败';
    }

    /* ------------------------------------------------------------------
     * Transport：一次请求只送一个文件
     * ------------------------------------------------------------------ */

    function send(options, handlers) {
        var xhr = new window.XMLHttpRequest();
        var retries = 0;

        function fire(name, event) {
            if (typeof handlers[name] === 'function') {
                handlers[name](event);
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
                // 2xx 且响应体非空才视为成功：入口对 AJAX 异常可能回 200 整页提示，
                // 这里不做结构判断，交给 Renderer 决定是替换列表还是投递错误
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
     * Renderer：只认「列表 HTML」，其余响应体按错误处理
     * ------------------------------------------------------------------ */

    function listOf(container) {
        var prefix = attr(container, 'data-file-prefix', '');
        var el = prefix === '' ? null : document.getElementById(prefix + 'List');
        if (el) {
            return el;
        }

        return container.querySelector('.fileList') || container.querySelector('.file-list');
    }

    function render(container, html) {
        // 整页 HTML 说明入口走的是 DomainException 的提示页（如上传超限），不能塞进列表
        if (/<html[\s>]|<!DOCTYPE/i.test(html)) {
            var match = String(html).match(/<h2[^>]*>([\s\S]*?)<\/h2>/i);
            var text = match ? match[1].replace(/<[^>]*>/g, '').replace(/^\s+|\s+$/g, '') : '';

            return text || (typeof window.lang === 'function' ? window.lang('upload_failed') : '上传失败');
        }

        var list = listOf(container);
        if (list) {
            list.innerHTML = html;
            // 列表重建即旧提示失效（与原「错误行为列表首行、随列表重建消失」等价）
            clearError(container);
        }

        return '';
    }

    function clearError(container) {
        var tips = container.querySelectorAll('.file-error');
        for (var i = tips.length - 1; i >= 0; i--) {
            if (tips[i].parentNode) {
                tips[i].parentNode.removeChild(tips[i]);
            }
        }
    }

    /**
     * 单个文件被拒的提示。
     *
     * 挂在容器末尾（CSS `clear: both` 落到上传按钮与已传列表下方），
     * 不再插到列表首行：列表区为左侧 80px 按钮让出了 margin-left，
     * 提示行会与按钮并列显示，看不出归属哪个字段。
     */
    function showError(container, message) {
        var text = String(message || '').replace(/^\s+|\s+$/g, '');
        if (!container || text === '') {
            if (typeof window.alert === 'function') {
                window.alert(text || '上传失败');
            }
            return;
        }

        // 同一原因并发失败时只留一条，避免叠成重复行
        var tips = container.querySelectorAll('.file-error');
        for (var i = 0; i < tips.length; i++) {
            if (tips[i].textContent === text) {
                return;
            }
        }

        var item = document.createElement('p');
        item.className = 'file-error';
        item.textContent = text;
        container.appendChild(item);
    }

    function setBusy(container, busy) {
        var status = container.querySelector('.fileStatus') || container.querySelector('.file-status');
        var button = container.querySelector('.btnFile') || container.querySelector('.btn-file');
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

    function urlOf(container) {
        return window.route('file.box', {}, {
            query: {
                module: attr(container, 'data-file-module', ''),
                prefix: attr(container, 'data-file-prefix', '')
            }
        });
    }

    function formDataOf(container, item) {
        var prefix = attr(container, 'data-file-prefix', '');
        var body = new window.FormData();

        // 字段名必须与 UploadedFile::allFromGlobals($prefix . '_file') 对齐
        body.append(prefix + '_file[]', item.file, item.name);
        body.append('type', attr(container, 'data-file-type', ''));
        body.append('item_id', attr(container, 'data-file-item-id', ''));
        body.append('draft_token', attr(container, 'data-file-draft-token', ''));
        body.append('token', pageToken());
        body.append('img_width', attr(container, 'data-file-img-width', ''));
        body.append('extensions', attr(container, 'data-file-extensions', ''));
        body.append('max_size', attr(container, 'data-file-max-size', ''));

        return body;
    }

    function stateOf(container) {
        if (!container.__douFileBox) {
            container.__douFileBox = { queue: [], running: 0, xhrs: [], aborted: false };
        }

        return container.__douFileBox;
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

        // 请求结束后必须从 xhrs 里摘掉：否则长时间不刷新的页面会一直持有已完成的 XHR 与响应体
        function release() {
            for (var i = 0; i < state.xhrs.length; i++) {
                if (state.xhrs[i] === xhr) {
                    state.xhrs.splice(i, 1);
                    return;
                }
            }
        }

        xhr = send({
            url: urlOf(container),
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

    function tooLarge(container, file) {
        var maxKb = parseInt(attr(container, 'data-file-max-size', '0'), 10);
        if (!(maxKb > 0) || !file || typeof file.size !== 'number') {
            return false;
        }
        if (file.size <= maxKb * 1024) {
            return false;
        }
        var label = typeof window.lang === 'function' ? window.lang('file_out_size', '上传文件超过') : '上传文件超过';
        showError(container, label + maxKb + 'KB');

        return true;
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

            var accepted = [];
            for (var n = 0; n < files.length; n++) {
                if (!tooLarge(container, files[n])) {
                    accepted.push(files[n]);
                }
            }
            if (!accepted.length) {
                return;
            }

            var plain = [];
            for (var k = 0; k < accepted.length; k++) {
                plain.push({ file: accepted[k], name: accepted[k].name });
            }
            enqueue(container, plain);
        });

        return input;
    }

    /* ------------------------------------------------------------------
     * 命名空间 API
     * ------------------------------------------------------------------ */

    var douFileBox = {
        /**
         * 初始化 root 下的上传容器。幂等：重复调用不会重复绑定。
         * AJAX 局部刷新插入的新容器需要再调一次（或直接调 pick）。
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
                // 单图字段一次只能选一个：服务端写入前会清空该字段既有文件，重复上传即替换
                inputOf(container).multiple = attr(container, 'data-file-type', '') !== 'main';
            }
        },

        /**
         * 打开文件选择器。
         *
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
         * 删除指定编号，并用服务端返回的列表 HTML 回填。
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
            body.append('prefix', attr(container, 'data-file-prefix', ''));
            body.append('token', pageToken());

            send({
                url: window.route('file.destroy'),
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
         * 中止该容器所有在途请求并清空队列。
         *
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

    window.douFileBox = douFileBox;

    /* ------------------------------------------------------------------
     * 全局事件委托：只用一次 document 监听，覆盖后续动态插入的容器
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (event) {
        var pick = closest(event.target, '[data-file-pick]');
        if (pick) {
            var pickBox = closest(pick, '[data-file-type]');
            if (pickBox) {
                event.preventDefault();
                douFileBox.pick(pickBox);
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
        if (!number) {
            return;
        }
        douFileBox.destroy(container, number);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            douFileBox.init(document);
        });
    } else {
        douFileBox.init(document);
    }
})(window, document);
