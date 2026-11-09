document.querySelectorAll('[data-menu-toggle]').forEach((button) => {
    button.addEventListener('click', () => document.querySelector('[data-portal]')?.classList.toggle('menu-open'));
});

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById('password');
        if (!input) return;
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        button.textContent = visible ? 'Göster' : 'Gizle';
    });
});

const cameraForm = document.querySelector('[data-camera-form]');
if (cameraForm) {
    const video = cameraForm.querySelector('[data-camera-video]');
    const canvas = cameraForm.querySelector('[data-camera-canvas]');
    const output = cameraForm.querySelector('[data-camera-output]');
    const startButton = cameraForm.querySelector('[data-camera-start]');
    const captureButton = cameraForm.querySelector('[data-camera-capture]');
    const clearButton = cameraForm.querySelector('[data-camera-clear]');
    let stream = null;
    let starting = false;
    let disposed = false;

    startButton?.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia || !video) {
            alert('Bu tarayıcıda kamera desteği yok. Dosyadan yükleme alanını kullanın.');
            return;
        }

        if (starting || stream) return;
        starting = true;
        startButton.disabled = true;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            if (disposed) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
                return;
            }
            video.srcObject = stream;
            await video.play();
        } catch (error) {
            stream?.getTracks().forEach((track) => track.stop());
            stream = null;
            alert('Kamera açılamadı. Dosyadan yükleme alanını kullanın.');
        } finally {
            starting = false;
            startButton.disabled = Boolean(stream);
        }
    });

    captureButton?.addEventListener('click', () => {
        if (!video || !canvas || !output || !video.videoWidth) return;
        const context = canvas.getContext('2d');
        if (!context) return;
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        output.value = canvas.toDataURL('image/jpeg', 0.9);
        canvas.hidden = false;
    });

    clearButton?.addEventListener('click', () => {
        if (output) output.value = '';
        if (canvas) canvas.hidden = true;
    });

    window.addEventListener('pagehide', () => {
        disposed = true;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    });
    window.addEventListener('pageshow', () => {
        disposed = false;
        startButton.disabled = false;
    });
}

document.querySelectorAll('form').forEach((form) => {
    const block = form.querySelector('[data-location-block]');
    const ward = form.querySelector('[data-location-ward]');
    const bed = form.querySelector('[data-location-bed]');
    if (!block || !ward || !bed) return;

    const filterLocations = () => {
        const blockId = block.value;
        ward.querySelectorAll('option[data-block-id]').forEach((option) => {
            option.hidden = !blockId || option.dataset.blockId !== blockId;
        });
        if (ward.selectedOptions[0]?.hidden) ward.value = '';
        const wardId = ward.value;
        bed.querySelectorAll('option[data-ward-id]').forEach((option) => {
            option.hidden = !wardId || option.dataset.wardId !== wardId;
        });
        if (bed.selectedOptions[0]?.hidden) bed.value = '';
    };

    block.addEventListener('change', filterLocations);
    ward.addEventListener('change', filterLocations);
    filterLocations();
});

const closeModal = (modal) => {
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.toggle('modal-open', Boolean(document.querySelector('.portal-modal:not([hidden])')));
};

const openModal = (modal) => {
    if (!modal) return;
    document.querySelectorAll('.portal-modal:not([hidden])').forEach((other) => { other.hidden = true; });
    modal.hidden = false;
    document.body.classList.add('modal-open');
};

document.querySelectorAll('[data-modal-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const modal = document.getElementById(button.dataset.modalOpen);
        if (!modal) return;

        if (button.dataset.info) {
            const info = JSON.parse(button.dataset.info);
            modal.querySelector('[data-info-name]').textContent = info.name || 'Hükümlü bilgisi';
            modal.querySelector('[data-info-identity]').textContent = info.identity || '—';
            modal.querySelector('[data-info-uyap]').textContent = info.uyap || '—';
            modal.querySelector('[data-info-ds]').textContent = info.ds || '—';
            modal.querySelector('[data-info-release]').textContent = info.release || '—';
            modal.querySelector('[data-info-note]').textContent = info.note || '—';
        }

        openModal(modal);
    });
});

