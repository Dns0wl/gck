const app = document.getElementById('hwmb-app');
if (app) {
  const state = {
    items: [],
    meta: { page: 1, pages: 1, perPage: 10 },
    filters: { search: '', material: '', leather: '', date_from: '', date_to: '' },
    loading: false,
    currentItem: null,
  };

  const tpl = document.createElement('div');
  tpl.innerHTML = `
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
  app.appendChild(tpl);

  const modal = document.createElement('div');
  modal.className = 'hwmb-modal';
  modal.setAttribute('hidden', 'hidden');
  modal.innerHTML = `
    <div class="hwmb-modal__card" role="dialog" aria-modal="true">
      <button class="hwmb-modal__close" type="button" data-modal-close>&times;</button>
      <h2>Process Manual Book</h2>
      <p class="hwmb-modal__subtitle" data-modal-product></p>
      <form class="hwmb-modal__form">
        <label class="hwmb-modal__field">
          <span>Customer Name</span>
          <input type="text" name="customer_name" placeholder="Nama pelanggan" required />
        </label>
        <div class="hwmb-modal__field">
          <span>Date Order (DD/MM/YY)</span>
          <div class="hwmb-date-options">
            <label><input type="radio" name="date_mode" value="now" checked /> Now</label>
            <label><input type="radio" name="date_mode" value="choose" /> Choose</label>
          </div>
          <input type="date" name="order_date" class="hwmb-date-picker" data-date-picker />
        </div>
        <div class="hwmb-modal__actions">
          <button type="submit" class="hwmb-button-primary">Submit &amp; Download</button>
        </div>
      </form>
    </div>
  `;
  document.body.appendChild(modal);

  const modalForm = modal.querySelector('form');
  const modalNameInput = modalForm.querySelector('[name="customer_name"]');
  const modalDatePicker = modalForm.querySelector('[name="order_date"]');
  const modalProductLabel = modal.querySelector('[data-modal-product]');
  const modalSubmitButton = modalForm.querySelector('button[type="submit"]');

  modal.querySelector('[data-modal-close]').addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
  modalForm.addEventListener('submit', handleProcessSubmit);
  modalForm.querySelectorAll('input[name="date_mode"]').forEach((input) => {
    input.addEventListener('change', updateDatePickerVisibility);
  });
  updateDatePickerVisibility();

  const filterInputs = tpl.querySelectorAll('[data-filter]');
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
    const wrapper = tpl.querySelector('.hwmb-table-wrapper');
    if (state.loading) {
      wrapper.innerHTML = '<div class="hwmb-empty">Loading…</div>';
      return;
    }
    if (!state.items.length) {
      wrapper.innerHTML = '<div class="hwmb-empty">No manual book found.</div>';
      return;
    }
    const rows = state.items.map((item) => {
      const viewBtn = item.pdf
        ? `<a href="${item.pdf}" target="_blank" rel="noreferrer">View</a>`
        : '';
      const downloadBtn = item.pdf
        ? `<a href="${item.pdf}" download>Download</a>`
        : '';
      const rebuildBtn = HWMBApp.canBuild
        ? `<button data-rebuild="${item.id}">Rebuild</button>`
        : '';
      const processBtn = HWMBApp.canBuild
        ? `<button data-process="${item.id}">Process</button>`
        : '';
      return `
        <tr>
          <td>${item.title || ''}</td>
          <td>${item.serial || ''}</td>
          <td>${item.material || ''}</td>
          <td>${item.leather || ''}</td>
          <td>${item.date || ''}</td>
          <td class="hwmb-actions">${viewBtn}${downloadBtn}${rebuildBtn}${processBtn}</td>
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
      btn.addEventListener('click', () => openProcessModal(btn.dataset.process));
    });
  }

  async function rebuildItem(id) {
    if (!HWMBApp.canBuild) {
      return;
    }
    const button = tpl.querySelector(`[data-rebuild="${id}"]`);
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

  function openProcessModal(id) {
    if (!HWMBApp.canBuild) {
      return;
    }
    const item = state.items.find((entry) => String(entry.id) === String(id));
    state.currentItem = item || null;
    modalForm.reset();
    modalDatePicker.value = '';
    updateDatePickerVisibility();
    modalProductLabel.textContent = item ? `${item.title || ''} · Serial ${item.serial || ''}` : '';
    modal.removeAttribute('hidden');
    document.body.classList.add('hwmb-modal-open');
    modalNameInput.focus();
  }

  function closeModal() {
    modal.setAttribute('hidden', 'hidden');
    document.body.classList.remove('hwmb-modal-open');
    state.currentItem = null;
    setModalLoading(false);
  }

  function updateDatePickerVisibility() {
    const mode = modalForm.date_mode.value;
    if ('choose' === mode) {
      modalDatePicker.style.display = 'block';
      modalDatePicker.required = true;
    } else {
      modalDatePicker.style.display = 'none';
      modalDatePicker.required = false;
      modalDatePicker.value = '';
    }
  }

  function formatDisplayDate(value) {
    if (!value) {
      return '';
    }
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = String(date.getFullYear()).slice(-2);
    return `${day}/${month}/${year}`;
  }

  async function handleProcessSubmit(event) {
    event.preventDefault();
    if (!state.currentItem) {
      return;
    }
    const customerName = modalNameInput.value.trim();
    if (!customerName) {
      modalNameInput.focus();
      return;
    }
    const mode = modalForm.date_mode.value;
    let finalDate = '';
    if ('now' === mode) {
      finalDate = formatDisplayDate(new Date());
    } else {
      if (!modalDatePicker.value) {
        modalDatePicker.focus();
        return;
      }
      finalDate = formatDisplayDate(new Date(modalDatePicker.value));
    }

    if (!finalDate) {
      return;
    }

    const windowName = `hwmbProcess${Date.now()}`;
    const previewWindow = window.open('', windowName);
    if (previewWindow) {
      previewWindow.document.write('<p style="font-family: sans-serif; padding: 1rem;">Processing manual book…</p>');
    }
    setModalLoading(true);
    try {
      const result = await processManual(customerName, finalDate);
      if (!result || !result.pdf) {
        throw new Error('Manual book payload missing');
      }
      const blob = base64ToBlob(result.pdf, 'application/pdf');
      const url = URL.createObjectURL(blob);
      if (previewWindow && !previewWindow.closed) {
        previewWindow.location.href = url;
      } else {
        window.open(url, '_blank');
      }
      triggerDownload(url, result.filename || 'manual-book.pdf');
      closeModal();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (error) {
      console.error(error);
      if (previewWindow && !previewWindow.closed) {
        previewWindow.document.body.innerHTML = '<p style="font-family: sans-serif; padding: 1rem;">Gagal memproses manual book.</p>';
      }
      alert('Gagal memproses manual book. Silakan coba lagi.');
    } finally {
      setModalLoading(false);
    }
  }

  async function processManual(customerName, orderDate) {
    if (!state.currentItem) {
      throw new Error('Manual tidak ditemukan');
    }
    const response = await fetch(`${HWMBApp.rest}/process/${state.currentItem.id}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': HWMBApp.nonce,
      },
      body: JSON.stringify({ customer_name: customerName, order_date: orderDate }),
    });
    if (!response.ok) {
      throw new Error(`Process request failed with status ${response.status}`);
    }
    const payload = await response.json();
    if (!payload.success) {
      throw new Error(payload.message || 'Process request failed');
    }
    return payload;
  }

  function base64ToBlob(base64, mimeType) {
    const binary = atob(base64);
    const len = binary.length;
    const buffer = new Uint8Array(len);
    for (let i = 0; i < len; i += 1) {
      buffer[i] = binary.charCodeAt(i);
    }
    return new Blob([buffer], { type: mimeType });
  }

  function triggerDownload(url, filename) {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  function setModalLoading(isLoading) {
    if (!modalSubmitButton) {
      return;
    }
    modalSubmitButton.disabled = Boolean(isLoading);
    modalSubmitButton.textContent = isLoading ? 'Processing…' : 'Submit & Download';
  }

  function renderPagination() {
    const container = tpl.querySelector('.hwmb-pagination');
    const { page, pages } = state.meta;
    if (pages <= 1) {
      container.innerHTML = '';
      return;
    }
    const prevDisabled = page <= 1 ? 'disabled' : '';
    const nextDisabled = page >= pages ? 'disabled' : '';
    container.innerHTML = `
      <button ${prevDisabled}>Prev</button>
      <span>Page ${page} of ${pages}</span>
      <button ${nextDisabled}>Next</button>
    `;
    const buttons = container.querySelectorAll('button');
    const prevBtn = buttons[0];
    const nextBtn = buttons[1];
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (state.meta.page > 1) {
                state.meta.page -= 1;
                loadItems();
            }
        });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        if (state.meta.page < state.meta.pages) {
          state.meta.page += 1;
          loadItems();
        }
      });
    }
  }

  loadItems();
}
