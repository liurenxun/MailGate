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

});
