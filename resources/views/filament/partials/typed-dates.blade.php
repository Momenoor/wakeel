{{--
    A date, time or date-and-time picker typed into as well as picked from (App\Support\Dates): its box, which
    Filament keeps read-only, made writable — typing in the system's format (22/10/2026, 05:35 PM; 22/10/26,
    5/1/2026, 17:35 too), read when Enter is pressed or the box is left; what can't be read is put back as it
    was. Clicking still opens the calendar, the arrows still move in it.
--}}
<script>
    (() => {
        const box = (target) => target?.matches?.('input[data-typed-date]') && ! target.disabled ? target : null;

        // The format and the ways it is commonly typed short: day and month without their 0, a two-digit
        // year, a time without its 0 or in 24 hours.
        const formats = (format) => {
            const all = new Set([format]);
            const vary = (from, to) => [...all].forEach((f) => f.includes(from) && all.add(f.replace(from, to)));

            vary('DD', 'D');
            vary('MM', 'M');
            vary('YYYY', 'YY');
            vary('hh:mm A', 'h:mm A');
            vary('h:mm A', 'H:mm');
            vary('H:mm', 'HH:mm');
            vary('HH:mm', 'H:mm');

            return [...all];
        };

        // The text in the box taken as the picker's date; whether it could be.
        const take = (input) => {
            const picker = window.Alpine?.$data(input);

            if (! picker?.setState || ! window.dayjs) {
                return false;
            }

            // As typed on either keyboard: Arabic digits, am/pm in any case, ص/م.
            const text = input.value.trim()
                .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
                .replace(/\s*(am|ص)$/i, ' AM')
                .replace(/\s*(pm|م)$/i, ' PM');

            if (text === '') {
                picker.clearState();

                return true;
            }

            // Read in English: the page's dayjs may be in Arabic, whose AM/PM are ص/م (turned to AM/PM above).
            const date = window.dayjs(text, formats(input.dataset.typedDate), 'en', true);

            if (! date.isValid()) {
                picker.setDisplayText();

                return false;
            }

            picker.focusedDate = date;
            picker.hour = date.hour();
            picker.minute = date.minute();
            picker.second = 0;
            picker.setState(date);
            // One out of its limits (minDate, maxDate) is not taken: what was there comes back.
            picker.setDisplayText();

            return true;
        };

        document.addEventListener('focusin', (event) => {
            const input = box(event.target);

            if (input) {
                input.readOnly = false;
                input.setAttribute('autocomplete', 'off');
            }
        }, true);

        // Caught before the picker's own keys (Backspace and Delete emptied it, Space opened the calendar): the
        // box's typing is the box's. Enter takes what was typed — the calendar opened when there is nothing to take.
        document.addEventListener('keydown', (event) => {
            const input = box(event.target);

            if (! input || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (event.key === 'Enter') {
                if (input.dataset.typed && take(input)) {
                    delete input.dataset.typed;
                    event.preventDefault();
                    event.stopPropagation();
                    const picker = window.Alpine.$data(input);
                    picker.isOpen?.() && picker.closePanel?.();
                }

                return;
            }

            if (! ['ArrowUp', 'ArrowDown', 'Escape', 'Tab'].includes(event.key)) {
                event.stopPropagation();
            }
        }, true);

        // Typed into (not only looked at): read when the box is left.
        document.addEventListener('input', (event) => {
            const input = box(event.target);

            if (input) {
                input.dataset.typed = '1';
            }
        }, true);

        document.addEventListener('focusout', (event) => {
            const input = box(event.target);

            if (input?.dataset.typed) {
                delete input.dataset.typed;
                take(input);
            }
        }, true);
    })();
</script>
