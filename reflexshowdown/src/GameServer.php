<?php
namespace App;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;

/**
 * GameServer — routing semua pesan WebSocket.
 *
 * FITUR BARU:
 *   - Room list: broadcast ke semua client saat ada perubahan room
 *   - PIN room: host bisa set PIN saat create_room
 *   - Live chat global: pesan antar semua pemain yang online
 */
class GameServer implements MessageComponentInterface
{
    private LoopInterface $loop;
    private DB            $db;
    private array         $rooms    = [];     // code => Room
    private array         $connRoom = [];     // connId => room_code
    private array         $connUser = [];     // connId => [username, player_id]
    private array         $allConns = [];     // connId => conn (semua koneksi login)

    public function __construct(LoopInterface $loop, DB $db)
    {
        $this->loop = $loop;
        $this->db   = $db;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        echo "[+] Koneksi baru #" . spl_object_id($conn) . "\n";
    }

    public function onMessage(ConnectionInterface $conn, $raw): void
    {
        $msg    = json_decode($raw, true);
        $type   = $msg['type'] ?? '';
        $connId = (string)spl_object_id($conn);

        match ($type) {
            'register'     => $this->register($conn, $connId, $msg),
            'login'        => $this->login($conn, $connId, $msg),
            'create_room'  => $this->createRoom($conn, $connId, $msg),
            'join_room'    => $this->joinRoom($conn, $connId, $msg),
            'start_game'   => $this->startGame($conn, $connId),
            'player_click' => $this->playerClick($connId, $msg),
            'leave_room'   => $this->leaveRoom($conn, $connId),
            'chat_send'    => $this->chatSend($conn, $connId, $msg),
            'get_rooms'    => $this->sendRoomList($conn),
            'set_char'     => $this->setChar($conn, $connId, $msg),
            'add_bot'      => $this->addBot($conn, $connId),
            'remove_bot'   => $this->removeBot($conn, $connId),
            default        => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $connId = (string)spl_object_id($conn);
        echo "[-] Putus #$connId\n";

        $code = $this->connRoom[$connId] ?? null;
        if ($code && isset($this->rooms[$code])) {
            $room = $this->rooms[$code];
            $room->removePlayer($conn);
            if ($room->isEmpty()) {
                unset($this->rooms[$code]);
                echo "    Room $code dihapus (kosong)\n";
            } else {
                $room->broadcast([
                    'type'    => 'room_update',
                    'players' => $this->playerList($room),
                    'host'    => $this->hostName($room),
                ]);
            }
            $this->broadcastRoomList();
        }

        unset($this->connRoom[$connId], $this->connUser[$connId], $this->allConns[$connId]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[ERR] " . $e->getMessage() . "\n";
        $conn->close();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    private function register(ConnectionInterface $conn, string $id, array $msg): void
    {
        $name = $this->sanitizeName($msg['username'] ?? '');
        $pass = $msg['password'] ?? '';

        if (!$name) {
            $this->err($conn, 'Username harus 2-20 karakter huruf/angka.');
            return;
        }
        if (strlen($pass) < 6) {
            $this->err($conn, 'Password minimal 6 karakter.');
            return;
        }

        $playerId = $this->db->register($name, $pass);
        if ($playerId === null) {
            $this->err($conn, 'Username sudah dipakai. Coba username lain.');
            return;
        }

        $this->connUser[$id]  = ['username' => $name, 'player_id' => $playerId];
        $this->allConns[$id]  = $conn;

        $conn->send(json_encode([
            'type'      => 'login_ok',
            'username'  => $name,
            'player_id' => $playerId,
            'is_new'    => true,
        ]));
        // Kirim room list saat pertama login
        $this->sendRoomList($conn);
        echo "    Register: $name\n";
    }

    private function login(ConnectionInterface $conn, string $id, array $msg): void
    {
        $name = $this->sanitizeName($msg['username'] ?? '');
        $pass = $msg['password'] ?? '';

        if (!$name) {
            $this->err($conn, 'Username tidak valid.');
            return;
        }

        $player = $this->db->verifyLogin($name, $pass);
        if (!$player) {
            $this->err($conn, 'Username atau password salah.');
            return;
        }

        $this->connUser[$id] = $player;
        $this->allConns[$id] = $conn;

        $conn->send(json_encode([
            'type'      => 'login_ok',
            'username'  => $player['username'],
            'player_id' => $player['player_id'],
            'is_new'    => false,
        ]));
        // Kirim room list saat pertama login
        $this->sendRoomList($conn);
        echo "    Login: {$player['username']}\n";
    }

    // ── Room ──────────────────────────────────────────────────────────────────

    private function createRoom(ConnectionInterface $conn, string $id, array $msg): void
    {
        if (!isset($this->connUser[$id])) {
            $this->err($conn, 'Login dulu sebelum membuat room.');
            return;
        }

        $this->leaveRoom($conn, $id);

        $rounds = max(3, min(10, (int)($msg['rounds'] ?? 5)));
        $maxPlayers = max(2, min(5, (int)($msg['max_players'] ?? 4)));

        // PIN opsional — kosong berarti room publik
        $pin = trim($msg['pin'] ?? '');
        $pin = preg_replace('/[^0-9]/', '', $pin);   // hanya angka
        if (strlen($pin) > 0 && strlen($pin) < 4) {
            $this->err($conn, 'PIN harus 4 digit angka atau kosong (tanpa PIN).');
            return;
        }
        $pin = strlen($pin) >= 4 ? substr($pin, 0, 6) : '';

        do {
            $code = strtoupper(substr(md5(uniqid('rs', true)), 0, 6));
        } while (isset($this->rooms[$code]));

        $room = new Room($code, $this->loop, $this->db, $rounds, $maxPlayers, $pin);
        $u    = $this->connUser[$id];
        $room->addPlayer($conn, $u['username'], $u['player_id']);
        $this->rooms[$code]  = $room;
        $this->connRoom[$id] = $code;

        $conn->send(json_encode([
            'type'        => 'room_joined',
            'code'        => $code,
            'is_host'     => true,
            'players'     => $this->playerList($room),
            'host'        => $u['username'],
            'rounds'      => $rounds,
            'max_players' => $maxPlayers,
            'has_pin'     => $pin !== '',
            'chars'       => $room->usedChars(),
        ]));

        $this->broadcastRoomList();
        echo "    Room dibuat: $code" . ($pin ? " (PIN)" : "") . " oleh {$u['username']}\n";
    }

    private function joinRoom(ConnectionInterface $conn, string $id, array $msg): void
    {
        if (!isset($this->connUser[$id])) {
            $this->err($conn, 'Login dulu sebelum bergabung ke room.');
            return;
        }

        $code = strtoupper(trim($msg['code'] ?? ''));
        $room = $this->rooms[$code] ?? null;

        if (!$room) {
            $this->err($conn, 'Room tidak ditemukan.');
            return;
        }
        if ($room->state !== Room::S_WAITING) {
            $this->err($conn, 'Game sedang berlangsung, tidak bisa bergabung.');
            return;
        }
        if (count($room->active()) >= $room->maxPlayers) {
            $this->err($conn, 'Room penuh.');
            return;
        }

        // Cek PIN jika room punya PIN
        if ($room->pin !== '') {
            $inputPin = trim($msg['pin'] ?? '');
            if ($inputPin !== $room->pin) {
                $this->err($conn, 'PIN salah.');
                return;
            }
        }

        $u = $this->connUser[$id];
        foreach ($room->active() as $p) {
            if ($p['username'] === $u['username']) {
                $this->err($conn, 'Kamu sudah ada di room ini.');
                return;
            }
        }

        $this->leaveRoom($conn, $id);

        $room->addPlayer($conn, $u['username'], $u['player_id']);
        $this->connRoom[$id] = $code;

        $room->broadcast([
            'type'    => 'room_update',
            'players' => $this->playerList($room),
            'host'    => $this->hostName($room),
            'chars'   => $room->usedChars(),
        ]);
        $conn->send(json_encode([
            'type'        => 'room_joined',
            'code'        => $code,
            'is_host'     => false,
            'players'     => $this->playerList($room),
            'host'        => $this->hostName($room),
            'rounds'      => $room->totalRounds,
            'max_players' => $room->maxPlayers,
            'has_pin'     => $room->pin !== '',
            'chars'       => $room->usedChars(),
        ]));

        $this->broadcastRoomList();
        echo "    {$u['username']} bergabung ke room $code\n";
    }

    private function leaveRoom(ConnectionInterface $conn, string $id): void
    {
        $code = $this->connRoom[$id] ?? null;
        if (!$code || !isset($this->rooms[$code])) return;

        $room = $this->rooms[$code];
        $room->removePlayer($conn);
        unset($this->connRoom[$id]);

        if ($room->isEmpty()) {
            unset($this->rooms[$code]);
            echo "    Room $code dihapus (ditinggalkan)\n";
        } else {
            $room->broadcast([
                'type'    => 'room_update',
                'players' => $this->playerList($room),
                'host'    => $this->hostName($room),
                'chars'   => $room->usedChars(),
            ]);
        }
        $this->broadcastRoomList();
    }

    private function startGame(ConnectionInterface $conn, string $id): void
    {
        $code = $this->connRoom[$id] ?? null;
        $room = $code ? ($this->rooms[$code] ?? null) : null;
        if (!$room) return;

        if ($id !== (string)$room->hostConnId) {
            $this->err($conn, 'Hanya host yang bisa memulai game.');
            return;
        }
        if (count($room->active()) < 2) {
            $this->err($conn, 'Minimal 2 pemain untuk memulai game.');
            return;
        }

        $room->startGame();
        $this->broadcastRoomList();   // update status room → playing
        echo "    Game dimulai di room $code\n";
    }

    private function playerClick(string $id, array $msg = []): void
    {
        $code = $this->connRoom[$id] ?? null;
        if ($code && isset($this->rooms[$code])) {
            $clientRt = isset($msg['client_rt']) && is_numeric($msg['client_rt'])
                ? (int)$msg['client_rt'] : null;
            $this->rooms[$code]->handleClick($id, $clientRt);
        }
    }

    // ── Karakter & Bot ──────────────────────────────────────────────────────

    private function setChar(ConnectionInterface $conn, string $id, array $msg): void
    {
        $code = $this->connRoom[$id] ?? null;
        $room = $code ? ($this->rooms[$code] ?? null) : null;
        if (!$room) return;
        $charIdx = max(0, min(4, (int)($msg['char_idx'] ?? 0)));
        if ($room->setChar($id, $charIdx)) {
            $room->broadcast([
                'type'    => 'room_update',
                'players' => $this->playerList($room),
                'host'    => $this->hostName($room),
                'chars'   => $room->usedChars(),
            ]);
        } else {
            $this->err($conn, 'Karakter itu sudah dipakai pemain lain.');
        }
    }

    private function addBot(ConnectionInterface $conn, string $id): void
    {
        $code = $this->connRoom[$id] ?? null;
        $room = $code ? ($this->rooms[$code] ?? null) : null;
        if (!$room) return;
        if ($id !== (string)$room->hostConnId) { $this->err($conn, 'Hanya host yang bisa menambah bot.'); return; }
        if (count($room->active()) >= $room->maxPlayers) { $this->err($conn, 'Room sudah penuh.'); return; }

        $botNames = ['Kage-Bot','Ronin-Bot','Yurei-Bot','Oni-Bot','Tengu-Bot'];
        $name = $botNames[$room->botCount() % count($botNames)] . (count($room->active()) + 1);
        $room->addBot($name);
        $room->broadcast([
            'type'    => 'room_update',
            'players' => $this->playerList($room),
            'host'    => $this->hostName($room),
            'chars'   => $room->usedChars(),
        ]);
        $this->broadcastRoomList();
    }

    private function removeBot(ConnectionInterface $conn, string $id): void
    {
        $code = $this->connRoom[$id] ?? null;
        $room = $code ? ($this->rooms[$code] ?? null) : null;
        if (!$room) return;
        if ($id !== (string)$room->hostConnId) { $this->err($conn, 'Hanya host yang bisa menghapus bot.'); return; }
        if ($room->removeBot()) {
            $room->broadcast([
                'type'    => 'room_update',
                'players' => $this->playerList($room),
                'host'    => $this->hostName($room),
                'chars'   => $room->usedChars(),
            ]);
            $this->broadcastRoomList();
        }
    }

    // ── Room list ─────────────────────────────────────────────────────────────

    /**
     * Kirim daftar semua room aktif ke satu koneksi atau semua koneksi.
     */
    private function sendRoomList(ConnectionInterface $conn): void
    {
        $conn->send(json_encode([
            'type'  => 'room_list',
            'rooms' => $this->buildRoomList(),
        ]));
    }

    /**
     * Broadcast room list ke semua koneksi yang sudah login.
     * Dipanggil setiap kali ada room dibuat, diubah, atau dihapus.
     */
    private function broadcastRoomList(): void
    {
        $payload = json_encode([
            'type'  => 'room_list',
            'rooms' => $this->buildRoomList(),
        ]);
        foreach ($this->allConns as $conn) {
            try { $conn->send($payload); } catch (\Throwable $_) {}
        }
    }

    private function buildRoomList(): array
    {
        $list = [];
        foreach ($this->rooms as $code => $room) {
            $list[] = [
                'code'        => $code,
                'host'        => $this->hostName($room),
                'players'     => count($room->active()),
                'max_players' => $room->maxPlayers,
                'rounds'      => $room->totalRounds,
                'status'      => $room->state === Room::S_WAITING ? 'waiting' : 'playing',
                'has_pin'     => $room->pin !== '',
            ];
        }
        return $list;
    }

    // ── Chat global ───────────────────────────────────────────────────────────

    private function chatSend(ConnectionInterface $conn, string $id, array $msg): void
    {
        if (!isset($this->connUser[$id])) return;

        $text = trim($msg['text'] ?? '');
        if ($text === '' || mb_strlen($text) > 100) return;

        $text     = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $username = $this->connUser[$id]['username'];
        $payload  = json_encode([
            'type'     => 'chat_message',
            'username' => $username,
            'text'     => $text,
            'time'     => date('H:i'),
        ]);

        foreach ($this->allConns as $c) {
            try { $c->send($payload); } catch (\Throwable $_) {}
        }
        echo "    Chat [{$username}]: $text\n";
    }

    // ── Utils ─────────────────────────────────────────────────────────────────

    private function sanitizeName(string $raw): string
    {
        $name = trim(preg_replace('/[^a-zA-Z0-9_\- ]/u', '', $raw));
        return (strlen($name) >= 2 && strlen($name) <= 20) ? $name : '';
    }

    private function playerList(Room $room): array
    {
        return array_values(array_map(fn($p) => [
            'username' => $p['username'],
            'char_idx' => $p['char_idx'],
            'is_bot'   => $p['is_bot'],
        ], $room->active()));
    }

    private function hostName(Room $room): string
    {
        return $room->players[(string)$room->hostConnId]['username'] ?? '';
    }

    private function err(ConnectionInterface $conn, string $msg): void
    {
        $conn->send(json_encode(['type' => 'error', 'message' => $msg]));
    }
}
