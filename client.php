<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>WebSocket Chat Test</title>
    <style>
        body {
            font-family: sans-serif;
        }

        #messages {
            border: 1px solid #ccc;
            height: 200px;
            overflow: auto;
            padding: 10px;
            margin-bottom: 5px;
        }

        #typingIndicator {
            font-style: italic;
            margin-bottom: 5px;
            color: #555;
        }

        #msgInput {
            width: 70%;
        }

        #usernameModal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        #usernameModalContent {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        #usernameModal input {
            width: 80%;
            padding: 5px;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>
    <h2>💬 WebSocket Chat</h2>
    <div id="messages"></div>
    <div id="typingIndicator"></div>
    <input type="text" id="msgInput" placeholder="Type a message">
    <button id="sendBtn">Send</button>
    <button id="resetBtn">Reset Local Chat</button>
    <h3>Active Users:</h3>
    <div id="activeUsers"></div>

    <div id="usernameModal">
        <div id="usernameModalContent">
            <h3>Enter your name</h3>
            <input type="text" id="usernameInput" placeholder="Your name">
            <br>
            <button id="setUsernameBtn">Start Chat</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const messages = document.getElementById('messages');
            const typingIndicator = document.getElementById('typingIndicator');
            const input = document.getElementById('msgInput');
            const sendBtn = document.getElementById('sendBtn');
            const resetBtn = document.getElementById('resetBtn');
            const usernameModal = document.getElementById('usernameModal');
            const usernameInput = document.getElementById('usernameInput');
            const setUsernameBtn = document.getElementById('setUsernameBtn');

            let username = localStorage.getItem('chatName');
            let socket;
            let typingTimeout;

            function startChat() {
                username = usernameInput.value.trim() || "Guest";
                localStorage.setItem('chatName', username);
                usernameModal.style.display = 'none';
                initWebSocket();
            }

            if (!username) {
                usernameModal.style.display = 'flex';
                setUsernameBtn.addEventListener('click', startChat);
                usernameInput.addEventListener('keypress', e => {
                    if (e.key === 'Enter') startChat();
                });
            } else {
                usernameModal.style.display = 'none';
                initWebSocket();
            }

            function initWebSocket() {
                // Load chat history
                const storedMessages = JSON.parse(localStorage.getItem('chatMessages') || '[]');
                storedMessages.forEach(msg => renderMessage(msg));

                // Connect WebSocket
                socket = new WebSocket("ws://10.0.144.28:8080/chat");

                socket.onopen = () => {
                    socket.send(JSON.stringify({
                        type: 'setName',
                        name: username || "Guest"
                    }));
                    renderMessage({
                        sender: 'System',
                        text: '✅ Connected to WebSocket server'
                    });
                };

                socket.onmessage = (event) => {
                    const data = JSON.parse(event.data);

                    if (data.type === 'typing') {
                        const senderName = typeof data.sender === 'object' ? data.sender.name : data.sender;
                        typingIndicator.textContent = data.typing ? `${senderName} is typing...` : '';
                    } else if (data.type === 'activeUsers') {
                        const usersDiv = document.getElementById('activeUsers');
                        usersDiv.innerHTML = data.users
                            .sort((a, b) => a.name.localeCompare(b.name))
                            .map(u => {
                                const color = u.online ? 'green' : 'red';
                                const statusText = u.online ? '' : ' (Disconnected)';
                                return `<div><span style="color:${color}; font-weight:bold;">●</span> ${u.name}${statusText}</div>`;
                            }).join('');
                    } else {
                        // Safely handle sender as string
                        const senderName = typeof data.sender === 'object' && data.sender !== null ? data.sender.name : data.sender;
                        const text = typeof data.text === 'string' ? data.text : JSON.stringify(data.text);

                        renderMessage({
                            sender: senderName,
                            text
                        });
                        saveMessage({
                            sender: senderName,
                            text
                        });
                    }
                };




                socket.onclose = () => {
                    renderMessage({
                        sender: 'System',
                        text: '❌ Disconnected from server'
                    });
                };

                sendBtn.onclick = sendMessage;
                input.addEventListener("keypress", e => {
                    if (e.key === "Enter") sendMessage();
                });

                // Typing detection
                input.addEventListener('input', () => {
                    if (!socket || socket.readyState !== WebSocket.OPEN) return;

                    socket.send(JSON.stringify({
                        type: 'typing',
                        typing: true
                    }));

                    clearTimeout(typingTimeout);
                    typingTimeout = setTimeout(() => {
                        socket.send(JSON.stringify({
                            type: 'typing',
                            typing: false
                        }));
                    }, 1000); // stop typing after 1 second of inactivity
                });

                resetBtn.onclick = () => {
                    localStorage.removeItem('chatMessages');
                    messages.innerHTML = '';
                    alert("✅ Local chat history cleared.");
                };

                function sendMessage() {
                    const text = input.value.trim();
                    if (!text || !socket || socket.readyState !== WebSocket.OPEN) return;

                    const message = {
                        sender: username,
                        text
                    };
                    socket.send(JSON.stringify({
                        text
                    }));
                    renderMessage(message, true);
                    saveMessage(message);

                    // Stop typing after sending
                    socket.send(JSON.stringify({
                        type: 'typing',
                        typing: false
                    }));
                    input.value = '';
                }

                function renderMessage(msg, isOwn = false) {
                    const div = document.createElement('div');
                    const senderName = typeof msg.sender === 'object' && msg.sender !== null ? msg.sender.name : msg.sender;

                    if (senderName === 'System') {
                        div.innerHTML = `<i>${msg.text}</i>`; // system messages like disconnections
                    } else {
                        div.innerHTML = isOwn ?
                            `<b>You:</b> ${msg.text}` :
                            `<b>${senderName}:</b> ${msg.text}`;
                    }

                    messages.appendChild(div);
                    messages.scrollTop = messages.scrollHeight;
                }



                function saveMessage(msg) {
                    const current = JSON.parse(localStorage.getItem('chatMessages') || '[]');
                    current.push(msg);
                    localStorage.setItem('chatMessages', JSON.stringify(current));
                } // inside initWebSocket()
                setInterval(() => {
                    if (socket && socket.readyState === WebSocket.OPEN) {
                        socket.send(JSON.stringify({
                            type: 'ping'
                        }));
                    }
                }, 5000); // every 5 seconds

            }
        });
    </script>
</body>

</html>