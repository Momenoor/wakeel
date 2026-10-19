// Whether these bytes are a PDF: "%PDF-" within the first kilobyte, where the format allows it to be.
// Shared by the Sign PDF and Split PDF pages.
export function isPdf(bytes) {
    const head = new TextDecoder('latin1').decode(bytes.subarray(0, 1024));

    return head.includes('%PDF-');
}
