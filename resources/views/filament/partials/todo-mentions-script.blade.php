{{-- The to-do note's "@" suggestions (todoMentions), ready before Alpine starts. --}}
<script>
    document.addEventListener('alpine:init', () => {
            Alpine.data('todoMentions', (people) => ({
                people,
                open: false,
                query: '',
                at: -1,
                idx: 0,
                get matches() {
                    const q = this.query.toLowerCase();
                    return this.people.filter((name) => name.toLowerCase().includes(q)).slice(0, 6);
                },
                scan(el) {
                    const before = el.value.slice(0, el.selectionStart);
                    const at = before.lastIndexOf('@');
                    // An "@" starting a word, and what follows it, still being written.
                    if (at < 0 || (at > 0 && ! /\s/.test(before[at - 1])) || before.length - at > 31) {
                        this.open = false;
                        return;
                    }
                    this.at = at;
                    this.query = before.slice(at + 1);
                    this.idx = 0;
                    this.open = this.matches.length > 0;
                },
                pick(el, name) {
                    const end = el.selectionStart;
                    el.value = el.value.slice(0, this.at) + '@' + name + ' ' + el.value.slice(end);
                    const caret = this.at + name.length + 2;
                    el.setSelectionRange(caret, caret);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    this.open = false;
                    el.focus();
                },
                key(e, el) {
                    if (! this.open || this.matches.length === 0) {
                        return;
                    }
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        this.idx = (this.idx + (e.key === 'ArrowDown' ? 1 : this.matches.length - 1)) % this.matches.length;
                    } else if (e.key === 'Enter' || e.key === 'Tab') {
                        e.preventDefault();
                        e.stopPropagation();
                        this.pick(el, this.matches[this.idx]);
                    } else if (e.key === 'Escape') {
                        e.stopPropagation();
                        this.open = false;
                    }
                },
            }));
    
    });
</script>
