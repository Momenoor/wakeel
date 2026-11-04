// What the PDF tools (Split, Merge, Compress PDF) share: how a size is told, how words are filled in, how a number
// typed on any keyboard is read, how files are put in order, and how a result is downloaded.

// A megabyte as the size limits on upload pages are safest read: a million bytes (a part of "4.99 MB" here is
// under 5 MB however the page receiving it counts).
export const MB = 1_000_000;

export const fill = (text, values) => Object.entries(values).reduce((result, [key, value]) => result.replaceAll(`:${key}`, value), text);

export const megabytes = (bytes) => (bytes / MB).toFixed(2);

// A number as typed on any keyboard: Arabic-Indic (٠–٩) and Persian (۰–۹) digits, and "٫" or "," for the decimal
// point. A number field (type="number") took none of them — on an Arabic phone keyboard what was typed read as
// empty — so the fields are text, read here.
export function numberFrom(typed) {
    const text = String(typed ?? '')
        .trim()
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
        .replace(/[٫,]/g, '.')
        .replace(/\s+/g, '');

    return /^\d+(\.\d+)?$|^\.\d+$/.test(text) ? Number(text) : NaN;
}

// Names in the order a folder shows them: "2 - reply.pdf" before "10 - report.pdf", Arabic and English alike.
const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

export const byName = (a, b) => collator.compare(a, b);

export function save(blob, name) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 60_000);
}

// A name a file can be saved under, ending in ".pdf".
export function pdfName(name, fallback) {
    const base = String(name ?? '').replace(/\.pdf$/i, '').replace(/[\\/:*?"<>|]+/g, ' ').trim();

    return `${base || fallback}.pdf`;
}
