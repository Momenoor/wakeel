{{--
    The emoji picker of the chat's message box (chat-emoji-button): its emojis kept here once for the page, not
    sent again with every redraw of the conversation. A group per tab, those used lately first (kept in this
    browser); a tap puts one where the cursor is, and the picker stays open for another.
--}}
@php
    $emojiGroups = [
        ['icon' => '😀', 'label' => __('Smileys'), 'emojis' => '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😗 😚 😋 😛 😜 🤪 😝 🤨 🧐 🤓 😎 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🤭 🤫 😶 😐 😑 😬 🙄 😯 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤧 😷 🤒 🤕'],
        ['icon' => '👍', 'label' => __('Gestures and people'), 'emojis' => '👍 👎 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 👋 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 🫡 🫶 👀 🧠 🙋 🙋‍♂️ 🙋‍♀️ 🤷 🤷‍♂️ 🤷‍♀️ 🤦 🤦‍♂️ 🤦‍♀️ 🙇 👨‍💼 👩‍💼 👨‍⚖️ 👩‍⚖️ 👨‍💻 👩‍💻 👪'],
        ['icon' => '❤️', 'label' => __('Hearts and symbols'), 'emojis' => '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 ✅ ☑️ ✔️ ❌ ❎ ⚠️ ❗ ❓ ‼️ ⁉️ 💯 🔥 ✨ ⭐ 🌟 💫 🎉 🎊 🎁 🏆 🥇 🎯 💡 🔔 📌 📍 🔴 🟠 🟡 🟢 🔵 🟣 ⚫ ⚪ ➡️ ⬅️ ⬆️ ⬇️ 🔁 🆗 🆕'],
        ['icon' => '💼', 'label' => __('Work and objects'), 'emojis' => '📄 📃 📑 📋 📁 📂 🗂️ 📅 📆 🗓️ ⏰ ⏳ ⌛ ⌚ 📞 ☎️ 📱 💻 🖥️ 🖨️ ⌨️ 📧 📨 📩 📤 📥 📎 🖇️ ✏️ 🖊️ 🖋️ 📝 🔍 🔎 🔒 🔓 🔑 ⚖️ 🏛️ 🏢 🏦 💼 💰 💵 💳 🧾 📊 📈 📉 🚗 ✈️ 🏠 🕌 ☕'],
        ['icon' => '🌴', 'label' => __('Nature and food'), 'emojis' => '☀️ 🌤️ ⛅ 🌧️ ⛈️ 🌙 🌈 ❄️ 🌊 🌴 🌵 🌹 🌸 🌻 🍀 🍁 🐪 🐎 🦅 🐱 🐶 🍎 🍊 🍋 🍉 🍇 🍓 🍌 🥭 🍞 🥐 🍔 🍕 🌯 🥗 🍚 🍲 🍰 🎂 🍩 🍪 🍫 🍬 🍵 🥤 🧃 🍽️'],
        ['icon' => '🇦🇪', 'label' => __('Flags'), 'emojis' => '🇦🇪 🇸🇦 🇪🇬 🇯🇴 🇱🇧 🇸🇾 🇮🇶 🇰🇼 🇶🇦 🇧🇭 🇴🇲 🇾🇪 🇵🇸 🇲🇦 🇩🇿 🇹🇳 🇸🇩 🇮🇳 🇵🇰 🇧🇩 🇱🇰 🇵🇭 🇳🇵 🇬🇧 🇺🇸 🏳️ 🏴'],
    ];
@endphp
<style>
    /* The picker's tabs and emojis scroll (by wheel, touch or trackpad) with no scroll bar showing. */
    .wk-noscroll { scrollbar-width: none; -ms-overflow-style: none; }
    .wk-noscroll::-webkit-scrollbar { display: none; width: 0; height: 0; }
</style>
<script>
    (() => {
        const groups = @js($emojiGroups).map((g) => ({ ...g, emojis: g.emojis.split(' ') }));
        const KEY = 'wakeel-emoji-recent';
        const recentLabel = @js(__('Recently used'));

        const register = () => Alpine.data('wkEmoji', () => ({
            open: false,
            tab: 0,
            recent: [],

            init() {
                try { this.recent = JSON.parse(localStorage.getItem(KEY) || '[]').slice(0, 24); } catch (e) { this.recent = []; }
                this.tab = this.recent.length ? -1 : 0;
            },

            get groups() {
                return groups;
            },

            get shown() {
                return this.tab === -1 ? this.recent : groups[this.tab].emojis;
            },

            get title() {
                return this.tab === -1 ? recentLabel : groups[this.tab].label;
            },

            toggle() {
                this.open = ! this.open;
            },

            // Put where the cursor is in the message box, told to Livewire as typing would be.
            pick(emoji) {
                const input = this.$el.closest('form')?.querySelector('[x-ref="input"]');
                if (input) {
                    const start = input.selectionStart ?? input.value.length;
                    const end = input.selectionEnd ?? start;
                    input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
                    const at = start + emoji.length;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    // A phone's keyboard is left closed; a computer keeps typing where it was.
                    if (! window.matchMedia('(pointer: coarse)').matches) {
                        input.focus();
                    }
                    try { input.setSelectionRange(at, at); } catch (e) {}
                }
                this.recent = [emoji, ...this.recent.filter((e) => e !== emoji)].slice(0, 24);
                try { localStorage.setItem(KEY, JSON.stringify(this.recent)); } catch (e) {}
            },
        }));

        if (window.Alpine) {
            register();
        } else {
            document.addEventListener('alpine:init', register);
        }
    })();
</script>
