// Where things are. A placed item is kept in the page's DISPLAY space at scale 1 — what pdf.js shows: the
// page's CropBox, turned by its /Rotate, one unit per PDF point, origin top-left, y down — so the screen
// only multiplies by the zoom. Only when the PDF is written is it turned into the page's own PDF space.

export const POINTS_PER_MM = 72 / 25.4;

export function mmToPoints(mm) {
    return mm * POINTS_PER_MM;
}

export function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

export function center(item) {
    return { x: item.x + item.w / 2, y: item.y + item.h / 2 };
}

// Kept on its page: at least its centre stays on the paper.
export function keepOnPage(item, page) {
    const { x, y } = center(item);

    item.x = clamp(x, 0, page.w) - item.w / 2;
    item.y = clamp(y, 0, page.h) - item.h / 2;

    return item;
}

// Resized from its centre by how far the pointer moved from it — the same however it is turned, and the
// aspect never changes.
export function resized(start, startPointer, pointer, minSide = 6) {
    const c = center(start);
    const before = Math.hypot(startPointer.x - c.x, startPointer.y - c.y) || 1;
    const factor = Math.max(Math.hypot(pointer.x - c.x, pointer.y - c.y) / before, minSide / Math.min(start.w, start.h));
    const w = start.w * factor;
    const h = start.h * factor;

    return { x: c.x - w / 2, y: c.y - h / 2, w, h };
}

// Turned about its centre by the pointer's angle around it (clockwise on screen, in degrees); it snaps to
// the nearest 45° when close to one.
export function rotated(start, startPointer, pointer) {
    const c = center(start);
    const angle = (point) => Math.atan2(point.y - c.y, point.x - c.x) * 180 / Math.PI;
    let rot = start.rot + angle(pointer) - angle(startPointer);
    rot = ((rot % 360) + 360) % 360;

    const nearest = Math.round(rot / 45) * 45;

    return Math.abs(rot - nearest) < 4 ? nearest % 360 : rot;
}

/**
 * Where pdf-lib draws an item on its page.
 *
 * viewport: the page's pdf.js viewport at scale 1 — it knows the CropBox (its offset included) and the
 * /Rotate, and convertToPdfPoint() undoes both. The item's centre is taken there; the image is then turned
 * by the page's rotation less the item's own (pdf-lib turns counter-clockwise in PDF space, the screen
 * clockwise, and the page is shown turned clockwise by /Rotate). pdf-lib turns an image about its
 * bottom-left corner, so that corner is found from the centre.
 */
export function pdfPlacement(viewport, item) {
    const c = center(item);
    const [cx, cy] = viewport.convertToPdfPoint(c.x, c.y);
    const angle = (((viewport.rotation - item.rot) % 360) + 360) % 360;
    const radians = angle * Math.PI / 180;
    const cos = Math.cos(radians);
    const sin = Math.sin(radians);
    const halfW = item.w / 2;
    const halfH = item.h / 2;

    return {
        x: cx - (cos * halfW - sin * halfH),
        y: cy - (sin * halfW + cos * halfH),
        width: item.w,
        height: item.h,
        angle,
    };
}

/**
 * Where pdf-lib draws an upright box of the page's display space (a changed line's cover): its two corners
 * taken back through the viewport, so a CropBox offset or a /Rotate is undone as for an item.
 */
export function pdfRectangle(viewport, box) {
    const [x1, y1] = viewport.convertToPdfPoint(box.x, box.y);
    const [x2, y2] = viewport.convertToPdfPoint(box.x + box.w, box.y + box.h);

    return { x: Math.min(x1, x2), y: Math.min(y1, y2), width: Math.abs(x2 - x1), height: Math.abs(y2 - y1) };
}
