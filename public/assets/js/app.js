'use strict';

/*
 * FindSync frontend — hash-routed vanilla JS SPA.
 * All API calls are same-origin, carry the session cookie, and writes carry CSRF.
 */

const API_BASE = 'api/index.php?route=';
const CATEGORIES = ['Wallet', 'ID Card', 'Keys', 'Electronics', 'Bag', 'Umbrella', 'Clothing', 'Other'];
const LOCATIONS = ['Library', 'Cafeteria', 'Main Gate', 'Computer Laboratory', 'Gymnasium', 'Classroom', 'Hallway', 'Other'];

const STATUS_TONES = {
  open: 'open', available: 'available', pending: 'pending', approved: 'approved',
  rejected: 'rejected', released: 'released', resolved: 'resolved', claimed: 'claimed',
  cancelled: 'cancelled', disposed: 'disposed'
};

const REASON_LABELS = {
  same_category: 'Same category',
  same_location: 'Same location',
  shared_keywords: 'Shared keywords',
  dates_near: 'Dates within 7 days'
};

let csrfToken = '';
let currentUser = null;
let loadSeq = 0;
let pendingAlert = null;
let activeModal = null;
let importResult = null;

const app = document.getElementById('app');
let view = null;
let alertsEl = null;
let modalRoot = null;

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
  timeZone: 'Asia/Manila',
  year: 'numeric', month: 'short', day: '2-digit',
  hour: '2-digit', minute: '2-digit', hour12: true
});

/* ------------------------------------------------------------------ */
/* Small helpers                                                       */
/* ------------------------------------------------------------------ */

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, function (ch) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
  });
}

function raw(html) {
  return { __html: String(html ?? '') };
}

function cell(value) {
  if (value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, '__html')) {
    return value.__html;
  }
  if (value === null || value === undefined || value === '') {
    return '<span class="muted">—</span>';
  }
  return esc(value);
}

function apiUrl(route) {
  return API_BASE + encodeURIComponent(route);
}

function sampleUrl(filename) {
  const path = String(location.pathname || '').toLowerCase();
  const inPublicFolder = path.endsWith('/public/') || path.endsWith('/public/index.php');
  return (inPublicFolder ? '../samples/' : 'samples/') + filename;
}

function normalizeHash() {
  return String(location.hash || '').replace(/^#\/?/, '');
}

function homeFor(role) {
  return role === 'staff' ? '#/staff/dashboard' : '#/student/dashboard';
}

function short(value, limit) {
  const text = String(value ?? '');
  const chars = Array.from(text);
  return chars.length <= limit ? text : chars.slice(0, limit - 1).join('') + '\u2026';
}

function todayISO() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
  }).formatToParts(new Date());
  const get = function (type) {
    const part = parts.find(function (p) { return p.type === type; });
    return part ? part.value : '';
  };
  return get('year') + '-' + get('month') + '-' + get('day');
}

/* Backend timestamps are UTC "YYYY-MM-DD HH:MM:SS"; event_date is displayed verbatim. */
function fmtTs(value) {
  if (!value) return '\u2014';
  let text = String(value).trim();
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(text)) {
    text = text.replace(' ', 'T') + 'Z';
  }
  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return String(value);
  return dateFormatter.format(date);
}

function pill(status) {
  const value = String(status ?? '').trim();
  const tone = STATUS_TONES[value.toLowerCase()] || 'neutral';
  return '<span class="pill pill--' + esc(tone) + '">' + esc(value || 'Unknown') + '</span>';
}

function scoreBadgeHtml(score) {
  const numeric = Number(score);
  const display = Number.isFinite(numeric) ? String(Math.round(numeric)) : String(score ?? '\u2014');
  return '<span class="score"><strong>' + esc(display) + '</strong><span>/100 heuristic</span></span>';
}

function reasonsHtml(reasons) {
  let list = [];
  if (Array.isArray(reasons)) {
    list = reasons;
  } else if (reasons && typeof reasons === 'object') {
    list = Object.keys(reasons).map(function (key) {
      const value = reasons[key];
      return key + ': ' + (value && typeof value === 'object' ? JSON.stringify(value) : value);
    });
  } else if (reasons) {
    list = [reasons];
  }
  if (!list.length) return '<p class="muted small">No score reasons supplied.</p>';
  return '<ul>' + list.map(function (item) {
    let text = item;
    if (item && typeof item === 'object') {
      text = item.reason || item.label || item.description || JSON.stringify(item);
    }
    const label = REASON_LABELS[text] || text;
    return '<li>' + esc(label) + '</li>';
  }).join('') + '</ul>';
}

function policyHtml(policy) {
  if (!policy) return '';
  if (typeof policy === 'string') {
    return '<details class="note"><summary>Matching policy</summary><p>' + esc(policy) + '</p></details>';
  }
  const entries = Array.isArray(policy)
    ? policy.map(function (item) { return ['rule', item]; })
    : Object.entries(policy);
  if (!entries.length) return '';
  return '<details class="note"><summary>Matching policy (heuristic, not proof of ownership)</summary><ul>' +
    entries.map(function (pair) {
      const value = pair[1] && typeof pair[1] === 'object' ? JSON.stringify(pair[1]) : pair[1];
      return '<li><strong>' + esc(String(pair[0]).replace(/_/g, ' ')) + ':</strong> ' + esc(value) + '</li>';
    }).join('') + '</ul></details>';
}

function statCard(label, value, tone) {
  return '<div class="stat ' + (tone ? 'stat--' + esc(tone) : '') + '">' +
    '<span class="stat-label">' + esc(label) + '</span>' +
    '<strong class="stat-value">' + esc(value ?? 0) + '</strong></div>';
}

function emptyState(title, hint) {
  return '<div class="empty"><strong>' + esc(title || 'Nothing here yet') + '</strong>' +
    (hint ? '<span>' + esc(hint) + '</span>' : '') + '</div>';
}

function loadingCard(label) {
  return '<div class="loading" role="status"><span class="spinner" aria-hidden="true"></span><span>' +
    esc(label || 'Loading\u2026') + '</span></div>';
}

function table(headers, rows, emptyTitle, emptyHint) {
  if (!rows.length) return emptyState(emptyTitle, emptyHint);
  return '<div class="table-scroll"><table><thead><tr>' +
    headers.map(function (header) { return '<th scope="col">' + esc(header) + '</th>'; }).join('') +
    '</tr></thead><tbody>' +
    rows.map(function (row) {
      return '<tr>' + row.map(function (value) { return '<td>' + cell(value) + '</td>'; }).join('') + '</tr>';
    }).join('') + '</tbody></table></div>';
}

function activityHtml(items) {
  const list = Array.isArray(items) ? items : [];
  if (!list.length) return emptyState('No recent activity', 'Actions will appear here as they happen.');
  return '<ul class="activity-list">' + list.map(function (item) {
    const label = item.action || item.message || item.details || item.type || 'Activity';
    const actor = item.actor_username || item.actor || '';
    const stamp = item.created_at || item.createdAt || item.timestamp || item.time || '';
    return '<li><span class="activity-dot" aria-hidden="true"></span><div><p>' + esc(label) + '</p>' +
      '<p class="muted small">' + (actor ? esc(actor) + ' \u00b7 ' : '') + esc(fmtTs(stamp)) + '</p></div></li>';
  }).join('') + '</ul>';
}

