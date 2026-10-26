async function postJSON(url, body) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        return await res.json();
    } catch {
        return { success: false, error: 'Could not reach the server. Please try again.' };
    }
}

function setMessage(el, text, ok) {
    el.textContent = text;
    el.className = `form-message ${ok ? 'ok' : 'err'}`;
    el.hidden = false;
}