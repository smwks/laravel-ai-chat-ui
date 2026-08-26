/**
 * Plain-text search over a rendered ai-chat-ui JSON tree (see
 * partials/json-viewer.blade.php). Not JSON-aware — it walks the already
 * -rendered DOM text nodes, wraps matches in <mark>, and expands any
 * collapsed ancestor (an element with an Alpine x-data holding `open`)
 * so a match inside a collapsed node becomes visible.
 */
function aiChatUiJsonSearch(root, search) {
    root.querySelectorAll('mark.json-search-hit').forEach((mark) => {
        const parent = mark.parentNode;
        parent.replaceChild(document.createTextNode(mark.textContent), mark);
        parent.normalize();
    });

    const needle = search.trim().toLowerCase();

    if (!needle) {
        return;
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const matches = [];
    let node;

    while ((node = walker.nextNode())) {
        if (node.textContent.toLowerCase().includes(needle)) {
            matches.push(node);
        }
    }

    matches.forEach((textNode) => {
        const text = textNode.textContent;
        const lower = text.toLowerCase();
        const frag = document.createDocumentFragment();
        let cursor = 0;
        let idx;

        while ((idx = lower.indexOf(needle, cursor)) !== -1) {
            frag.appendChild(document.createTextNode(text.slice(cursor, idx)));

            const mark = document.createElement('mark');
            mark.className = 'json-search-hit bg-yellow-200';
            mark.textContent = text.slice(idx, idx + needle.length);
            frag.appendChild(mark);

            cursor = idx + needle.length;
        }

        frag.appendChild(document.createTextNode(text.slice(cursor)));

        const parent = textNode.parentNode;
        parent.replaceChild(frag, textNode);

        let el = parent;
        while (el && el !== root) {
            if (el.hasAttribute && el.hasAttribute('x-data') && window.Alpine) {
                const data = window.Alpine.$data(el);
                if (data && data.open === false) {
                    data.open = true;
                }
            }
            el = el.parentElement;
        }
    });

    const first = root.querySelector('mark.json-search-hit');
    if (first) {
        first.scrollIntoView({block: 'nearest'});
    }
}

window.aiChatUiJsonSearch = aiChatUiJsonSearch;
