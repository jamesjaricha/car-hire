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
/*
| Vehicle photograph gallery
|--------------------------------------------------------------------------
|
| Clicking a thumbnail swaps the hero image. Delegated from the document for
| the same reasons as the copy handler below: one listener, and it survives
| markup that renders after load.
|
| Progressive enhancement, and it genuinely is here — without JavaScript every
| photograph is still on the page in the thumbnail strip, which scrolls. This
| only makes them enlargeable. Nothing is hidden behind the script.
|
| The selected state lives in `aria-current`, not in a class. Tailwind styles
| it with `aria-[current=true]:` and a screen reader reads it, so the two
| cannot disagree about which photograph is showing.
*/
document.addEventListener('click', (event) => {
    const thumb = event.target.closest('[data-gallery-thumb]');

    if (! thumb) {
        return;
    }

    const hero = document.querySelector('[data-gallery-hero]');

    if (! hero) {
        return;
    }

    hero.src = thumb.dataset.full;

    // Scoped to this strip rather than the document: a page with two galleries
    // would otherwise clear the selection on both.
    //
    // `data-gallery-strip` rather than `data-gallery-thumbs` deliberately —
    // see the view. A container name that is the button name plus one letter
    // makes every substring search over the markup wrong.
    const strip = thumb.closest('[data-gallery-strip]');

    strip?.querySelectorAll('[data-gallery-thumb]').forEach((other) => {
        other.removeAttribute('aria-current');
    });

    thumb.setAttribute('aria-current', 'true');
});

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
