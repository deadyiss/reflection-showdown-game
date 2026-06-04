<?php
/**
 * analytics.php — Dashboard Analisis Statistik (mengambil data NYATA dari DB)
 *
 * Menjawab hipotesis proposal:
 *   H1: Performa pemain meningkat setelah beberapa ronde (tren RT menurun).
 *   H2: Pemain yang konsisten (SD rendah) lebih sering menang (win rate tinggi).
 *
 * Semua angka di halaman ini dihitung langsung dari tabel:
 *   players, game_sessions, rounds, round_results.
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/../config.php';
$db  = $cfg['db'];

try {
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    die('<h1>Database connection failed</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
}

/* ── Helper statistik ─────────────────────────────────────────────────── */
function mean(array $a): float { return count($a) ? array_sum($a) / count($a) : 0.0; }
function stddev(array $a): float {
    $n = count($a); if ($n < 2) return 0.0;
    $m = mean($a); $s = 0.0;
    foreach ($a as $x) $s += ($x - $m) ** 2;
    return sqrt($s / $n);
}

/* ── 1. Ringkasan umum ────────────────────────────────────────────────── */
$totPlayers  = (int)$pdo->query("SELECT COUNT(*) c FROM players")->fetch()['c'];
$totSessions = (int)$pdo->query("SELECT COUNT(*) c FROM game_sessions")->fetch()['c'];
$totRounds   = (int)$pdo->query("SELECT COUNT(*) c FROM rounds")->fetch()['c'];
$totClicks   = (int)$pdo->query("SELECT COUNT(*) c FROM round_results")->fetch()['c'];

