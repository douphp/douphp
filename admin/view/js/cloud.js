(function ($) {
  var meta = window.__douInstall || {};
  var installId = meta.install_id || '';
  var token = meta.token || '';
  var steps = meta.steps || [];
  var langPack = meta.lang || {};
  var stepUrl = route('admin.cloud.install_step');
  var progressUrl = route('admin.cloud.install_progress');

  var $log = $('#dou-install-log');
  if (!$log.length) { return; }

  var currentStep = steps[0];
  var seededLines = {};
  var activeSpinner = null;
  var activeLine = null;          // 当前进行中行（同一步骤循环时继续计时不重置）
  var activeProgress = null;      // 进行中行进度 span（旋转圈前：N% 或「已用时 N 秒」）
  var progressHasPercent = false; // 本行已显示真实百分比（秒表不再覆写）
  var elapsedTimer = null;        // 秒表刷新 interval
  var elapsedStart = 0;           // 当前行计时起点（毫秒时间戳）
  var dlPollTimer = null;         // 下载进度轮询 interval
  var dlPollFails = 0;            // 下载进度轮询连续失败次数
  var stepOrder = ['preflight', 'download', 'unzip', 'snapshot', 'apply', 'finalize'];

  function escapeText(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function appendLog(text, cls) {
    var $p = $('<p></p>');
    if (cls) { $p.addClass(cls); }
    $p.text(String(text == null ? '' : text));
    $log.append($p);
    return $p;
  }

  function appendLogHtml(html, cls) {
    var $p = $('<p></p>');
    if (cls) { $p.addClass(cls); }
    $p.html(html);
    $log.append($p);
    return $p;
  }

  function pushLogs(logs, level) {
    if (!logs || !logs.length) { return; }
    for (var i = 0; i < logs.length; i++) {
      var line = String(logs[i] == null ? '' : logs[i]);
      if (seededLines[line]) {
        continue;
      }
      appendLog(line, level === 'error' ? 'error' : '');
    }
  }

  function seedLine(text) {
    if (!text) { return null; }
    if (seededLines[text]) { return seededLines[text]; }
    var $p = appendLog(text);
    seededLines[text] = $p;
    return $p;
  }

  function predictStepText(step) {
    if (step === 'download') {
      var url = meta.download_url ? String(meta.download_url) : '';
      var mode = meta.mode ? String(meta.mode) : '';
      if (url === '' || mode === 'local') { return ''; }
      return (langPack.cloud_down_ing_0 || '') + url + (langPack.cloud_down_ing_1 || '');
    }
    if (step === 'unzip') {
      return langPack.cloud_unzip_ing || '';
    }
    if (step === 'snapshot') {
      return langPack.cloud_snapshot_ing || '';
    }
    if (step === 'apply') {
      var prefix = langPack.cloud_install_ing || '';
      if (!prefix) { return ''; }
      var typeLabel = meta.type_label ? String(meta.type_label) : '';
      return prefix + typeLabel + '…';
    }
    return '';
  }

  function stopElapsedTimer() {
    if (elapsedTimer) {
      clearInterval(elapsedTimer);
      elapsedTimer = null;
    }
  }

  function formatElapsed() {
    var sec = Math.floor((Date.now() - elapsedStart) / 1000);
    // 不足 1 秒不显示秒表，避免快速完成的步骤留下「已用时 0 秒」
    if (sec < 1) { return ''; }
    // 语言串沿用项目 d% 占位约定
    return String(langPack.cloud_step_elapsed || '已用时 d% 秒').replace('d%', String(sec));
  }

  function renderElapsed() {
    if (!activeProgress || progressHasPercent) { return; }
    var text = formatElapsed();
    activeProgress.text(text === '' ? '' : ' ' + text);
  }

  function setActivePercent(pct) {
    if (!activeProgress) { return; }
    var n = parseInt(pct, 10);
    if (isNaN(n)) { return; }
    if (n < 0) { n = 0; }
    if (n > 100) { n = 100; }
    progressHasPercent = true; // 百分比保持到下次更新，期间秒表不覆写
    activeProgress.text(' ' + n + '%');
  }

  function clearActiveSpinner() {
    // 停表定格：旧行保留已显示的时间 / 百分比文本
    stopElapsedTimer();
    if (activeSpinner) {
      activeSpinner.remove();
      activeSpinner = null;
    }
    activeLine = null;
    activeProgress = null;
    progressHasPercent = false;
  }

  function setActiveSpinner($line) {
    // 目标行未变（快照 / 下载分片循环）：同一行继续计时不重置
    if ($line && $line.length && activeLine && $line.get(0) === activeLine.get(0)) {
      return;
    }
    clearActiveSpinner();
    if (!$line || !$line.length) { return; }
    activeLine = $line;
    activeProgress = $('<span class="install-step-progress"></span>');
    activeSpinner = $('<i class="bi bi-arrow-repeat install-step-spinner" aria-hidden="true"></i>');
    $line.append(activeProgress).append(' ').append(activeSpinner);
    elapsedStart = Date.now();
    renderElapsed();
    elapsedTimer = setInterval(renderElapsed, 1000);
  }

  function attachStepSpinner(step) {
    var idx = -1;
    for (var i = 0; i < stepOrder.length; i++) {
      if (stepOrder[i] === step) { idx = i; break; }
    }
    if (idx < 0) { idx = 0; }
    for (var j = idx; j < stepOrder.length; j++) {
      var text = predictStepText(stepOrder[j]);
      if (text) {
        var $line = seedLine(text);
        if ($line) { setActiveSpinner($line); }
        return;
      }
    }
    clearActiveSpinner();
  }

  function seedInitialLogs() {
    attachStepSpinner('download');
  }

  function buildRedirectLinkHtml(url, label, target) {
    var safeUrl = escapeText(url);
    var safeLabel = escapeText(label || langPack.cloud_account || url);
    var attrs = 'class="btn" href="' + safeUrl + '"';
    if (target === '_blank') {
      attrs += ' target="_blank" rel="noopener"';
    }
    return '<a ' + attrs + '>' + safeLabel + '</a>';
  }

  function showFailure(data) {
    var msg = data && data.error ? String(data.error) : '';
    var redirectUrl = data && data.redirect_url ? String(data.redirect_url) : '';
    if (msg !== '' || redirectUrl !== '') {
      var html = escapeText(msg);
      if (redirectUrl !== '') {
        if (html !== '') { html += ' '; }
        html += buildRedirectLinkHtml(
          redirectUrl,
          data.redirect_label,
          data.redirect_target
        );
      }
      appendLogHtml(html, 'error');
    }
    var m = meta.mode ? String(meta.mode) : '';
    var useUpdateHome = (m === 'update' || m === 'patch');
    var backHref = useUpdateHome ? route('admin.cloud.update') : route('admin.index');
    var backLabel = useUpdateHome
      ? (langPack.cloud_update_home || '返回更新主页')
      : (langPack.cloud_admin_home || '返回管理中心');
    appendLogHtml('<a class="btn-secondary" href="' + backHref + '">' + escapeText(backLabel) + '</a>');
  }

  function showFinishActions(result) {
    var back = (result && result.btn_back_html) ? String(result.btn_back_html) : '';
    var action = (result && result.btn_action_html) ? String(result.btn_action_html) : '';
    var note = (result && result.finish_note_html) ? String(result.finish_note_html) : '';
    var m = meta.mode ? String(meta.mode) : '';
    var isUpdate = (m === 'update' || m === 'patch');
    // 更新/补丁：返回更新主页 → 启用模板/插件 → 查看还原点（同一行，还原点最后）
    // 全新安装：启用在前、返回在后（维持原顺序）
    var html = isUpdate ? (back + action + note) : (action + back);
    if (html !== '') { appendLogHtml(html); }
  }

  function stopDownloadPoll() {
    if (dlPollTimer) {
      clearInterval(dlPollTimer);
      dlPollTimer = null;
    }
  }

  function startDownloadPoll() {
    stopDownloadPoll();
    dlPollFails = 0;
    dlPollTimer = setInterval(function () {
      $.ajax({
        url: progressUrl,
        type: 'GET',
        dataType: 'json',
        data: { install_id: installId, token: token }
      }).then(function (data) {
        if (data && data.ok && data.percent !== null && data.percent !== undefined) {
          dlPollFails = 0;
          setActivePercent(data.percent);
        }
      }, function () {
        // 旧代码窗口 / 网络异常：连续 3 次失败即静默停用，回落秒表
        dlPollFails++;
        if (dlPollFails >= 3) {
          stopDownloadPoll();
        }
      });
    }, 1200);
  }

  function buildNextUrl(next) {
    if (!next) { return null; }
    var query = {};
    var nextType = next.type ? String(next.type) : '';
    if (nextType !== '' && nextType !== 'system') {
      query.type = nextType;
    }
    var cid = next.cloud_id || '';
    if (next.batch && next.batch.length) {
      cid = [cid].concat(next.batch).join('|');
    }
    query.cloud_id = cid;
    if (next.mode) { query.mode = String(next.mode); }
    if (next.version) { query.version = String(next.version); }
    if (next.theme_id) { query.theme_id = String(next.theme_id); }
    return route('admin.cloud.install', {}, { query: query });
  }

  function runStep(step) {
    currentStep = step;
    attachStepSpinner(step);
    // 下载步骤：并行轮询真实百分比（需有 download_url 且非 local 模式）
    if (step === 'download'
      && meta.download_url && String(meta.download_url) !== ''
      && String(meta.mode || '') !== 'local') {
      startDownloadPoll();
    }
    return $.ajax({
      url: stepUrl,
      type: 'POST',
      dataType: 'json',
      data: { install_id: installId, step: step, token: token }
    }).then(function (data) {
      // 本步请求已返回：停止下载轮询；下载成功时先将该行定格 100% 再进入下一步
      stopDownloadPoll();
      if (data && typeof data === 'object' && step === 'download' && data.ok) {
        setActivePercent(100);
      }
      if (!data || typeof data !== 'object') {
        clearActiveSpinner();
        showFailure({
          error: langPack.cloud_install_step_failed_generic
            || langPack.cloud_install_request_failed
            || ''
        });
        return $.Deferred().reject().promise();
      }
      if (data.mode !== undefined && data.mode !== null && String(data.mode) !== '') {
        meta.mode = String(data.mode);
      }
      pushLogs(data.logs || [], data.ok ? 'info' : 'error');
      // 快照等步骤携带真实进度（0-100 整数，完成态为 100）时更新进行中行
      if (typeof data.progress === 'number') {
        setActivePercent(data.progress);
      }
      if (!data.ok) {
        clearActiveSpinner();
        if (!data.error || String(data.error) === '') {
          data.error = langPack.cloud_install_step_failed_generic || '';
        }
        showFailure(data);
        return $.Deferred().reject().promise();
      }
      if (data.finished) {
        clearActiveSpinner();
        // 升级完成即静默强刷角标：批量时当前页角标随之递减，落库后下一模块页 / 返回更新主页首屏即最新
        if (window.DouUpdateBadge) { window.DouUpdateBadge.refresh(true); }
        if (data.result && data.result.next_install) {
          var url = buildNextUrl(data.result.next_install);
          if (url) {
            var nextId = data.result.next_install.cloud_id || '';
            var nextType = data.result.next_install.type || '';
            var nextMode = data.result.next_install.mode || '';
            var nextLine;
            if (nextType === 'theme') {
              nextLine = (langPack.cloud_install_theme || '即将安装模板') + '：' + nextId;
            } else {
              var key = nextMode === 'update' ? 'cloud_update_next' : 'cloud_install_next';
              var prefix = langPack[key] || (nextMode === 'update' ? '即将更新下一个模块' : '即将安装下一个模块');
              nextLine = prefix + '：' + nextId + ' >>>>>>>>>>';
            }
            appendLog(nextLine);
            setTimeout(function () { window.location.href = url; }, 800);
            return;
          }
        }
        showFinishActions(data.result || {});
        return;
      }
      var nextStep = data.next;
      if (nextStep !== undefined && nextStep !== null && String(nextStep) !== '') {
        return runStep(String(nextStep));
      }
      clearActiveSpinner();
    }, function (jqXHR, textStatus) {
      stopDownloadPoll();
      clearActiveSpinner();
      var parsed = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
      if (parsed && typeof parsed === 'object' && parsed.error) {
        showFailure(parsed);
        return $.Deferred().reject().promise();
      }
      var netMsg = langPack.cloud_install_request_failed || '';
      if (jqXHR && jqXHR.status) {
        netMsg = netMsg !== '' ? netMsg + ' (HTTP ' + jqXHR.status + ')' : 'HTTP ' + jqXHR.status;
      }
      if (textStatus === 'timeout') {
        netMsg = langPack.cloud_install_request_failed || textStatus;
      }
      showFailure({ error: netMsg });
      return $.Deferred().reject().promise();
    });
  }

  $(function () {
    if (steps && steps.length) {
      seedInitialLogs();
      runStep(steps[0]);
    }
  });
})(jQuery);
