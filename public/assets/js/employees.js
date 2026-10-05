/* Employees page: searchable, sortable list with add / edit / record departure / reinstate. */
"use strict";

(() => {
  const { api, esc, fmt, fmtDate, emptyState, fillSelect, toast, confirm, formModal, debounce, isAdmin, $ } = App;

  const TYPES = { permanent: "Permanent", contract: "Contract", temporary: "Temporary", intern: "Intern" };
  const REASONS = ["Resignation", "Retirement", "End of contract", "Transfer", "Dismissal", "Other"];
  const filters = $("#employee-filters").elements;
  const today = new Date().toLocaleDateString("sv-SE"); // local YYYY-MM-DD

  let groups = [];
  let units = [];
  let rows = [];
  const view = { sort: "name", dir: "asc", page: 1, perPage: 25 };

  function tenure(from, to) {
    const start = new Date(from);
    const end = to ? new Date(to) : new Date();
    const years = (end - start) / (365.25 * 24 * 3600 * 1000);
    return years < 1 ? `${Math.max(0, Math.round(years * 12))} mo` : `${years.toFixed(1)} yrs`;
  }

  // ------------------------------------------------------------------ filters

  async function loadLookups() {
    [groups, units] = await Promise.all([api("api/unit-groups.php"), api("api/units.php")]);
    fillSelect(filters.group_id, groups, { placeholder: "All groups" });
    fillUnitFilter();
  }

  function fillUnitFilter() {
    const groupId = Number(filters.group_id.value) || null;
    const list = groupId ? units.filter((u) => u.group_id === groupId) : units;
    fillSelect(filters.unit_id, list, {
      placeholder: "All units",
      label: (u) => (groupId ? u.name : `${u.name} (${u.group_name})`),
    });
  }

  const reloadFromStart = () => {
    view.page = 1;
    load();
  };

  filters.q.addEventListener("input", debounce(reloadFromStart, 300));
  filters.group_id.addEventListener("change", () => {
    fillUnitFilter();
    reloadFromStart();
  });
  ["unit_id", "status", "type"].forEach((name) => filters[name].addEventListener("change", reloadFromStart));
  $("#f-reset").addEventListener("click", () => {
    $("#employee-filters").reset();
    fillUnitFilter();
    Object.assign(view, { sort: "name", dir: "asc", page: 1 });
    load();
  });

  // ------------------------------------------------------------------ list

  async function load() {
    const table = $("#employee-table");
    table.classList.add("is-loading");
    try {
      const data = await api("api/employees.php", {
        params: {
          q: filters.q.value.trim(),
          group_id: filters.group_id.value,
          unit_id: filters.unit_id.value,
          status: filters.status.value,
          type: filters.type.value,
          sort: view.sort,
          dir: view.dir,
          page: view.page,
          per_page: view.perPage,
        },
      });
      rows = data.rows;
      render(data);
    } catch (error) {
      table.innerHTML = emptyState(error.message, "fa-triangle-exclamation");
      $("#employee-pager").innerHTML = "";
    } finally {
      table.classList.remove("is-loading");
    }
  }

  function header(key, label, extra = "") {
    if (!key) return `<th scope="col" ${extra}>${label}</th>`;
    const sorted = view.sort === key;
    const icon = sorted ? (view.dir === "asc" ? "fa-sort-up" : "fa-sort-down") : "fa-sort";
    const aria = sorted ? (view.dir === "asc" ? "ascending" : "descending") : "none";
    return `<th scope="col" class="sortable${sorted ? " sorted" : ""}" data-sort="${key}" aria-sort="${aria}" tabindex="0" ${extra}>
      ${label}<i class="fa ${icon} sort-icon" aria-hidden="true"></i></th>`;
  }

  function statusCell(e) {
    if (!e.termination_date || e.termination_date > today) return `<span class="status-badge status-balanced"><i class="fa fa-check" aria-hidden="true"></i>Active</span>`;
    return `<span class="status-badge status-terminated"><i class="fa fa-user-slash" aria-hidden="true"></i>Left ${fmtDate(e.termination_date)}</span>
            <span class="cell-sub">${esc(e.termination_reason ?? "")}</span>`;
  }

  function actions(e) {
    if (!isAdmin) return "";
    const left = e.termination_date && e.termination_date <= today;
    return `<td class="row-actions">
      <button type="button" class="btn-icon" data-action="edit" title="Edit" aria-label="Edit ${esc(e.first_name)} ${esc(e.last_name)}"><i class="fa fa-pen" aria-hidden="true"></i></button>
      ${
        left
          ? `<button type="button" class="btn-icon" data-action="reinstate" title="Reinstate" aria-label="Reinstate ${esc(e.first_name)} ${esc(e.last_name)}"><i class="fa fa-rotate-left" aria-hidden="true"></i></button>`
          : `<button type="button" class="btn-icon" data-action="terminate" title="Record departure" aria-label="Record departure of ${esc(e.first_name)} ${esc(e.last_name)}"><i class="fa fa-user-slash" aria-hidden="true"></i></button>`
      }
      <button type="button" class="btn-icon danger" data-action="delete" title="Delete record" aria-label="Delete ${esc(e.first_name)} ${esc(e.last_name)}"><i class="fa fa-trash-can" aria-hidden="true"></i></button>
    </td>`;
  }

  function render({ total, page, per_page: perPage }) {
    if (!rows.length) {
      $("#employee-table").innerHTML = emptyState("No employees match these filters.", "fa-users");
      $("#employee-pager").innerHTML = "";
      return;
    }
    const showLeft = filters.status.value !== "active";
    $("#employee-table").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            ${header("name", "Employee")}
            ${header("position", "Position")}
            ${header("unit", "Unit")}
            ${header(null, "Type")}
            ${header("hire_date", "Hired")}
            ${showLeft ? header("termination_date", "Status") : header(null, "Status")}
            ${isAdmin ? '<th scope="col"><span class="visually-hidden">Actions</span></th>' : ""}
          </tr></thead>
          <tbody>
            ${rows
              .map(
                (e) => `<tr data-id="${e.id}">
                  <td>${esc(e.first_name)} ${esc(e.last_name)}<span class="cell-sub">${esc(e.employee_no)}</span></td>
                  <td>${e.position_title ? `${esc(e.position_title)}<span class="cell-sub">${esc(e.shift_name ?? "No shift")}</span>` : '<span class="text-muted">Unassigned</span>'}</td>
                  <td>${e.unit_name ? `${esc(e.unit_name)}<span class="cell-sub">${esc(e.group_name)}</span>` : "–"}</td>
                  <td>${TYPES[e.employment_type] ?? esc(e.employment_type)}</td>
                  <td class="text-nowrap">${fmtDate(e.hire_date)}<span class="cell-sub">${tenure(e.hire_date, e.termination_date && e.termination_date <= today ? e.termination_date : null)}</span></td>
                  <td>${statusCell(e)}</td>
                  ${actions(e)}
                </tr>`
              )
              .join("")}
          </tbody>
        </table>
      </div>`;

    const pages = Math.max(1, Math.ceil(total / perPage));
    const from = (page - 1) * perPage + 1;
    $("#employee-pager").innerHTML = `
      <span>Showing ${fmt(from)}–${fmt(Math.min(total, page * perPage))} of ${fmt(total)}</span>
      <div class="d-flex gap-2 align-items-center">
        <button type="button" class="btn btn-sm btn-ghost" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="fa fa-chevron-left me-1" aria-hidden="true"></i>Previous</button>
        <span>Page ${page} of ${pages}</span>
        <button type="button" class="btn btn-sm btn-ghost" data-page="${page + 1}" ${page >= pages ? "disabled" : ""}>Next<i class="fa fa-chevron-right ms-1" aria-hidden="true"></i></button>
      </div>`;
  }

  function sortBy(key) {
    if (view.sort === key) view.dir = view.dir === "asc" ? "desc" : "asc";
    else Object.assign(view, { sort: key, dir: key === "hire_date" || key === "termination_date" ? "desc" : "asc" });
    reloadFromStart();
  }

  $("#employee-table").addEventListener("click", (event) => {
    const th = event.target.closest("th[data-sort]");
    if (th) return sortBy(th.dataset.sort);
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const employee = rows.find((r) => r.id === Number(button.closest("tr").dataset.id));
    ({ edit: employeeModal, terminate: terminateModal, reinstate, delete: removeEmployee })[button.dataset.action](employee);
  });
  $("#employee-table").addEventListener("keydown", (event) => {
    const th = event.target.closest("th[data-sort]");
    if (th && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      sortBy(th.dataset.sort);
    }
  });
  $("#employee-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button) return;
    view.page = Number(button.dataset.page);
    load();
  });

  // ------------------------------------------------------------------ add / edit

  function employeeModal(employee = null) {
    const e = employee ?? {};
    const option = (value, label, current) => `<option value="${value}"${value === current ? " selected" : ""}>${label}</option>`;
    formModal({
      title: employee ? `Edit ${e.first_name} ${e.last_name}` : "Add employee",
      submitText: employee ? "Save changes" : "Add employee",
      size: "modal-lg",
      body: `
        <div class="row g-3">
          <div class="col-md-5"><label class="form-label" for="e-first">First name</label>
            <input id="e-first" name="first_name" class="form-control" maxlength="80" required value="${esc(e.first_name ?? "")}"></div>
          <div class="col-md-5"><label class="form-label" for="e-last">Last name</label>
            <input id="e-last" name="last_name" class="form-control" maxlength="80" required value="${esc(e.last_name ?? "")}"></div>
          <div class="col-md-2"><label class="form-label" for="e-no">Employee no.</label>
            <input id="e-no" name="employee_no" class="form-control" maxlength="20" value="${esc(e.employee_no ?? "")}" placeholder="Auto" ${employee ? "disabled" : ""}></div>
          <div class="col-md-4"><label class="form-label" for="e-gender">Gender</label>
            <select id="e-gender" name="gender" class="form-select">
              ${option("", "Not specified", e.gender ?? "")}${option("female", "Female", e.gender)}${option("male", "Male", e.gender)}${option("other", "Other", e.gender)}
            </select></div>
          <div class="col-md-4"><label class="form-label" for="e-birth">Birth date</label>
            <input id="e-birth" name="birth_date" type="date" class="form-control" value="${esc(e.birth_date ?? "")}" max="${today}"></div>
          <div class="col-md-4"><label class="form-label" for="e-type">Employment type</label>
            <select id="e-type" name="employment_type" class="form-select">
              ${Object.entries(TYPES).map(([v, l]) => option(v, l, e.employment_type ?? "permanent")).join("")}
            </select></div>
          <div class="col-md-4"><label class="form-label" for="e-group">Unit group</label>
            <select id="e-group" class="form-select"></select></div>
          <div class="col-md-4"><label class="form-label" for="e-unit">Unit</label>
            <select id="e-unit" class="form-select"></select></div>
          <div class="col-md-4"><label class="form-label" for="e-position">Position</label>
            <select id="e-position" name="position_id" class="form-select"></select></div>
          <div class="col-md-4"><label class="form-label" for="e-hire">Hire date</label>
            <input id="e-hire" name="hire_date" type="date" class="form-control" required value="${esc(e.hire_date ?? today)}"></div>
        </div>`,
      onOpen: (el) => {
        const groupSel = el.querySelector("#e-group");
        const unitSel = el.querySelector("#e-unit");
        const posSel = el.querySelector("#e-position");
        const loadPositions = async (selected = null) => {
          if (!unitSel.value) {
            fillSelect(posSel, [], { placeholder: "Unassigned" });
            return;
          }
          const positions = await api("api/positions.php", { params: { unit_id: unitSel.value } });
          fillSelect(posSel, positions, {
            placeholder: "Unassigned",
            value: selected,
            label: (p) => `${p.title} · ${p.shift_name ?? "no shift"}`,
          });
        };
        const fillUnits = (selected = null) =>
          fillSelect(unitSel, units.filter((u) => u.group_id === Number(groupSel.value)), { placeholder: groupSel.value ? "Select a unit" : "Select a group first", value: selected });
        fillSelect(groupSel, groups, { placeholder: "Select a group", value: e.group_id ?? "" });
        fillUnits(e.unit_id ?? null);
        loadPositions(e.position_id ?? null);
        groupSel.addEventListener("change", () => {
          fillUnits();
          loadPositions();
        });
        unitSel.addEventListener("change", () => loadPositions());
      },
      onSubmit: async (data) => {
        if (employee) {
          await api(`api/employees.php?id=${employee.id}`, { method: "PATCH", body: data });
          toast("Employee updated.");
        } else {
          const created = await api("api/employees.php", { method: "POST", body: data });
          toast(`${created.first_name} ${created.last_name} added as ${created.employee_no}.`);
        }
        await load();
      },
    });
  }

  function terminateModal(e) {
    formModal({
      title: `Record departure · ${e.first_name} ${e.last_name}`,
      submitText: "Record departure",
      body: `
        <p>The employee stays on file for reporting; they no longer count towards headcount from the last working day on.</p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="t-date">Last working day</label>
            <input id="t-date" name="termination_date" type="date" class="form-control" required value="${today}" min="${esc(e.hire_date)}"></div>
          <div class="col-md-6"><label class="form-label" for="t-reason">Reason</label>
            <select id="t-reason" name="termination_reason" class="form-select">${REASONS.map((r) => `<option>${r}</option>`).join("")}</select></div>
        </div>`,
      onSubmit: async (data) => {
        await api(`api/employees.php?id=${e.id}`, { method: "PATCH", body: data });
        toast(`Departure of ${e.first_name} ${e.last_name} recorded.`);
        await load();
      },
    });
  }

  async function reinstate(e) {
    const ok = await confirm({
      title: "Reinstate employee",
      message: `Reinstate <strong>${esc(e.first_name)} ${esc(e.last_name)}</strong>? The recorded departure (${fmtDate(e.termination_date)}) is removed.`,
      confirmText: "Reinstate",
      danger: false,
    });
    if (!ok) return;
    try {
      await api(`api/employees.php?id=${e.id}`, { method: "PATCH", body: { termination_date: null, termination_reason: null } });
      toast(`${e.first_name} ${e.last_name} reinstated.`);
      await load();
    } catch (error) {
      toast(error.message, "error");
    }
  }

  async function removeEmployee(e) {
    const ok = await confirm({
      title: "Delete employee record",
      message: `Permanently delete <strong>${esc(e.first_name)} ${esc(e.last_name)}</strong> (${esc(e.employee_no)})?
                Use <em>Record departure</em> for people who left; delete only records created by mistake, since deleting removes them from turnover history.`,
      confirmText: "Delete permanently",
    });
    if (!ok) return;
    try {
      await api(`api/employees.php?id=${e.id}`, { method: "DELETE" });
      toast("Employee record deleted.");
      await load();
    } catch (error) {
      toast(error.message, "error");
    }
  }

  $("#add-employee")?.addEventListener("click", () => employeeModal(null));

  loadLookups()
    .catch((error) => toast(error.message, "error"))
    .finally(load);
})();
