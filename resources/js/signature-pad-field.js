// The SignaturePad form field (App\Filament\Forms\Components\SignaturePad), loaded by Alpine when the field
// is on the page: its state is the drawing as a PNG data URL cropped to the ink, or null when empty.
import { createPad, fitPad, trimmedDataUrl, undoStroke } from './pdf-signer/drawing.js';

export default function signaturePadField({ state, penColor, disabled }) {
    let pad = null;
    let resizeObserver = null;

    return {
        state,
        empty: true,

        init() {
            const canvas = this.$refs.canvas;
            pad = createPad(canvas, { penColor });

            if (disabled) {
                pad.off();
            }

            fitPad(pad, canvas);
            resizeObserver = new ResizeObserver(() => fitPad(pad, canvas));
            resizeObserver.observe(canvas);

            pad.addEventListener('endStroke', () => this.commit());

            // Emptied from outside (the form was reset): the pad follows.
            this.$watch('state', (value) => {
                if (! value && ! pad.isEmpty()) {
                    pad.clear();
                    this.empty = true;
                }
            });
        },

        commit() {
            this.empty = pad.isEmpty();
            this.state = this.empty ? null : trimmedDataUrl(this.$refs.canvas);
        },

        undo() {
            undoStroke(pad);
            this.commit();
        },

        clear() {
            pad.clear();
            this.commit();
        },

        destroy() {
            resizeObserver?.disconnect();
            pad?.off();
        },
    };
}
