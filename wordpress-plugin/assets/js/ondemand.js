/* Discovery only. All remote strings use textContent, never HTML. */
(() => {
    'use strict';
    const states = {CurrentEnforced: '現施行', PreviousEnforced: '過去施行', UnEnforced: '未施行', Repeal: '廃止'};
    const matches = {not_checked: '内部版は未照合', matched: 'メタデータ上の一意候補（本文同一性は未確認）', unmatched: '対応する内部版なし', ambiguous: '内部版を一意に特定できません', unavailable: '照合情報を取得・確認できません。時間をおいて明示的に再操作してください。'};
    function enforcement(value) {
        if (value.pseudo_date) return `参考日〈擬似的な施行期日〉：${value.pseudo_date}（確定した施行日とは扱いません）`;
        return value.api_date ? `施行期日（API記録）：${value.api_date}` : '施行日未定／日付情報なし';
    }
    function init(root, config) {
        const q = name => root.querySelector(`[data-${name}]`);
        let busy = false, query = '', mode = 'title', offset = 0, selectedLaw = null, revisions = [], page = 0;
        function node(tag, text) { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; }
        function button(text, action) { const el = node('button', text); el.type = 'button'; el.disabled = busy; el.addEventListener('click', action); return el; }
        function message(text, error = false) { q('status').textContent = text; q('status').classList.toggle('is-error', error); }
        async function run(action) {
            if (busy) return;
            busy = true; root.setAttribute('aria-busy', 'true');
            root.querySelectorAll('button,input,select').forEach(el => { el.disabled = true; });
            message('取得中です…');
            try { await action(); } catch (error) { message(error.message || '取得に失敗しました。再操作してください。', true); }
            finally { busy = false; root.removeAttribute('aria-busy'); root.querySelectorAll('button,input,select').forEach(el => { el.disabled = false; }); }
        }
        async function api(path, args, post = false) {
            const url = new URL(path, config.api);
            const options = {method: post ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': config.nonce}};
            if (post) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(args); }
            else Object.entries(args).forEach(([key, value]) => url.searchParams.set(key, value));
            const response = await fetch(url, options);
            let data; try { data = await response.json(); } catch (_) { throw new Error('正常な応答を取得できません。ページを再読み込みしてください。'); }
            if (!response.ok) throw new Error(data.message || '取得に失敗しました。');
            return data;
        }
        function resetSelection() { q('selected').textContent = '改正履歴から1件選択してください。'; }
        function showSelected(record) {
            q('selected').replaceChildren();
            const lines = [record.law.title, `法令ID：${record.law.id}`, `公式版ID：${record.official_revision_id}`, `改正法令：${record.amendment.title || '記録なし'} ${record.amendment.number}`, `改正法令ID：${record.amendment.id || 'なし'}`, enforcement(record.enforcement), `施行備考：${record.enforcement.comment || 'なし'}`, `履歴状態：${states[record.revision_status] || '状態不明'}`, matches[record.internal.status] || '照合状態不明'];
            lines.forEach(text => q('selected').append(node('p', text)));
            record.internal.candidates.forEach(c => q('selected').append(node('p', `内部版候補：${c.law_data_id} / ${c.sub_revision}`)));
            if (record.internal.checked_at) q('selected').append(node('p', `照合日時（UTC）：${record.internal.checked_at}`));
            q('selected').append(button('内部版を照合', () => run(async () => {
                // Remove a previous success while a fresh check is pending/fails.
                const unchecked = {...record, internal: {...record.internal, status: 'not_checked', candidates: [], checked_at: null}};
                showSelected(unchecked);
                const data = await api('revision-match', {law_id: record.law.id, revision_id: record.official_revision_id}, true);
                showSelected(data); message(matches[data.internal.status], data.internal.status === 'unavailable');
            })));
        }
        function showRevisions() {
            q('revisions').replaceChildren(); const list = node('ul');
            revisions.slice(page * 20, (page + 1) * 20).forEach(r => {
                const item = node('li'); item.append(node('p', `${r.amendment.title || '新規制定等（改正法令名なし）'} ${r.amendment.number}`), node('p', enforcement(r.enforcement)), node('p', `状態：${states[r.revision_status] || '状態不明'} ／ 内部版は未照合`), node('p', `施行備考：${r.enforcement.comment || 'なし'}`), node('p', `公式版ID：${r.official_revision_id}`), button('この改正版を選択', () => { if (!busy) showSelected(r); })); list.append(item);
            });
            q('revisions').append(revisions.length ? list : node('p', '正常に取得しましたが、改正履歴は0件です。'));
            q('rev-prev').hidden = page === 0; q('rev-next').hidden = (page + 1) * 20 >= revisions.length;
        }
        async function search(next) {
            selectedLaw = null; revisions = []; page = 0; q('law').textContent = ''; showRevisions(); resetSelection(); q('laws').replaceChildren();
            q('law-prev').hidden = true; q('law-next').hidden = true;
            const data = await api('laws', {mode, query, offset: next}); offset = next;
            const list = node('ul'); data.items.forEach(law => {
                const item = node('li'); item.append(node('p', `${law.title} ／ ${law.number} ／ ${law.id}`), button('この法令の履歴', () => run(async () => {
                    selectedLaw = law; revisions = []; page = 0; showRevisions(); resetSelection(); q('law').textContent = `${law.title} ／ ${law.number} ／ ${law.id}`;
                    const data = await api('revisions', {law_id: selectedLaw.id}); revisions = data.items; page = 0; showRevisions(); message(`改正履歴：${revisions.length}件`);
                }))); list.append(item);
            });
            q('laws').append(data.items.length ? list : node('p', '該当する法令はありません。'));
            q('law-prev').hidden = offset === 0; q('law-next').hidden = data.next_offset === null;
            q('law-next').dataset.offset = data.next_offset; message(`該当法令：${data.total}件`);
        }
        q('search').addEventListener('submit', event => { event.preventDefault(); if (busy) return; const form = new FormData(event.currentTarget); mode = form.get('mode'); query = form.get('query'); run(() => search(0)); });
        q('law-prev').addEventListener('click', () => run(() => search(Math.max(0, offset - 20))));
        q('law-next').addEventListener('click', () => run(() => search(Number(q('law-next').dataset.offset))));
        q('rev-prev').addEventListener('click', () => { if (!busy && page > 0) { page--; showRevisions(); } });
        q('rev-next').addEventListener('click', () => { if (!busy && (page + 1) * 20 < revisions.length) { page++; showRevisions(); } });
    }
    // Export pure helpers for offline tests; no requests occur on import/initial display.
    if (typeof module !== 'undefined') module.exports = {enforcement, init};
    if (typeof document !== 'undefined' && typeof egovOndemand !== 'undefined') document.querySelectorAll('[data-egov-ondemand]').forEach(root => init(root, egovOndemand));
})();
