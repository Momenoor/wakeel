// A PDF handed from one PDF tool to the next — merged, then compressed, then split — without leaving the browser:
// kept in this browser's own storage (IndexedDB) while the next tool's page opens, and taken out (and forgotten)
// by it. Nothing goes to the server.

const DATABASE = 'pdf-tools';
const STORE = 'handoff';
const KEY = 'next';
// A PDF left there longer than this (its page closed before the next one opened) is not taken up any more.
const FRESH_FOR = 60 * 60 * 1000;

function open() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function inStore(database, mode, act) {
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(STORE, mode);
        const request = act(transaction.objectStore(STORE));
        transaction.oncomplete = () => resolve(request?.result);
        transaction.onerror = () => reject(transaction.error);
    });
}

// The PDF kept for the next tool, which is then opened.
export async function handOn(blob, name, url) {
    const database = await open();
    await inStore(database, 'readwrite', (store) => store.put({ blob, name, at: Date.now() }, KEY));
    database.close();
    window.location.href = url;
}

// The PDF handed on to this page, if any: { blob, name } — taken out, so it is used once.
export async function takeHandoff() {
    if (! new URLSearchParams(window.location.search).has('handoff') || ! window.indexedDB) {
        return null;
    }

    try {
        const database = await open();
        const kept = await inStore(database, 'readonly', (store) => store.get(KEY));
        await inStore(database, 'readwrite', (store) => store.delete(KEY));
        database.close();

        return kept && Date.now() - kept.at < FRESH_FOR ? { blob: kept.blob, name: kept.name } : null;
    } catch {
        return null;
    }
}
