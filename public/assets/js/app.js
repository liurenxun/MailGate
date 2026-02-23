'use strict';

document.addEventListener('DOMContentLoaded', () => {

    // ── フラッシュメッセージ自動削除 ─────────────────────────────
    document.querySelectorAll('.alert-autofade').forEach(el => {
        setTimeout(() => el.remove(), 4200);
    });

    // ── 削除確認ダイアログ ───────────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', e => {
            const msg = el.dataset.confirm || '本当に削除しますか？';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // ── ダッシュボード：全選択チェックボックス ───────────────────
    const checkAll = document.getElementById('check-all');
    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.notif-check').forEach(cb => {
                cb.checked = checkAll.checked;
            });
        });
    }

    // ── メール iframe 高さ自動調整試行 ──────────────────────────
    // sandbox="" では contentWindow にアクセス不可のため、
    // 一定時間後にリサイズを試みて失敗したら固定高さを維持する
    const mailFrame = document.getElementById('mail-body-frame');
    if (mailFrame) {
        mailFrame.addEventListener('load', () => {
            try {
                const h = mailFrame.contentWindow.document.body.scrollHeight;
                if (h > 100) mailFrame.style.height = h + 32 + 'px';
            } catch (_) {
                // sandbox="" により cross-origin 扱い → 固定高さで表示
            }
        });
    }

    // ── 通知アクション AJAX（ゴミ箱・復元・完全削除）────────────────
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    async function notifAction(action, nid) {
        const res = await fetch('/dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${action}&nid=${nid}&csrf_token=${encodeURIComponent(csrfToken)}`,
        });
        return res.ok && (await res.json()).ok;
    }

    function fadeRemoveRow(btn) {
        const row = btn.closest('tr');
        if (!row) return;
        row.style.transition = 'opacity .3s';
        row.style.opacity = '0';
        setTimeout(() => row.remove(), 320);
    }

    document.querySelectorAll('.notif-trash-btn').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            if (await notifAction('trash', btn.dataset.nid)) fadeRemoveRow(btn);
        });
    });

    document.querySelectorAll('.notif-restore-btn').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            if (await notifAction('restore', btn.dataset.nid)) fadeRemoveRow(btn);
        });
    });

    document.querySelectorAll('.notif-delete-btn').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            if (!confirm('このメールを完全に削除しますか？この操作は取り消せません。')) return;
            if (await notifAction('delete_permanent', btn.dataset.nid)) fadeRemoveRow(btn);
        });
    });

});
