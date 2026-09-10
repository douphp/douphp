(function ($) {
  var meta = window.__douInstall || {};
  var installId = meta.install_id || '';
  var token = meta.token || '';
  var steps = meta.steps || [];
  var langPack = meta.lang || {};
  var stepUrl = route('admin.cloud.install_step');

  var $log = $('#dou-install-log');
  if (!$log.length) { return; }

  var currentStep = steps[0];
  var seededLines = {};
  var activeSpinner = null;
  var stepOrder = ['preflight', 'download', 'unzip', 'apply', 'finalize'];

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
    if (step === 'apply') {
      var prefix = langPack.cloud_install_ing || '';
      if (!prefix) { return ''; }
      var typeLabel = meta.type_label ? String(meta.type_label) : '';
      return prefix + typeLabel + '…';
    }
    return '';
  }

  function clearActiveSpinner() {
    if (activeSpinner) {
      activeSpinner.remove();
      activeSpinner = null;
    }
  }

  function setActiveSpinner($line) {
    clearActiveSpinner();
    if (!$line || !$line.length) { return; }
    activeSpinner = $('<i class="bi bi-arrow-repeat install-step-spinner" aria-hidden="true"></i>');
    $line.append(' ').append(activeSpinner);
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
    var html = '';
    if (result && result.btn_action_html) { html += result.btn_action_html; }
    if (result && result.btn_back_html) { html += result.btn_back_html; }
    if (html !== '') {
      appendLogHtml(html);
    }
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
    return $.ajax({
      url: stepUrl,
      type: 'POST',
      dataType: 'json',
      data: { install_id: installId, step: step, token: token }
    }).then(function (data) {
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
      clearActiveSpinner();
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
