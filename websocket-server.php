<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\App;
use React\EventLoop\Loop;

class Chat implements MessageComponentInterface
{
    protected $clients;
    protected $usernames; // store username per connection ['resourceId' => ['name'=>string, 'online'=>bool, 'lastPing'=>timestamp]]

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->usernames = [];
        echo "Server started...\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    protected function broadcastActiveUsers()
    {
        $userList = [];

        // Map username => online status (true if any connection is online)
        $userStatus = [];
        foreach ($this->usernames as $u) {
            $name = $u['name'];
            $online = $u['online'];
            if (!isset($userStatus[$name])) {
                $userStatus[$name] = $online;
            } else {
                $userStatus[$name] = $userStatus[$name] || $online;
            }
        }

        foreach ($userStatus as $name => $online) {
            $userList[] = [
                'name' => $name,
                'online' => $online
            ];
        }

        foreach ($this->clients as $client) {
            $client->send(json_encode([
                'type' => 'activeUsers',
                'users' => $userList
            ]));
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        if (!$data) return;

        // ------------------------------
        // Handle heartbeat/ping
        if (isset($data['type']) && $data['type'] === 'ping') {
            if (isset($this->usernames[$from->resourceId])) {
                $this->usernames[$from->resourceId]['lastPing'] = time();
                $this->usernames[$from->resourceId]['online'] = true;
                $this->broadcastActiveUsers();
            }
            return;
        }

        // ------------------------------
        // Handle username assignment
        if (isset($data['type']) && $data['type'] === 'setName') {
            $this->usernames[$from->resourceId] = [
                'name' => $data['name'],
                'online' => true,
                'lastPing' => time()
            ];

            $from->send(json_encode([
                'sender' => 'System',
                'text' => "✅ Your name is set to {$data['name']}"
            ]));

            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send(json_encode([
                        'sender' => 'System',
                        'text' => "✅ {$data['name']} has joined the chat"
                    ]));
                }
            }

            $this->broadcastActiveUsers();
            return;
        }

        // ------------------------------
        // Handle typing
        if (isset($data['type']) && $data['type'] === 'typing') {
            $sender = isset($this->usernames[$from->resourceId]['name'])
                ? $this->usernames[$from->resourceId]['name']
                : 'Unknown';
            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send(json_encode([
                        'type' => 'typing',
                        'sender' => $sender,
                        'typing' => $data['typing']
                    ]));
                }
            }
            return;
        }

        // ------------------------------
        // Handle normal text messages
        if (isset($data['text'])) {
            $sender = isset($this->usernames[$from->resourceId]['name'])
                ? $this->usernames[$from->resourceId]['name']
                : 'Unknown';
            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send(json_encode([
                        'sender' => $sender,
                        'text' => $data['text']
                    ]));
                }
            }
        }
    }
    public function onClose(ConnectionInterface $conn)
    {
        if (isset($this->usernames[$conn->resourceId])) {
            $name = $this->usernames[$conn->resourceId]['name'];
            $this->usernames[$conn->resourceId]['online'] = false;

            // Broadcast system message to ALL clients
            foreach ($this->clients as $client) {
                $client->send(json_encode([
                    'sender' => 'System',
                    'text' => "❌ {$name} disconnected"
                ]));
            }
        }

        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} disconnected\n";

        $this->broadcastActiveUsers();
    }



    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    public function checkInactiveUsers()
    {
        $now = time();
        foreach ($this->usernames as $id => &$user) {

            // --- Check for timeout ---
            if ($user['online'] && isset($user['lastPing']) && ($now - $user['lastPing'] > 30)) {
                $user['online'] = false;

                // Broadcast timeout message once
                foreach ($this->clients as $client) {
                    $client->send(json_encode([
                        'sender' => 'System',
                        'text' => "❌ {$user['name']} disconnected (timeout)"
                    ]));
                }

                $this->broadcastActiveUsers();
            }

            // --- Check for reconnection ---
            if (!$user['online'] && isset($user['lastPing']) && ($now - $user['lastPing'] <= 30)) {
                $user['online'] = true;

                // Broadcast reconnection message once
                foreach ($this->clients as $client) {
                    $client->send(json_encode([
                        'sender' => 'System',
                        'text' => "✅ {$user['name']} reconnected"
                    ]));
                }

                $this->broadcastActiveUsers();
            }
        }
    }
}

// ------------------------------
// Run server with periodic cleanup
$loop = Loop::get();
$chat = new Chat();

// optional: check inactive users every 10s
$loop->addPeriodicTimer(10, function () use ($chat) {
    $chat->checkInactiveUsers();
});

// ✅ Use LAN IP so others can connect
$app = new Ratchet\App('10.0.144.28', 8080, '0.0.0.0', $loop);
$app->route('/chat', $chat, ['*']);
$app->run();
