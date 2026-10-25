<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Chat',
    'intro' => 'Instant conversations inside the system between colleagues in the office, one-to-one or in groups, with pictures, files and voice messages. Find it under "Communication → Chat". The floating blue chat icon at the bottom of every page also opens your conversations without leaving your work.',
    'actions' => [

        [
            'id' => 'open',
            'title' => 'Open and read a conversation',
            'description' => 'The list on the side shows your conversations, the most recent first, with the time of the last message. A blue dot on the picture means unread messages. Clicking a conversation opens it and marks it read.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Chat'],
            'steps' => [
                ['text' => 'In the side menu, go to "Communication" → "Chat". Your conversations appear on one side. Until you choose one, the other panel shows "Pick a conversation, or search a colleague to start one."', 'shot' => 'chat-1'],
                ['text' => 'Click the conversation you want to see its messages: yours in blue, others\' in grey with the sender\'s name, the time sent and a ✓ mark when delivered.', 'shot' => 'chat-2-open'],
            ],
            'tips' => ['You hear a sound and get a notification when a new message arrives. You can mute the sound from the speaker icon in the top bar.'],
        ],

        [
            'id' => 'send',
            'title' => 'Send a message, a file or a voice message',
            'description' => 'Type your message, attach a file or record your voice.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Chat'],
            'steps' => [
                ['text' => 'Click in the "Type a message..." box at the bottom of the conversation and type your text. The paperclip icon attaches a file or picture, and the microphone icon records a voice message.', 'shot' => 'chat-3-typing'],
                ['text' => 'Click the blue send button (or press Enter). Your message appears for everyone straight away.', 'shot' => 'chat-4-sent'],
            ],
            'tips' => ['When you hover over a message, buttons appear: "Reply" to reply to it, "React" to add an emoji, and "Delete" to delete your own message.'],
        ],

        [
            'id' => 'start',
            'title' => 'Start a new conversation with a colleague',
            'description' => 'Search for a colleague\'s name to start a one-to-one conversation. If you already have a conversation together, that one opens, so you never get two.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Chat'],
            'steps' => [
                ['text' => 'Type the colleague\'s name in the "Start a chat with..." box above the list of conversations. The matching names appear.', 'shot' => 'chat-7-search'],
                ['text' => 'Click the colleague\'s name to open the conversation and start typing.'],
            ],
        ],

        [
            'id' => 'group',
            'title' => 'Create and manage a group',
            'description' => 'A group is a conversation between more than two people, such as the team of assistants. Whoever creates the group manages it: they rename it and add or remove members.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Chat'],
            'steps' => [
                ['text' => 'Click "New group", type the "Group name", search for your colleagues and tick each one you want, then click "Create group" (the number of members chosen shows in brackets).', 'shot' => 'chat-6-newgroup'],
                ['text' => 'To manage an existing group, open it and click the "Members" icon: "Rename", "Remove" next to any member, "Add" for new members, and "Leave the group" to leave it yourself.', 'shot' => 'chat-5-members'],
            ],
        ],

        [
            'id' => 'dock',
            'title' => 'The chat and to-do list windows',
            'description' => 'At the bottom of every page there are two bubbles: chat (blue) and "To-do list" (purple). The number on each is how many new items there are. Each opens a floating window next to its bubble. In the to-do list: "Add a to-do… (@ to mention someone)", "To the next day", "Hide done" / "Clear done", "Larger text" / "Smaller text" (remembered for you), and "Pin to the page". Both windows remember whether they were open or closed, for each user on each device, so they come back as you left them after you move to another page or refresh. On a phone, if there is no room for the window beside its bubble, it moves aside to stay on the screen (16 pixels from each edge, or centred if it is wider). It does the same when you drag the bubble, resize the window or turn the screen.',
            'roles' => ['Every user signed in to the system'],
            'permissions' => [],
            'steps' => [
                ['text' => 'Click the chat bubble to open the "Messages" window, or the to-do bubble to open the "To-do list". Click it again, or ×, to close it.', 'shot' => 'dock-1'],
            ],
            'tips' => ['Closed the window on your computer but it is still open on your phone? Each device remembers its own state.'],
        ],
    ],
];
