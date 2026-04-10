const customerApiBase = 'careconnect.php';
const customerDocApiBase = 'document.php';
const customerStatusApiBase = 'customer_status.php';

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    };
    return map[char] || char;
  });
}

function formatCustomerDate(value) {
  if (!value) return '';
  const normalized = String(value).replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }
  return new Intl.DateTimeFormat('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function networkDisplayName(partner) {
  const company = String(partner.name_firma || '').trim();
  if (company) return company;
  const person = [partner.vorname, partner.nachname]
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .join(' ');
  return person || 'Netzwerkpartner';
}

function networkPersonName(partner) {
  const person = [partner.vorname, partner.nachname]
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .join(' ');
  const company = String(partner.name_firma || '').trim();
  if (company && person) return person;
  return '';
}

function renderCustomerNetworkCards(container, partners) {
  if (!container) return;

  if (!partners || partners.length === 0) {
    container.innerHTML = `
      <article class="cp-card cp-card-shadow">
        <p class="cp-text-small">Aktuell sind keine Netzwerkpartner hinterlegt.</p>
      </article>
    `;
    return;
  }

  container.innerHTML = partners.map((partner) => {
    const phone = String(partner.telefon || '').trim();
    const email = String(partner.email || '').trim();
    const address = String(partner.adresse || '').trim();
    const type = String(partner.typ_bezeichnung || partner.type_label || '').trim();
    const person = networkPersonName(partner);

    return `
      <article class="cp-card cp-card-shadow cp-network-card">
        <div class="cp-network-head">
          <div>
            <h3>${escapeHtml(networkDisplayName(partner))}</h3>
            ${type ? `<p class="cp-network-type">${escapeHtml(type)}</p>` : ''}
          </div>
        </div>
        ${person ? `<p class="cp-network-person">${escapeHtml(person)}</p>` : ''}
        ${address ? `<p class="cp-network-address">${escapeHtml(address)}</p>` : ''}
        <div class="cp-action-row">
          ${
            phone
              ? `<a class="cp-btn cp-btn-secondary cp-btn-inline" href="tel:${escapeHtml(phone)}">Anrufen</a>`
              : ''
          }
          ${
            email
              ? `<a class="cp-btn cp-btn-secondary cp-btn-inline" href="mailto:${escapeHtml(email)}">Mail senden</a>`
              : ''
          }
        </div>
      </article>
    `;
  }).join('');
}

function initCustomerNav(activePage) {
  const nav = document.querySelector('[data-customer-nav]');
  if (!nav) return;

  const items = [
    { key: 'home', href: 'customer_home.html', label: 'Home' },
    { key: 'status', href: 'customer_status.html', label: 'Status' },
    { key: 'documents', href: 'customer_documents.html', label: 'Dokumente' },
    { key: 'account', href: 'customer_account.html', label: 'Konto' },
  ];

  nav.innerHTML = items.map((item) => `
    <a href="${item.href}" class="cp-nav-link ${item.key === activePage ? 'is-active' : ''}">
      ${item.label}
    </a>
  `).join('');
}

async function confirmCustomerLogout() {
  const shouldLogout = window.confirm('Moechten Sie sich wirklich abmelden?');
  if (!shouldLogout) return;

  await fetch(`${customerApiBase}?action=logout`, {
    credentials: 'include',
  });
  window.location.href = 'customer_login.html';
}

function bindCustomerLogout() {
  const button = document.getElementById('btnLogout');
  if (!button) return;
  button.addEventListener('click', confirmCustomerLogout);
}

async function ensureCustomerSession() {
  const response = await fetch(`${customerApiBase}?action=customer_self_get`, {
    credentials: 'include',
  });
  const data = await response.json();
  if (!data.success) {
    window.location.href = 'customer_login.html';
    return null;
  }
  return data.customer || null;
}

function initCustomerShell(activePage) {
  initCustomerNav(activePage);
  bindCustomerLogout();
}