/* ── 2. Statistik per pemain (untuk H2: konsistensi vs win rate) ──────── */
// Ambil RT valid (bukan timeout 9999, bukan early) per pemain
$rows = $pdo->query("
    SELECT p.player_id, p.username, p.total_games, p.total_wins,
           rr.reaction_time_ms AS rt, rr.is_early_click AS early
    FROM players p
    JOIN round_results rr ON rr.player_id = p.player_id
    ORDER BY p.player_id
")->fetchAll();

$perPlayer = [];   // player_id => {username, rts[], earlies, total, games, wins}
foreach ($rows as $r) {
    $pid = $r['player_id'];
    if (!isset($perPlayer[$pid])) {
        $perPlayer[$pid] = [
            'username' => $r['username'],
            'rts'      => [], 'early' => 0, 'total' => 0,
            'games'    => (int)$r['total_games'],
            'wins'     => (int)$r['total_wins'],
        ];
    }
    $perPlayer[$pid]['total']++;
    if ((int)$r['early'] === 1) $perPlayer[$pid]['early']++;
    elseif ((int)$r['rt'] < 9000) $perPlayer[$pid]['rts'][] = (int)$r['rt'];
}

$playerStats = [];
foreach ($perPlayer as $pid => $d) {
    $avg = mean($d['rts']);
    $sd  = stddev($d['rts']);
    $best = $d['rts'] ? min($d['rts']) : 0;
    $range = $d['rts'] ? (max($d['rts']) - min($d['rts'])) : 0;
    $winRate = $d['games'] > 0 ? ($d['wins'] / $d['games'] * 100) : 0;
    $ecr = $d['total'] > 0 ? ($d['early'] / $d['total'] * 100) : 0;
    $playerStats[] = [
        'username' => $d['username'],
        'samples'  => count($d['rts']),
        'avg'      => $avg, 'sd' => $sd, 'best' => $best, 'range' => $range,
        'games'    => $d['games'], 'wins' => $d['wins'],
        'win_rate' => $winRate, 'ecr' => $ecr,
    ];
}
// urutkan by win rate desc lalu avg asc
usort($playerStats, fn($a,$b) => $b['win_rate'] <=> $a['win_rate'] ?: $a['avg'] <=> $b['avg']);

/* ── 3. Tren performa per nomor ronde (untuk H1) ──────────────────────── */
// Rata-rata RT (semua pemain) per round_number, hanya RT valid
$trendRows = $pdo->query("
    SELECT r.round_number AS rn, AVG(rr.reaction_time_ms) AS avg_rt, COUNT(*) AS n
    FROM rounds r
    JOIN round_results rr ON rr.round_id = r.round_id
    WHERE rr.is_early_click = 0 AND rr.reaction_time_ms < 9000
    GROUP BY r.round_number
    ORDER BY r.round_number
")->fetchAll();

$trendLabels = []; $trendData = [];
foreach ($trendRows as $t) { $trendLabels[] = 'R'.$t['rn']; $trendData[] = round((float)$t['avg_rt']); }

// Uji H1: bandingkan rata-rata ronde awal (1-2) vs akhir (4-5)
$earlyRounds = []; $lateRounds = [];
foreach ($trendRows as $t) {
    if (in_array((int)$t['rn'], [1,2])) $earlyRounds[] = (float)$t['avg_rt'];
    if (in_array((int)$t['rn'], [4,5])) $lateRounds[] = (float)$t['avg_rt'];
}
$avgEarly = mean($earlyRounds);
$avgLate  = mean($lateRounds);
$improvePct = $avgEarly > 0 ? (($avgEarly - $avgLate) / $avgEarly * 100) : 0;
$h1Proven = $improvePct > 0;   // RT akhir < RT awal = membaik

/* ── 4. Uji H2: korelasi SD (konsistensi) vs win rate ────────────────── */
// Pearson r antara SD dan win_rate. Negatif kuat = H2 terbukti.
$sdArr = []; $wrArr = [];
foreach ($playerStats as $p) {
    if ($p['samples'] >= 2 && $p['games'] > 0) { $sdArr[] = $p['sd']; $wrArr[] = $p['win_rate']; }
}
function pearson(array $x, array $y): ?float {
    $n = count($x); if ($n < 2) return null;
    $mx = mean($x); $my = mean($y);
    $num = 0; $dx = 0; $dy = 0;
    for ($i=0;$i<$n;$i++){ $a=$x[$i]-$mx; $b=$y[$i]-$my; $num+=$a*$b; $dx+=$a*$a; $dy+=$b*$b; }
    if ($dx == 0 || $dy == 0) return null;
    return $num / sqrt($dx * $dy);
}
$corr = pearson($sdArr, $wrArr);
$h2Proven = ($corr !== null && $corr < -0.3);   // korelasi negatif cukup kuat

$hasData = $totClicks > 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>刃影 Analisis Statistik — Reflex Showdown</title>
<link rel="stylesheet" href="css/theme.css">
<link rel="stylesheet" href="css/style.css">
<style>
html,body{overflow-y:auto!important;overflow-x:hidden;height:auto!important;min-height:100%}
body{padding:0}
.an-wrap{max-width:1000px;margin:0 auto;padding:24px 18px 60px}
.an-head{text-align:center;margin-bottom:26px}
.an-head .kanji{font-size:40px;color:var(--gold)}
.an-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.an-stat{background:var(--ink-800);box-shadow:inset 0 0 0 2px var(--hairline);padding:16px;text-align:center}
.an-stat .v{font-family:var(--font-px);font-size:20px;color:var(--gold)}
.an-stat .l{font-family:var(--font-px);font-size:8px;color:var(--paper-3);margin-top:8px}
.an-section{background:var(--ink-800);box-shadow:inset 0 0 0 2px var(--hairline);padding:18px;margin-bottom:18px}
.an-section h2{font-family:var(--font-px);font-size:13px;color:var(--gold-soft);margin-bottom:14px;line-height:1.5}
.an-table{width:100%;border-collapse:collapse;font-size:13px}
.an-table th,.an-table td{padding:8px 10px;text-align:left;box-shadow:inset 0 -1px 0 var(--hairline)}
.an-table th{font-family:var(--font-px);font-size:8px;color:var(--paper-3)}
.an-table td{font-family:var(--font-dot)}
.an-table .num{font-family:var(--font-px);font-size:10px}
.hyp{padding:14px;box-shadow:inset 0 0 0 2px var(--hairline);margin-bottom:12px}
.hyp.proven{box-shadow:inset 0 0 0 2px var(--jade)}
.hyp.rejected{box-shadow:inset 0 0 0 2px var(--crimson)}
.hyp-badge{font-family:var(--font-px);font-size:8px;padding:4px 8px;display:inline-block;margin-bottom:8px}
.hyp.proven .hyp-badge{background:var(--jade);color:#06281a}
.hyp.rejected .hyp-badge{background:var(--crimson);color:#fff}
.hyp h3{font-family:var(--font-px);font-size:10px;margin-bottom:8px;line-height:1.6}
.hyp p{font-size:14px;line-height:1.7;color:var(--paper-2)}
.an-chartbox{height:280px;position:relative}
.empty-note{text-align:center;padding:40px;font-family:var(--font-px);font-size:10px;color:var(--paper-3);line-height:2}
.back-link{display:inline-block;margin-bottom:18px}
.kpi-good{color:var(--jade)} .kpi-bad{color:var(--crimson)}
</style>
</head>
<body class="stage grain">
<div class="an-wrap">
  <a href="analytics.php" class="btn btn-ghost" style="padding:7px 14px;font-size:10px;text-decoration:none">統計 · Analytics</a>

  

  <div class="an-head">
    <div class="kanji">統計</div>
    <h1 class="brush-title hero-ink" style="font-size:22px;margin-top:8px">STATISTICAL ANALYSIS</h1>
    <p class="label" style="margin-top:8px">Real data from database · Reflex Showdown</p>
  </div>

<?php if (!$hasData): ?>
  <div class="an-section">
    <div class="empty-note">
      NO GAME DATA YET.<br><br>
      Play a few sessions first (at least 2 human players, several rounds),<br>
      then open this page again to see the analysis.
    </div>
  </div>
<?php else: ?>

  <!-- Ringkasan -->
  <div class="an-grid">
    <div class="an-stat"><div class="v"><?= $totPlayers ?></div><div class="l">PLAYERS</div></div>
    <div class="an-stat"><div class="v"><?= $totSessions ?></div><div class="l">SESSIONS</div></div>
    <div class="an-stat"><div class="v"><?= $totRounds ?></div><div class="l">ROUNDS</div></div>
    <div class="an-stat"><div class="v"><?= $totClicks ?></div><div class="l">CLICK DATA</div></div>
  </div>

  <!-- H1: Tren performa -->
  <div class="an-section">
    <h2>H1 · DOES PERFORMANCE IMPROVE AFTER SEVERAL ROUNDS?</h2>
    <div class="an-chartbox"><canvas id="trendChart"></canvas></div>
    <div class="hyp <?= $h1Proven ? 'proven' : 'rejected' ?>" style="margin-top:16px">
      <span class="hyp-badge"><?= $h1Proven ? 'CONFIRMED' : 'TIDAK CONFIRMED' ?></span>
      <h3>Average RT rounds 1–2 vs rounds 4–5</h3>
      <p>
        Average RT early rounds (1–2): <b class="num"><?= round($avgEarly) ?> ms</b>.
        Average RT late rounds (4–5): <b class="num"><?= round($avgLate) ?> ms</b>.
        <?php if ($h1Proven): ?>
          A <b class="kpi-good">decrease of <?= round($improvePct,1) ?>%</b> occurred (lower RT = faster reaction),
          so hypothesis H1 — performance improves after several rounds — is <b>confirmed</b> on this data.
          <?php if ($improvePct >= 10 && $improvePct <= 20): ?>
            The improvement also falls within the proposal prediction range (10–20%).
          <?php endif; ?>
        <?php else: ?>
          Late RT is <b class="kpi-bad">not lower</b> than early RT (difference <?= round($improvePct,1) ?>%),
          so with the currently collected data hypothesis H1 is <b>not yet confirmed</b>.
          Possibly too few sessions, or fatigue/target-variation effects between rounds.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <!-- H2: Konsistensi vs win rate -->
  <div class="an-section">
    <h2>H2 · DO MORE CONSISTENT PLAYERS WIN MORE OFTEN?</h2>
    <div class="an-chartbox"><canvas id="corrChart"></canvas></div>
    <div class="hyp <?= $h2Proven ? 'proven' : 'rejected' ?>" style="margin-top:16px">
      <span class="hyp-badge"><?= $h2Proven ? 'CONFIRMED' : 'TIDAK CONFIRMED' ?></span>
      <h3>Correlation of RT Standard Deviation vs Win Rate</h3>
      <p>
        <?php if ($corr === null): ?>
          Not enough data to compute correlation (need at least 2 players with finished sessions).
          Play more sessions to test H2.
        <?php else: ?>
          Pearson correlation coefficient between SD (consistency) and win rate = <b class="num"><?= round($corr,2) ?></b>.
          <?php if ($h2Proven): ?>
            This <b class="kpi-good">negative</b> value means the smaller the SD (more consistent),
            the higher the win rate — exactly as the proposal predicted. Hypothesis H2 is <b>confirmed</b>.
          <?php elseif ($corr < 0): ?>
            The correlation is negative (as expected) but not strong enough (|r| &lt; 0.3),
            so H2 <b>cannot yet be confirmed</b> with current data.
          <?php else: ?>
            The correlation is <b class="kpi-bad">not negative</b>, so hypothesis H2 is <b>not confirmed</b> on this data.
            More player & session samples are needed.
          <?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <!-- Tabel statistik per pemain -->
  <div class="an-section">
    <h2>順位 · PER-PLAYER STATISTICS</h2>
    <table class="an-table">
      <thead><tr>
        <th>PLAYER</th><th>SESSIONS</th><th>WINS</th><th>WIN RATE</th>
        <th>AVG RT</th><th>SD (CONSIST.)</th><th>BEST</th><th>EARLY %</th>
      </tr></thead>
      <tbody>
      <?php foreach ($playerStats as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['username']) ?></td>
          <td class="num"><?= $p['games'] ?></td>
          <td class="num"><?= $p['wins'] ?></td>
          <td class="num"><?= round($p['win_rate']) ?>%</td>
          <td class="num"><?= $p['samples'] ? round($p['avg']) : '-' ?></td>
          <td class="num"><?= $p['samples'] >= 2 ? round($p['sd']) : '-' ?></td>
          <td class="num kpi-good"><?= $p['best'] ?: '-' ?></td>
          <td class="num"><?= round($p['ecr']) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="text-align:left;margin-top:12px">
      SD = Standard Deviation of reaction time (smaller = more consistent).
      Win Rate = total_wins / total_games. Early % = percentage of clicks before the signal.
    </p>
  </div>

  <!-- Kesimpulan -->
  <div class="an-section">
    <h2>結論 · CONCLUSION</h2>
    <p style="font-size:14px;line-height:1.8;color:var(--paper-2)">
      Based on <b><?= $totClicks ?></b> click records from <b><?= $totSessions ?></b> game sessions:
      Hypothesis <b>H1</b> (performance improves) <b class="<?= $h1Proven?'kpi-good':'kpi-bad' ?>"><?= $h1Proven?'CONFIRMED':'not confirmed' ?></b>,
      and hypothesis <b>H2</b> (consistent players win more)
      <b class="<?= $h2Proven?'kpi-good':'kpi-bad' ?>"><?= $h2Proven?'CONFIRMED':'not confirmed' ?></b>.
      All figures above are computed directly from tables <code>round_results</code>, <code>rounds</code>,
      <code>game_sessions</code>, dan <code>players</code> — not simulated data.
    </p>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <script>
    const trendLabels = <?= json_encode($trendLabels) ?>;
    const trendData   = <?= json_encode($trendData) ?>;
    new Chart(document.getElementById('trendChart'), {
      type:'line',
      data:{labels:trendLabels,datasets:[{label:'Average RT (ms)',data:trendData,
        borderColor:'#d23b40',backgroundColor:'#d23b4022',tension:.3,fill:true,pointRadius:5}]},
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{labels:{color:'#8d8474'}}},
        scales:{y:{title:{display:true,text:'RT (ms) — lower is better',color:'#8d8474'},ticks:{color:'#8d8474'},grid:{color:'#251e34'}},
                x:{ticks:{color:'#8d8474'},grid:{color:'#251e34'}}}}
    });

    const corrPoints = <?= json_encode(array_map(fn($p)=>['x'=>round($p['sd']),'y'=>round($p['win_rate']),'u'=>$p['username']], array_filter($playerStats, fn($p)=>$p['samples']>=2 && $p['games']>0))) ?>;
    new Chart(document.getElementById('corrChart'), {
      type:'scatter',
      data:{datasets:[{label:'Players (SD vs Win Rate)',
        data:corrPoints.map(p=>({x:p.x,y:p.y})),
        backgroundColor:'#d8a94b',pointRadius:7}]},
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{labels:{color:'#8d8474'}},
          tooltip:{callbacks:{label:c=>corrPoints[c.dataIndex].u+': SD='+c.parsed.x+'ms, WR='+c.parsed.y+'%'}}},
        scales:{y:{title:{display:true,text:'Win Rate (%)',color:'#8d8474'},ticks:{color:'#8d8474'},grid:{color:'#251e34'}},
                x:{title:{display:true,text:'SD / Consistency (ms) — more left = more consistent',color:'#8d8474'},ticks:{color:'#8d8474'},grid:{color:'#251e34'}}}}
    });
  </script>

<?php endif; ?>

</div>
</body>
</html>
