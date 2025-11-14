const app = document.getElementById('hwmb-app');
if (app) {
  const state = {
    items: [],
    meta: { page: 1, pages: 1, perPage: 10 },
    filters: { search: '', material: '', leather: '', date_from: '', date_to: '' },
    loading: false,
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
      return `
        <tr>
          <td>${item.title || ''}</td>
          <td>${item.serial || ''}</td>
          <td>${item.material || ''}</td>
          <td>${item.leather || ''}</td>
          <td>${item.date || ''}</td>
          <td class="hwmb-actions">${viewBtn}${downloadBtn}${rebuildBtn}</td>
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
