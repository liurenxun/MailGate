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
        const row = btn.closest('.notif-item');
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

    // ── メールボックスサイドバー：ルール折りたたみ + ドラッグ並び替え ─
    const mbSidebar = document.querySelector('.mailbox-sidebar .list-group');
    if (mbSidebar) {
        // メールボックス行クリック：ルール折りたたみ or ナビゲーション
        mbSidebar.querySelectorAll('.mb-sidebar-row').forEach(row => {
            row.addEventListener('click', e => {
                e.preventDefault();
                const collapse = document.getElementById(row.dataset.target);
                const icon = row.querySelector('.mb-rule-icon');
                if (!collapse) { window.location.href = row.href; return; }
                const shown = collapse.style.display !== 'none';
                if (shown) {
                    collapse.style.display = 'none';
                    if (icon) icon.className = 'bi bi-plus-square mb-rule-icon flex-shrink-0';
                    window.location.href = row.href;
                } else {
                    collapse.style.display = 'block';
                    if (icon) icon.className = 'bi bi-dash-square mb-rule-icon flex-shrink-0';
                }
            });
        });

        // ドラッグ並び替え（.mb-sidebar-group 単位）
        let dragSrc = null;

        mbSidebar.querySelectorAll('.mb-sidebar-group[draggable="true"]').forEach(item => {
            item.addEventListener('dragstart', e => {
                if (e.target.closest('.mb-rule-collapse')) { e.preventDefault(); return; }
                dragSrc = item;
                e.dataTransfer.effectAllowed = 'move';
                setTimeout(() => item.classList.add('mb-dragging'), 0);
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('mb-dragging');
                mbSidebar.querySelectorAll('.mb-drag-over').forEach(el => el.classList.remove('mb-drag-over'));
                dragSrc = null;
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                mbSidebar.querySelectorAll('.mb-drag-over').forEach(el => el.classList.remove('mb-drag-over'));
                if (dragSrc && item !== dragSrc) item.classList.add('mb-drag-over');
            });
            item.addEventListener('dragleave', e => {
                if (!item.contains(e.relatedTarget)) item.classList.remove('mb-drag-over');
            });
            item.addEventListener('drop', e => {
                e.preventDefault();
                if (!dragSrc || dragSrc === item) return;
                item.classList.remove('mb-drag-over');
                // DOM 並び替え
                const items = [...mbSidebar.querySelectorAll('.mb-sidebar-group[draggable="true"]')];
                const srcIdx = items.indexOf(dragSrc);
                const dstIdx = items.indexOf(item);
                if (srcIdx < dstIdx) {
                    mbSidebar.insertBefore(dragSrc, item.nextSibling);
                } else {
                    mbSidebar.insertBefore(dragSrc, item);
                }
                // サーバーへ順番を保存
                const ids = [...mbSidebar.querySelectorAll('.mb-sidebar-group[data-mailbox-id]')]
                    .map(el => el.dataset.mailboxId);
                fetch('/dashboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=reorder_mailboxes&ids=${encodeURIComponent(ids.join(','))}&csrf_token=${encodeURIComponent(csrfToken)}`,
                });
            });
        });
    }

    // ── 今すぐ受信ボタン ─────────────────────────────────────────────
    const fetchNowBtn = document.getElementById('fetch-now-btn');
    if (fetchNowBtn) {
        const fetchLabel   = fetchNowBtn.querySelector('.fetch-label');
        const fetchIcon    = fetchNowBtn.querySelector('i');
        const fetchSpinner = fetchNowBtn.querySelector('.spinner-border');

        function resetFetchBtn() {
            fetchNowBtn.disabled = false;
            fetchNowBtn.className = 'btn btn-sm btn-outline-primary flex-shrink-0';
            fetchIcon.classList.remove('d-none');
            fetchSpinner.classList.add('d-none');
            fetchLabel.textContent = '受信';
        }

        fetchNowBtn.addEventListener('click', async () => {
            fetchNowBtn.disabled = true;
            fetchIcon.classList.add('d-none');
            fetchSpinner.classList.remove('d-none');
            fetchLabel.textContent = '受信中...';

            try {
                const res = await fetch('/fetch-now.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `csrf_token=${encodeURIComponent(csrfToken)}`,
                });
                const data = await res.json();

                fetchSpinner.classList.add('d-none');
                fetchIcon.classList.remove('d-none');

                if (data.ok) {
                    fetchNowBtn.className = 'btn btn-sm btn-success flex-shrink-0';
                    fetchLabel.textContent = data.fetched > 0
                        ? `${data.fetched}件受信`
                        : '新着なし';
                    setTimeout(() => location.reload(), 1500);
                } else if (data.error === 'busy') {
                    fetchNowBtn.className = 'btn btn-sm btn-warning flex-shrink-0';
                    fetchLabel.textContent = 'Cron実行中';
                    setTimeout(resetFetchBtn, 3000);
                } else if (data.error === 'rate_limit') {
                    fetchNowBtn.className = 'btn btn-sm btn-secondary flex-shrink-0';
                    fetchLabel.textContent = `${data.wait}秒後に再試行`;
                    setTimeout(resetFetchBtn, data.wait * 1000);
                } else {
                    fetchNowBtn.className = 'btn btn-sm btn-danger flex-shrink-0';
                    fetchLabel.textContent = 'エラー';
                    setTimeout(resetFetchBtn, 3000);
                }
            } catch (_) {
                fetchSpinner.classList.add('d-none');
                fetchIcon.classList.remove('d-none');
                fetchNowBtn.className = 'btn btn-sm btn-danger flex-shrink-0';
                fetchLabel.textContent = 'エラー';
                setTimeout(resetFetchBtn, 3000);
            }
        });
    }

    // ── 個人ルール編集モーダル populate ─────────────────────────────
    document.querySelectorAll('.rule-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editRuleId').value       = btn.dataset.ruleId;
            document.getElementById('editLabel').value        = btn.dataset.label ?? '';
            document.getElementById('editMatchField').value   = btn.dataset.matchField;
            document.getElementById('editMatchPattern').value = btn.dataset.matchPattern;
            document.getElementById('editRuleAction').value   = btn.dataset.ruleAction;
            document.getElementById('editPriority').value     = btn.dataset.priority;
        });
    });

});
