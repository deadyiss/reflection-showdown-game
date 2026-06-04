<?php
namespace App;

use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Room — state machine satu sesi permainan.
 *
 * FITUR BARU: Random Input Target
 *   Setiap ronde, server memilih target input secara acak:
 *   - Huruf A–Z  (contoh: "Press W")
 *   - Angka 0–9  (contoh: "Press 5")
 *   - Mouse kiri / kanan / tengah
 *
 *   Pemain HANYA mendapat poin jika menekan input yang TEPAT.
 *   Menekan input lain setelah sinyal = diabaikan (tidak dikirim ke server).
 *   Klik sebelum sinyal = early click = ronde berakhir = −1 poin.
 *
 * TARGET INPUT yang mungkin dipilih server:
 *   type: 'key'   → value: 'A'–'Z' atau '0'–'9'
 *   type: 'mouse' → value: 'left', 'right', 'middle'
 */
class Room
{
    const S_WAITING      = 'waiting';
    const S_ROUND_WAIT   = 'round_wait';
    const S_ROUND_SIGNAL = 'round_signal';
    const S_ROUND_RESULT = 'round_result';
    const S_GAME_OVER    = 'game_over';

    // Pool target yang bisa dipilih server
    private const TARGETS = [
        // Huruf
        ['type' => 'key', 'value' => 'A'], ['type' => 'key', 'value' => 'B'],
        ['type' => 'key', 'value' => 'C'], ['type' => 'key', 'value' => 'D'],
        ['type' => 'key', 'value' => 'E'], ['type' => 'key', 'value' => 'F'],
        ['type' => 'key', 'value' => 'G'], ['type' => 'key', 'value' => 'H'],
        ['type' => 'key', 'value' => 'J'], ['type' => 'key', 'value' => 'K'],
        ['type' => 'key', 'value' => 'L'], ['type' => 'key', 'value' => 'M'],
        ['type' => 'key', 'value' => 'N'], ['type' => 'key', 'value' => 'P'],
        ['type' => 'key', 'value' => 'Q'], ['type' => 'key', 'value' => 'R'],
        ['type' => 'key', 'value' => 'S'], ['type' => 'key', 'value' => 'T'],
        ['type' => 'key', 'value' => 'U'], ['type' => 'key', 'value' => 'V'],
        ['type' => 'key', 'value' => 'W'], ['type' => 'key', 'value' => 'X'],
        ['type' => 'key', 'value' => 'Y'], ['type' => 'key', 'value' => 'Z'],
        // Angka
        ['type' => 'key', 'value' => '0'], ['type' => 'key', 'value' => '1'],
        ['type' => 'key', 'value' => '2'], ['type' => 'key', 'value' => '3'],
        ['type' => 'key', 'value' => '4'], ['type' => 'key', 'value' => '5'],
        ['type' => 'key', 'value' => '6'], ['type' => 'key', 'value' => '7'],
        ['type' => 'key', 'value' => '8'], ['type' => 'key', 'value' => '9'],
        // Mouse
        ['type' => 'mouse', 'value' => 'left'],
        ['type' => 'mouse', 'value' => 'right'],
        ['type' => 'mouse', 'value' => 'middle'],
    ];

    public string  $code;
    public string  $state        = self::S_WAITING;
    public int     $totalRounds;
    public int     $maxPlayers;       // maks pemain (2-5)
    public string  $pin;              // PIN room, '' = tidak ada PIN
    public int     $currentRound = 0;
    public ?int    $sessionId    = null;
    public array   $players      = [];
    public ?string $hostConnId   = null;

    private LoopInterface   $loop;
    private DB              $db;
    private array           $clicks     = [];
    private array           $hasClicked = [];
    private int             $signalMs   = 0;
    private array           $target     = [];   // target ronde ini
    private array           $bots       = [];   // botConnId => true
    private array           $botTimers  = [];   // timer auto-click bot
    private $fastTimer = null;  // timer resolve cepat setelah ada yg klik
    private ?TimerInterface $timer      = null;

    public function __construct(string $code, LoopInterface $loop, DB $db, int $rounds = 5, int $maxPlayers = 4, string $pin = '')
    {
        $this->code        = $code;
        $this->loop        = $loop;
        $this->db          = $db;
        $this->totalRounds = $rounds;
        $this->maxPlayers  = $maxPlayers;
        $this->pin         = $pin;
    }

