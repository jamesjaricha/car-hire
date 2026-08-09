/*
| Copy to clipboard
|--------------------------------------------------------------------------
|
| Used for the payment reference on the confirmation page. A mistyped
| reference is the most common cause of a payment nobody can attribute, and an
| unattributed payment is a customer who has paid and is not confirmed.
|
| Delegated from the document rather than bound per button, so it costs one
| listener and keeps working if a page ever renders these dynamically.
|
| Progressive enhancement: without JavaScript the reference is still visible
| and selectable, so nothing is lost — only convenience is added.
*/
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');

    if (! button) {
        return;
    }

    const label = button.querySelector('[data-copy-label]');
    const original = label?.textContent ?? '';

    try {
        await navigator.clipboard.writeText(button.dataset.copy);
    } catch {
        // Clipboard access can be refused — an insecure origin, a browser
        // permission, an older device. Say nothing and change nothing: the
        // reference is still on screen to be selected by hand, and a false
        // "Copied" would be worse than no feedback at all.
        return;
    }

    if (! label) {
        return;
    }

    label.textContent = 'Copied';

    // Long enough to read, short enough not to linger. The button returns to
    // its resting state on its own rather than leaving stale feedback behind.
    window.setTimeout(() => {
        label.textContent = original;
    }, 1600);
});
