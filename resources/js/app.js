import './bootstrap';

import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.default.css';

// Inisialisasi otomatis untuk <select multiple data-tomselect> di halaman manapun —
// dipakai untuk pemilihan Asesor Internal (form Ajukan Dokumen) & cakupan Unit Kerja
// (Admin > Template Workflow), supaya lebih rapi & bisa dicari ketimbang checkbox panjang.
function initTomSelects(root = document) {
    root.querySelectorAll('select[data-tomselect]').forEach((el) => {
        if (el.tomselect) return;
        new TomSelect(el, {
            plugins: ['remove_button'],
            placeholder: el.dataset.placeholder || 'Cari & pilih...',
            allowEmptyOption: true,
        });
    });
}

document.addEventListener('DOMContentLoaded', () => initTomSelects());
