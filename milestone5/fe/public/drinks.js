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
    const ratingText = (d) => (d.review_count ? `★ ${d.avg_rating} (${d.review_count})` : 'No reviews yet');

    // Spec 3c & 3d: 6 Rule-Based Tag Pills + 5 Featured Drinks preview
    const TAG_PILLS = ['All', 'Iced', 'Hot', 'Smoothie', 'Dessert', 'Healthy', 'Matcha'];

    async function loadMenu() {
        const tabs = $('categoryTabs');
        tabs.replaceChildren();
        TAG_PILLS.forEach((tagName, i) => {
            const tab = el('button', { className: 'tab', type: 'button', role: 'tab' }, tagName);
            tab.setAttribute('aria-selected', String(i === 0));
            tab.addEventListener('click', () => showTag(tagName === 'All' ? '' : tagName, tab));
            tabs.append(tab);
        });
        await showTag('', tabs.firstChild);
    }

    async function showTag(tag, activeTab) {
        document.querySelectorAll('#categoryTabs .tab').forEach((t) =>
            t.setAttribute('aria-selected', String(t === activeTab)));
        const list = $('drinkList');
        list.replaceChildren(el('li', {}, 'Loading...'));
        const r = await api('GET', '/api/drinks?limit=5' + (tag ? `&tag=${encodeURIComponent(tag)}` : ''));
        list.replaceChildren();
        if (!r.success) { list.append(el('li', {}, 'The menu is unavailable right now.')); return; }
        if (!r.drinks.length) { list.append(el('li', {}, 'No drinks match this tag yet.')); return; }

        r.drinks.forEach((d) => {
            const li = el('li');
            const link = el('a', { href: `/drink/${d.drink_id}` },
                d.status === 'pending' ? `${d.name} (pending approval)` : d.name
            );
            const meta = el('span', {}, `${d.category || 'Drink'} · ${ratingText(d)}`);
            li.append(link, meta);
            list.append(li);
        });
    }

    // ---------- Pantry with Autocomplete & Big Trash Can Dropzone ----------
    const surpriseRow = $('pantry').querySelector('.surprise-row');
    const matchBtn = el('button', { type: 'button', className: 'surprise-btn' }, 'What can I make?');
    surpriseRow.append(matchBtn);
    const matchOut = el('div', { className: 'surprise-result', ariaLive: 'polite' });
    surpriseRow.after(matchOut);

    if (window.attachAutocomplete) {
        window.attachAutocomplete($('pantryInput'), '/api/suggest/ingredients', async (ingName) => {
            $('pantryInput').value = ingName;
            $('pantryForm').requestSubmit();
        });
    }

    window.loadPantryApi = async () => {
        selected.clear();
        const note = $('pantryNote'), jar = $('jar');
        const locked = !window.currentUser;
        currentUser = window.currentUser;
        $('pantryInput').disabled = locked;
        $('pantryAdd').disabled = locked;
        matchBtn.disabled = locked;
        jar.classList.toggle('disabled', locked);

        if (locked) {
            pantry = [];
            note.textContent = 'Log in to use your pantry jar. Your ingredients are saved to your account.';
            drawJar();
            return;
        }
        note.textContent = 'Add ingredients below. Drag boxes around the jar, or drag onto the 🗑️ trash can to delete.';
        const r = await api('GET', '/api/pantry');
        pantry = r.success ? r.items.map((i) => clean(i.name)).filter(Boolean) : [];
        if (!r.success) note.textContent = errText(r);
        drawJar();
    };

    $('pantryForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!window.currentUser) return;
        const item = clean($('pantryInput').value).toLowerCase();
        $('pantryInput').value = '';
        if (item.length < 2 || pantry.includes(item)) return;
        const r = await api('POST', '/api/pantry', { ingredient: item });
        if (r.success) {
            pantry.push(item);
            drawJar();
        } else {
            $('surpriseNote').textContent = errText(r);
        }
    });

    // Big Trash Can drag-and-drop deletion (Q5)
    const trashCan = $('trashCan');
    if (trashCan) {
        trashCan.addEventListener('dragover', (e) => {
            e.preventDefault();
            trashCan.classList.add('drag-over');
        });
        trashCan.addEventListener('dragleave', () => {
            trashCan.classList.remove('drag-over');
        });
        trashCan.addEventListener('drop', async (e) => {
            e.preventDefault();
            trashCan.classList.remove('drag-over');
            if (!window.currentUser) return;
            const item = e.dataTransfer.getData('text/plain');
            if (!item || !pantry.includes(item)) return;
            const r = await api('DELETE', `/api/pantry/${encodeURIComponent(item)}`);
            if (r.success) {
                pantry = pantry.filter((p) => p !== item);
                selected.delete(item);
                drawJar();
                $('surpriseNote').textContent = `Removed "${item}" from your pantry.`;
            } else {
                $('surpriseNote').textContent = errText(r);
            }
        });
    }

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
                el('a', { href: `/drink/${m.drink_id}` }, m.name),
                el('span', {}, m.can_make ? 'You have everything!' : `You have ${m.have} of ${m.total}. Missing: ${m.missing.join(', ')}`)
            );
            ul.append(li);
        });
        matchOut.append(ul);
    });

    loadMenu();
    window.loadPantryApi();
})();