function field(name, label, control, options) {
  const opts = options || {};
  return '<div class="field' + (opts.full ? ' full' : '') + '">' +
    '<label for="f-' + esc(name) + '">' + esc(label) + '</label>' + control +
    (opts.hint ? '<p class="help">' + esc(opts.hint) + '</p>' : '') +
    (opts.error === false ? '' : '<p class="field-error" data-error-for="' + esc(name) + '" aria-live="polite"></p>') +
    '</div>';
}

function selectControl(name, values, selected, options) {
  const opts = options || {};
  const entries = values.map(function (entry) {
    if (entry && typeof entry === 'object') return { value: entry.value, label: entry.label };
    return { value: entry, label: entry };
  });
  return '<select id="f-' + esc(name) + '" name="' + esc(name) + '"' +
    (opts.required ? ' required' : '') + '>' +
    (opts.placeholder ? '<option value="">' + esc(opts.placeholder) + '</option>' : '') +
    entries.map(function (entry) {
      return '<option value="' + esc(entry.value) + '"' +
        (String(entry.value) === String(selected ?? '') ? ' selected' : '') + '>' +
        esc(entry.label) + '</option>';
    }).join('') + '</select>';
}

function reportFormHtml(kind, isStaff, students) {
  const noun = kind === 'lost' ? 'lost report' : 'found item';
  const mustChooseStudent = isStaff && kind === 'lost';
  const ownerField = isStaff
    ? field('student_id', kind === 'lost' ? 'Owner student' : 'Finder student (optional)',
        selectControl('student_id', (students || []).map(function (s) {
          return { value: s.id, label: s.student_number + ' \u2014 ' + s.full_name };
        }), '', {
          required: mustChooseStudent,
          placeholder: kind === 'lost' ? 'Select a student' : 'Anonymous finder'
        }),
        {
          full: true,
          hint: kind === 'lost'
            ? 'Lost reports must reference a student in the registry.'
            : 'Leave blank to record an anonymous finder.'
        })
    : '';
  const noStudents = mustChooseStudent && !(students || []).length
    ? '<p class="note note--warn">Add a student in the Students screen before creating a lost report.</p>'
    : '';
  return '<form data-form="report-' + esc(kind) + '" data-staff="' + (isStaff ? 'true' : 'false') + '" class="form-grid">' +
    field('category', 'Category', selectControl('category', CATEGORIES, '', { required: true, placeholder: 'Select category' })) +
    field('location', 'Location', selectControl('location', LOCATIONS, '', { required: true, placeholder: 'Select location' })) +
    field('event_date', kind === 'lost' ? 'Date lost' : 'Date found',
      '<input id="f-event_date" name="event_date" type="date" required max="' + esc(todayISO()) + '">') +
    ownerField +
    field('description', 'Description',
      '<textarea id="f-description" name="description" rows="4" required minlength="5" maxlength="500" ' +
      'placeholder="Distinguishing details, colour, brand, contents\u2026"></textarea>',
      { full: true, hint: 'Avoid sensitive personal information; 5\u2013500 characters.' }) +
    noStudents +
    '<div class="form-actions"><button type="submit" class="btn btn--primary">Submit ' + esc(noun) + '</button>' +
    '<span class="muted small">The server validates all fields.</span></div></form>';
}

/* ------------------------------------------------------------------ */
/* Alerts, loading, navigation                                         */
/* ------------------------------------------------------------------ */

function showAlert(type, message) {
  if (!alertsEl) return;
  alertsEl.innerHTML = '<div class="alert alert--' + esc(type) + '" role="' +
    (type === 'error' ? 'alert' : 'status') + '"><span>' + esc(message) + '</span>' +
    '<button type="button" class="alert-close" data-action="dismiss-alert" aria-label="Dismiss message">&times;</button></div>';
}

function clearAlert() {
  if (alertsEl) alertsEl.innerHTML = '';
}

function queueAlert(type, message) {
  pendingAlert = { type: type, message: message };
}

function flushAlert() {
  if (!pendingAlert) return;
  const item = pendingAlert;
  pendingAlert = null;
  showAlert(item.type, item.message);
}

function beginLoad(label) {
  const seq = ++loadSeq;
  if (view) view.innerHTML = loadingCard(label);
  return function () { return seq === loadSeq; };
}