// --- Düzenleme popup: satır verisinden doldur ve action ayarla ---
document.querySelectorAll('[data-edit-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const modal = document.getElementById('editInmateModal');
        if (!modal) return;
        let data;
        try { data = JSON.parse(button.dataset.edit); } catch (e) { return; }
        const form = modal.querySelector('[data-edit-form]');
        if (form) form.setAttribute('action', data.url || '');
        modal.querySelectorAll('[data-edit-field]').forEach((field) => {
            const value = data[field.dataset.editField];
            if (field.type === 'checkbox') {
                field.checked = Number(value) === 1;
            } else {
                field.value = value ?? '';
            }
        });
        const detay = modal.querySelector('[data-edit-detay]');
        if (detay && data.detay) detay.setAttribute('href', data.detay);
        openModal(modal);
    });
});

document.querySelectorAll('[data-modal-close]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.closest('.portal-modal')));
});

document.querySelectorAll('.portal-modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    });

    if (modal.dataset.openOnLoad === 'true') {
        openModal(modal);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.portal-modal:not([hidden])').forEach((modal) => closeModal(modal));
    }
});

// --- Premium tema anahtarı (global, koyu/açık) ---
const applyThemeIcon = () => {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.querySelectorAll('[data-theme-icon]').forEach((el) => { el.textContent = dark ? '☀' : '☾'; });
};
applyThemeIcon();
document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (dark) {
            document.documentElement.removeAttribute('data-theme');
            try { localStorage.setItem('cte-theme', 'light'); } catch (e) {}
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            try { localStorage.setItem('cte-theme', 'dark'); } catch (e) {}
        }
        applyThemeIcon();
    });
});

// --- Liste araç çubuğu: anlık arama, kolon gizle/kopyala, font ölçeği ---
document.querySelectorAll('[data-list-tools]').forEach((tools) => {
    const key = tools.dataset.tableKey || 'list';
    const scope = tools.closest('.panel, section') || document;
    const table = scope.querySelector('[data-list-table]') || scope.querySelector('table');
    if (!table) return;
    const rows = () => Array.from(table.querySelectorAll('tbody tr[data-filter-row]'));
    const noMatchRow = table.querySelector('[data-no-match-row]');
    const emptyRow = table.querySelector('[data-empty-row]');

    // Anlık arama (debounce)
    const filterInput = tools.querySelector('[data-instant-filter]');
    const countLabel = tools.querySelector('[data-filter-count]');
    const runFilter = () => {
        const term = (filterInput?.value || '').trim().toLocaleLowerCase('tr');
        let visible = 0;
        rows().forEach((row) => {
            const match = !term || (row.dataset.filterRow || '').includes(term);
            row.hidden = !match;
            if (match) visible += 1;
        });
        if (noMatchRow) noMatchRow.hidden = !(term && visible === 0);
        if (emptyRow && rows().length > 0) emptyRow.hidden = true;
        if (countLabel) countLabel.textContent = term ? `${visible} kayıt` : '';
        // sıra numaralarını görünür satırlara göre yenile
        let i = 0;
        rows().forEach((row) => {
            if (row.hidden) return;
            const cell = row.querySelector('.row-index');
            if (cell) cell.textContent = ++i;
        });
    };
    let timer = null;
    filterInput?.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(runFilter, 160);
    });

    // Kolon gizle/göster (localStorage)
    const colKey = `cte-cols-${key}`;
    const setColVisible = (index, visible) => {
        table.querySelectorAll(`[data-col="${index}"]`).forEach((cell) => { cell.hidden = !visible; });
    };
    let hidden = [];
    try {
        const saved = JSON.parse(localStorage.getItem(colKey) || '[]');
        hidden = Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && /^\d+$/.test(value)) : [];
    } catch (e) { hidden = []; }
    tools.querySelectorAll('[data-col-toggle]').forEach((checkbox) => {
        const index = checkbox.value;
        if (hidden.includes(index)) { checkbox.checked = false; setColVisible(index, false); }
        checkbox.addEventListener('change', () => {
            setColVisible(index, checkbox.checked);
            const current = new Set(hidden);
            if (checkbox.checked) current.delete(index); else current.add(index);
            hidden = Array.from(current);
            try { localStorage.setItem(colKey, JSON.stringify(hidden)); } catch (e) {}
        });
    });

    // Kolon kopyalama
    tools.querySelectorAll('[data-col-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const index = button.dataset.colCopy;
            const values = rows()
                .filter((row) => !row.hidden)
                .map((row) => row.querySelector(`[data-col="${index}"]`)?.innerText.trim() ?? '')
                .join('\n');
            if (button.disabled) return;
            button.disabled = true;
            try {
                await navigator.clipboard.writeText(values);
                const original = button.textContent;
                button.textContent = '✓ Kopyalandı';
                setTimeout(() => { button.textContent = original; button.disabled = false; }, 1200);
            } catch (e) {
                button.disabled = false;
                alert('Kopyalama başarısız. Tarayıcı izni gerekebilir.');
            }
            button.closest('[data-list-menu-pop]')?.setAttribute('hidden', '');
        });
    });

    // Font ölçeği
    tools.querySelectorAll('[data-font-step]').forEach((button) => {
        button.addEventListener('click', () => {
            const step = Number(button.dataset.fontStep);
            let scale = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--table-font-scale')) || 1;
            scale = step === 0 ? 1 : Math.min(1.4, Math.max(0.8, scale + step * 0.05));
            document.documentElement.style.setProperty('--table-font-scale', String(scale));
            try { localStorage.setItem('cte-font-scale', String(scale)); } catch (e) {}
        });
    });

    runFilter();
});

