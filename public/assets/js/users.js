/* Users page: add accounts, change role, reset passwords, disable / enable. */
"use strict";

(() => {
  const { api, esc, emptyState, toast, confirm, formModal, $ } = App;

  const ROLES = { admin: "Administrator", viewer: "Viewer" };
  const MIN_PASSWORD = 10;
  let users = [];

  function lastLogin(value) {
    if (!value) return '<span class="text-muted">Never</span>';
    return esc(new Date(value.replace(" ", "T")).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" }));
  }

  async function load() {
    try {
      users = await api("api/users.php");
      render();
    } catch (error) {
      $("#user-table").innerHTML = emptyState(error.message, "fa-triangle-exclamation");
    }
  }

  function render() {
    if (!users.length) {
      $("#user-table").innerHTML = emptyState("No users.", "fa-users");
      return;
    }
    $("#user-table").innerHTML = `
      <div class="table-wrap">
        <table class="table-app">
          <thead><tr>
            <th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr></thead>
          <tbody>${users
            .map(
              (u) => `<tr data-id="${u.id}">
                <td>${esc(u.full_name)}<span class="cell-sub">${esc(u.username)}</span></td>
                <td><span class="role-badge role-${esc(u.role)}">${ROLES[u.role] ?? esc(u.role)}</span></td>
                <td>${
                  u.is_active
                    ? '<span class="status-badge status-balanced"><i class="fa fa-check" aria-hidden="true"></i>Active</span>'
                    : '<span class="status-badge status-terminated"><i class="fa fa-ban" aria-hidden="true"></i>Disabled</span>'
                }</td>
                <td>${lastLogin(u.last_login_at)}</td>
                <td class="row-actions">
                  <button type="button" class="btn-icon" data-action="edit" title="Edit" aria-label="Edit ${esc(u.username)}"><i class="fa fa-pen" aria-hidden="true"></i></button>
                  <button type="button" class="btn-icon" data-action="password" title="Set a new password" aria-label="Set a new password for ${esc(u.username)}"><i class="fa fa-key" aria-hidden="true"></i></button>
                  <button type="button" class="btn-icon${u.is_active ? " danger" : ""}" data-action="toggle"
                    title="${u.is_active ? "Disable" : "Enable"}" aria-label="${u.is_active ? "Disable" : "Enable"} ${esc(u.username)}">
                    <i class="fa ${u.is_active ? "fa-ban" : "fa-rotate-left"}" aria-hidden="true"></i></button>
                </td>
              </tr>`
            )
            .join("")}</tbody>
        </table>
      </div>`;
  }

  const roleOptions = (current) =>
    Object.entries(ROLES)
      .map(([value, label]) => `<option value="${value}"${value === current ? " selected" : ""}>${label}</option>`)
      .join("");

  const passwordFields = (label = "Password") => `
    <div class="col-md-6"><label class="form-label" for="u-password">${label}</label>
      <input id="u-password" name="password" type="password" class="form-control" minlength="${MIN_PASSWORD}" autocomplete="new-password" required></div>
    <div class="col-md-6"><label class="form-label" for="u-password2">Repeat password</label>
      <input id="u-password2" name="password2" type="password" class="form-control" autocomplete="new-password" required></div>
    <div class="col-12 form-text mt-1">At least ${MIN_PASSWORD} characters.</div>`;

  function checkPasswords(data) {
    if (data.password !== data.password2) throw new Error("The passwords do not match.");
    delete data.password2;
  }

  function addUser() {
    formModal({
      title: "Add user",
      submitText: "Add user",
      body: `<div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="u-name">Full name</label>
            <input id="u-name" name="full_name" class="form-control" maxlength="100" required></div>
          <div class="col-md-6"><label class="form-label" for="u-username">Username</label>
            <input id="u-username" name="username" class="form-control" maxlength="50" autocomplete="off" required></div>
          <div class="col-md-6"><label class="form-label" for="u-role">Role</label>
            <select id="u-role" name="role" class="form-select">${roleOptions("viewer")}</select></div>
          <div class="w-100"></div>
          ${passwordFields()}
        </div>`,
      onSubmit: async (data) => {
        checkPasswords(data);
        const created = await api("api/users.php", { method: "POST", body: data });
        toast(`User “${created.username}” added.`);
        await load();
      },
    });
  }

  function editUser(u) {
    formModal({
      title: `Edit ${u.username}`,
      submitText: "Save changes",
      body: `<div class="row g-3">
          <div class="col-md-7"><label class="form-label" for="u-name">Full name</label>
            <input id="u-name" name="full_name" class="form-control" maxlength="100" required value="${esc(u.full_name)}"></div>
          <div class="col-md-5"><label class="form-label" for="u-role">Role</label>
            <select id="u-role" name="role" class="form-select">${roleOptions(u.role)}</select></div>
        </div>`,
      onSubmit: async (data) => {
        await api(`api/users.php?id=${u.id}`, { method: "PATCH", body: data });
        toast("User updated.");
        await load();
      },
    });
  }

  function resetPassword(u) {
    formModal({
      title: `New password for ${u.username}`,
      submitText: "Set password",
      body: `<p>The user is signed out everywhere and must sign in with the new password.</p><div class="row g-3">${passwordFields("New password")}</div>`,
      onSubmit: async (data) => {
        checkPasswords(data);
        await api(`api/users.php?id=${u.id}`, { method: "PATCH", body: { password: data.password } });
        toast(`Password for “${u.username}” changed.`);
      },
    });
  }

  async function toggleUser(u) {
    if (u.is_active) {
      const ok = await confirm({
        title: "Disable user",
        message: `Disable <strong>${esc(u.full_name)}</strong> (${esc(u.username)})? They are signed out immediately and cannot sign in until enabled again.`,
        confirmText: "Disable",
      });
      if (!ok) return;
    }
    try {
      await api(`api/users.php?id=${u.id}`, { method: "PATCH", body: { is_active: !u.is_active } });
      toast(`User “${u.username}” ${u.is_active ? "disabled" : "enabled"}.`);
      await load();
    } catch (error) {
      toast(error.message, "error");
    }
  }

  $("#add-user").addEventListener("click", addUser);
  $("#user-table").addEventListener("click", (event) => {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const user = users.find((u) => u.id === Number(button.closest("tr").dataset.id));
    ({ edit: editUser, password: resetPassword, toggle: toggleUser })[button.dataset.action](user);
  });

  load();
})();