function reportError(error, form) {
  if (error && error.handled) return;
  if (form && error && error.errors && typeof error.errors === 'object') {
    Object.keys(error.errors).forEach(function (key) {
      const node = form.querySelector('[data-error-for="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
      if (node) node.textContent = Array.isArray(error.errors[key]) ? error.errors[key].join(' ') : String(error.errors[key]);
    });
  }
  showAlert('error', (error && error.message) ? error.message : 'Something went wrong. Please try again.');
}

function closeNav() {
  const sidebar = document.getElementById('sidebar');
  const scrim = document.getElementById('scrim');
  const toggle = document.getElementById('menuToggle');
  if (sidebar) sidebar.classList.remove('open');
  if (scrim) scrim.hidden = true;
  if (toggle) toggle.setAttribute('aria-expanded', 'false');
}

function toggleNav() {
  const sidebar = document.getElementById('sidebar');
  const scrim = document.getElementById('scrim');
  const toggle = document.getElementById('menuToggle');
  if (!sidebar) return;
  const open = sidebar.classList.toggle('open');
  if (scrim) scrim.hidden = !open;
  if (toggle) toggle.setAttribute('aria-expanded', String(open));
}

function navFor(role) {
  if (role === 'staff') {
    return [
      { path: '#/staff/dashboard', label: 'Dashboard', icon: '\u25a4' },
      { path: '#/staff/lost', label: 'All Lost', icon: '\u2315' },
      { path: '#/staff/found', label: 'All Found', icon: '\u25c8' },
      { path: '#/staff/students', label: 'Students', icon: '\u265f' },
      { path: '#/staff/matches', label: 'Match Review', icon: '\u25ce' },
      { path: '#/staff/claims', label: 'Claim Queue', icon: '\u2713' },
      { path: '#/staff/import', label: 'CSV Import', icon: '\u21ea' },
      { path: '#/staff/export', label: 'Reports / Export', icon: '\u21e9' },
      { path: '#/staff/audit', label: 'Audit Logs', icon: '\u2263' }
    ];
  }
  return [
    { path: '#/student/dashboard', label: 'Dashboard', icon: '\u25a4' },
    { path: '#/student/report-lost', label: 'Report Lost', icon: '\u2315' },
    { path: '#/student/report-found', label: 'Report Found', icon: '\u25c8' },
    { path: '#/student/reports', label: 'My Reports', icon: '\u2630' },
    { path: '#/student/matches', label: 'Suggested Matches', icon: '\u25ce' },
    { path: '#/student/claims', label: 'My Claims', icon: '\u2713' },
    { path: '#/student/profile', label: 'Profile', icon: '\u265e' }
  ];
}

function renderPortal(title, subtitle) {
  const role = currentUser && currentUser.role === 'staff' ? 'staff' : 'student';
  const nav = navFor(role);
  const current = '#/' + normalizeHash();
  const user = currentUser || {};
  const displayName = user.full_name || user.username || 'Account';

  app.innerHTML =
    '<div class="app-shell">' +
      '<aside class="sidebar" id="sidebar">' +
        '<div class="brand"><span class="brand-mark" aria-hidden="true">FS</span><div>' +
          '<strong>FindSync</strong><small>Campus Lost &amp; Found</small></div></div>' +
        '<nav class="side-nav" aria-label="Main navigation">' +
          '<p class="nav-label">Workspace</p>' +
          nav.map(function (item) {
            const active = item.path === current;
            return '<a class="nav-link' + (active ? ' active' : '') + '" href="' + esc(item.path) + '"' +
              (active ? ' aria-current="page"' : '') + '><span class="nav-icon" aria-hidden="true">' +
              esc(item.icon) + '</span><span>' + esc(item.label) + '</span></a>';
          }).join('') +
        '</nav>' +
        '<div class="side-foot"><div class="side-user">' +
          '<span class="avatar" aria-hidden="true">' + esc(Array.from(displayName)[0] || '?') + '</span>' +
          '<div><strong>' + esc(displayName) + '</strong><small>' + esc(user.role || '') + '</small></div></div>' +
          '<button type="button" class="btn btn--ghost btn--block" data-action="logout">Sign out</button>' +
        '</div>' +
      '</aside>' +
      '<div class="scrim" id="scrim" data-action="close-nav" hidden></div>' +
      '<div class="main-wrap">' +
        '<header class="topbar">' +
          '<button type="button" id="menuToggle" class="icon-btn" data-action="toggle-nav" ' +
            'aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar">&#9776;</button>' +
          '<div class="topbar-title"><p class="eyebrow">' +
            esc(role === 'staff' ? 'Staff portal' : 'Student portal') + '</p><h1>' + esc(title) + '</h1>' +
            (subtitle ? '<p class="muted">' + esc(subtitle) + '</p>' : '') + '</div>' +
          '<div class="topbar-meta"><span class="pill pill--soft">' + esc(user.username || '') + '</span></div>' +
        '</header>' +
        '<main id="main" tabindex="-1">' +
          '<div id="alerts" aria-live="polite"></div><div id="view"></div>' +
        '</main>' +
        '<footer class="footer">FindSync \u00b7 Local EDM demonstration \u00b7 Fictional data only</footer>' +
      '</div>' +
    '</div><div id="modalRoot"></div>';

  view = document.getElementById('view');
  alertsEl = document.getElementById('alerts');
  modalRoot = document.getElementById('modalRoot');
  flushAlert();
}

function renderAuth(mode) {
  const register = mode === 'register';
  const form = register
    ? '<form data-form="register" class="form-grid">' +
        field('student_number', 'Student number',
          '<input id="f-student_number" name="student_number" type="text" required minlength="3" maxlength="32" pattern="[A-Za-z0-9-]{3,32}" placeholder="2026-1001">',
          { full: true, hint: '3\u201332 letters, numbers or hyphens.' }) +
        field('full_name', 'Full name',
          '<input id="f-full_name" name="full_name" type="text" required minlength="3" maxlength="120" autocomplete="name" placeholder="Fictional student name">',
          { full: true }) +
        field('program', 'Program',
          '<input id="f-program" name="program" type="text" required minlength="2" maxlength="120" placeholder="BSIT">',
          { full: true }) +
        field('username', 'Username',
          '<input id="f-username" name="username" type="text" required minlength="3" maxlength="80" pattern="[A-Za-z0-9_.-]{3,80}" autocomplete="username" placeholder="ana.reyes">',
          { full: true }) +
        field('password', 'Password',
          '<input id="f-password" name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">',
          { full: true }) +
        '<div class="form-actions"><button type="submit" class="btn btn--primary btn--block">Create student account</button></div>' +
      '</form>'
    : '<form data-form="login" class="form-grid">' +
        field('username', 'Username',
          '<input id="f-username" name="username" type="text" required maxlength="80" autocomplete="username" placeholder="Your username">',
          { full: true }) +
        field('password', 'Password',
          '<input id="f-password" name="password" type="password" required minlength="8" autocomplete="current-password" placeholder="Your password">',
          { full: true }) +
        '<div class="form-actions"><button type="submit" class="btn btn--primary btn--block">Sign in</button></div>' +
      '</form>';

  app.innerHTML =
    '<div class="auth-page">' +
      '<section class="auth-panel">' +
        '<span class="brand-mark" aria-hidden="true">FS</span>' +
        '<h1>One place for campus lost &amp; found.</h1>' +
        '<p>Report an item, review explainable candidate matches, and let the lost-and-found office verify every claim.</p>' +
        '<ul class="auth-features"><li><span aria-hidden="true">&#10003;</span> Student and staff portals</li>' +
        '<li><span aria-hidden="true">&#10003;</span> Rule-based match suggestions</li>' +
        '<li><span aria-hidden="true">&#10003;</span> Audited claim verification</li></ul>' +
      '</section>' +
      '<section class="auth-side"><div class="auth-card">' +
        '<p class="eyebrow">FindSync</p>' +
        '<h2>' + (register ? 'Create your account' : 'Welcome back') + '</h2>' +
        '<p>' + (register ? 'Register with a fictional student number to use the student portal.' :
          'Sign in with your student or staff credentials.') + '</p>' +
        '<div id="alerts" aria-live="polite"></div>' + form +
        '<p class="auth-switch">' + (register
          ? 'Already registered? <a href="#/login">Sign in</a>'
          : 'New student? <a href="#/register">Create an account</a>') + '</p>' +
        '<p class="auth-note">Demonstration only. Use fictional records; staff accounts are provided by the setup.</p>' +
      '</div></section>' +
    '</div><div id="modalRoot"></div>';

  view = null;
  alertsEl = document.getElementById('alerts');
  modalRoot = document.getElementById('modalRoot');
  flushAlert();
}

/* ------------------------------------------------------------------ */
/* API                                                                 */
/* ------------------------------------------------------------------ */

async function refreshCsrf() {
  let response;
  try {
    response = await fetch(apiUrl('auth/csrf'), { credentials: 'same-origin', cache: 'no-store' });
  } catch (error) {
    throw new Error('Cannot reach the server. Start Apache and check the FindSync URL.');
  }
  if (!response.ok) throw new Error('Could not initialise the security token.');
  let payload;
  try { payload = await response.json(); } catch (error) {
    throw new Error('The security token response was unreadable.');
  }
  if (!payload || payload.success !== true || !payload.data || !payload.data.csrfToken) {
    throw new Error('The security token response was invalid.');
  }
  csrfToken = payload.data.csrfToken;
}

async function api(route, options) {
  const opts = options || {};
  const method = String(opts.method || 'GET').toUpperCase();
  const headers = {};
  if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken;

  const fetchOptions = { method: method, headers: headers, credentials: 'same-origin' };
  if (opts.form) {
    fetchOptions.body = opts.form;
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    fetchOptions.body = JSON.stringify(opts.body);
  }

  let response;
  try {
    response = await fetch(apiUrl(route), fetchOptions);
  } catch (error) {
    throw new Error('Cannot connect to the server. Check your local web server and database.');
  }

  let payload;
  try { payload = await response.json(); } catch (error) {
    throw new Error('The server returned an unreadable response.');
  }
  if (!payload || typeof payload.success !== 'boolean') {
    throw new Error('Unexpected response from the server.');
  }
  if (payload.success) return payload.data;

  const errorData = payload.error || {};
  const message = String(errorData.message || 'The request could not be completed.');

  if (response.status === 401) {
    currentUser = null;
    queueAlert('note', message || 'Your session expired. Please sign in again.');
    if (location.hash !== '#/login') location.hash = '#/login';
    else route();
    const error = new Error(message);
    error.code = errorData.code || 'UNAUTHENTICATED';
    error.handled = true;
    throw error;
  }

  if (response.status === 403 && errorData.code === 'CSRF_INVALID' && opts.retryCsrf !== false) {
    await refreshCsrf();
    showAlert('note', 'Your security token was refreshed. Retrying that request\u2026');
    return api(route, {
      method: method, body: opts.body, form: opts.form, retryCsrf: false
    });
  }

  showAlert('error', message);
  const error = new Error(message);
  error.code = errorData.code || 'ERROR';
  error.errors = errorData.errors || {};
  error.status = response.status;
  throw error;
}

async function refreshView(message) {
  if (message) queueAlert('success', message);
  await route();
}

async function goHome() {
  const target = homeFor(currentUser && currentUser.role);
  if (location.hash !== target) location.hash = target;
  else await route();
}

/* ------------------------------------------------------------------ */
/* Modal                                                               */
/* ------------------------------------------------------------------ */

function closeModal() {
  activeModal = null;
  if (modalRoot) modalRoot.innerHTML = '';
}

function openModal(config) {
  if (!modalRoot) return;
  activeModal = config;
  const footer = config.footerHtml || (
    '<button type="button" class="btn btn--ghost" data-action="modal-cancel">Cancel</button>' +
    '<button type="button" class="btn ' + (config.danger ? 'btn--danger' : 'btn--primary') +
    '" data-action="modal-confirm">' + esc(config.confirmLabel || 'Confirm') + '</button>'
  );
  modalRoot.innerHTML =
    '<div class="modal-backdrop" data-action="modal-backdrop">' +
      '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" data-action="modal-noop">' +
        '<div class="modal-header"><h2 id="modalTitle">' + esc(config.title || 'Confirm') + '</h2>' +
        '<button type="button" class="modal-close" data-action="modal-cancel" aria-label="Close dialog">&times;</button></div>' +
        config.bodyHtml + '<div class="modal-footer">' + footer + '</div>' +
      '</div></div>';
  requestAnimationFrame(function () {
    const first = modalRoot.querySelector('.modal input:not([type="hidden"]), .modal textarea, .modal select, .modal button');
    if (first) first.focus();
  });
}

async function confirmModal(button) {
  if (!activeModal || !button) return;
  const oldLabel = button.textContent;
  button.disabled = true;
  button.textContent = 'Working\u2026';
  try {
    const result = await activeModal.onConfirm(modalRoot.querySelector('.modal'));
    if (result !== false) closeModal();
  } catch (error) {
    reportError(error);
  } finally {
    if (button.isConnected) {
      button.disabled = false;
      button.textContent = oldLabel;
    }
  }
}

function openClaimModal(match) {
  const payload = {
    lost_report_id: match.lost_report_id,
    found_item_id: match.found_item_id,
    category: match.category,
    location: match.location,
    event_date: match.event_date
  };
  openModal({
    title: 'Request claim',
    bodyHtml:
      '<div class="note">' +
        '<p>You are requesting the found item <strong>#' + esc(match.found_item_id) + '</strong> for your lost report <strong>#' +
        esc(match.lost_report_id) + '</strong>.</p>' +
        '<p class="small">Category: ' + esc(match.category || '\u2014') + ' \u00b7 Location: ' +
        esc(match.location || '\u2014') + ' \u00b7 Date: ' + esc(match.event_date || '\u2014') + '</p>' +
      '</div>' +
      '<form data-form="claim" class="form-grid" style="margin-top:1rem">' +
        '<input type="hidden" name="lost_report_id" value="' + esc(match.lost_report_id) + '">' +
        '<input type="hidden" name="found_item_id" value="' + esc(match.found_item_id) + '">' +
        field('ownership_evidence', 'Ownership evidence',
          '<textarea id="f-ownership_evidence" name="ownership_evidence" rows="5" required minlength="10" maxlength="2000" ' +
          'placeholder="Describe a unique detail only the owner would know\u2026"></textarea>',
          { full: true, hint: 'Private to you and staff. 10\u20132000 characters.' }) +
        '<div class="form-actions"><button type="submit" class="btn btn--primary">Submit claim request</button></div>' +
      '</form>',
    footerHtml: '<button type="button" class="btn btn--ghost" data-action="modal-cancel">Cancel</button>'
  });
}

function openReviewModal(kind, id) {
  const approve = kind === 'approve';
  openModal({
    title: approve ? 'Approve claim #' + id : 'Reject claim #' + id,
    danger: !approve,
    confirmLabel: approve ? 'Approve claim' : 'Reject claim',
    bodyHtml:
      '<p>' + (approve
        ? 'Approve only after you have compared the private ownership evidence with the records. This does not release the item.'
        : 'The student will see the reason you provide.') + '</p>' +
      '<div class="field full"><label for="f-reviewText">' + (approve ? 'Staff notes (optional)' : 'Rejection reason') + '</label>' +
      '<textarea id="f-reviewText" rows="4" maxlength="1000"' + (approve ? '' : ' required') + '></textarea>' +
      '<p class="field-error" data-error-for="reason" aria-live="polite"></p></div>',
    onConfirm: async function (modal) {
      const value = modal.querySelector('#f-reviewText').value.trim();
      if (!approve && !value) {
        showAlert('error', 'A rejection reason is required.');
        return false;
      }
      const body = approve ? { staff_notes: value } : { reason: value };
      await api('claims/' + encodeURIComponent(id) + '/' + kind, { method: 'POST', body: body });
      queueAlert('success', 'Claim #' + id + (approve ? ' approved.' : ' rejected.'));
      await route();
    }
  });
}

function openReleaseModal(id) {
  openModal({
    title: 'Record physical release',
    danger: true,
    confirmLabel: 'Yes, record release',
    bodyHtml:
      '<div class="note note--warn"><strong>This is a separate step after approval.</strong> ' +
      'Recording a release marks the lost report resolved and the found item claimed. It cannot be undone here.</div>' +
      '<label class="check" style="margin-top:1rem"><input type="checkbox" id="f-releaseConfirm">' +
      '<span>I have verified the claimant and physically handed over the item.</span></label>',
    onConfirm: async function (modal) {
      const box = modal.querySelector('#f-releaseConfirm');
      if (!box || !box.checked) {
        showAlert('error', 'Confirm the physical handover before recording a release.');
        return false;
      }
      await api('claims/' + encodeURIComponent(id) + '/release', { method: 'POST', body: { confirm: true } });
      queueAlert('success', 'Release recorded for claim #' + id + '. Statuses are now synchronised.');
      await route();
    }
  });
}

/* ------------------------------------------------------------------ */
/* Routing                                                             */
/* ------------------------------------------------------------------ */

async function route() {
  closeModal();
  closeNav();

  if (!currentUser) {
    const hash = normalizeHash();
    if (hash !== 'login' && hash !== 'register') {
      history.replaceState(null, '', '#/login');
    }
    renderAuth(normalizeHash() === 'register' ? 'register' : 'login');
    document.title = 'Sign in \u00b7 FindSync';
    return;
  }

  const role = currentUser.role === 'staff' ? 'staff' : 'student';
  const nav = navFor(role);
  const allowed = nav.map(function (item) { return item.path.slice(2); });
  let path = normalizeHash();
  if (!path || path === 'login' || path === 'register' || allowed.indexOf(path) === -1) {
    path = role === 'staff' ? 'staff/dashboard' : 'student/dashboard';
    history.replaceState(null, '', '#/' + path);
  }
  const item = nav.find(function (entry) { return entry.path === '#/' + path; });
  renderPortal(item ? item.label : 'Dashboard', role === 'staff'
    ? 'Consolidated records, verification and integration overview'
    : 'Track your reports, matches and claims');
  document.title = (item ? item.label : 'Dashboard') + ' \u00b7 FindSync';

  try {
    switch (path) {
      case 'student/dashboard': await studentDashboard(); break;
      case 'student/report-lost': await reportPage('lost', false); break;
      case 'student/report-found': await reportPage('found', false); break;
      case 'student/reports': await myReportsScreen(); break;
      case 'student/matches': await studentMatchesScreen(); break;
      case 'student/claims': await claimsScreen(false); break;
      case 'student/profile': await profileScreen(); break;
      case 'staff/dashboard': await staffDashboard(); break;
      case 'staff/lost': await recordsScreen('lost', true); break;
      case 'staff/found': await recordsScreen('found', true); break;
      case 'staff/students': await studentsScreen(); break;
      case 'staff/matches': await staffMatchesScreen(); break;
      case 'staff/claims': await claimsScreen(true); break;
      case 'staff/import': await importScreen(); break;
      case 'staff/export': await exportScreen(); break;
      case 'staff/audit': await auditScreen(); break;
      default: view.innerHTML = emptyState('Screen not found', 'Choose a screen from the navigation.');
    }
  } catch (error) {
    reportError(error);
  }
}

/* ------------------------------------------------------------------ */
/* Dashboards                                                          */
/* ------------------------------------------------------------------ */

async function studentDashboard() {
  const isCurrent = beginLoad('Loading your dashboard\u2026');
  try {
    const data = await api('me/dashboard');
    if (!isCurrent()) return;
    const greeting = currentUser.full_name ? ', ' + String(currentUser.full_name).split(/\s+/)[0] : '';
    view.innerHTML =
      '<div class="note">Welcome back' + esc(greeting) + '. Matching suggestions are hints only \u2014 staff verify every claim.</div>' +
      '<div class="stats-grid" style="margin-top:1rem">' +
        statCard('My open lost', data.my_lost_open, '') +
        statCard('My found available', data.my_found_available, 'teal') +
        statCard('Pending claims', data.my_pending_claims, 'warn') +
        statCard('Released claims', data.my_released_claims, 'green') +
      '</div>' +
      '<div class="grid-two">' +
        '<section class="card"><h2>Recent activity</h2>' + activityHtml(data.recent_activity) + '</section>' +
        '<section class="card"><h2>Quick actions</h2><div class="stack">' +
          '<a class="btn btn--primary" href="#/student/report-lost">Report a lost item</a>' +
          '<a class="btn" href="#/student/report-found">Report a found item</a>' +
          '<a class="btn" href="#/student/matches">View suggested matches</a>' +
          '<a class="btn" href="#/student/claims">Check my claims</a>' +
        '</div></section>' +
      '</div>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

async function staffDashboard() {
  const isCurrent = beginLoad('Loading consolidated dashboard\u2026');
  try {
    const data = await api('dashboard');
    if (!isCurrent()) return;
    const imports = Array.isArray(data.latest_imports) ? data.latest_imports : [];
    const importRows = imports.map(function (batch) {
      return [
        '#' + batch.id,
        batch.source_type || '\u2014',
        batch.filename || '\u2014',
        (batch.accepted_rows ?? 0) + ' / ' + (batch.total_rows ?? 0),
        batch.rejected_rows ?? 0,
        fmtTs(batch.created_at)
      ];
    });
    view.innerHTML =
      '<div class="stats-grid">' +
        statCard('Open lost reports', data.open_lost, '') +
        statCard('Available found items', data.available_found, 'teal') +
        statCard('Candidate matches', data.candidate_matches, 'warn') +
        statCard('Completed claims', data.completed_claims, 'green') +
      '</div>' +
      '<div class="grid-two">' +
        '<section class="card"><h2>Recent activity</h2>' + activityHtml(data.recent_activity) + '</section>' +
        '<section class="card"><div class="spread"><h2>Latest imports</h2>' +
          '<a class="btn btn--small" href="#/staff/import">Open import</a></div>' +
          table(['Batch', 'Type', 'File', 'Accepted / total', 'Rejected', 'Created'], importRows,
            'No imports yet', 'CSV batches will appear here.') +
        '</section>' +
      '</div>' +
      '<section class="card"><div class="spread"><div><h2>Reporting</h2>' +
        '<p class="muted small">Download the consolidated CSV built from committed database records.</p></div>' +
        '<a class="btn btn--teal" href="' + esc(apiUrl('reports/export')) + '" download>Export CSV report</a></div></section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

/* ------------------------------------------------------------------ */
/* Reports                                                             */
/* ------------------------------------------------------------------ */

async function reportPage(kind, isStaff) {
  const isCurrent = beginLoad('Preparing form\u2026');
  let students = [];
  if (isStaff) {
    try {
      const data = await api('students');
      students = Array.isArray(data.students) ? data.students : [];
    } catch (error) {
      reportError(error);
    }
  }
  if (!isCurrent()) return;
  const heading = kind === 'lost' ? 'Report a lost item' : 'Report a found item';
  view.innerHTML =
    '<div class="grid-two"><section class="card"><h2>' + esc(heading) + '</h2>' +
      reportFormHtml(kind, isStaff, students) + '</section>' +
      '<section class="card"><h2>Before you submit</h2>' +
        '<ul class="reasons"><li>Use one of the fixed categories and locations.</li>' +
        '<li>Describe distinguishing features, not private contact details.</li>' +
        '<li>Dates use the campus timezone (Asia/Manila).</li>' +
        '<li>New records are matched with existing opposite-side reports.</li></ul>' +
        '<p class="note">Matching is rule-based and never proves ownership.</p>' +
      '</section></div>';
}

function reportTable(kind, records, staff) {
  const headers = staff
    ? ['ID', kind === 'lost' ? 'Student #' : 'Finder #', kind === 'lost' ? 'Student' : 'Finder',
       'Category', 'Description', 'Location', 'Date', 'Status', 'Source', 'Created']
    : ['ID', 'Category', 'Description', 'Location', 'Date', 'Status', 'Source', 'Created'];
  const rows = (records || []).map(function (record) {
    const base = [
      '#' + record.id,
      record.category || '\u2014',
      short(record.description, 110),
      record.location || '\u2014',
      record.event_date || '\u2014',
      raw(pill(record.status)),
      record.source || '\u2014',
      fmtTs(record.created_at)
    ];
    if (!staff) return base;
    const ownerNumber = kind === 'lost' ? record.student_number : record.finder_number;
    const ownerName = kind === 'lost' ? record.student_name : record.finder_name;
    return ['#' + record.id, ownerNumber || '\u2014', ownerName || '\u2014'].concat(base.slice(1));
  });
  return table(headers, rows,
    kind === 'lost' ? 'No lost reports yet' : 'No found items yet',
    staff ? 'Records from forms and CSV imports will appear here.' : 'Submit a report to see it here.');
}

async function myReportsScreen() {
  const isCurrent = beginLoad('Loading your reports\u2026');
  try {
    const results = await Promise.all([api('lost'), api('found')]);
    if (!isCurrent()) return;
    view.innerHTML =
      '<section class="card"><div class="spread"><h2>My lost reports</h2>' +
        '<a class="btn btn--small btn--primary" href="#/student/report-lost">New lost report</a></div>' +
        reportTable('lost', results[0].reports, false) + '</section>' +
      '<section class="card"><div class="spread"><h2>My found items</h2>' +
        '<a class="btn btn--small" href="#/student/report-found">New found report</a></div>' +
        reportTable('found', results[1].items, false) + '</section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

async function recordsScreen(kind, isStaff) {
  const isCurrent = beginLoad('Loading records\u2026');
  try {
    const calls = [api(kind)];
    if (isStaff) calls.push(api('students'));
    const results = await Promise.all(calls);
    if (!isCurrent()) return;
    const records = kind === 'lost' ? (results[0].reports || []) : (results[0].items || []);
    const students = isStaff ? (results[1].students || []) : [];
    view.innerHTML =
      '<section class="card"><h2>' + esc(kind === 'lost' ? 'All lost reports' : 'All found items') + '</h2>' +
        reportTable(kind, records, true) + '</section>' +
      (isStaff ? '<section class="card"><h2>Create ' + esc(kind === 'lost' ? 'lost report' : 'found item') +
        '</h2>' + reportFormHtml(kind, true, students) + '</section>' : '');
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

/* ------------------------------------------------------------------ */
/* Matches                                                             */
/* ------------------------------------------------------------------ */

function matchCardHtml(match) {
  const payload = {
    lost_report_id: match.lost_report_id,
    found_item_id: match.found_item_id,
    category: match.category,
    location: match.location,
    event_date: match.event_date
  };
  return '<article class="card match-card">' +
    '<div class="card-head"><div><p class="eyebrow">Candidate match</p>' +
      '<h2>' + esc(match.category || 'Item') + ' \u00b7 ' + esc(match.location || 'Unknown location') + '</h2></div>' +
      scoreBadgeHtml(match.score) + '</div>' +
    '<dl class="detail-list">' +
      '<div><dt>Lost report</dt><dd>#' + esc(match.lost_report_id) + '</dd></div>' +
      '<div><dt>Found item</dt><dd>#' + esc(match.found_item_id) + '</dd></div>' +
      '<div><dt>Event date</dt><dd>' + esc(match.event_date || '\u2014') + '</dd></div>' +
      '<div><dt>Safe preview</dt><dd>' + esc(match.description_preview || 'Limited details') + '</dd></div>' +
    '</dl>' +
    '<div class="reasons"><p class="label">Why suggested</p>' + reasonsHtml(match.reasons) + '</div>' +
    '<div class="card-actions"><button type="button" class="btn btn--primary" data-action="open-claim" ' +
      'data-match="' + esc(encodeURIComponent(JSON.stringify(payload))) + '">Request claim</button></div>' +
  '</article>';
}

async function studentMatchesScreen() {
  const isCurrent = beginLoad('Loading suggested matches\u2026');
  try {
    const data = await api('matches');
    if (!isCurrent()) return;
    const matches = Array.isArray(data.matches) ? data.matches : [];
    view.innerHTML = policyHtml(data.policy) +
      '<div class="note">Finder names, numbers and full descriptions are intentionally hidden at this stage.</div>' +
      (matches.length
        ? matches.map(matchCardHtml).join('')
        : emptyState('No suggested matches', 'Matches appear when an open lost report and an available found item align.'));
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

async function staffMatchesScreen() {
  const isCurrent = beginLoad('Loading match review\u2026');
  try {
    const data = await api('matches');
    if (!isCurrent()) return;
    const matches = Array.isArray(data.matches) ? data.matches : [];
    const rows = matches.map(function (match) {
      return [
        '#' + match.lost_report_id,
        '#' + match.found_item_id,
        match.lost_student_number || '\u2014',
        short(match.lost_description, 140),
        short(match.found_description, 140),
        match.finder_name || 'Anonymous',
        match.finder_number || '\u2014',
        raw(scoreBadgeHtml(match.score)),
        raw(reasonsHtml(match.reasons))
      ];
    });
    view.innerHTML = policyHtml(data.policy) +
      '<section class="card"><h2>Candidate pairs (' + matches.length + ')</h2>' +
      table(['Lost', 'Found', 'Lost student #', 'Lost description', 'Found description',
        'Finder', 'Finder #', 'Score', 'Reasons'], rows,
        'No candidate matches', 'Pairs appear here when open lost and available found records align.') +
      '<p class="note">Scores are deterministic hints, not calibrated probabilities or ownership proof. ' +
      'Private ownership evidence is only shown in the claim queue.</p></section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

/* ------------------------------------------------------------------ */
/* Claims                                                              */
/* ------------------------------------------------------------------ */

function claimActions(claim) {
  const id = esc(claim.id);
  let html = '';
  if (claim.status === 'pending') {
    html += '<button type="button" class="btn btn--small btn--primary" data-action="approve-claim" data-id="' + id + '">Approve</button>';
  }
  if (claim.status === 'pending' || claim.status === 'approved') {
    html += '<button type="button" class="btn btn--small btn--danger" data-action="reject-claim" data-id="' + id + '">Reject</button>';
  }
  if (claim.status === 'approved') {
    html += '<button type="button" class="btn btn--small btn--teal" data-action="release-claim" data-id="' + id + '">Release</button>';
  }
  return html || '<span class="muted">No actions</span>';
}

function claimsTable(claims, staff) {
  const headers = staff
    ? ['Claim', 'Lost', 'Found', 'Status', 'Requester', 'Ownership evidence', 'Staff notes', 'Created', 'Reviewed', 'Actions']
    : ['Claim', 'Lost', 'Found', 'Status', 'Ownership evidence', 'Staff notes', 'Created', 'Reviewed', 'Released'];
  const rows = (claims || []).map(function (claim) {
    const common = [
      '#' + claim.id,
      '#' + claim.lost_report_id,
      '#' + claim.found_item_id,
      raw(pill(claim.status)),
      short(claim.ownership_evidence, 120),
      claim.staff_notes ? short(claim.staff_notes, 100) : '\u2014',
      fmtTs(claim.created_at),
      fmtTs(claim.reviewed_at)
    ];
    if (staff) {
      return common.concat([claim.requester_username || '\u2014', raw(claimActions(claim))]);
    }
    return common.concat([fmtTs(claim.released_at)]);
  });
  return table(headers, rows, 'No claims yet',
    staff ? 'Student claim requests will appear here for review.' : 'Request a claim from a suggested match to see it here.');
}

async function claimsScreen(staff) {
  const isCurrent = beginLoad('Loading claims\u2026');
  try {
    const data = await api('claims');
    if (!isCurrent()) return;
    const claims = Array.isArray(data.claims) ? data.claims : [];
    view.innerHTML = '<section class="card"><div class="spread"><h2>' +
      (staff ? 'Claim queue' : 'My claims') + ' (' + claims.length + ')</h2>' +
      (staff
        ? '<span class="muted small">Approve first \u2014 release is always a separate step.</span>'
        : '<a class="btn btn--small" href="#/student/matches">Find a match</a>') +
      '</div>' + claimsTable(claims, staff) + '</section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

/* ------------------------------------------------------------------ */
/* Students, profile, import, export, audit                            */
/* ------------------------------------------------------------------ */

async function studentsScreen() {
  const isCurrent = beginLoad('Loading student registry\u2026');
  try {
    const data = await api('students');
    if (!isCurrent()) return;
    const students = Array.isArray(data.students) ? data.students : [];
    const rows = students.map(function (student) {
      return [student.student_number, student.full_name, student.program, fmtTs(student.created_at)];
    });
    view.innerHTML =
      '<section class="card"><h2>Student registry (' + students.length + ')</h2>' +
        table(['Student #', 'Full name', 'Program', 'Created'], rows,
          'No students yet', 'Add a fictional student to start creating records.') + '</section>' +
      '<section class="card"><h2>Add student</h2>' +
        '<form data-form="student-add" class="form-grid">' +
          field('student_number', 'Student number',
            '<input id="f-student_number" name="student_number" type="text" required minlength="3" maxlength="32" pattern="[A-Za-z0-9-]{3,32}" placeholder="2026-1004">') +
          field('program', 'Program',
            '<input id="f-program" name="program" type="text" required minlength="2" maxlength="120" placeholder="BSIT">') +
          field('full_name', 'Full name',
            '<input id="f-full_name" name="full_name" type="text" required minlength="3" maxlength="120" placeholder="Fictional student name">') +
          '<div class="form-actions"><button type="submit" class="btn btn--primary">Add student</button></div>' +
        '</form></section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

async function profileScreen() {
  const isCurrent = beginLoad('Loading profile\u2026');
  try {
    const data = await api('me/profile');
    if (!isCurrent()) return;
    const profile = data.profile || {};
    view.innerHTML =
      '<div class="grid-two">' +
        '<section class="card"><h2>Your student profile</h2><dl class="detail-list">' +
          '<div><dt>Student number</dt><dd>' + esc(profile.student_number || '\u2014') + '</dd></div>' +
          '<div><dt>Full name</dt><dd>' + esc(profile.full_name || '\u2014') + '</dd></div>' +
          '<div><dt>Program</dt><dd>' + esc(profile.program || '\u2014') + '</dd></div>' +
          '<div><dt>Username</dt><dd>' + esc(currentUser.username || '\u2014') + '</dd></div>' +
        '</dl><p class="note">Student number and role are managed by the registry and cannot be changed here.</p></section>' +
        '<section class="card"><h2>Edit profile</h2>' +
          '<form data-form="profile" class="form-grid">' +
            field('full_name', 'Full name',
              '<input id="f-full_name" name="full_name" type="text" required minlength="3" maxlength="120" value="' +
              esc(profile.full_name || '') + '">', { full: true }) +
            field('program', 'Program',
              '<input id="f-program" name="program" type="text" required minlength="2" maxlength="120" value="' +
              esc(profile.program || '') + '">', { full: true }) +
            '<div class="form-actions"><button type="submit" class="btn btn--primary">Save changes</button></div>' +
          '</form></section>' +
      '</div>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

function importResultHtml() {
  if (!importResult) {
    return '<h2>Last import result</h2>' + emptyState('No import yet', 'Upload a CSV to see row-level results here.');
  }
  const result = importResult;
  const errors = Array.isArray(result.errors) ? result.errors : [];
  const errorRows = errors.map(function (item) {
    return ['Row ' + (item.row_number ?? '\u2014'), item.reason || '\u2014'];
  });
  return '<h2>Last import result</h2><dl class="detail-list">' +
    '<div><dt>Batch</dt><dd>#' + esc(result.batch_id ?? '\u2014') + '</dd></div>' +
    '<div><dt>Source</dt><dd>' + esc(result.source_type || '\u2014') + '</dd></div>' +
    '<div><dt>File</dt><dd>' + esc(result.filename || '\u2014') + '</dd></div>' +
    '<div><dt>Accepted</dt><dd>' + esc(result.accepted_rows ?? 0) + ' / ' + esc(result.total_rows ?? 0) + '</dd></div>' +
    '<div><dt>Rejected</dt><dd>' + esc(result.rejected_rows ?? 0) + '</dd></div>' +
    '</dl>' +
    (errorRows.length
      ? '<h3>Rejected rows</h3>' + table(['Row', 'Reason'], errorRows, '', '')
      : '<p class="note">Every row passed validation.</p>');
}

async function importScreen() {
  const isCurrent = beginLoad('Loading imports\u2026');
  try {
    const data = await api('imports');
    if (!isCurrent()) return;
    const batches = Array.isArray(data.batches) ? data.batches : [];
    const rows = batches.map(function (batch) {
      return [
        '#' + batch.id,
        batch.source_type || '\u2014',
        batch.filename || '\u2014',
        batch.total_rows ?? 0,
        batch.accepted_rows ?? 0,
        batch.rejected_rows ?? 0,
        batch.error_count ?? 0,
        fmtTs(batch.created_at)
      ];
    });
    view.innerHTML =
      '<div class="grid-two">' +
        '<section class="card"><h2>Upload supplier CSV</h2>' +
          '<form data-form="import" class="form-grid">' +
            field('source_type', 'Import into',
              selectControl('source_type', [
                { value: 'found', label: 'Found items' },
                { value: 'lost', label: 'Lost reports' }
              ], 'found', { required: true }), { full: true }) +
            field('file', 'CSV file',
              '<input id="f-file" name="file" type="file" accept=".csv,text/csv" required>',
              { full: true, hint: 'Expected header: studentId,category,description,location,date' }) +
            '<div class="form-actions"><button type="submit" class="btn btn--primary">Import CSV</button></div>' +
          '</form>' +
          '<div class="sample-links"><span>Sample files:</span>' +
            '<a href="' + esc(sampleUrl('sample_found.csv')) + '" download>valid sample_found.csv</a>' +
            '<a href="' + esc(sampleUrl('sample_invalid.csv')) + '" download>invalid sample_invalid.csv</a>' +
          '</div></section>' +
        '<section class="card">' + importResultHtml() + '</section>' +
      '</div>' +
      '<section class="card"><h2>Import history</h2>' +
        table(['Batch', 'Type', 'File', 'Rows', 'Accepted', 'Rejected', 'Errors', 'Created'], rows,
          'No import batches yet', 'Accepted and rejected rows are recorded per batch.') + '</section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

async function exportScreen() {
  view.innerHTML =
    '<section class="card"><h2>Consolidated reports</h2>' +
      '<p>Download a CSV that combines lost reports, found items and their provenance from the database.</p>' +
      '<p class="muted small">Columns: Source, Record ID, Student ID, Category, Description, Location, Date, Status.</p>' +
      '<div class="card-actions"><a class="btn btn--teal" href="' + esc(apiUrl('reports/export')) +
      '" download>Download CSV export</a><a class="btn" href="#/staff/dashboard">Back to dashboard</a></div></section>';
}

async function auditScreen() {
  const isCurrent = beginLoad('Loading audit logs\u2026');
  try {
    const data = await api('audit');
    if (!isCurrent()) return;
    const logs = Array.isArray(data.logs) ? data.logs : [];
    const rows = logs.map(function (log) {
      return [
        '#' + log.id,
        log.actor_username || 'System',
        log.action || '\u2014',
        log.entity_type || '\u2014',
        log.entity_id ?? '\u2014',
        log.details || '\u2014',
        fmtTs(log.created_at)
      ];
    });
    view.innerHTML = '<section class="card"><h2>Audit logs (' + logs.length + ')</h2>' +
      table(['ID', 'Actor', 'Action', 'Entity', 'Entity ID', 'Details', 'Created'], rows,
        'No audit events', 'Sign-ins, imports, claims and exports are recorded here.') + '</section>';
  } catch (error) {
    if (isCurrent()) reportError(error);
  }
}

/* ------------------------------------------------------------------ */
/* Forms                                                               */
/* ------------------------------------------------------------------ */

function setBusy(form, busy) {
  const button = form.querySelector('button[type="submit"]');
  if (!button) return;
  if (busy) {
    button.dataset.originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Working\u2026';
  } else {
    button.disabled = false;
    button.textContent = button.dataset.originalLabel || button.textContent;
  }
}

function clearFieldErrors(form) {
  form.querySelectorAll('[data-error-for]').forEach(function (node) { node.textContent = ''; });
}

async function handleSubmit(event) {
  const form = event.target.closest('form[data-form]');
  if (!form) return;
  event.preventDefault();
  if (!form.reportValidity()) return;

  const kind = form.dataset.form;
  const values = new FormData(form);
  const get = function (key) { return String(values.get(key) ?? '').trim(); };
  clearFieldErrors(form);
  setBusy(form, true);

  try {
    if (kind === 'login') {
      await refreshCsrf();
      const user = await api('auth/login', {
        method: 'POST',
        body: { username: get('username'), password: String(values.get('password') ?? '') }
      });
      await refreshCsrf();
      currentUser = await api('auth/me') || user;
      queueAlert('success', 'Signed in as ' + (currentUser.username || 'user') + '.');
      await goHome();
      return;
    }

    if (kind === 'register') {
      await refreshCsrf();
      await api('auth/register', {
        method: 'POST',
        body: {
          student_number: get('student_number'),
          full_name: get('full_name'),
          program: get('program'),
          username: get('username'),
          password: String(values.get('password') ?? '')
        }
      });
      await refreshCsrf();
      currentUser = await api('auth/me');
      queueAlert('success', 'Account created. Welcome to FindSync!');
      await goHome();
      return;
    }

    if (kind === 'report-lost' || kind === 'report-found') {
      const routeName = kind === 'report-lost' ? 'lost' : 'found';
      const payload = {
        category: get('category'),
        description: get('description'),
        location: get('location'),
        event_date: get('event_date')
      };
      if (form.dataset.staff === 'true' && get('student_id')) payload.student_id = get('student_id');
      await api(routeName, { method: 'POST', body: payload });
      await refreshView(routeName === 'lost' ? 'Lost report submitted.' : 'Found item submitted.');
      return;
    }

    if (kind === 'student-add') {
      await api('students', {
        method: 'POST',
        body: {
          student_number: get('student_number'),
          full_name: get('full_name'),
          program: get('program')
        }
      });
      await refreshView('Student added to the registry.');
      return;
    }

    if (kind === 'profile') {
      await api('me/profile', { method: 'PATCH', body: { full_name: get('full_name'), program: get('program') } });
      currentUser = await api('auth/me') || currentUser;
      await refreshView('Profile updated.');
      return;
    }

    if (kind === 'claim') {
      const lostId = get('lost_report_id');
      const foundId = get('found_item_id');
      await api('claims', {
        method: 'POST',
        body: {
          lost_report_id: lostId,
          found_item_id: foundId,
          ownership_evidence: get('ownership_evidence')
        }
      });
      closeModal();
      await refreshView('Claim request submitted for review.');
      return;
    }

    if (kind === 'import') {
      const formData = new FormData(form);
      const result = await api('import', { method: 'POST', form: formData });
      importResult = result || {};
      const summary = 'Import complete: ' + (importResult.accepted_rows ?? 0) + ' accepted, ' +
        (importResult.rejected_rows ?? 0) + ' rejected.';
      await refreshView(summary);
    }
  } catch (error) {
    reportError(error, form);
  } finally {
    if (form.isConnected) setBusy(form, false);
  }
}

/* ------------------------------------------------------------------ */
/* Clicks                                                              */
/* ------------------------------------------------------------------ */

async function doLogout() {
  try {
    await api('auth/logout', { method: 'POST', body: {} });
  } catch (error) {
    if (currentUser) {
      reportError(error);
      return;
    }
  }
  currentUser = null;
  importResult = null;
  try { await refreshCsrf(); } catch (error) { /* login screen will report server issues */ }
  queueAlert('success', 'You have been signed out.');
  history.replaceState(null, '', '#/login');
  await route();
}

function handleClick(event) {
  const target = event.target.closest('[data-action]');
  if (!target) return;
  const action = target.dataset.action;

  switch (action) {
    case 'skip-main': {
      event.preventDefault();
      const main = document.getElementById('main');
      if (main) { main.focus(); main.scrollIntoView(); }
      break;
    }
    case 'toggle-nav': toggleNav(); break;
    case 'close-nav': closeNav(); break;
    case 'dismiss-alert': clearAlert(); break;
    case 'logout': doLogout(); break;
    case 'open-claim': {
      let match = null;
      try { match = JSON.parse(decodeURIComponent(target.dataset.match || '')); } catch (error) { match = null; }
      if (match) openClaimModal(match);
      else showAlert('error', 'That match could not be opened. Please reload the page.');
      break;
    }
    case 'approve-claim': openReviewModal('approve', target.dataset.id); break;
    case 'reject-claim': openReviewModal('reject', target.dataset.id); break;
    case 'release-claim': openReleaseModal(target.dataset.id); break;
    case 'modal-cancel':
    case 'modal-backdrop': closeModal(); break;
    case 'modal-noop': break;
    case 'modal-confirm': confirmModal(target); break;
    default: break;
  }
}

/* ------------------------------------------------------------------ */
/* Boot                                                                */
/* ------------------------------------------------------------------ */

app.addEventListener('click', handleClick);
app.addEventListener('submit', handleSubmit);
document.addEventListener('click', function (event) {
  const skip = event.target.closest('.skip-link');
  if (!skip) return;
  event.preventDefault();
  const main = document.getElementById('main') || document.querySelector('.auth-card');
  if (main) { main.setAttribute('tabindex', '-1'); main.focus(); main.scrollIntoView(); }
});
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape' && activeModal) closeModal();
});
window.addEventListener('hashchange', function () { closeNav(); route(); });

(async function boot() {
  try {
    await refreshCsrf();
  } catch (error) {
    pendingAlert = { type: 'error', message: error.message };
  }
  try {
    currentUser = await api('auth/me');
  } catch (error) {
    currentUser = null;
  }
  await route();
})();
