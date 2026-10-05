/* Shifts page: list, add, edit and delete shifts. */
"use strict";

(() => {
  const { api, esc, fmt, emptyState, toast, confirm, $ } = App;

  const form = $("#shift-form");
  const fields = form.elements; // named inputs ("id", "name") would shadow form.id / form.name
  const errorBox = $("#shift-error");
  let shifts = [];

  const hours = (h) => `${Number(h).toLocaleString("en-US", { maximumFractionDigits: 2 })} h`;

  function lengthHours(start, end) {
    if (!start || !end) return null;
    const toMin = (t) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
    let minutes = toMin(end) - toMin(start);
    if (minutes <= 0) minutes += 24 * 60;
    return minutes / 60;
  }

  function updateLengthHint() {
    const length = lengthHours(fields.start_time.value, fields.end_time.value);
    $("#shift-length").textContent = length
      ? `Shift length: ${hours(length)}${fields.end_time.value <= fields.start_time.value ? " (ends the next day)" : ""}.`
      : "An end time before the start time means the shift ends the next day.";
  }

  async function load() {
    try {
      shifts = await api("api/shifts.php");
      render();
    } catch (error) {
      $("#shift-table").innerHTML = emptyState(error.message, "fa-triangle-exclamation");
    }
  }

  function render() {
    if (!shifts.length) {
      $("#shift-table").innerHTML = emptyState("No shifts defined yet. Add the first one with the form.", "fa-user-clock");
      return;
    }
    $("#shift-table").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">Shift</th>
            <th scope="col">Hours</th>
            <th scope="col" class="num">Days / week</th>
            <th scope="col" class="num">Effective</th>
            <th scope="col" class="num">Positions</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr></thead>
          <tbody>
            ${shifts
              .map(
                (s) => `<tr data-id="${s.id}">
                  <td>${esc(s.name)}${s.notes ? `<span class="cell-sub">${esc(s.notes)}</span>` : ""}</td>
                  <td class="text-nowrap">${esc(s.start_time)} – ${esc(s.end_time)}<span class="cell-sub">${hours(s.length_hours)}</span></td>
                  <td class="num">${fmt(s.active_days)}</td>
                  <td class="num">${hours(s.effective_hours)}</td>
                  <td class="num">${fmt(s.position_count)}</td>
                  <td class="row-actions">
                    <button type="button" class="btn-icon" data-action="edit" title="Edit" aria-label="Edit shift: ${esc(s.name)}"><i class="fa fa-pen" aria-hidden="true"></i></button>
                    <button type="button" class="btn-icon danger" data-action="delete"
                      title="${s.position_count ? "In use – move its positions to another shift first" : "Delete"}"
                      aria-label="Delete shift: ${esc(s.name)}" ${s.position_count ? "disabled" : ""}><i class="fa fa-trash-can" aria-hidden="true"></i></button>
                  </td>
                </tr>`
              )
              .join("")}
          </tbody>
        </table>
      </div>`;
  }

  function resetForm() {
    form.reset();
    fields.id.value = "";
    fields.active_days.value = "5";
    $("#shift-form-title").textContent = "Add shift";
    $("#shift-submit").textContent = "Add shift";
    $("#shift-cancel").classList.add("d-none");
    errorBox.classList.add("d-none");
    updateLengthHint();
  }

  function editShift(shift) {
    fields.id.value = shift.id;
    fields.name.value = shift.name;
    fields.start_time.value = shift.start_time;
    fields.end_time.value = shift.end_time;
    fields.active_days.value = String(shift.active_days);
    fields.effective_hours.value = shift.effective_hours;
    fields.notes.value = shift.notes ?? "";
    $("#shift-form-title").textContent = `Edit “${shift.name}”`;
    $("#shift-submit").textContent = "Save changes";
    $("#shift-cancel").classList.remove("d-none");
    errorBox.classList.add("d-none");
    updateLengthHint();
    fields.name.focus();
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(form));
    const id = data.id;
    delete data.id;
    $("#shift-submit").disabled = true;
    errorBox.classList.add("d-none");
    try {
      if (id) {
        await api(`api/shifts.php?id=${id}`, { method: "PATCH", body: data });
        toast(`Shift “${data.name}” updated.`);
      } else {
        await api("api/shifts.php", { method: "POST", body: data });
        toast(`Shift “${data.name}” added.`);
      }
      resetForm();
      await load();
    } catch (error) {
      errorBox.textContent = error.message;
      errorBox.classList.remove("d-none");
    } finally {
      $("#shift-submit").disabled = false;
    }
  });

  $("#shift-cancel").addEventListener("click", resetForm);
  fields.start_time.addEventListener("input", updateLengthHint);
  fields.end_time.addEventListener("input", updateLengthHint);

  $("#shift-table").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const shift = shifts.find((s) => s.id === Number(button.closest("tr").dataset.id));
    if (button.dataset.action === "edit") {
      editShift(shift);
      return;
    }
    const ok = await confirm({ title: "Delete shift", message: `Delete the shift <strong>${esc(shift.name)}</strong>?`, confirmText: "Delete" });
    if (!ok) return;
    try {
      await api(`api/shifts.php?id=${shift.id}`, { method: "DELETE" });
      toast(`Shift “${shift.name}” deleted.`);
      if (fields.id.value === String(shift.id)) resetForm();
      await load();
    } catch (error) {
      toast(error.message, "error");
    }
  });

  load();
})();
