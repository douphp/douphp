(function () {
  "use strict";

  var form = document.querySelector("[data-aigc-form]");
  var resultBox = document.getElementById("aigcResult");
  var pollTimer = null;

  function text(tag, value, className) {
    var el = document.createElement(tag);
    el.textContent = value == null ? "" : String(value);
    if (className) el.className = className;
    return el;
  }

  function fileUrl(base, taskId, index, mode) {
    return base + "?id=" + encodeURIComponent(taskId) + "&index=" + encodeURIComponent(index) + "&mode=" + (mode || "inline");
  }

  function renderFiles(data, type) {
    var box = document.createElement("div");
    box.className = "aigc-result-files";
    var files = data.result && data.result.files ? data.result.files : [];
    if (!files.length) {
      box.appendChild(text("p", "生成完成，但没有返回文件。", "cue"));
      return box;
    }
    files.forEach(function (file, index) {
      var name = file.name || ("文件 " + (index + 1));
      if (type === "image") {
        var img = document.createElement("img");
        img.src = fileUrl(data.file_url, data.task_id, index, "inline");
        img.alt = name;
        img.className = "aigc-image";
        box.appendChild(img);
      } else if (type === "video") {
        var video = document.createElement("video");
        video.src = fileUrl(data.file_url, data.task_id, index, "inline");
        video.controls = true;
        video.className = "aigc-video";
        box.appendChild(video);
      } else if (type === "audio") {
        var audio = document.createElement("audio");
        audio.src = fileUrl(data.file_url, data.task_id, index, "inline");
        audio.controls = true;
        audio.className = "aigc-audio";
        box.appendChild(audio);
      }
      var link = document.createElement("a");
      link.href = fileUrl(data.file_url, data.task_id, index, "download");
      link.textContent = "下载 " + name;
      link.className = "aigc-download";
      box.appendChild(link);
    });
    return box;
  }

  function render(data) {
    if (!resultBox) return;
    resultBox.innerHTML = "";
    var type = form ? form.getAttribute("data-type") : "";
    var status = data.status || "";

    if (status === "succeeded") {
      resultBox.appendChild(text("div", "生成完成", "aigc-success"));
      resultBox.appendChild(renderFiles(data, type));
      return;
    }
    if (status === "failed" || status === "timeout") {
      resultBox.appendChild(text("div", data.error || "生成失败", "cue-red"));
      return;
    }
    resultBox.appendChild(text("div", "生成中，请稍候…", "aigc-loading"));
  }

  function poll(pollUrl, fileUrl, taskId) {
    var id = parseInt(taskId, 10) || 0;
    if (!id) return;

    var attempts = 0;
    var tick = function () {
      attempts++;
      if (attempts > 300) {
        render({ status: "failed", error: "生成超时，请稍后重试" });
        return;
      }
      fetch(route("aigc.poll", {}, { query: { id: id } }))
        .then(function (res) { return res.json(); })
        .then(function (payload) {
          var data = payload && payload.data ? payload.data : payload;
          if (!data || !data.success) {
            render({ status: "failed", error: (data && data.error) || "轮询失败" });
            return;
          }
          data.file_url = fileUrl;
          render(data);
          if (data.status === "succeeded" || data.status === "failed" || data.status === "timeout") {
            return;
          }
          pollTimer = setTimeout(tick, 2000);
        })
        .catch(function () {
          pollTimer = setTimeout(tick, 3000);
        });
    };
    tick();
  }

  if (form && typeof route === "function") {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (pollTimer) clearTimeout(pollTimer);
      var submitUrl = form.getAttribute("data-submit-url");
      var pollUrl = form.getAttribute("data-poll-url");
      var fileUrl = form.getAttribute("data-file-url");
      var data = new FormData(form);
      data.append("slug", form.getAttribute("data-slug") || "");

      if (resultBox) {
        resultBox.innerHTML = '<div class="aigc-loading">正在提交…</div>';
      }

      fetch(submitUrl, { method: "POST", body: data })
        .then(function (res) { return res.json(); })
        .then(function (payload) {
          var data = payload && payload.data ? payload.data : payload;
          if (!data || !data.success) {
            render({ status: "failed", error: (data && data.error) || "提交失败" });
            return;
          }
          if (data.status === "succeeded") {
            data.file_url = fileUrl;
            render(data);
          } else {
            poll(pollUrl, fileUrl, data.task_id);
          }
        })
        .catch(function () {
          render({ status: "failed", error: "提交失败，请检查网络" });
        });
    });
  }

  var deleteButtons = document.querySelectorAll("[data-del-url]");
  for (var i = 0; i < deleteButtons.length; i++) {
    deleteButtons[i].addEventListener("click", function () {
      var btn = this;
      var id = btn.getAttribute("data-task-id");
      var url = btn.getAttribute("data-del-url");
      if (!confirm("确定删除该作品？")) return;
      var body = new URLSearchParams();
      body.append("id", id);
      fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString()
      }).then(function (res) { return res.json(); })
        .then(function (payload) {
          if (payload && payload.code === "OK") {
            var card = btn.closest(".aigc-work");
            if (card) card.parentNode.removeChild(card);
          } else {
            alert((payload && payload.message) || "删除失败");
          }
        })
        .catch(function () { alert("删除失败"); });
    });
  }
})();
