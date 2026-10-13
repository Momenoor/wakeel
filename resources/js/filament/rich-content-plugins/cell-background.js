// Table cell shading in the rich editor: a background colour on a cell,
// stored as <td data-bg="#dbeafe" style="background-color: #dbeafe">. Written
// against the editor's own TipTap (window.FilamentRichEditor.tiptap), so no
// build step. The PHP side: App\Filament\Support\EditorCellBackground.
const { Extension } = window.FilamentRichEditor.tiptap.core

export default Extension.create({
    name: 'cellBackground',

    addGlobalAttributes() {
        return [
            {
                types: ['tableCell', 'tableHeader'],
                attributes: {
                    'data-bg': {
                        default: null,
                        parseHTML: (element) => element.getAttribute('data-bg'),
                        renderHTML: (attributes) => {
                            const colour = attributes['data-bg']

                            if (! colour) {
                                return {}
                            }

                            return { 'data-bg': colour, style: `background-color: ${colour}` }
                        },
                    },
                },
            },
        ]
    },

    addCommands() {
        return {
            setCellBackground:
                (colour) =>
                ({ commands }) =>
                    commands.setCellAttribute('data-bg', colour),
            unsetCellBackground:
                () =>
                ({ commands }) =>
                    commands.setCellAttribute('data-bg', null),
        }
    },
})
