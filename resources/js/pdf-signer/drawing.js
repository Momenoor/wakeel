// Drawing a signature by hand (signature_pad): shared by the SignaturePad form field and the PDF editor's
// "Draw now".
import SignaturePad from 'signature_pad';

export function createPad(canvas, { penColor = '#0b1f66' } = {}) {
    const pad = new SignaturePad(canvas, {
        penColor,
        minWidth: 0.8,
        maxWidth: 2.8,
        // Transparent: the ink alone goes on the paper.
        backgroundColor: 'rgba(0,0,0,0)',
    });

    // Measured again as each stroke begins: on a phone the pad could be measured before it was laid out (0
    // wide), its drawing surface left 1 pixel across and stretched over the box — nothing drawn showed.
    pad.addEventListener('beginStroke', () => {
        if (! isFitted(canvas)) {
            fitPad(pad, canvas);
        }
    });

    return pad;
}

// Whether the canvas's pixels match its box at the screen's density.
function isFitted(canvas) {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);

    return canvas.offsetWidth === 0 || canvas.width === Math.round(canvas.offsetWidth * ratio);
}

// The canvas's pixels sized to its box at the screen's density (sharp ink), keeping what was drawn.
export function fitPad(pad, canvas) {
    // Not laid out yet (hidden, or still opening): measured later, at the first stroke or the next resize.
    if (canvas.offsetWidth === 0 || canvas.offsetHeight === 0) {
        return;
    }

    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const strokes = pad.toData();

    canvas.width = Math.max(1, Math.round(canvas.offsetWidth * ratio));
    canvas.height = Math.max(1, Math.round(canvas.offsetHeight * ratio));
    canvas.getContext('2d').scale(ratio, ratio);

    pad.clear();
    pad.fromData(strokes);
}

export function undoStroke(pad) {
    const strokes = pad.toData();
    strokes.pop();
    pad.fromData(strokes);
}

// The drawing cropped to its ink, as a PNG data URL — or null when nothing is drawn.
export function trimmedDataUrl(canvas, padding = 6) {
    const { width, height } = canvas;
    const pixels = canvas.getContext('2d').getImageData(0, 0, width, height).data;
    let top = height;
    let left = width;
    let right = -1;
    let bottom = -1;

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (pixels[(y * width + x) * 4 + 3] > 8) {
                if (x < left) left = x;
                if (x > right) right = x;
                if (y < top) top = y;
                if (y > bottom) bottom = y;
            }
        }
    }

    if (right < 0) {
        return null;
    }

    left = Math.max(0, left - padding);
    top = Math.max(0, top - padding);
    right = Math.min(width - 1, right + padding);
    bottom = Math.min(height - 1, bottom + padding);

    const cropped = document.createElement('canvas');
    cropped.width = right - left + 1;
    cropped.height = bottom - top + 1;
    cropped.getContext('2d').drawImage(canvas, left, top, cropped.width, cropped.height, 0, 0, cropped.width, cropped.height);

    return cropped.toDataURL('image/png');
}
