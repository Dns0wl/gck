const app = document.getElementById('hwmb-app');
if (!app) {
  return;
}

const state = {
  items: [],
  meta: { page: 1, pages: 1, perPage: 10 },
  filters: { search: '', material: '', leather: '', date_from: '', date_to: '' },
  loading: false,
};

const view = document.createElement('div');
view.innerHTML = `
  <div class="hwmb-header">
    <div class="hwmb-brand">
      <img src="${app.dataset.logo}" alt="HW Manual Book logo" />
    </div>
    <h1>HW Manual Book</h1>
  </div>
  <div class="hwmb-filters">
    <input type="search" placeholder="Search product or serial" data-filter="search" />
    <input type="text" placeholder="Material" data-filter="material" />
    <input type="text" placeholder="Leather type" data-filter="leather" />
    <input type="date" data-filter="date_from" />
    <input type="date" data-filter="date_to" />
  </div>
  <div class="hwmb-table-wrapper"></div>
  <div class="hwmb-pagination"></div>
`;
app.appendChild(view);

const modal = document.createElement('div');
modal.className = 'hwmb-modal';
modal.innerHTML = `
  <div class="hwmb-modal__panel">
    <h2>Process Manual Book</h2>
    <form>
      <label>
        Nama Pelanggan
        <input type="text" name="customer_name" required placeholder="Masukkan nama pelanggan" />
      </label>
      <div>
        <span style="display:block;margin-bottom:6px;color:var(--hwmb-muted);font-size:0.9rem;">Tanggal Pemesanan</span>
        <div class="hwmb-date-options">
          <label><input type="radio" name="date_mode" value="now" checked /> Sekarang</label>
          <label><input type="radio" name="date_mode" value="choose" /> Pilih tanggal</label>
        </div>
      </div>
      <label>
        Pilih tanggal
        <input type="date" name="order_date" disabled />
      </label>
      <div class="hwmb-modal-actions">
        <button type="button" class="is-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="is-primary">Process</button>
      </div>
    </form>
  </div>
`;
document.body.appendChild(modal);

const modalForm = modal.querySelector('form');
const dateInput = modalForm.querySelector('input[name="order_date"]');
const dateRadios = modalForm.querySelectorAll('input[name="date_mode"]');
const submitBtn = modalForm.querySelector('button[type="submit"]');
let activePost = null;

function toggleDateInput() {
  const useCustom = modalForm.querySelector('input[name="date_mode"]:checked').value === 'choose';
  dateInput.disabled = !useCustom;
  if (!useCustom) {
    dateInput.value = '';
  }
}

dateRadios.forEach((radio) => radio.addEventListener('change', toggleDateInput));
modal.querySelector('[data-modal-close]').addEventListener('click', closeModal);
modal.addEventListener('click', (event) => {
  if (event.target === modal) {
    closeModal();
  }
});

function openModal(postId) {
  if (!HWMBApp.canBuild) {
    return;
  }
  activePost = postId;
  modal.classList.add('is-visible');
  modalForm.reset();
  toggleDateInput();
}

function closeModal() {
  activePost = null;
  modal.classList.remove('is-visible');
  modalForm.reset();
  toggleDateInput();
  submitBtn.disabled = false;
  submitBtn.textContent = 'Process';
}

modalForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!activePost) {
    return;
  }

  const formData = new FormData(modalForm);
  const payload = {
    customer_name: (formData.get('customer_name') || '').toString().trim(),
    mode: (formData.get('date_mode') || 'now').toString(),
    order_date: (formData.get('order_date') || '').toString(),
  };

  if (!payload.customer_name) {
    alert('Nama pelanggan wajib diisi.');
    return;
  }

  if (payload.mode === 'choose' && !payload.order_date) {
    alert('Silakan pilih tanggal pemesanan.');
    return;
  }

  submitBtn.disabled = true;
  submitBtn.textContent = 'Processing…';

  try {
    const response = await fetch(`${HWMBApp.rest}/process/${activePost}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': HWMBApp.nonce,
      },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data?.message || 'Gagal memproses manual book');
    }
    if (data?.pdf) {
      window.open(data.pdf, '_blank', 'noopener');
    }
    closeModal();
    loadItems();
  } catch (error) {
    console.error(error);
    alert(error.message);
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Process';
  }
});

toggleDateInput();

const filterInputs = view.querySelectorAll('[data-filter]');
filterInputs.forEach((input) => {
  input.addEventListener('input', () => {
    state.filters[input.dataset.filter] = input.value;
    state.meta.page = 1;
    loadItems();
  });
});

function buildQuery() {
  const params = new URLSearchParams();
  params.set('page', state.meta.page);
  params.set('per_page', state.meta.perPage);
  Object.entries(state.filters).forEach(([key, value]) => {
    if (value) {
      params.set(key, value);
    }
  });
  return params.toString();
}

async function loadItems() {
  state.loading = true;
  renderTable();
  try {
    const res = await fetch(`${HWMBApp.rest}/list?${buildQuery()}`);
    const data = await res.json();
    state.items = data.items || [];
    state.meta = data.meta || state.meta;
  } catch (error) {
    console.error(error);
  } finally {
    state.loading = false;
    renderTable();
    renderPagination();
  }
}

function renderTable() {
  const wrapper = view.querySelector('.hwmb-table-wrapper');
  if (state.loading) {
    wrapper.innerHTML = '<div class="hwmb-empty">Loading…</div>';
    return;
  }
  if (!state.items.length) {
    wrapper.innerHTML = '<div class="hwmb-empty">No manual book found.</div>';
    return;
  }

  const rows = state.items.map((item) => {
    const viewBtn = item.pdf ? `<a class="hwmb-btn-primary" href="${item.pdf}" target="_blank" rel="noreferrer">View</a>` : '';
    const downloadBtn = item.pdf ? `<a class="hwmb-btn-muted" href="${item.pdf}" download>Download</a>` : '';
    const rebuildBtn = HWMBApp.canBuild ? `<button type="button" class="hwmb-btn-muted" data-rebuild="${item.id}">Rebuild</button>` : '';
    const processBtn = HWMBApp.canBuild ? `<button type="button" class="hwmb-btn-primary" data-process="${item.id}">Process</button>` : '';
    return `
      <tr>
        <td>${item.title || ''}</td>
        <td>${item.serial || ''}</td>
        <td>${item.material || ''}</td>
        <td>${item.leather || ''}</td>
        <td>${item.date || ''}</td>
        <td class="hwmb-actions">${processBtn}${viewBtn}${downloadBtn}${rebuildBtn}</td>
      </tr>
    `;
  });

  wrapper.innerHTML = `
    <table class="hwmb-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Serial</th>
          <th>Material</th>
          <th>Leather</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>${rows.join('')}</tbody>
    </table>
  `;

  wrapper.querySelectorAll('[data-rebuild]').forEach((btn) => {
    btn.addEventListener('click', () => rebuildItem(btn.dataset.rebuild));
  });
  wrapper.querySelectorAll('[data-process]').forEach((btn) => {
    btn.addEventListener('click', () => openModal(btn.dataset.process));
  });
}

async function rebuildItem(id) {
  if (!HWMBApp.canBuild) {
    return;
  }
  const button = view.querySelector(`[data-rebuild="${id}"]`);
  if (button) {
    button.disabled = true;
    button.textContent = 'Building…';
  }
  try {
    await fetch(`${HWMBApp.rest}/build/${id}`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': HWMBApp.nonce,
      },
    });
    await loadItems();
  } catch (error) {
    console.error(error);
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = 'Rebuild';
    }
  }
}

function renderPagination() {
  const container = view.querySelector('.hwmb-pagination');
  const { page, pages } = state.meta;
  if (pages <= 1) {
    container.innerHTML = '';
    return;
  }
  const prevDisabled = page <= 1 ? 'disabled' : '';
  const nextDisabled = page >= pages ? 'disabled' : '';
  container.innerHTML = `
    <button ${prevDisabled} data-page="prev">Prev</button>
    <span>Page ${page} of ${pages}</span>
    <button ${nextDisabled} data-page="next">Next</button>
  `;
  container.querySelectorAll('button').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (btn.dataset.page === 'prev' && state.meta.page > 1) {
        state.meta.page -= 1;
        loadItems();
      }
      if (btn.dataset.page === 'next' && state.meta.page < state.meta.pages) {
        state.meta.page += 1;
        loadItems();
      }
    });
  });
}

loadItems();