    // ── Player management ─────────────────────────────────────────────────────

    public function addPlayer(ConnectionInterface $conn, string $username, int $playerId, int $charIdx = -1): void
    {
        $id = $this->connId($conn);
        $this->players[$id] = [
            'conn'       => $conn,
            'username'   => $username,
            'player_id'  => $playerId,
            'points'     => 0,
            'rt_history' => [],
            'active'     => true,
            'is_bot'     => false,
            'char_idx'   => $charIdx >= 0 ? $charIdx : $this->firstFreeChar(),
        ];
        if ($this->hostConnId === null) $this->hostConnId = $id;
    }

    /** Tambah bot ke room. Bot punya connId virtual 'bot_N'. */
    public function addBot(string $botName): string
    {
        $id = 'bot_' . (count($this->bots) + 1) . '_' . substr(md5(uniqid()), 0, 4);
        $this->bots[$id] = true;
        $this->players[$id] = [
            'conn'       => null,
            'username'   => $botName,
            'player_id'  => 0,
            'points'     => 0,
            'rt_history' => [],
            'active'     => true,
            'is_bot'     => true,
            'char_idx'   => $this->firstFreeChar(),
        ];
        return $id;
    }

    /** Hapus 1 bot (yang terakhir ditambah). */
    public function removeBot(): bool
    {
        $botIds = array_keys($this->bots);
        if (empty($botIds)) return false;
        $last = end($botIds);
        unset($this->players[$last], $this->bots[$last]);
        return true;
    }

    public function botCount(): int { return count($this->bots); }

    /** Set karakter pemain, tolak jika sudah dipakai pemain lain. */
    public function setChar(string $connId, int $charIdx): bool
    {
        if (!isset($this->players[$connId])) return false;
        foreach ($this->players as $cid => $p) {
            if ($cid !== $connId && $p['active'] && $p['char_idx'] === $charIdx) {
                return false;  // sudah dipakai
            }
        }
        $this->players[$connId]['char_idx'] = $charIdx;
        return true;
    }

    /** Cari index karakter pertama yang belum dipakai (0-4). */
    private function firstFreeChar(): int
    {
        $used = [];
        foreach ($this->players as $p) {
            if ($p['active']) $used[$p['char_idx']] = true;
        }
        for ($i = 0; $i < 5; $i++) if (!isset($used[$i])) return $i;
        return 0;
    }

    /** Daftar index karakter yang sedang dipakai. */
    public function usedChars(): array
    {
        $out = [];
        foreach ($this->players as $p) {
            if ($p['active']) $out[$p['username']] = $p['char_idx'];
        }
        return $out;
    }

    public function removePlayer(ConnectionInterface $conn): void
    {
        $id = $this->connId($conn);
        if (isset($this->players[$id])) {
            $this->players[$id]['active'] = false;
        }
        if ($this->hostConnId === $id) {
            $this->hostConnId = null;
            foreach ($this->active() as $cid => $_) {
                $this->hostConnId = $cid;
                break;
            }
        }
    }

    public function active(): array
    {
        return array_filter($this->players, fn($p) => $p['active']);
    }

    public function isEmpty(): bool
    {
        return count($this->active()) === 0;
    }

    // ── Game flow ─────────────────────────────────────────────────────────────

    public function startGame(): void
    {
        if ($this->state !== self::S_WAITING) return;
        $this->currentRound = 0;
        foreach ($this->players as &$p) {
            $p['points']     = 0;
            $p['rt_history'] = [];
        }
        unset($p);

        $this->sessionId = $this->db->createSession($this->code, date('Y-m-d H:i:s'));
        $this->broadcast(['type' => 'game_start', 'total_rounds' => $this->totalRounds]);
        $this->timer = $this->loop->addTimer(1.5, fn() => $this->startWait());
    }

