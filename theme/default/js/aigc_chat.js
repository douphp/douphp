// Vue3 chat 应用：AI 助手对话（SSE 流式 + 会话管理）
const { createApp, ref, reactive, computed, onMounted, nextTick } = Vue;

// 界面文案注入（模板 #aigcChatApp data-i18n；缺省中文兜底）
let CHAT_I18N = {};
try {
  const i18nRoot = document.querySelector("#aigcChatApp");
  if (i18nRoot && i18nRoot.dataset.i18n) {
    CHAT_I18N = JSON.parse(i18nRoot.dataset.i18n) || {};
  }
} catch (e) {
  CHAT_I18N = {};
}
const t = (key, fallback) => {
  if (CHAT_I18N[key] !== undefined && CHAT_I18N[key] !== "") {
    return CHAT_I18N[key];
  }
  return fallback !== undefined ? fallback : key;
};
const locale = CHAT_I18N.locale || "zh-CN";

createApp({
  setup() {
    // 响应式状态
    const messages = ref([]);
    const input_message = ref("");
    const is_processing = ref(false);
    const show_scroll_button = ref(false);
    const chat_messages = ref(null);
    const available_models = ref({});
    const selected_model = ref("");
    const current_model_name = ref("AI");
    const suggested_questions = ref([]);

    // 应用上下文（由模板 data-* 注入）
    const app_id = ref(0);
    const app_slug = ref("");

    // 游客模式（模板 data-guest 注入）：会话凭 localStorage 的 session_sn 持有
    const is_guest = ref(false);

    // 会话状态
    const sessions = ref([]);
    const current_session = ref(null); // {session_sn, status, ...}
    const last_message_id = ref(0);

    // 会话时间分组标签（由模板 data-* 注入，缺省中文兜底）
    const group_labels = reactive({
      today: t("today", "今天"),
      week: t("week", "7天内"),
      earlier: t("earlier", "更早"),
    });

    // 按创建时间分组：今天 / 7天内 / 更早
    const session_groups = computed(() => {
      const groups = [
        { label: group_labels.today, items: [] },
        { label: group_labels.week, items: [] },
        { label: group_labels.earlier, items: [] },
      ];
      const now = new Date();
      const today_start = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
      const week_start = today_start - 6 * 86400000;
      sessions.value.forEach((session) => {
        const raw = session.updated_at || session.created_at || "";
        const time = raw ? new Date(String(raw).replace(/-/g, "/")).getTime() : 0;
        if (time >= today_start) {
          groups[0].items.push(session);
        } else if (time >= week_start) {
          groups[1].items.push(session);
        } else {
          groups[2].items.push(session);
        }
      });
      return groups.filter((group) => group.items.length > 0);
    });

    // 内部状态
    const current_reader = ref(null);
    const current_thinking_interval = ref(null);
    const current_timeout_warning = ref(null);
    const is_program_scrolling = ref(false);
    const current_request_id = ref(0);

    // 长会话分页 / 待定占位气泡
    const has_more = ref(false);
    const first_message_id = ref(0);
    const pending_message = ref(null);
    let error_label = t("error", "生成失败，请稍后重试");

    if (typeof marked !== "undefined") {
      marked.setOptions({ breaks: true, gfm: true });
    }

    const escape_html = (text) => {
      const div = document.createElement("div");
      div.textContent = text == null ? "" : String(text);
      return div.innerHTML;
    };

    // AI markdown 渲染：marked 不过滤内嵌 HTML，统一经 DOMPurify 消毒后再 v-html 注入
    const render_markdown = (content) => {
      const raw = typeof marked !== "undefined" ? marked.parse(content || "") : escape_html(content);
      return typeof DOMPurify !== "undefined" ? DOMPurify.sanitize(raw) : raw;
    };

    const render_content = (role, content) => {
      if (role === "ai") {
        return render_markdown(content);
      }
      return escape_html(content);
    };

    // 初始化
    onMounted(async () => {
      const root = document.querySelector("#aigcChatApp");
      let preferred_session_sn = "";
      if (root) {
        app_id.value = parseInt(root.dataset.appId || "0");
        app_slug.value = root.dataset.slug || "";
        is_guest.value = root.dataset.guest === "1";
        preferred_session_sn = root.dataset.sessionSn || "";
        if (root.dataset.labelError) error_label = root.dataset.labelError;
        if (root.dataset.groupToday) group_labels.today = root.dataset.groupToday;
        if (root.dataset.groupWeek) group_labels.week = root.dataset.groupWeek;
        if (root.dataset.groupEarlier) group_labels.earlier = root.dataset.groupEarlier;
        try {
          const models = JSON.parse(root.dataset.models || "{}");
          available_models.value = models;
          const first_model = Object.keys(models)[0];
          if (first_model) {
            selected_model.value = first_model;
            current_model_name.value = models[first_model];
          }
        } catch (e) {
          console.error("解析模型数据失败:", e);
        }
        try {
          const questions = JSON.parse(root.dataset.suggestions || "[]");
          suggested_questions.value = Array.isArray(questions) ? questions : [];
        } catch (e) {
          suggested_questions.value = [];
        }
      }

      if (is_guest.value) {
        // 游客：服务端会话列表恒为空，改凭本地 sn 恢复最近会话
        await restore_guest_session();
      } else {
        await load_sessions();

        // 从历史会话页进入时优先打开指定会话，否则打开最近一条
        if (preferred_session_sn && sessions.value.some((session) => session.session_sn === preferred_session_sn)) {
          await switch_session(preferred_session_sn);
        } else if (sessions.value.length > 0) {
          await switch_session(sessions.value[0].session_sn);
        }
      }
    });

    // 模型切换
    const on_model_change = () => {
      if (available_models.value[selected_model.value]) {
        current_model_name.value = available_models.value[selected_model.value];
        show_toast(t("model_switched", "已切换到") + " " + current_model_name.value, "info", 2000);
      }
    };

    // 滚动控制
    const is_at_bottom = () => {
      const container = chat_messages.value;
      if (!container) return true;
      return container.scrollHeight - container.scrollTop - container.clientHeight < 50;
    };

    const smart_scroll_to_bottom = () => {
      const container = chat_messages.value;
      if (!container) return;
      if (is_at_bottom()) {
        is_program_scrolling.value = true;
        container.scrollTop = container.scrollHeight;
        setTimeout(() => {
          is_program_scrolling.value = false;
        }, 50);
      }
    };

    const scroll_to_bottom = () => {
      const container = chat_messages.value;
      if (container) {
        container.scrollTop = container.scrollHeight;
      }
      show_scroll_button.value = false;
    };

    // Toast 提示
    const show_toast = (message, type = "info", duration = 4000) => {
      const toast = document.createElement("div");
      toast.className = `aigc-chat-toast ${type}`;
      toast.innerHTML = `<div class="toast-content">${escape_html(message)}</div><span class="close-btn">×</span>`;
      toast.querySelector(".close-btn").addEventListener("click", () => close_toast(toast));
      document.body.appendChild(toast);
      if (duration > 0) {
        setTimeout(() => close_toast(toast), duration);
      }
      return toast;
    };

    const close_toast = (toast) => {
      toast.classList.add("closing");
      setTimeout(() => {
        if (toast.parentElement) {
          toast.parentElement.removeChild(toast);
        }
      }, 300);
    };

    // 添加消息（content 为已渲染 HTML，raw 为纯文本原文）
    const add_message = (content, type, is_typing = false, raw = "") => {
      const msg = reactive({
        id: 0,
        role: type === "user" ? "user" : "ai",
        raw,
        content,
        type,
        is_typing,
        feedback: 0,
        sources: [],
      });
      messages.value.push(msg);
      nextTick(() => smart_scroll_to_bottom());
      return msg;
    };

    const formatDate = (dateString) => {
      if (!dateString) return "";
      const date = new Date(dateString.replace(/-/g, "/"));
      const diffDays = Math.floor((new Date() - date) / 86400000);
      if (diffDays === 0) {
        return date.toLocaleTimeString(locale, { hour: "2-digit", minute: "2-digit" });
      } else if (diffDays === 1) {
        return t("yesterday", "昨天");
      } else if (diffDays < 7) {
        return `${diffDays}${t("days_ago", "天前")}`;
      }
      return date.toLocaleDateString(locale, { month: "2-digit", day: "2-digit" });
    };

    // ------------------------------------------------------------------
    // 会话管理
    // ------------------------------------------------------------------

    // 游客本地会话引用键（按应用隔离）
    const guest_sn_key = () => `aigc_guest_sn_${app_id.value}`;

    const save_guest_sn = (sn) => {
      try {
        localStorage.setItem(guest_sn_key(), sn);
      } catch (e) {
        // 隐私模式等 localStorage 不可用场景，静默
      }
    };

    const clear_guest_sn = () => {
      try {
        localStorage.removeItem(guest_sn_key());
      } catch (e) {
        // 同上
      }
    };

    // 游客会话恢复：读本地 sn 并验有效（失效则静默丢弃，发送首条消息时自然新建）
    const restore_guest_session = async () => {
      let sn = "";
      try {
        sn = localStorage.getItem(guest_sn_key()) || "";
      } catch (e) {
        return;
      }
      if (!sn) return;

      try {
        const response = await fetch(route("aigc.messages", {}, { query: { session_sn: sn } }));
        const result = await response.json();
        if (response.ok && result && result.code === "OK") {
          messages.value = [];
          last_message_id.value = 0;
          first_message_id.value = 0;
          has_more.value = !!(result.data && result.data.has_more);
          current_session.value = result.data.session;
          (result.data.messages || []).forEach((record) => {
            append_record(record);
          });
          nextTick(() => scroll_to_bottom());
        } else {
          clear_guest_sn();
        }
      } catch (error) {
        console.error("恢复访客会话错误:", error);
      }
    };

    // 登录后认领游客会话：本地存在游客 sn 时静默提交，成功则刷新列表
    const claim_guest_session = async () => {
      let sn = "";
      try {
        sn = localStorage.getItem(guest_sn_key()) || "";
      } catch (e) {
        return;
      }
      if (!sn) return;

      try {
        const response = await fetch(route("aigc.claim"), {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: `session_sn=${encodeURIComponent(sn)}`,
        });
        const result = await response.json();
        if (response.ok && result && result.code === "OK") {
          clear_guest_sn();
          if (result.data && result.data.claimed) {
            await load_sessions();
          }
        }
      } catch (error) {
        console.error("认领访客会话错误:", error);
      }
    };

    const load_sessions = async () => {
      try {
        const response = await fetch(route('aigc.sessions', {}, { query: { slug: app_slug.value } }));
        const result = await response.json();
        if (response.ok && result && result.code === "OK") {
          sessions.value = result.data && Array.isArray(result.data.sessions) ? result.data.sessions : [];
        }
      } catch (error) {
        console.error("加载会话列表错误:", error);
      }
    };

    const new_session = () => {
      messages.value = [];
      current_session.value = null;
      last_message_id.value = 0;
      first_message_id.value = 0;
      has_more.value = false;
      if (is_guest.value) {
        // 避免旧 sn 残留导致下次进入回到已丢弃的会话
        clear_guest_sn();
      }
      show_toast(t("new_session_ready", "新会话已就绪，请输入您的第一个问题"), "info", 2000);
    };

    const create_session = async (first_message) => {
      let title = first_message.length > 20 ? first_message.substring(0, 20) + "..." : first_message;
      let body = `slug=${encodeURIComponent(app_slug.value)}&title=${encodeURIComponent(title)}`;
      if (selected_model.value) {
        body += `&model_id=${selected_model.value}`;
      }

      try {
        const response = await fetch(route('aigc.new_session'), {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: body,
        });
        const result = await response.json();
        if (response.ok && result && result.code === "OK" && result.data && result.data.session) {
          current_session.value = result.data.session;
          sessions.value.unshift(result.data.session);
          if (is_guest.value) {
            save_guest_sn(result.data.session.session_sn);
          }
          return true;
        }
        show_toast((result && result.message) || t("create_session_failed", "创建会话失败"), "error", 4000);
        return false;
      } catch (error) {
        console.error("创建会话错误:", error);
        return false;
      }
    };

    const switch_session = async (session_sn) => {
      messages.value = [];
      last_message_id.value = 0;
      first_message_id.value = 0;
      has_more.value = false;

      try {
        const response = await fetch(route('aigc.messages', {}, { query: { session_sn: session_sn } }));
        const result = await response.json();
        if (response.ok && result && result.code === "OK") {
          current_session.value = result.data.session;
          has_more.value = !!(result.data && result.data.has_more);
          (result.data.messages || []).forEach((record) => {
            append_record(record);
          });
          nextTick(() => scroll_to_bottom());
        } else {
          show_toast((result && result.message) || t("load_session_failed", "加载会话记录失败"), "error", 3000);
        }
      } catch (error) {
        console.error("切换会话错误:", error);
      }
    };

    const build_message = (record) => {
      let content = "";
      let type = "ai";
      if (record.role === "user") {
        type = "user";
        content = escape_html(record.content);
      } else if (record.role === "system") {
        type = "system";
        content = escape_html(record.content);
      } else if (record.has_error && !record.content) {
        content = escape_html(error_label);
      } else {
        content = render_content("ai", record.content);
      }
      let sources = [];
      if (record.metadata && record.metadata.knowledge && Array.isArray(record.metadata.knowledge.hits)) {
        sources = record.metadata.knowledge.hits;
      }
      return {
        id: record.id || 0,
        role: record.role || "ai",
        raw: record.content == null ? "" : String(record.content),
        content,
        type,
        is_typing: false,
        feedback: parseInt(record.feedback || 0, 10),
        sources,
      };
    };

    const append_record = (record) => {
      if (record.id && record.id > last_message_id.value) {
        last_message_id.value = record.id;
      }
      if (record.id && (!first_message_id.value || record.id < first_message_id.value)) {
        first_message_id.value = record.id;
      }
      messages.value.push(reactive(build_message(record)));
    };

    // 前置插入更早的历史消息并保持滚动位置
    const prepend_records = (records) => {
      const built = records.map((record) => reactive(build_message(record)));
      messages.value = built.concat(messages.value);
      if (records.length > 0) {
        const firstId = records[0].id || 0;
        if (firstId && (!first_message_id.value || firstId < first_message_id.value)) {
          first_message_id.value = firstId;
        }
      }
    };

    const load_earlier = async () => {
      if (!current_session.value || !first_message_id.value || is_processing.value) {
        return;
      }
      const container = chat_messages.value;
      const prev_height = container ? container.scrollHeight : 0;
      const prev_scroll_top = container ? container.scrollTop : 0;
      try {
        const response = await fetch(route('aigc.messages', {}, {
          query: {
            session_sn: current_session.value.session_sn,
            before_id: first_message_id.value,
          },
        }));
        const result = await response.json();
        if (!response.ok || !result || result.code !== "OK") {
          show_toast((result && result.message) || t("load_earlier_failed", "加载更早消息失败"), "error", 3000);
          return;
        }
        const records = result.data.messages || [];
        prepend_records(records);
        has_more.value = !!(result.data && result.data.has_more);
        nextTick(() => {
          if (container) {
            container.scrollTop = container.scrollHeight - prev_height + prev_scroll_top;
          }
        });
      } catch (error) {
        console.error("加载更早消息错误:", error);
        show_toast(t("load_earlier_failed", "加载更早消息失败"), "error", 3000);
      }
    };

    // ------------------------------------------------------------------
    // 发送消息
    // ------------------------------------------------------------------

    const use_suggestion = (question) => {
      input_message.value = String(question || "");
      nextTick(() => {
        const textarea = document.querySelector("#aigcChatApp .aigc-chat-input");
        if (textarea) textarea.focus();
      });
    };

    const copy_message = async (msg) => {
      const text = (msg && msg.raw) ? String(msg.raw) : "";
      if (!text) return;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          const area = document.createElement("textarea");
          area.value = text;
          area.style.position = "fixed";
          area.style.opacity = "0";
          document.body.appendChild(area);
          area.select();
          document.execCommand("copy");
          document.body.removeChild(area);
        }
        show_toast(t("copied", "已复制"), "info", 1500);
      } catch (e) {
        show_toast(t("copy_failed", "复制失败"), "error", 2000);
      }
    };

    const submit_feedback = async (msg, value) => {
      if (!msg || !msg.id || !current_session.value) return;
      if (msg.feedback === value) return;
      try {
        const body = new URLSearchParams();
        body.append("session_sn", current_session.value.session_sn);
        body.append("message_id", msg.id);
        body.append("value", value);
        const response = await fetch(route("aigc.feedback"), {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: body.toString(),
        });
        const result = await response.json();
        if (response.ok && result && result.code === "OK") {
          msg.feedback = value;
          show_toast(t("feedback_thanks", "感谢反馈"), "info", 1500);
        } else {
          show_toast((result && result.message) || t("feedback_failed", "反馈失败"), "error", 2000);
        }
      } catch (e) {
        show_toast(t("feedback_failed", "反馈失败"), "error", 2000);
      }
    };

    const begin_request = () => {
      current_request_id.value++;
      const request_id = current_request_id.value;
      is_processing.value = true;
      const thinking_msg = add_message(t("thinking_connecting", "正在连接 AI..."), "ai", true);
      pending_message.value = thinking_msg;
      let dots = 0;
      const thinking_interval = setInterval(() => {
        if (request_id !== current_request_id.value) {
          clearInterval(thinking_interval);
          return;
        }
        dots = (dots + 1) % 4;
        if (thinking_msg && (thinking_msg.content.includes(t("thinking_connecting", "正在连接 AI")) || thinking_msg.content.includes(t("thinking_thinking", "AI 正在思考")))) {
          const base_text = thinking_msg.content.includes(t("thinking_connecting", "正在连接 AI"))
            ? t("thinking_connecting", "正在连接 AI")
            : t("thinking_thinking", "AI 正在思考");
          thinking_msg.content = base_text + ".".repeat(dots);
        }
      }, 500);
      current_thinking_interval.value = thinking_interval;

      return { request_id, thinking_msg, thinking_interval };
    };

    const regenerate_message = async (msg) => {
      if (is_processing.value) {
        show_toast(t("wait_processing", "请等待当前消息处理完成"), "warning", 3000);
        return;
      }
      const index = messages.value.indexOf(msg);
      if (index < 0) return;
      let prompt = "";
      for (let i = index - 1; i >= 0; i--) {
        if (messages.value[i].role === "user") {
          prompt = messages.value[i].raw || "";
          break;
        }
      }
      if (!prompt) {
        show_toast(t("regenerate_no_prompt", "找不到对应的用户提问"), "warning", 3000);
        return;
      }
      if (!current_session.value) return;

      messages.value.splice(index, 1);
      const { request_id, thinking_msg, thinking_interval } = begin_request();
      await stream_chat(prompt, thinking_msg, thinking_interval, request_id, true);
    };

    const send_message = async () => {
      const message = input_message.value.trim();
      if (!message) {
        show_toast(t("empty_message", "请输入消息内容"), "warning", 3000);
        return;
      }
      if (is_processing.value) {
        show_toast(t("wait_processing", "请等待当前消息处理完成"), "warning", 3000);
        return;
      }

      if (!current_session.value) {
        const created = await create_session(message);
        if (!created) return;
      }

      add_message(escape_html(message), "user", false, message);
      input_message.value = "";
      nextTick(() => {
        const container = chat_messages.value;
        if (container) container.scrollTop = container.scrollHeight;
      });

      const { request_id, thinking_msg, thinking_interval } = begin_request();
      await stream_chat(message, thinking_msg, thinking_interval, request_id);
    };

    // 停止生成
    const stop_generation = () => {
      current_request_id.value++;
      if (current_reader.value) {
        current_reader.value.cancel();
        current_reader.value = null;
      }
      if (current_thinking_interval.value) {
        clearInterval(current_thinking_interval.value);
        current_thinking_interval.value = null;
      }
      if (current_timeout_warning.value) {
        clearTimeout(current_timeout_warning.value);
        current_timeout_warning.value = null;
      }
      // 首个 chunk 未到达时移除“正在连接 AI...”占位气泡
      if (pending_message.value) {
        const pending_index = messages.value.indexOf(pending_message.value);
        if (pending_index > -1) {
          messages.value.splice(pending_index, 1);
        }
        pending_message.value = null;
      }
      is_processing.value = false;
      show_scroll_button.value = false;
      show_toast(t("stopped", "已停止生成"), "info", 2000);
    };

    // ------------------------------------------------------------------
    // SSE 流式对话
    // ------------------------------------------------------------------

    const source_route_map = {
      doc: "doc.show",
      article: "article.show",
      product: "product.show",
      professional: "professional.show",
      solution: "solution.show",
      support: "support.show",
      course: "course.show",
      download: "download.show",
      gallery: "gallery.show",
      cases: "cases.show",
      video: "video.show",
      item: "item.show",
    };

    const build_source = (hit) => {
      const type = hit.source_type || "";
      let source_url = "";
      if (source_route_map[type] && hit.source_id) {
        try {
          source_url = route(source_route_map[type], { id: hit.source_id });
        } catch (e) {
          source_url = "";
        }
      }
      return {
        source_type: type,
        source_id: hit.source_id || 0,
        source_name: t("source_" + type, type),
        title: hit.title || "",
        score: hit.score || 0,
        source_url,
      };
    };

    const stream_chat = async (prompt, target_element, thinking_interval, request_id, regenerate = false) => {
      const form_data = new FormData();
      form_data.append("prompt", prompt);
      form_data.append("session_sn", current_session.value.session_sn);
      if (selected_model.value) {
        form_data.append("model_id", selected_model.value);
      }
      if (regenerate) {
        form_data.append("regenerate", "1");
      }

      const is_valid_request = () => request_id === current_request_id.value;

      let content_queue = [];
      let is_typing = false;
      let full_markdown_content = "";
      let first_chunk_received = false;

      const timeout_warning = setTimeout(() => {
        if (!is_valid_request()) return;
        if (!first_chunk_received && target_element.content && target_element.content.includes(t("thinking_connecting", "正在连接 AI"))) {
          target_element.content = t("thinking_wait", "AI 正在思考，请稍候...");
        }
      }, 10000);
      current_timeout_warning.value = timeout_warning;

      const type_writer = () => {
        if (!is_valid_request()) {
          is_typing = false;
          content_queue = [];
          return;
        }
        if (content_queue.length === 0) {
          is_typing = false;
          return;
        }
        is_typing = true;
        const content = content_queue.shift();
        full_markdown_content += content;
        target_element.content = render_markdown(full_markdown_content);
        smart_scroll_to_bottom();

        let delay = 30;
        if (content.match(/[\u4e00-\u9fa5]/)) {
          delay = 50;
        } else if (!content.match(/[a-zA-Z0-9]/)) {
          delay = 20;
        }
        setTimeout(type_writer, delay);
      };

      const cleanup = () => {
        if (thinking_interval) clearInterval(thinking_interval);
        if (timeout_warning) clearTimeout(timeout_warning);
        current_reader.value = null;
        current_thinking_interval.value = null;
        current_timeout_warning.value = null;
      };

      const fail = (errMsg) => {
        cleanup();
        pending_message.value = null;
        const index = messages.value.indexOf(target_element);
        if (index > -1) {
          messages.value.splice(index, 1);
        }
        show_toast(errMsg, "error", 5000);
        is_processing.value = false;
      };

      try {
        const response = await fetch(route('aigc.stream'), {
          method: "POST",
          body: form_data,
        });

        // 前置校验失败时后端走 ApiResponse 包络（非 200），先报错再放弃 SSE 流
        if (!response.ok) {
          let errMsg = t("request_failed", "请求失败，请稍后重试");
          try {
            const errPayload = await response.json();
            if (errPayload && errPayload.message) {
              errMsg = errPayload.message;
            }
          } catch (e) {
            // 非 JSON 报错，沿用兜底文案
          }
          fail(errMsg);
          return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        current_reader.value = reader;

        const read_stream = async () => {
          const { done, value } = await reader.read();

          if (done) {
            cleanup();
            const check_complete = setInterval(() => {
              if (!is_valid_request()) {
                clearInterval(check_complete);
                return;
              }
              if (!is_typing && content_queue.length === 0) {
                clearInterval(check_complete);
                if (full_markdown_content) {
                  target_element.content = render_markdown(full_markdown_content);
                  target_element.raw = full_markdown_content;
                } else {
                  // 空回复：移除占位气泡，避免留下空白 AI 消息
                  const empty_index = messages.value.indexOf(target_element);
                  if (empty_index > -1) {
                    messages.value.splice(empty_index, 1);
                  }
                }
                pending_message.value = null;
                is_processing.value = false;
                // 刷新会话列表（标题可能由首问改写）；游客无列表
                if (!is_guest.value) {
                  load_sessions();
                }
              }
            }, 100);
            return;
          }

          const chunk = decoder.decode(value, { stream: true });
          chunk.split("\n").forEach((line) => {
            if (!line.startsWith("data: ")) return;
            const data = line.substring(6);
            if (data === "[DONE]") return;

            try {
              const json = JSON.parse(data);
              if (json.message_id || json.knowledge) {
                if (json.message_id) {
                  target_element.id = parseInt(json.message_id, 10) || 0;
                }
                if (json.knowledge && Array.isArray(json.knowledge.hits)) {
                  target_element.sources = json.knowledge.hits.map(build_source);
                }
                return;
              }
              if (json.content) {
                if (!first_chunk_received) {
                  first_chunk_received = true;
                  if (thinking_interval) clearInterval(thinking_interval);
                  if (timeout_warning) clearTimeout(timeout_warning);
                  pending_message.value = null;
                  target_element.is_typing = false;
                  target_element.content = "";
                }
                content_queue.push(json.content);
                if (!is_typing) {
                  type_writer();
                }
              } else if (json.error) {
                fail(json.error);
              }
            } catch (e) {
              console.error("解析JSON错误:", e, "原始数据:", data);
            }
          });

          read_stream();
        };

        read_stream();
      } catch (error) {
        if (!is_valid_request()) return;
        console.error("请求错误:", error);
        fail(t("network_failed", "请求失败，请检查网络连接"));
      }
    };

    // 监听滚动
    onMounted(() => {
      const container = chat_messages.value;
      if (container) {
        container.addEventListener("scroll", () => {
          if (is_program_scrolling.value) return;
          if (is_processing.value) {
            show_scroll_button.value = !is_at_bottom();
          }
        });
      }
    });

    return {
      messages,
      input_message,
      is_processing,
      show_scroll_button,
      chat_messages,
      available_models,
      selected_model,
      current_model_name,
      sessions,
      session_groups,
      current_session,
      suggested_questions,
      send_message,
      stop_generation,
      scroll_to_bottom,
      on_model_change,
      new_session,
      switch_session,
      has_more,
      load_earlier,
      formatDate,
      use_suggestion,
      copy_message,
      regenerate_message,
      submit_feedback,
      t,
    };
  },
}).mount("#aigcChatApp");