// Açılır menüler (sütun / kopyala)
document.querySelectorAll('[data-list-menu]').forEach((menu) => {
    const toggle = menu.querySelector('[data-list-menu-toggle]');
    const pop = menu.querySelector('[data-list-menu-pop]');
    toggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        const isHidden = pop.hasAttribute('hidden');
        document.querySelectorAll('[data-list-menu-pop]').forEach((p) => p.setAttribute('hidden', ''));
        if (isHidden) pop.removeAttribute('hidden');
    });
});
document.addEventListener('click', () => {
    document.querySelectorAll('[data-list-menu-pop]').forEach((p) => p.setAttribute('hidden', ''));
});

// --- İnfaz hesaplayıcı: araca göre ilgili alanları göster (eski ayrı popup karşılığı) ---
document.querySelectorAll('[data-calculator-form]').forEach((form) => {
    const select = form.querySelector('[data-tool-select]');
    const fieldsByTool = {
        'date-interval': ['start_date', 'end_date'],
        apc: ['start_date', 'value_days'],
        'terror-one-third': ['start_date', 'years', 'months', 'days', 'credit_days'],
        fine: ['start_date', 'value_days'],
        'one-tenth': ['start_date', 'years', 'months', 'days', 'credit_days'],
        'ds-one': ['start_date', 'value_days'],
        'ds-three': ['start_date', 'value_days'],
        'law-7550': ['start_date', 'end_date'],
    };
    const apply = () => {
        const active = fieldsByTool[select.value] || ['start_date'];
        form.querySelectorAll('[data-field]').forEach((label) => {
            const field = label.dataset.field;
            if (field === 'tool') return;
            label.hidden = !active.includes(field);
        });
    };
    select?.addEventListener('change', apply);
    apply();
});