    private function startWait(): void
    {
        $this->currentRound++;
        $this->clicks     = [];
        $this->hasClicked = [];
        $this->signalMs   = 0;
        $this->target     = [];
        foreach ($this->botTimers as $t) { try { $this->loop->cancelTimer($t); } catch (\Throwable $_) {} }
        $this->botTimers  = [];
        $this->fastTimer  = null;
        $this->state      = self::S_ROUND_WAIT;

        $this->broadcast([
            'type'  => 'round_wait',
            'round' => $this->currentRound,
            'total' => $this->totalRounds,
        ]);

        $delay = rand(20, 60) / 10.0;
        $this->timer = $this->loop->addTimer($delay, fn() => $this->fireSignal());
    }

    private function fireSignal(): void
    {
        if ($this->state !== self::S_ROUND_WAIT) return;
        $this->state    = self::S_ROUND_SIGNAL;
        $this->signalMs = (int)(microtime(true) * 1000);

        // Pilih target random dari pool
        $this->target = self::TARGETS[array_rand(self::TARGETS)];

        $this->broadcast([
            'type'      => 'round_signal',
            'signal_ms' => $this->signalMs,
            'target'    => $this->target,    // kirim ke semua client
        ]);

        // Jadwalkan bot 'menekan' dengan reaction time acak.
        // EASY: 900–1600ms — setara kecepatan manusia mencari & menekan
        // tombol acak (huruf/klik berbeda tiap ronde). Bot tetap kalah kalau
        // pemain fokus & cepat, tapi menang kalau pemain lengah.
        foreach ($this->bots as $botId => $_) {
            if (!($this->players[$botId]['active'] ?? false)) continue;
            $reactionSec = rand(900, 1600) / 1000.0;   // 0.9s – 1.6s (EASY)
            $this->botTimers[$botId] = $this->loop->addTimer($reactionSec, function () use ($botId) {
                $this->botClick($botId);
            });
        }

        $this->timer = $this->loop->addTimer(4.0, fn() => $this->resolve());
    }

    /** Bot melakukan klik otomatis (dipanggil timer). */
    private function botClick(string $botId): void
    {
        if ($this->state !== self::S_ROUND_SIGNAL) return;
        if (isset($this->hasClicked[$botId])) return;
        $this->hasClicked[$botId] = true;
        $now = (int)(microtime(true) * 1000);
        $rt  = max(1, $now - $this->signalMs);
        $this->clicks[$botId] = ['rt' => $rt, 'is_early' => false, 'recv_ms' => $now];
        // Bot pertama yang menekan juga langsung menyelesaikan ronde.
        $this->cancelTimer();
        $this->resolve();
    }

    // ── Click handler ─────────────────────────────────────────────────────────

    /**
     * handleClick() dipanggil GameServer saat menerima pesan player_click.
     * Server tidak perlu tahu input apa yang ditekan — validasi input
     * dilakukan di sisi CLIENT (app.js) sebelum pesan dikirim.
     * Jika pesan sampai ke sini, berarti input sudah benar.
     */
    public function handleClick(string $connId, ?int $clientRt = null): void
    {
        if (!isset($this->players[$connId]) || !$this->players[$connId]['active']) return;
        if (isset($this->hasClicked[$connId])) return;
        $this->hasClicked[$connId] = true;

        $now = (int)(microtime(true) * 1000);

        // EARLY CLICK — klik sebelum sinyal muncul
        if ($this->state === self::S_ROUND_WAIT) {
            $this->clicks[$connId] = ['rt' => 9999, 'is_early' => true, 'recv_ms' => $now];
            $this->sendTo($connId, [
                'type'    => 'early_click',
                'message' => 'Klik terlalu cepat! Ronde berakhir. −1 poin.',
            ]);
            $this->cancelTimer();
            $this->resolveEarlyEnd($connId);
            return;
        }

        // KLIK NORMAL — setelah sinyal, input sudah divalidasi client
        if ($this->state !== self::S_ROUND_SIGNAL) return;

        // RT akurat: pakai yang diukur client kalau wajar (bebas delay jaringan).
        $serverRt = max(1, $now - $this->signalMs);
        $rt = $serverRt;
        if ($clientRt !== null && $clientRt >= 50 && $clientRt <= 5000) {
            $rt = $clientRt;   // percaya ukuran client (akurat detik input)
        }
        $this->clicks[$connId] = ['rt' => $rt, 'is_early' => false, 'recv_ms' => $now];

        // RESOLVE LANGSUNG: begitu pemain pertama menekan dengan benar, ronde
        // selesai seketika. Yang belum menekan otomatis dapat 9999 (0 poin) di
        // resolve(). Tidak ada penungguan → tidak ada delay animasi.
        $this->cancelTimer();
        $this->resolve();
    }

