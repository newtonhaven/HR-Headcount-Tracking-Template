/* Organization page: browse and edit unit groups -> units -> positions -> tasks. */
"use strict";

(() => {
  const { api, esc, fmt, fmtPct, fmtMinutes, statusBadge, gapCell, emptyState, fillSelect, toast, confirm, formModal, $ } = App;

  const num = (n) => Number(n).toLocaleString("en-US", { maximumFractionDigits: 2 });
  const range = (min, max) => (Number(min) === Number(max) ? num(min) : `${num(min)}–${num(max)}`);
  const plural = (n, word) => `${fmt(n)} ${word}${Number(n) === 1 ? "" : "s"}`;

  // ------------------------------------------------------------------ state (selection survives reloads via the URL)

  const query = new URLSearchParams(location.search);
  const state = {
    groups: [],
    units: [],
    positions: [],
    shifts: [],
    group: Number(query.get("group")) || null,
    unit: Number(query.get("unit")) || null,
    position: Number(query.get("position")) || null,
  };

  function syncUrl() {
    const params = new URLSearchParams();
    if (state.group) params.set("group", state.group);
    if (state.unit) params.set("unit", state.unit);
    if (state.position) params.set("position", state.position);
    history.replaceState(null, "", params.toString() ? `?${params}` : location.pathname);
  }

  // ------------------------------------------------------------------ generic column

  function column(selector, { getItems, onSelect, onAction, onAdd }) {
    const root = $(selector);
    const col = {
      root,
      list: $("[data-list]", root),
      count: $("[data-count]", root),
      add: $("[data-add]", root),
      filter: $("[data-filter]", root),
    };
    const itemFor = (li) => getItems().find((i) => i.id === Number(li.dataset.id));

    col.filter.addEventListener("input", () => applyFilter(col));
    col.add.addEventListener("click", onAdd);
    col.list.addEventListener("click", (event) => {
      const li = event.target.closest(".miller-item");
      if (!li) return;
      const item = itemFor(li);
      const button = event.target.closest("[data-action]");
      if (button) onAction(button.dataset.action, item);
      else onSelect(item);
    });
    col.list.addEventListener("keydown", (event) => {
      // Arrow keys move between the items' select buttons.
      if (!event.target.matches(".item-main") || !["ArrowDown", "ArrowUp"].includes(event.key)) return;
      event.preventDefault();
      const visible = [...col.list.querySelectorAll(".miller-item:not([hidden]) .item-main")];
      visible[visible.indexOf(event.target) + (event.key === "ArrowDown" ? 1 : -1)]?.focus();
    });
    return col;
  }

  function applyFilter(col) {
    const q = col.filter.value.trim().toLowerCase();
    col.list.querySelectorAll(".miller-item").forEach((li) => {
      li.hidden = Boolean(q) && !li.dataset.search.includes(q);
    });
  }

  function renderList(col, items, { selectedId, name, meta, actions, empty }) {
    col.count.textContent = items.length;
    if (!items.length) {
      col.list.innerHTML = `<li class="empty-state">${esc(empty)}</li>`;
      return;
    }
    col.list.innerHTML = items
      .map((item) => {
        const selected = item.id === selectedId;
        const buttons = actions
          .map(
            (a) => `<button type="button" class="btn-icon${a.danger ? " danger" : ""}" data-action="${a.key}"
              title="${a.label}" aria-label="${a.label}: ${esc(name(item))}"><i class="fa ${a.icon}" aria-hidden="true"></i></button>`
          )
          .join("");
        return `<li class="miller-item${selected ? " selected" : ""}" data-id="${item.id}" data-search="${esc(name(item).toLowerCase())}">
            <button type="button" class="item-main" aria-pressed="${selected}">
              <span class="item-name">${esc(name(item))}</span><span class="item-meta">${meta(item)}</span>
            </button>
            <span class="item-actions">${buttons}</span>
            <i class="fa fa-chevron-right chevron" aria-hidden="true"></i>
          </li>`;
      })
      .join("");
    applyFilter(col);
  }

  function placeholder(col, message) {
    col.count.textContent = "0";
    col.list.innerHTML = `<li class="empty-state">${esc(message)}</li>`;
  }

  const RENAME = { key: "rename", label: "Rename", icon: "fa-pen" };
  const DELETE = { key: "delete", label: "Delete", icon: "fa-trash-can", danger: true };

  const groupsCol = column("#col-groups", {
    getItems: () => state.groups,
    onSelect: (g) => select({ group: g.id, unit: null, position: null }),
    onAction: groupAction,
    onAdd: () => nameModal({ title: "Add unit group", label: "Group name", submitText: "Add group" }, async (name) => {
      const created = await api("api/unit-groups.php", { method: "POST", body: { name } });
      toast(`Unit group “${name}” added.`);
      await select({ group: created.id, unit: null, position: null });
    }),
  });

  const unitsCol = column("#col-units", {
    getItems: () => state.units,
    onSelect: (u) => select({ group: state.group, unit: u.id, position: null }),
    onAction: unitAction,
    onAdd: () => nameModal({ title: "Add unit", label: "Unit name", submitText: "Add unit" }, async (name) => {
      const created = await api("api/units.php", { method: "POST", body: { group_id: state.group, name } });
      toast(`Unit “${name}” added.`);
      await select({ group: state.group, unit: created.id, position: null });
    }),
  });

  const positionsCol = column("#col-positions", {
    getItems: () => state.positions,
    onSelect: (p) => select({ group: state.group, unit: state.unit, position: p.id }),
    onAction: positionAction,
    onAdd: () => positionModal(null),
  });

  // ------------------------------------------------------------------ loading

  async function loadGroups() {
    state.groups = await api("api/unit-groups.php");
    if (state.group && !state.groups.some((g) => g.id === state.group)) {
      Object.assign(state, { group: null, unit: null, position: null });
    }
    renderList(groupsCol, state.groups, {
      selectedId: state.group,
      name: (g) => g.name,
      meta: (g) => `${plural(g.unit_count, "unit")} · ${plural(g.position_count, "position")}`,
      actions: [RENAME, DELETE],
      empty: "No unit groups yet. Add the first one.",
    });
  }

  async function loadUnits() {
    unitsCol.add.disabled = !state.group;
    if (!state.group) {
      state.units = [];
      placeholder(unitsCol, "Select a unit group");
      return;
    }
    state.units = await api("api/units.php", { params: { group_id: state.group } });
    if (state.unit && !state.units.some((u) => u.id === state.unit)) {
      Object.assign(state, { unit: null, position: null });
    }
    renderList(unitsCol, state.units, {
      selectedId: state.unit,
      name: (u) => u.name,
      meta: (u) => `${plural(u.position_count, "position")} · ${fmt(u.headcount)} staff`,
      actions: [RENAME, { key: "move", label: "Move to another group", icon: "fa-arrows-up-down-left-right" }, DELETE],
      empty: "No units in this group yet.",
    });
  }

  async function loadPositions() {
    positionsCol.add.disabled = !state.unit;
    if (!state.unit) {
      state.positions = [];
      placeholder(positionsCol, "Select a unit");
      return;
    }
    state.positions = await api("api/positions.php", { params: { unit_id: state.unit } });
    if (state.position && !state.positions.some((p) => p.id === state.position)) {
      state.position = null;
    }
    renderList(positionsCol, state.positions, {
      selectedId: state.position,
      name: (p) => p.title,
      meta: (p) => `${esc(p.shift_name ?? "No shift")} · ${fmt(p.headcount)} staff · ${plural(p.task_count, "task")}`,
      actions: [{ key: "edit", label: "Edit", icon: "fa-pen" }, DELETE],
      empty: "No positions in this unit yet.",
    });
  }

  async function loadTasks() {
    const hasPosition = Boolean(state.position);
    $("#add-task").disabled = !hasPosition;
    $("#copy-tasks").disabled = !hasPosition;
    if (!hasPosition) {
      $("#tasks-title").textContent = "Tasks";
      $("#tasks-subtitle").textContent = "Select a position to see and edit its task definitions.";
      $("#tasks-summary").innerHTML = "";
      $("#tasks-table").innerHTML = emptyState("No position selected", "fa-list-check");
      return;
    }
    const { position, tasks } = await api("api/tasks.php", { params: { position_id: state.position } });
    state.tasks = tasks;
    $("#tasks-title").textContent = `Tasks · ${position.title}`;
    $("#tasks-subtitle").textContent = `${position.group_name} › ${position.unit_name} · ${position.shift_name ?? "no shift assigned (defaults used)"}`;
    renderSummary(position);
    renderTasks(tasks);
  }

  function renderSummary(p) {
    $("#tasks-summary").innerHTML = `
      <div class="summary-strip">
        <div><div class="s-label">Daily workload</div><div class="s-value">${fmtMinutes(p.daily_workload_minutes)}</div></div>
        <div><div class="s-label">Capacity per person</div><div class="s-value">${fmtMinutes(p.effective_minutes)}</div></div>
        <div><div class="s-label">Required (FTE)</div><div class="s-value">${fmt(p.required_fte, 1)}</div></div>
        <div><div class="s-label">Current headcount</div><div class="s-value">${fmt(p.headcount)}</div></div>
        <div><div class="s-label">Gap</div><div class="s-value">${gapCell(p.gap)}</div><div class="mt-1">${statusBadge(p.status)}</div></div>
      </div>`;
  }

  function frequency(t) {
    const parts = [];
    if (t.daily_max > 0) parts.push(`${range(t.daily_min, t.daily_max)} / day`);
    if (t.weekly_max > 0) parts.push(`${range(t.weekly_min, t.weekly_max)} / week`);
    if (t.monthly_max > 0) parts.push(`${range(t.monthly_min, t.monthly_max)} / month`);
    return parts.join("<br>") || "–";
  }

  function renderTasks(tasks) {
    if (!tasks.length) {
      $("#tasks-table").innerHTML = emptyState("This position has no tasks yet. Add tasks or copy them from a similar position.", "fa-list-check");
      return;
    }
    const total = tasks.reduce((sum, t) => sum + t.standard_minutes, 0);
    $("#tasks-table").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">Task</th>
            <th scope="col" class="num">Duration (min)</th>
            <th scope="col" class="num">Frequency</th>
            <th scope="col" class="num">Allowances</th>
            <th scope="col" class="num">Daily load (min)</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr></thead>
          <tbody>
            ${tasks
              .map(
                (t) => `<tr data-id="${t.id}">
                  <td>${esc(t.name)}<span class="cell-sub">per ${esc(t.unit_of_measure || "item")}</span></td>
                  <td class="num">${range(t.duration_min, t.duration_max)}</td>
                  <td class="num">${frequency(t)}</td>
                  <td class="num">${num(t.personal_allowance_pct)}% + ${num(t.rest_allowance_pct)}%</td>
                  <td class="num">${fmt(t.standard_minutes, 1)}</td>
                  <td class="row-actions">
                    <button type="button" class="btn-icon" data-task-action="edit" title="Edit" aria-label="Edit task: ${esc(t.name)}"><i class="fa fa-pen" aria-hidden="true"></i></button>
                    <button type="button" class="btn-icon danger" data-task-action="delete" title="Delete" aria-label="Delete task: ${esc(t.name)}"><i class="fa fa-trash-can" aria-hidden="true"></i></button>
                  </td>
                </tr>`
              )
              .join("")}
          </tbody>
          <tfoot><tr>
            <td colspan="4">Total daily load (${tasks.length} tasks)</td>
            <td class="num">${fmt(total, 1)}</td><td></td>
          </tr></tfoot>
        </table>
      </div>`;
  }

  /** Reload everything from the given level down, keeping the selection where possible. */
  async function refresh() {
    try {
      await loadGroups();
      await loadUnits();
      await loadPositions();
      await loadTasks();
    } catch (error) {
      toast(error.message, "error");
    }
    syncUrl();
  }

  async function select(selection) {
    Object.assign(state, selection);
    syncUrl();
    await refresh();
  }

  // ------------------------------------------------------------------ modals

  function nameModal({ title, label, submitText = "Save", value = "" }, save) {
    formModal({
      title,
      submitText,
      body: `<label class="form-label" for="name-input">${esc(label)}</label>
             <input id="name-input" name="name" class="form-control" maxlength="150" required value="${esc(value)}">`,
      onSubmit: async ({ name }) => {
        if (!name.trim()) throw new Error(`${label} is required.`);
        await save(name.trim());
      },
    });
  }

  async function ensureShifts() {
    if (!state.shifts.length) state.shifts = await api("api/shifts.php");
    return state.shifts;
  }

  async function positionModal(position) {
    const shifts = await ensureShifts().catch((e) => (toast(e.message, "error"), null));
    if (!shifts) return;
    formModal({
      title: position ? "Edit position" : "Add position",
      submitText: position ? "Save changes" : "Add position",
      body: `
        <div class="mb-3">
          <label class="form-label" for="pos-title">Position title</label>
          <input id="pos-title" name="title" class="form-control" maxlength="150" required value="${esc(position?.title ?? "")}" placeholder="e.g. Ward Nurse">
        </div>
        <label class="form-label" for="pos-shift">Shift</label>
        <select id="pos-shift" name="shift_id" class="form-select"></select>
        <div class="form-text">The shift sets the capacity per person and how weekly tasks are spread. Manage shifts on the Shifts page.</div>`,
      onOpen: (el) =>
        fillSelect(el.querySelector("#pos-shift"), shifts, {
          placeholder: "No shift",
          value: position?.shift_id ?? "",
          label: (s) => `${s.name} (${s.start_time}–${s.end_time}, ${s.active_days} d/wk)`,
        }),
      onSubmit: async ({ title, shift_id }) => {
        const body = { title, shift_id: shift_id || null };
        if (position) {
          await api(`api/positions.php?id=${position.id}`, { method: "PATCH", body });
          toast("Position updated.");
          await refresh();
        } else {
          const created = await api("api/positions.php", { method: "POST", body: { ...body, unit_id: state.unit } });
          toast(`Position “${title}” added.`);
          await select({ group: state.group, unit: state.unit, position: created.id });
        }
      },
    });
  }

  function taskModal(task) {
    const field = (name, label, attrs = "") =>
      `<input name="${name}" class="form-control" type="number" min="0" step="any" inputmode="decimal" aria-label="${esc(label)}"
         value="${task && Number(task[name]) ? esc(task[name]) : ""}" ${attrs}>`;
    const row = (prefix, label, hint = "") => `
      <div class="col-5 col-md-4 d-flex align-items-center"><span class="form-label mb-0">${label}</span>${hint}</div>
      <div class="col">${field(`${prefix}_min`, `${label} min`)}</div>
      <div class="col">${field(`${prefix}_max`, `${label} max`)}</div>`;

    formModal({
      title: task ? "Edit task" : "Add task",
      submitText: task ? "Save changes" : "Add task",
      size: "modal-lg",
      body: `
        <div class="row g-3 mb-3">
          <div class="col-md-8">
            <label class="form-label" for="task-name">Task</label>
            <input id="task-name" name="name" class="form-control" maxlength="255" required value="${esc(task?.name ?? "")}" placeholder="e.g. Register patient arrivals">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="task-uom">Counted per</label>
            <input id="task-uom" name="unit_of_measure" class="form-control" maxlength="50" value="${esc(task?.unit_of_measure ?? "")}" placeholder="patient, report, call…">
          </div>
        </div>
        <div class="row g-2 align-items-center">
          <div class="col-5 col-md-4"></div><div class="col form-label mb-0">Min</div><div class="col form-label mb-0">Max</div>
          <div class="w-100"></div>
          ${row("duration", "Minutes per item")}
          <div class="w-100"></div>
          ${row("daily", "Times per day")}
          <div class="w-100"></div>
          ${row("weekly", "Times per week")}
          <div class="w-100"></div>
          ${row("monthly", "Times per month")}
        </div>
        <div class="form-text mb-3">Fill in the frequency that fits the task (per day, week <em>or</em> month) and leave the others empty. A single value can go in “Min”.</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="task-personal">Personal allowance (%)</label>
            ${field("personal_allowance_pct", "Personal allowance", 'id="task-personal" max="100"')}
          </div>
          <div class="col-md-6">
            <label class="form-label" for="task-rest">Rest / fatigue allowance (%)</label>
            ${field("rest_allowance_pct", "Rest allowance", 'id="task-rest" max="100"')}
          </div>
        </div>`,
      onSubmit: async (data) => {
        if (task) {
          await api(`api/tasks.php?id=${task.id}`, { method: "PATCH", body: data });
          toast("Task updated.");
        } else {
          await api("api/tasks.php", { method: "POST", body: { ...data, position_id: state.position } });
          toast("Task added.");
        }
        await refresh();
      },
    });
  }

  function copyTasksModal() {
    const target = state.positions.find((p) => p.id === state.position);
    formModal({
      title: "Copy tasks from another position",
      submitText: "Copy tasks",
      body: `
        <p class="mb-3">Copies every task of the chosen position to <strong>${esc(target?.title ?? "this position")}</strong>. You can adjust the copies afterwards.</p>
        <div class="mb-3"><label class="form-label" for="copy-group">Unit group</label><select id="copy-group" class="form-select"></select></div>
        <div class="mb-3"><label class="form-label" for="copy-unit">Unit</label><select id="copy-unit" class="form-select" disabled><option value="">Select a group first</option></select></div>
        <div class="mb-2"><label class="form-label" for="copy-position">Position</label><select id="copy-position" name="from_position_id" class="form-select" disabled><option value="">Select a unit first</option></select></div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="copy-replace" name="replace" value="1">
          <label class="form-check-label" for="copy-replace">Replace the tasks this position already has</label>
        </div>`,
      onOpen: (el) => {
        const groupSel = el.querySelector("#copy-group");
        const unitSel = el.querySelector("#copy-unit");
        const posSel = el.querySelector("#copy-position");
        fillSelect(groupSel, state.groups, { placeholder: "Select a unit group", value: state.group });
        const loadUnitsFor = async () => {
          posSel.disabled = true;
          fillSelect(posSel, [], { placeholder: "Select a unit first" });
          unitSel.disabled = !groupSel.value;
          if (!groupSel.value) return fillSelect(unitSel, [], { placeholder: "Select a group first" });
          fillSelect(unitSel, await api("api/units.php", { params: { group_id: groupSel.value } }), { placeholder: "Select a unit" });
        };
        unitSel.addEventListener("change", async () => {
          posSel.disabled = !unitSel.value;
          if (!unitSel.value) return fillSelect(posSel, [], { placeholder: "Select a unit first" });
          const positions = (await api("api/positions.php", { params: { unit_id: unitSel.value } })).filter((p) => p.id !== state.position);
          fillSelect(posSel, positions, {
            placeholder: positions.length ? "Select a position" : "No other positions here",
            label: (p) => `${p.title} · ${p.shift_name ?? "no shift"} (${plural(p.task_count, "task")})`,
          });
        });
        groupSel.addEventListener("change", loadUnitsFor);
        loadUnitsFor();
      },
      onSubmit: async ({ from_position_id, replace }) => {
        if (!from_position_id) throw new Error("Choose the position to copy from.");
        const result = await api("api/tasks.php?action=copy", {
          method: "POST",
          body: { from_position_id: Number(from_position_id), to_position_id: state.position, replace: Boolean(replace) },
        });
        toast(`${plural(result.copied, "task")} copied.`);
        await refresh();
      },
    });
  }

  // ------------------------------------------------------------------ actions

  async function remove(url, title, message, success) {
    if (!(await confirm({ title, message, confirmText: "Delete" }))) return;
    try {
      await api(url, { method: "DELETE" });
      toast(success);
      await refresh();
    } catch (error) {
      toast(error.message, "error");
    }
  }

  function groupAction(action, g) {
    if (action === "rename") {
      nameModal({ title: "Rename unit group", label: "Group name", value: g.name }, async (name) => {
        await api(`api/unit-groups.php?id=${g.id}`, { method: "PATCH", body: { name } });
        toast("Unit group renamed.");
        await refresh();
      });
    } else if (action === "delete") {
      remove(
        `api/unit-groups.php?id=${g.id}`,
        "Delete unit group",
        `Delete <strong>${esc(g.name)}</strong>? This also deletes its ${plural(g.unit_count, "unit")} and
         ${plural(g.position_count, "position")} with all their tasks. Employees in those positions stay on file but become unassigned.`,
        `Unit group “${g.name}” deleted.`
      );
    }
  }

  function unitAction(action, u) {
    if (action === "rename") {
      nameModal({ title: "Rename unit", label: "Unit name", value: u.name }, async (name) => {
        await api(`api/units.php?id=${u.id}`, { method: "PATCH", body: { name } });
        toast("Unit renamed.");
        await refresh();
      });
    } else if (action === "move") {
      formModal({
        title: `Move “${u.name}”`,
        submitText: "Move unit",
        body: `<label class="form-label" for="move-group">Move to unit group</label><select id="move-group" name="group_id" class="form-select"></select>
               <div class="form-text">The unit keeps its positions, tasks and employees.</div>`,
        onOpen: (el) =>
          fillSelect(el.querySelector("#move-group"), state.groups.filter((g) => g.id !== u.group_id), { placeholder: "Select a group" }),
        onSubmit: async ({ group_id }) => {
          if (!group_id) throw new Error("Choose the target group.");
          await api(`api/units.php?id=${u.id}`, { method: "PATCH", body: { group_id: Number(group_id) } });
          toast(`“${u.name}” moved.`);
          await select({ group: Number(group_id), unit: u.id, position: state.position });
        },
      });
    } else if (action === "delete") {
      remove(
        `api/units.php?id=${u.id}`,
        "Delete unit",
        `Delete <strong>${esc(u.name)}</strong>? This also deletes its ${plural(u.position_count, "position")} and their tasks.
         ${u.headcount ? `${plural(u.headcount, "employee")} will become unassigned.` : ""}`,
        `Unit “${u.name}” deleted.`
      );
    }
  }

  function positionAction(action, p) {
    if (action === "edit") {
      positionModal(p);
    } else if (action === "delete") {
      remove(
        `api/positions.php?id=${p.id}`,
        "Delete position",
        `Delete <strong>${esc(p.title)}</strong> (${esc(p.shift_name ?? "no shift")})? Its ${plural(p.task_count, "task")} are deleted too.
         ${p.headcount ? `${plural(p.headcount, "employee")} will become unassigned.` : ""}`,
        `Position “${p.title}” deleted.`
      );
    }
  }

  $("#add-task").addEventListener("click", () => taskModal(null));
  $("#copy-tasks").addEventListener("click", copyTasksModal);
  $("#tasks-table").addEventListener("click", (event) => {
    const button = event.target.closest("[data-task-action]");
    if (!button) return;
    const task = state.tasks.find((t) => t.id === Number(button.closest("tr").dataset.id));
    if (button.dataset.taskAction === "edit") taskModal(task);
    else remove(`api/tasks.php?id=${task.id}`, "Delete task", `Delete the task <strong>${esc(task.name)}</strong>?`, "Task deleted.");
  });

  groupsCol.add.disabled = false;
  refresh();
})();
