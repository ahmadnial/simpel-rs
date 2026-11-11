<div data-database-status role="status" aria-live="polite"
     style="display:flex;align-items:center;gap:6px;flex-shrink:0;padding:5px 16px;font-size:10px;line-height:16px;border-top:1px solid var(--border-subtle);background:var(--bg-elevated);color:var(--text-muted)">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" style="flex-shrink:0">
        <ellipse cx="12" cy="5" rx="8" ry="3"/>
        <path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>
    </svg>
    <span data-database-label style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">DB · Memeriksa…</span>
</div>
<script>
(() => {
    const indicator = document.querySelector('[data-database-status]');
    const label = indicator.querySelector('[data-database-label]');
    let checking = false;

    async function checkDatabase() {
        if (checking || document.visibilityState === 'hidden') return;
        checking = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        try {
            const response = await fetch(@json(route('database.status')), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            if (!response.ok) throw new Error('Unavailable');
            const status = await response.json();
            if (!status.connected || !status.database) throw new Error('Unverified');
            const environment = status.environment === 'production' ? ' · PRODUCTION' : '';
            label.textContent = `${status.database}${environment}`;
            indicator.style.color = status.environment === 'production' ? '#dc2626' : 'var(--text-secondary)';
            indicator.title = `Koneksi database aktif, diperiksa ${new Date().toLocaleTimeString('id-ID')}`;
        } catch {
            label.textContent = 'DB · Tidak terverifikasi';
            indicator.style.color = '#d97706';
            indicator.title = 'Koneksi database belum dapat diverifikasi. Periksa sebelum mengubah data.';
        } finally {
            clearTimeout(timeout);
            checking = false;
        }
    }

    checkDatabase();
    document.addEventListener('visibilitychange', checkDatabase);
    setInterval(checkDatabase, 60000);
})();
</script>