// --- Çoklu seçim filtreleri (durum/risk/karar) ---
document.querySelectorAll('[data-multi-filter]').forEach((filter) => {
    const toggle = filter.querySelector('[data-multi-toggle]');
    const pop = filter.querySelector('[data-multi-pop]');
    const label = filter.querySelector('[data-multi-label]');
    const placeholder = label?.textContent || '';
    const refresh = () => {
        const count = pop.querySelectorAll('input:checked').length;
        label.textContent = count ? `${count} seçili` : placeholder;
        filter.classList.toggle('has-selection', count > 0);
    };
    toggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        const isHidden = pop.hasAttribute('hidden');
        document.querySelectorAll('[data-multi-pop]').forEach((p) => p.setAttribute('hidden', ''));
        if (isHidden) pop.removeAttribute('hidden');
    });
    pop.addEventListener('click', (event) => event.stopPropagation());
    pop.querySelectorAll('input').forEach((input) => input.addEventListener('change', refresh));
    refresh();
});
document.addEventListener('click', () => {
    document.querySelectorAll('[data-multi-pop]').forEach((p) => p.setAttribute('hidden', ''));
});

document.querySelector('[data-apply-sort]')?.addEventListener('click', () => {
    const column = document.querySelector('[data-sort-column]')?.value || 'full_name';
    const direction = document.querySelector('[data-sort-direction]')?.value || 'asc';
    const map = {
        full_name_asc: 'full_name_asc',
        full_name_desc: 'full_name_desc',
        registered_at_asc: 'registered_at_asc',
        registered_at_desc: 'registered_at_desc',
        created_at_asc: 'created_at_asc',
        created_at_desc: 'created_at_desc',
    };
    const url = new URL(window.location.href);
    url.searchParams.set('sort', map[`${column}_${direction}`] || 'full_name_asc');
    window.location.href = url.toString();
});

document.querySelector('[data-select-all]')?.addEventListener('click', () => {
    document.querySelectorAll('input[name="inmate_ids[]"]').forEach((checkbox) => {
        checkbox.checked = true;
    });
});

document.querySelector('[data-clear-selection]')?.addEventListener('click', () => {
    document.querySelectorAll('input[name="inmate_ids[]"]').forEach((checkbox) => {
        checkbox.checked = false;
    });
});

document.querySelectorAll('[data-permission-preset]').forEach((button) => {
    button.addEventListener('click', () => {
        const preset = button.dataset.permissionPreset;
        document.querySelectorAll('select[data-permission-action]').forEach((select) => {
            const action = select.dataset.permissionAction;
            if (preset === 'inherit') {
                select.value = 'inherit';
            } else if (preset === 'readonly') {
                select.value = action === 'view' ? 'allow' : 'deny';
            } else if (preset === 'readwrite') {
                select.value = ['view', 'create', 'update', 'print', 'export', 'report'].includes(action) ? 'allow' : 'deny';
            }
        });
    });
});


// Read receipts are mutations: send a CSRF-protected POST when the conversation is visible.
let sendingReadReceipts = false;
const sendReadReceipts = async () => {
    if (sendingReadReceipts || document.visibilityState !== 'visible') return;
    sendingReadReceipts = true;
    try {
        await Promise.allSettled(Array.from(document.querySelectorAll('[data-chat-read]')).map(async (form) => {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (response.status === 204) form.remove();
        }));
    } finally {
        sendingReadReceipts = false;
    }
};
document.addEventListener('visibilitychange', sendReadReceipts);
sendReadReceipts();

// Birim oluşturucuda alan sırası ve kalıcı alan anahtarları.
document.querySelectorAll('[data-unit-builder]').forEach((form) => {
    const container = form.querySelector('[data-unit-fields]');
    const template = form.querySelector('[data-unit-field-template]');
    const reindex = () => container.querySelectorAll('[data-unit-field]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((input) => { input.name = input.name.replace(/fields\[[^\]]+\]/, `fields[${index}]`); });
    });
    form.querySelector('[data-add-unit-field]').addEventListener('click', () => {
        container.append(template.content.cloneNode(true));
        reindex();
        container.lastElementChild.querySelector('input').focus();
    });
    container.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('[data-unit-field]');
        if (button.matches('[data-remove-unit-field]')) row.remove();
        if (button.matches('[data-field-up]') && row.previousElementSibling) container.insertBefore(row, row.previousElementSibling);
        if (button.matches('[data-field-down]') && row.nextElementSibling) container.insertBefore(row.nextElementSibling, row);
        reindex();
    });
    form.addEventListener('submit', reindex);
});