    // ── resolveEarlyEnd ────────────────────────────────────────────────────────

    private function resolveEarlyEnd(string $earlyConnId): void
    {
        if ($this->state !== self::S_ROUND_WAIT) return;
        $this->state = self::S_ROUND_RESULT;

        $earlyUsername = $this->players[$earlyConnId]['username'];
        $roundId = $this->db->createRound($this->sessionId, $this->currentRound, 0);

        $results = [];
        foreach ($this->players as $cid => $p) {
            if (!$p['active']) continue;
            $isBot = ($p['is_bot'] ?? false) || $p['player_id'] <= 0;
            if ($cid === $earlyConnId) {
                $pts = -1;
                $this->players[$cid]['points']      += $pts;
                $this->players[$cid]['rt_history'][] = 9999;
                if (!$isBot) $this->db->saveResult($roundId, $p['player_id'],
                    $this->clicks[$cid]['recv_ms'], 9999, true, 1, $pts);
                $results[] = ['username' => $p['username'], 'rt' => 9999,
                              'is_early' => true, 'rank' => 1, 'points' => $pts];
            } else {
                if (!$isBot) $this->db->saveResult($roundId, $p['player_id'], 0, 0, false, 0, 0);
                $results[] = ['username' => $p['username'], 'rt' => null,
                              'is_early' => false, 'rank' => 0, 'points' => 0];
            }
        }

        $scores = [];
        foreach ($this->players as $cid => $p) {
            if ($p['active']) $scores[$p['username']] = $this->players[$cid]['points'];
        }

        $this->broadcast([
            'type'        => 'round_result',
            'round'       => $this->currentRound,
            'total'       => $this->totalRounds,
            'results'     => $results,
            'scores'      => $scores,
            'early_ended' => true,
            'culprit'     => $earlyUsername,
        ]);

        $this->timer = $this->loop->addTimer(4.5, function () {
            if ($this->currentRound >= $this->totalRounds) $this->endGame();
            else { $this->state = self::S_WAITING; $this->startWait(); }
        });
    }

    // ── resolve (normal) ──────────────────────────────────────────────────────

    private function resolve(): void
    {
        if (!in_array($this->state, [self::S_ROUND_SIGNAL, self::S_ROUND_WAIT])) return;
        $this->state = self::S_ROUND_RESULT;
        $this->cancelTimer();

        foreach ($this->active() as $cid => $_) {
            if (!isset($this->clicks[$cid])) {
                $this->clicks[$cid] = ['rt' => 9999, 'is_early' => false, 'recv_ms' => 0];
            }
        }

        $ranked = [];
        foreach ($this->clicks as $cid => $c) {
            if (!isset($this->players[$cid])) continue;
            $ranked[] = array_merge([
                'connId'    => $cid,
                'username'  => $this->players[$cid]['username'],
                'player_id' => $this->players[$cid]['player_id'],
            ], $c);
        }
        usort($ranked, fn($a, $b) => $a['rt'] <=> $b['rt']);

        $ptMap   = [3, 2, 1, 0, 0, 0];
        $results = [];
        $roundId = $this->db->createRound($this->sessionId, $this->currentRound, $this->signalMs);

        foreach ($ranked as $i => $r) {
            $pts = ($r['rt'] >= 9999) ? 0 : $ptMap[min($i, 5)];
            $this->players[$r['connId']]['points']      += $pts;
            $this->players[$r['connId']]['rt_history'][] = $r['rt'];
            // Simpan ke DB HANYA untuk pemain manusia (bot tidak ada di tabel players)
            if (!($this->players[$r['connId']]['is_bot'] ?? false) && $r['player_id'] > 0) {
                $this->db->saveResult($roundId, $r['player_id'], $r['recv_ms'],
                    $r['rt'], $r['is_early'], $i + 1, $pts);
            }
            $results[] = ['username' => $r['username'], 'rt' => $r['rt'],
                          'is_early' => $r['is_early'], 'rank' => $i + 1, 'points' => $pts];
        }

        $scores = [];
        foreach ($this->players as $cid => $p) {
            if ($p['active']) $scores[$p['username']] = $this->players[$cid]['points'];
        }

        $this->broadcast([
            'type'        => 'round_result',
            'round'       => $this->currentRound,
            'total'       => $this->totalRounds,
            'results'     => $results,
            'scores'      => $scores,
            'early_ended' => false,
        ]);

        $this->timer = $this->loop->addTimer(4.5, function () {
            if ($this->currentRound >= $this->totalRounds) $this->endGame();
            else { $this->state = self::S_WAITING; $this->startWait(); }
        });
    }

