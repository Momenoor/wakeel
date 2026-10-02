// Font size in the rich editor: a mark on the selected text, stored as
// <span data-font-size="14pt" style="font-size: 14pt">. Written against the
// editor's own TipTap (window.FilamentRichEditor.tiptap), so no build step.
// The PHP side: App\Filament\Support\EditorFontSize.
const { Mark } = window.FilamentRichEditor.tiptap.core

export default Mark.create({
    name: 'fontSize',

    parseHTML() {
        return [
            {
                tag: 'span[data-font-size]',
            },
        ]
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', HTMLAttributes, 0]
    },

    addAttributes() {
        return {
            'data-font-size': {
                default: null,
                parseHTML: (element) => element.getAttribute('data-font-size'),
                renderHTML: (attributes) => {
                    const size = attributes['data-font-size']

                    if (! size) {
                        return {}
                    }

                    return { 'data-font-size': size, style: `font-size: ${size}` }
                },
            },
        }
    },

    addCommands() {
        return {
            setFontSize:
                (size) =>
                ({ commands }) =>
                    commands.setMark(this.name, { 'data-font-size': size }),
            unsetFontSize:
                () =>
                ({ commands }) =>
                    commands.unsetMark(this.name),
        }
    },
})
