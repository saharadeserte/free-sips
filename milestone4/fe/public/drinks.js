// Loaded by landing.html AFTER its inline script. It reuses that script's globals:
// $, el, clean, currentUser, pantry, selected, drawJar.
(() => {
    const api = async (method, url, body) => {
        try {
            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: body ? { 'Content-Type': 'application/json' } : {},
                body: body ? JSON.stringify(body) : undefined
            });
            return await res.json();
        } catch {
            return { success: false, error: 'Could not reach the server.' };
        }
    };

    const ERRORS = {
        not_logged_in: 'Please log in first.',
        pantry_full: 'Your pantry is full (50 items). Remove one first.',
        database_timeout: 'The database is busy. Please try again.',
        alcohol_not_allowed: 'This site only accepts non-alcoholic recipes.',
        invalid_ingredients: 'Add 1 to 15 ingredients.',
        missing_fields: 'Please fill in the name and instructions.'
    };
    const errText = (r) => ERRORS[r.error] || r.error || 'Something went wrong.';
    const thumbOk = (u) => typeof u === 'string' && u.startsWith('https://www.thecocktaildb.com/');
    const ratingText = (d) => (d.review_count ? `★ ${d.avg_rating} (${d.review_count})` : 'No reviews yet');

    // ---------- Menu (from the database) ----------
    async function loadMenu() {
        const cats = await api('GET', '/api/categories');
        const tabs = $('categoryTabs');
        tabs.replaceChildren();
        const names = ['All', ...((cats.categories || []).map((c) => c.name))];
        names.forEach((name, i) => {
            const tab = el('button', { className: 'tab', type: 'button', role: 'tab' }, name);
            tab.setAttribute('aria-selected', String(i === 0));
            tab.addEventListener('click', () => showCategory(name === 'All' ? '' : name, tab));
            tabs.append(tab);
        });
        await showCategory('', tabs.firstChild);
    }

    async function showCategory(category, activeTab) {
        document.querySelectorAll('#categoryTabs .tab').forEach((t) =>
            t.setAttribute('aria-selected', String(t === activeTab)));
        const list = $('drinkList');
        list.replaceChildren(el('li', {}, 'Loading...'));
        const r = await api('GET', '/api/drinks?limit=60' + (category ? `&category=${encodeURIComponent(category)}` : ''));
        list.replaceChildren();
        if (!r.success) { list.append(el('li', {}, 'The menu is unavailable right now.')); return; }
        if (!r.drinks.length) { list.append(el('li', {}, 'No drinks yet. The ingestion job may still be running.')); return; }

        r.drinks.forEach((d) => {
            const li = el('li');
            const title = el('strong', {}, d.status === 'pending' ? `${d.name} (pending approval)` : d.name);
            title.style.cursor = 'pointer';
            title.tabIndex = 0;
            const open = () => toggleDetail(li, d.drink_id);
            title.addEventListener('click', open);
            title.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
            li.append(title, el('span', {}, `${d.category || 'Drink'} · ${ratingText(d)}`));
            list.append(li);
        });
    }

    async function toggleDetail(li, id) {
        const existing = li.querySelector('.detail');
        if (existing) { existing.remove(); return; }

        const box = el('div', { className: 'detail' }, 'Loading...');
        li.append(box);
        const r = await api('GET', `/api/drinks/${id}`);
        box.replaceChildren();
        if (!r.success) { box.textContent = errText(r); return; }
        const d = r.drink;

        if (thumbOk(d.thumb_url)) {
            const img = el('img', { src: d.thumb_url, alt: d.name, loading: 'lazy' });
            img.style.maxWidth = '160px';
            img.style.borderRadius = '12px';
            box.append(img);
        }
        const ul = el('ul');
        d.ingredients.forEach((i) => ul.append(el('li', {}, i.measure ? `${i.measure} ${i.name}` : i.name)));
        box.append(ul);
        if (d.instructions) box.append(el('p', {}, d.instructions));

        box.append(el('strong', {}, `Reviews (${d.review_count})`));
        if (!d.reviews.length) box.append(el('p', {}, 'Be the first to review this drink.'));
        d.reviews.forEach((rv) =>
            box.append(el('p', {}, `${'★'.repeat(rv.rating)}${'☆'.repeat(5 - rv.rating)} ${rv.username}${rv.comment ? ': ' + rv.comment : ''}`)));

        if (!currentUser) { box.append(el('p', {}, 'Log in to leave a review.')); return; }

        const select = el('select', { ariaLabel: 'Rating' });
        [5, 4, 3, 2, 1].forEach((n) => select.append(el('option', { value: String(n) }, `${n} ★`)));
        const comment = el('input', { type: 'text', maxLength: 500, placeholder: 'Optional comment' });
        const btn = el('button', { type: 'button', className: 'surprise-btn' }, 'Submit review');
        const msg = el('span', { className: 'surprise-note' });
        btn.addEventListener('click', async () => {
            const res = await api('POST', `/api/drinks/${id}/reviews`, { rating: Number(select.value), comment: comment.value });
            if (res.success) { li.querySelector('.detail').remove(); toggleDetail(li, id); }
            else msg.textContent = errText(res);
        });
        const row = el('div', { className: 'surprise-row' });
        row.append(select, comment, btn, msg);
        box.append(row);
    }

    // ---------- Pantry (stored in MariaDB, per account) ----------
    window.loadPantryApi = async () => {
        selected.clear();
        const note = $('pantryNote'), jar = $('jar');
        const locked = !currentUser;
        $('pantryInput').disabled = locked;
        $('pantryAdd').disabled = locked;
        matchBtn.disabled = locked;
        removeBtn.disabled = locked;
        recipeForm.querySelectorAll('input, textarea, button').forEach((x) => { x.disabled = locked; });
        jar.classList.toggle('disabled', locked);

        if (locked) {
            pantry = [];
            note.textContent = 'Log in to use your pantry jar. Your ingredients are saved to your account.';
            drawJar();
            return;
        }
        note.textContent = 'Add what you have at home, then click ingredients in the jar to select them.';
        const r = await api('GET', '/api/pantry');
        pantry = r.success ? r.items.map((i) => clean(i.name)).filter(Boolean) : [];
        if (!r.success) note.textContent = errText(r);
        drawJar();
    };

    $('pantryForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!currentUser) return;
        const item = clean($('pantryInput').value).toLowerCase();
        $('pantryInput').value = '';
        if (item.length < 2 || pantry.includes(item)) return;
        const r = await api('POST', '/api/pantry', { ingredient: item });
        if (r.success) { pantry.push(item); drawJar(); }
        else $('surpriseNote').textContent = errText(r);
    });

    const surpriseRow = $('pantry').querySelector('.surprise-row');
    const matchBtn = el('button', { type: 'button', className: 'surprise-btn' }, 'What can I make?');
    const removeBtn = el('button', { type: 'button', className: 'surprise-btn' }, 'Remove selected');
    surpriseRow.append(matchBtn, removeBtn);
    const matchOut = el('div', { className: 'surprise-result', ariaLive: 'polite' });
    surpriseRow.after(matchOut);

    removeBtn.addEventListener('click', async () => {
        const items = [...selected];
        if (!items.length) { $('surpriseNote').textContent = 'Select ingredients in the jar first.'; return; }
        for (const item of items) {
            const r = await api('DELETE', `/api/pantry/${encodeURIComponent(item)}`);
            if (r.success) pantry = pantry.filter((p) => p !== item);
        }
        selected.clear();
        drawJar();
    });

    matchBtn.addEventListener('click', async () => {
        matchOut.replaceChildren(el('p', {}, 'Checking your pantry...'));
        const chosen = [...selected];
        const r = await api('POST', '/api/match', chosen.length ? { ingredients: chosen } : {});
        matchOut.replaceChildren();
        if (!r.success) { matchOut.append(el('p', {}, errText(r))); return; }
        if (!r.matches.length) { matchOut.append(el('p', {}, 'No matches yet. Try adding more ingredients.')); return; }

        const ul = el('ul', { className: 'drink-list' });
        r.matches.forEach((m) => {
            const li = el('li');
            li.append(
                el('strong', {}, m.name),
                el('span', {}, m.can_make ? 'You have everything!' : `You have ${m.have} of ${m.total}. Missing: ${m.missing.join(', ')}`)
            );
            ul.append(li);
        });
        matchOut.append(ul);
    });

    // ---------- Share a recipe ----------
    const recipeSection = el('section', { className: 'section', id: 'recipes' });
    recipeSection.append(
        el('h2', {}, 'Share a recipe'),
        el('p', { className: 'locked' }, 'Non-alcoholic only. New recipes are reviewed before they appear for everyone.')
    );
    const recipeForm = el('form', { autocomplete: 'off' });
    recipeForm.style.display = 'grid';
    recipeForm.style.gap = '8px';
    const rName = el('input', { type: 'text', maxLength: 100, placeholder: 'Recipe name', ariaLabel: 'Recipe name' });
    const rCat = el('input', { type: 'text', maxLength: 60, placeholder: 'Category (e.g. Fruity)', ariaLabel: 'Category' });
    const rIngs = el('textarea', { rows: 4, placeholder: 'One ingredient per line, e.g.\n2 oz | lemon juice\nmint', ariaLabel: 'Ingredients' });
    const rSteps = el('textarea', { rows: 4, maxLength: 2000, placeholder: 'Instructions', ariaLabel: 'Instructions' });
    const rBtn = el('button', { type: 'submit', className: 'surprise-btn' }, 'Submit recipe');
    const rMsg = el('p', { className: 'surprise-note', ariaLive: 'polite' });
    recipeForm.append(rName, rCat, rIngs, rSteps, rBtn);
    recipeSection.append(recipeForm, rMsg);
    $('info').before(recipeSection);

    recipeForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ingredients = rIngs.value.split('\n').map((l) => l.trim()).filter(Boolean).slice(0, 15).map((line) => {
            const [a, b] = line.split('|').map((s) => s.trim());
            return b === undefined ? { name: a, measure: '' } : { name: b, measure: a };
        });
        const r = await api('POST', '/api/recipes', {
            name: rName.value, category: rCat.value, instructions: rSteps.value, ingredients
        });
        if (r.success) {
            rMsg.textContent = 'Thanks! Your recipe was submitted and will appear after review.';
            recipeForm.reset();
        } else {
            rMsg.textContent = errText(r);
        }
    });

    // ---------- Start ----------
    loadMenu();
    window.loadPantryApi();
})();
