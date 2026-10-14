// Table borders and cell alignment in the rich editor.
//
// On a table cell:
//   data-bw      border width in px (0 = none)
//   data-bc      border colour, #rrggbb
//   data-bs      the sides drawn, from t r b l (top right bottom left; physical)
//   data-halign  start | center | end        (text-align; start/end follow the text direction)
//   data-valign  top | middle | bottom       (vertical-align)
//
// The nine alignments are Flutter's AlignmentDirectional — top/centre/bottom
// by start/centre/end — so they mean the same in Arabic and English.
// Written against the editor's own TipTap (window.FilamentRichEditor.tiptap),
// so no build step. The PHP side: App\Filament\Support\EditorTableFormat and
// TableFormatExtension.
const { Extension, findParentNode } = window.FilamentRichEditor.tiptap.core

const CELLS = ['tableCell', 'tableHeader']
const WIDTHS = ['0', '0.5', '1', '1.5', '2', '3']
const SIDES = ['t', 'r', 'b', 'l']
const HORIZONTAL = ['start', 'center', 'end']
const VERTICAL = ['top', 'middle', 'bottom']

const colour = (value) => (/^#[0-9a-f]{6}$/i.test(value ?? '') ? value.toLowerCase() : null)
const oneOf = (value, allowed) => (allowed.includes(value) ? value : null)

// The sides drawn, in t r b l order — null for all four (the default) and for anything else.
const sides = (value) => {
    if (typeof value !== 'string' || ! /^[trbl]{1,4}$/.test(value)) {
        return null
    }

    const ordered = SIDES.filter((side) => value.includes(side)).join('')

    return ordered === 'trbl' ? null : ordered
}

export default Extension.create({
    name: 'tableFormat',

    addGlobalAttributes() {
        return [
            {
                types: CELLS,
                attributes: {
                    'data-bw': {
                        default: null,
                        parseHTML: (element) => oneOf(element.getAttribute('data-bw'), WIDTHS),
                        renderHTML: (attributes) => {
                            const width = oneOf(attributes['data-bw'], WIDTHS)

                            if (width === null) {
                                return {}
                            }

                            return {
                                'data-bw': width,
                                style: width === '0' ? 'border-width: 0; border-style: none' : `border-width: ${width}px; border-style: solid`,
                            }
                        },
                    },
                    'data-bc': {
                        default: null,
                        parseHTML: (element) => colour(element.getAttribute('data-bc')),
                        renderHTML: (attributes) => {
                            const value = colour(attributes['data-bc'])

                            return value ? { 'data-bc': value, style: `border-color: ${value}` } : {}
                        },
                    },
                    'data-bs': {
                        default: null,
                        parseHTML: (element) => sides(element.getAttribute('data-bs')),
                        renderHTML: (attributes) => {
                            const drawn = sides(attributes['data-bs'])

                            if (drawn === null) {
                                return {}
                            }

                            return {
                                'data-bs': drawn,
                                style: `border-style: ${SIDES.map((side) => (drawn.includes(side) ? 'solid' : 'none')).join(' ')}`,
                            }
                        },
                    },
                    'data-halign': {
                        default: null,
                        parseHTML: (element) => oneOf(element.getAttribute('data-halign'), HORIZONTAL),
                        renderHTML: (attributes) => {
                            const align = oneOf(attributes['data-halign'], HORIZONTAL)

                            return align ? { 'data-halign': align, style: `text-align: ${align}` } : {}
                        },
                    },
                    'data-valign': {
                        default: null,
                        parseHTML: (element) => oneOf(element.getAttribute('data-valign'), VERTICAL),
                        renderHTML: (attributes) => {
                            const align = oneOf(attributes['data-valign'], VERTICAL)

                            return align ? { 'data-valign': align, style: `vertical-align: ${align}` } : {}
                        },
                    },
                },
            },
        ]
    },

    addCommands() {
        return {
            // The border of the selected cells — or, with the cursor in a
            // single cell, of the whole table. Width, colour and the sides
            // drawn are set separately: whichever is given changes, the rest stay.
            setTableBorder:
                ({ width, colour: borderColour, sides: borderSides } = {}) =>
                ({ state, tr }) => {
                    const selection = state.selection
                    const cells = []

                    if (selection.$anchorCell) {
                        selection.forEachCell((node, pos) => cells.push([node, pos]))
                    } else {
                        const table = findParentNode((node) => node.type.name === 'table')(selection)

                        if (! table) {
                            return false
                        }

                        table.node.descendants((node, pos) => {
                            if (CELLS.includes(node.type.name)) {
                                cells.push([node, table.start + pos])
                            }
                        })
                    }

                    cells.forEach(([node, pos]) => {
                        const attrs = { ...node.attrs }

                        if (width !== undefined) {
                            attrs['data-bw'] = oneOf(String(width), WIDTHS)
                        }

                        if (borderColour !== undefined) {
                            attrs['data-bc'] = colour(borderColour)
                        }

                        if (borderSides !== undefined) {
                            attrs['data-bs'] = sides(borderSides)
                        }

                        tr.setNodeMarkup(pos, undefined, attrs)
                    })

                    return cells.length > 0
                },

            // The cell's text, vertically and horizontally (start/end follow
            // the text direction): AlignmentDirectional's nine positions.
            setCellAlignment:
                (vertical, horizontal) =>
                ({ chain }) =>
                    chain()
                        .setCellAttribute('data-valign', oneOf(vertical, VERTICAL))
                        .setCellAttribute('data-halign', oneOf(horizontal, HORIZONTAL))
                        .run(),
        }
    },
})