    // ── endGame ───────────────────────────────────────────────────────────────

    private function endGame(): void
    {
        $this->state = self::S_GAME_OVER;

        $winner = null; $bestPts = PHP_INT_MIN;
        foreach ($this->players as $cid => $p) {
            if (!$p['active']) continue;
            $pts = $this->players[$cid]['points'];
            if ($pts > $bestPts ||
                ($pts === $bestPts && $this->avgRt($p) < $this->avgRt($winner ?? []))) {
                $bestPts = $pts;
                $winner  = $this->players[$cid];
            }
        }

        $stats = [];
        foreach ($this->players as $cid => $p) {
            if (!$p['active']) continue;
            $p    = $this->players[$cid];
            $rts  = array_filter($p['rt_history'], fn($r) => $r < 9000);
            $count = count($rts);
            $avg   = $count ? (int)round(array_sum($rts) / $count) : 0;
            $sorted = array_values($rts); sort($sorted);
            $trend  = $count >= 4
                ? (int)round(array_sum(array_slice($sorted,-2))/2 - array_sum(array_slice($sorted,0,2))/2)
                : 0;
            $stats[$p['username']] = [
                'points'     => $this->players[$cid]['points'],
                'avg_rt'     => $avg,
                'best_rt'    => $count ? (int)min($rts) : 0,
                'worst_rt'   => $count ? (int)max($rts) : 0,
                'range_rt'   => $count ? (int)(max($rts) - min($rts)) : 0,
                'trend'      => $trend,
                'rt_history' => array_values($p['rt_history']),
            ];
        }

        if ($this->sessionId && $winner) {
            if (!($winner['is_bot'] ?? false) && ($winner['player_id'] ?? 0) > 0) {
                $this->db->finalizeSession($this->sessionId, $winner['player_id'], date('Y-m-d H:i:s'));
            }
        }

        $this->broadcast(['type' => 'game_over',
            'winner' => $winner ? $winner['username'] : null, 'stats' => $stats]);

        $this->timer = $this->loop->addTimer(3.0, function () {
            $this->state = self::S_WAITING; $this->sessionId = null;
            foreach ($this->players as &$p) { $p['points'] = 0; $p['rt_history'] = []; }
        });
    }

    // ── Utils ─────────────────────────────────────────────────────────────────

    public function broadcast(array $data): void
    {
        $json = json_encode($data);
        foreach ($this->active() as $p) {
            if ($p['is_bot'] || $p['conn'] === null) continue;  // bot tidak punya koneksi
            try { $p['conn']->send($json); } catch (\Throwable $_) {}
        }
    }

    public function sendTo(string $connId, array $data): void
    {
        $p = $this->players[$connId] ?? null;
        if ($p && $p['active'] && !$p['is_bot'] && $p['conn'] !== null) {
            try { $p['conn']->send(json_encode($data)); }
            catch (\Throwable $_) {}
        }
    }

    private function connId(ConnectionInterface $conn): string
    {
        return (string)spl_object_id($conn);
    }

    private function avgRt(array $player): float
    {
        if (empty($player)) return PHP_INT_MAX;
        $rts = array_filter($player['rt_history'] ?? [], fn($r) => $r < 9000);
        return count($rts) ? array_sum($rts) / count($rts) : PHP_INT_MAX;
    }

    private function cancelTimer(): void
    {
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
        foreach ($this->botTimers as $t) { try { $this->loop->cancelTimer($t); } catch (\Throwable $_) {} }
        $this->botTimers = [];
        if ($this->fastTimer !== null) { try { $this->loop->cancelTimer($this->fastTimer); } catch (\Throwable $_) {} $this->fastTimer = null; }
    }
}
