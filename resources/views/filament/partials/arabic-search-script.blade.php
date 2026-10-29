{{--
    Filament searches a select's options in the browser (and a checkbox list's, a builder's blocks) by the text
    taken apart (normalize('NFD')) and its accents dropped: Arabic taken apart too, as App\Support\ArabicSearch
    searches it — أ إ آ ؤ ئ, taken apart, lose their hamza and madda; ى is ي, ة is ه. Only text with Arabic in it;
    anything else as it was.
--}}
<script>
    (() => {
        const normalize = String.prototype.normalize;

        String.prototype.normalize = function (form) {
            const text = normalize.apply(this, arguments);

            return form === 'NFD' && /[؀-ۿ]/.test(text)
                ? text.replace(/[ٓ-ٕ]/g, '').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
                : text;
        };
    })();
</script>
