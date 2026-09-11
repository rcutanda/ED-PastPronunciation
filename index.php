<?php
// -ED Pronunciation Challenge
// Requires PHP with PDO_SQLITE enabled. The SQLite database stores both verbs and the 24-hour leaderboard.
$dbFile = __DIR__ . '/verbs.sqlite';
if (!file_exists($dbFile)) {
    http_response_code(500);
    die('Database file verbs.sqlite was not found. Keep it in the same folder as this PHP file.');
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function resetLeaderboardIfNeeded(PDO $pdo): int {
    $now = time();
    $stmt = $pdo->prepare("SELECT value FROM game_meta WHERE key = 'leaderboard_started_at'");
    $stmt->execute();
    $started = $stmt->fetchColumn();

    if ($started === false) {
        $started = $now;
        $ins = $pdo->prepare("INSERT INTO game_meta (key, value) VALUES ('leaderboard_started_at', ?)");
        $ins->execute([(string)$started]);
    } else {
        $started = (int)$started;
        if (($now - $started) >= 86400) {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM scores');
            $upd = $pdo->prepare("UPDATE game_meta SET value = ? WHERE key = 'leaderboard_started_at'");
            $upd->execute([(string)$now]);
            $pdo->commit();
            $started = $now;
        }
    }
    return max(0, 86400 - ($now - $started));
}

function getLeaderboard(PDO $pdo): array {
    $stmt = $pdo->query("SELECT student_name, score, answered, completed, lives_left, played_at
                         FROM scores
                         ORDER BY score DESC, completed DESC, lives_left DESC, played_at ASC
                         LIMIT 10");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_name TEXT NOT NULL,
        score INTEGER NOT NULL,
        answered INTEGER NOT NULL,
        completed INTEGER NOT NULL DEFAULT 0,
        lives_left INTEGER NOT NULL DEFAULT 0,
        played_at INTEGER NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_meta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");

    $secondsUntilReset = resetLeaderboardIfNeeded($pdo);

    if (isset($_GET['action']) && $_GET['action'] === 'check_name') {
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') jsonResponse(['ok' => false, 'error' => 'Student name is required.'], 400);
        $stmt = $pdo->prepare('SELECT 1 FROM scores WHERE LOWER(student_name) = LOWER(?) LIMIT 1');
        $stmt->execute([$name]);
        jsonResponse(['ok' => true, 'available' => $stmt->fetchColumn() === false]);
    }

    if (isset($_GET['action']) && $_GET['action'] === 'leaderboard') {
        jsonResponse(['ok' => true, 'scores' => getLeaderboard($pdo), 'secondsUntilReset' => $secondsUntilReset]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit_score') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'Invalid request.'], 400);

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') jsonResponse(['ok' => false, 'error' => 'Student name is required.'], 400);
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 30) : substr($name, 0, 30);

        $score = max(0, min(50, (int)($payload['score'] ?? 0)));
        $answered = max(0, min(50, (int)($payload['answered'] ?? 0)));
        $completed = !empty($payload['completed']) ? 1 : 0;
        $livesLeft = max(0, min(5, (int)($payload['livesLeft'] ?? 0)));

        $check = $pdo->prepare('SELECT 1 FROM scores WHERE LOWER(student_name) = LOWER(?) LIMIT 1');
        $check->execute([$name]);
        if ($check->fetchColumn() !== false) jsonResponse(['ok' => false, 'error' => 'That name is already in the high scores. Choose a different name.'], 409);

        $stmt = $pdo->prepare('INSERT INTO scores (student_name, score, answered, completed, lives_left, played_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $score, $answered, $completed, $livesLeft, time()]);
        $secondsUntilReset = resetLeaderboardIfNeeded($pdo);
        jsonResponse(['ok' => true, 'scores' => getLeaderboard($pdo), 'secondsUntilReset' => $secondsUntilReset]);
    }

    $stmt = $pdo->query("SELECT id, word, sound, level FROM verbs WHERE active = 1 ORDER BY level, id");
    $verbs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    die('Could not open or write to the SQLite database. Check that PDO_SQLITE is enabled and that verbs.sqlite is writable by PHP.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>-ED past pronunciation</title>
<style>
:root{
  --ink:#17324d; --muted:#66788a; --paper:#fff; --line:#dbe8f2;
  --blue:#367bf5; --red:#f05d5e; --green:#53c68c; --yellow:#ffd166;
  --good:#167a49; --bad:#b9353b; --shadow:0 18px 45px rgba(26,58,91,.16);
}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:
radial-gradient(circle at 15% 12%,#dff1ff 0 12%,transparent 13%),
radial-gradient(circle at 88% 18%,#fff0b8 0 10%,transparent 11%),
linear-gradient(135deg,#eef8ff,#fff9e8);padding:18px;display:grid;place-items:center}
.game{width:min(900px,100%);background:rgba(255,255,255,.96);border:3px solid #d8e9f5;border-radius:30px;box-shadow:var(--shadow);overflow:hidden}
header{padding:20px 22px 10px;text-align:center}
h1{margin:0;font-size:clamp(30px,5vw,46px);letter-spacing:-1px}.subtitle{margin:6px 0;color:var(--muted);font-weight:700}
.screen{padding:18px 24px 28px}.hidden{display:none!important}
.start-card,.level-card,.end-card{max-width:610px;margin:8px auto 18px;text-align:center;background:#f9fcff;border:2px solid var(--line);border-radius:24px;padding:28px}
.start-card h2,.level-card h2,.end-card h2{margin:0 0 10px;font-size:clamp(28px,5vw,40px)}
.name-row{display:flex;gap:10px;margin-top:20px}.name-row input{flex:1;min-width:0;border:2px solid #bcd3e5;border-radius:16px;padding:14px 16px;font-size:19px;font-weight:700;outline:none}.name-row input:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(54,123,245,.12)}
.primary{border:0;border-radius:16px;background:var(--ink);color:#fff;padding:14px 20px;font-size:17px;font-weight:900;cursor:pointer}.primary:hover{filter:brightness(1.08)}.scores-link{display:inline-block;margin-top:18px;color:#265cb6;font-weight:850;text-decoration:underline;cursor:pointer;background:none;border:0;font-size:15px}
.rules{margin:18px auto 0;text-align:left;max-width:470px;color:#4b6075;line-height:1.55}.rules strong{color:var(--ink)}
.topbar{display:grid;grid-template-columns:1.3fr repeat(4,1fr);gap:9px;margin-bottom:14px}.stat{background:#f7fbff;border:2px solid #e1ecf4;border-radius:16px;padding:9px;text-align:center;font-weight:900;min-width:0}.stat span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap}.stat b{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lives{font-size:20px;letter-spacing:2px;color:#e64650}
.progress-shell{margin:12px 0 20px}.progress-label{display:flex;justify-content:space-between;font-size:13px;font-weight:800;color:var(--muted);margin-bottom:6px}.progress{height:12px;background:#e9eff4;border-radius:999px;overflow:hidden}.progress>div{height:100%;width:0;background:linear-gradient(90deg,var(--blue),var(--green));transition:width .25s ease}
.prompt{background:#f8fbff;border:2px dashed #abc5dc;border-radius:24px;padding:30px 18px;text-align:center}.prompt small{display:block;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}#word{font-size:clamp(46px,10vw,78px);font-weight:950;line-height:1;letter-spacing:-2px}.speak{margin-top:15px;border:0;background:#e9f1ff;color:#17324d;border-radius:999px;padding:10px 16px;font-weight:900;cursor:pointer}
.choices{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:20px}.choice{border:0;border-radius:20px;padding:18px 10px;color:#fff;font-size:clamp(27px,5vw,39px);font-weight:950;cursor:pointer;box-shadow:0 7px 0 rgba(0,0,0,.12);transition:transform .08s,filter .08s}.choice:hover{filter:brightness(1.05)}.choice:active{transform:translateY(4px);box-shadow:0 3px 0 rgba(0,0,0,.12)}.choice:disabled{cursor:default;opacity:.72}.t{background:var(--blue)}.d{background:var(--red)}.id{background:var(--green);color:#113b29}
#feedback{min-height:58px;margin-top:16px;display:grid;place-items:center;text-align:center;font-size:19px;font-weight:900}#feedback.good{color:var(--good)}#feedback.bad{color:var(--bad)}
.tip{margin-top:10px;background:#fff8d8;border:2px solid #f0df90;border-radius:16px;padding:11px 13px;color:#5b4c13;font-size:14px;line-height:1.4}.mini{color:var(--muted);font-size:14px}.big-score{font-size:42px;font-weight:950;margin:10px 0}.level-badge{display:inline-block;background:#e9f1ff;border:2px solid #c8dcff;border-radius:999px;padding:7px 13px;font-weight:900;color:#265cb6;margin-bottom:10px}
.leaderboard{margin:22px auto 0;max-width:610px;text-align:left}.leaderboard h3{margin:0 0 5px;text-align:center;font-size:24px}.reset-note{text-align:center;color:var(--muted);font-size:13px;font-weight:800;margin:0 0 10px}.score-list{margin:0;padding:0;list-style:none;border:2px solid var(--line);border-radius:18px;overflow:hidden;background:#fff}.score-row{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;padding:10px 13px;border-bottom:1px solid var(--line);font-weight:850}.score-row:last-child{border-bottom:0}.score-row .rank{font-size:20px;text-align:center}.score-row .score{font-size:18px;color:#265cb6}.score-row.me{background:#fff8d8}.empty-board{text-align:center;padding:18px;color:var(--muted);font-weight:750}.board-status{text-align:center;color:var(--muted);font-size:13px;margin-top:8px}
.name-error{min-height:20px;margin-top:8px;color:var(--bad);font-size:13px;font-weight:800}.copyright{text-align:center;padding:10px 18px 16px;color:var(--muted);font-size:12px;font-weight:700}.copyright a{display:inline-flex;align-items:center;gap:4px;color:inherit;text-decoration:none}.copyright a:hover{text-decoration:underline}.github-icon{width:11px;height:11px;fill:currentColor;flex:none}
@media(max-width:700px){.topbar{grid-template-columns:repeat(2,1fr)}.topbar .player{grid-column:1/-1}.choices{grid-template-columns:1fr}.screen{padding-inline:15px}.name-row{flex-direction:column}}
</style>
</head>
<body>
<section class="game" aria-label="Past tense pronunciation game">
<header><h1>🎯 -ED past pronunciation</h1><p class="subtitle">Choose the correct pronunciation of the final <strong>-ed</strong>.</p></header>

<div id="startScreen" class="screen">
  <div class="start-card">
    <h2>Ready to play?</h2>
    <p class="mini">Enter your name to begin the 5-level challenge.</p>
    <form id="nameForm" class="name-row" autocomplete="off">
      <input id="studentName" type="text" maxlength="30" placeholder="Your name" required aria-label="Student name">
      <button class="primary" type="submit">Start game</button>
    </form>
    <div id="nameError" class="name-error" aria-live="polite"></div>
    <div class="rules"><strong>How it works:</strong> 10 questions per level, 5 levels, and 5 lives for the whole game. A wrong answer costs one life. Lose all 5 lives and the game ends.</div>
    <button id="viewScores" class="scores-link" type="button">See highest scores</button>
  </div>
</div>

<div id="gameScreen" class="screen hidden">
  <div class="topbar">
    <div class="stat player"><span>Player</span><b id="playerName">—</b></div>
    <div class="stat"><span>Level</span><b id="levelStat">1 / 5</b></div>
    <div class="stat"><span>Question</span><b id="questionStat">1 / 10</b></div>
    <div class="stat"><span>Score</span><b id="scoreStat">0</b></div>
    <div class="stat"><span>Lives</span><b id="livesStat" class="lives">♥♥♥♥♥</b></div>
  </div>
  <div class="progress-shell"><div class="progress-label"><span>Level progress</span><span id="progressText">0 / 10</span></div><div class="progress"><div id="bar"></div></div></div>
  <div id="playArea">
    <div class="prompt"><small>How do we pronounce -ed in...</small><div id="word">walked</div><button id="speak" class="speak" type="button">🔊 Hear the word</button></div>
    <div class="choices">
      <button class="choice t" data-sound="t">/t/</button>
      <button class="choice d" data-sound="d">/d/</button>
      <button class="choice id" data-sound="id">/ɪd/</button>
    </div>
    <div id="feedback" aria-live="polite"></div>
    <div class="tip"><strong>Tip:</strong> /ɪd/ comes after a final /t/ or /d/ sound. For the other verbs, decide whether the final sound before <em>-ed</em> is voiced or unvoiced.</div>
  </div>
</div>

<div id="scoresScreen" class="screen hidden"><div class="end-card"><div class="leaderboard" aria-live="polite">
<h3>🏅 Best scores</h3><p class="reset-note" id="welcomeResetNote">This list resets every 24 hours.</p>
<ol class="score-list" id="welcomeScoreList"><li class="empty-board">Loading scores…</li></ol>
<div class="board-status" id="welcomeBoardStatus"></div></div><button id="backFromScores" class="primary" type="button">Back</button></div></div>
<div id="levelScreen" class="screen hidden"><div class="level-card"><div class="level-badge" id="levelBadge">Level 1 complete</div><h2 id="levelTitle">Good work!</h2><p id="levelSummary"></p><button id="nextLevel" class="primary" type="button">Start next level</button></div></div>
<div id="endScreen" class="screen hidden"><div class="end-card"><h2 id="endTitle">Challenge complete!</h2><p id="endMessage"></p><div class="big-score" id="finalScore">0 / 50</div>
<div class="leaderboard" aria-live="polite">
  <h3>🏅 Best scores</h3>
  <p class="reset-note" id="resetNote">This list resets every 24 hours.</p>
  <ol class="score-list" id="scoreList"><li class="empty-board">Loading scores…</li></ol>
  <div class="board-status" id="boardStatus"></div>
</div>
<button id="restart" class="primary" type="button">Play again</button></div></div>
  <footer class="copyright"><a href="https://github.com/rcutanda/past_tense_pronunciation" target="_blank" rel="noopener noreferrer" aria-label="GitHub repository"><svg class="github-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 .5a7.5 7.5 0 0 0-2.37 14.62c.38.07.51-.16.51-.36v-1.46c-2.1.46-2.54-.89-2.54-.89-.34-.87-.84-1.1-.84-1.1-.69-.47.05-.46.05-.46.76.05 1.16.78 1.16.78.68 1.16 1.78.83 2.21.63.07-.49.27-.83.48-1.02-1.67-.19-3.43-.84-3.43-3.72 0-.82.29-1.49.78-2.02-.08-.19-.34-.96.07-2 0 0 .64-.2 2.06.77A7.1 7.1 0 0 1 8 3.52c.64 0 1.27.09 1.87.25 1.43-.97 2.06-.77 2.06-.77.41 1.04.15 1.81.07 2 .49.53.78 1.2.78 2.02 0 2.89-1.76 3.53-3.44 3.72.27.23.51.69.51 1.39v2.63c0 .2.14.43.52.36A7.5 7.5 0 0 0 8 .5Z"/></svg>CC-BY Ramón Cutanda López - v1.0</a></footer>
</section>
<script>
const allVerbs = <?php echo json_encode($verbs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const LEVELS=5, QUESTIONS_PER_LEVEL=10, MAX_LIVES=5;
let student='', level=1, qIndex=0, score=0, lives=MAX_LIVES, locked=false, levelQuestions=[], scoreSaved=false;

const $=id=>document.getElementById(id);
const startScreen=$('startScreen'), gameScreen=$('gameScreen'), scoresScreen=$('scoresScreen'), levelScreen=$('levelScreen'), endScreen=$('endScreen');
const wordEl=$('word'), feedbackEl=$('feedback'), barEl=$('bar');

function shuffle(arr){const a=[...arr];for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]]}return a}
function showOnly(el){[startScreen,gameScreen,scoresScreen,levelScreen,endScreen].forEach(x=>x.classList.toggle('hidden',x!==el))}
function makeLevelQuestions(lvl){
  const pool=allVerbs.filter(v=>Number(v.level)===lvl);
  if(pool.length>=QUESTIONS_PER_LEVEL) return shuffle(pool).slice(0,QUESTIONS_PER_LEVEL);
  return shuffle(allVerbs).slice(0,QUESTIONS_PER_LEVEL);
}
function updateStats(){
  $('playerName').textContent=student; $('levelStat').textContent=level+' / '+LEVELS;
  $('questionStat').textContent=Math.min(qIndex+1,QUESTIONS_PER_LEVEL)+' / '+QUESTIONS_PER_LEVEL;
  $('scoreStat').textContent=score; $('livesStat').textContent='♥'.repeat(lives)+'♡'.repeat(MAX_LIVES-lives);
  $('progressText').textContent=qIndex+' / '+QUESTIONS_PER_LEVEL; barEl.style.width=(qIndex/QUESTIONS_PER_LEVEL*100)+'%';
}
function beginGame(){level=1;qIndex=0;score=0;lives=MAX_LIVES;scoreSaved=false;beginLevel()}
function beginLevel(){qIndex=0;levelQuestions=makeLevelQuestions(level);showOnly(gameScreen);showQuestion()}
function showQuestion(){
  locked=false; feedbackEl.textContent=''; feedbackEl.className=''; document.querySelectorAll('.choice').forEach(b=>b.disabled=false);
  wordEl.textContent=levelQuestions[qIndex].word; updateStats();
}
function answer(chosen){
  if(locked)return; locked=true; document.querySelectorAll('.choice').forEach(b=>b.disabled=true);
  const correct=levelQuestions[qIndex].sound;
  if(chosen===correct){score++;feedbackEl.textContent='✓ Correct!';feedbackEl.className='good'}
  else{lives--;const label=correct==='id'?'/ɪd/':'/'+correct+'/';feedbackEl.textContent='✗ The correct sound is '+label+'. You lost one life.';feedbackEl.className='bad'}
  updateStats();
  setTimeout(()=>{
    if(lives<=0){gameOver();return}
    qIndex++;
    if(qIndex>=QUESTIONS_PER_LEVEL){completeLevel();return}
    showQuestion();
  },1100)
}
function completeLevel(){
  barEl.style.width='100%'; $('levelBadge').textContent='Level '+level+' complete';
  $('levelSummary').textContent=student+', your total score is '+score+' and you have '+lives+' '+(lives===1?'life':'lives')+' left.';
  if(level>=LEVELS){finishGame();return}
  $('nextLevel').textContent='Start level '+(level+1); showOnly(levelScreen);
}
function formatReset(seconds){
  const h=Math.floor(seconds/3600), m=Math.floor((seconds%3600)/60);
  return h+'h '+m+'m';
}
function renderLeaderboard(data){
  const list=$('scoreList'); list.innerHTML='';
  const rows=(data && data.scores)||[];
  if(!rows.length){list.innerHTML='<li class="empty-board">No scores yet. Be the first!</li>'}
  else rows.forEach((r,i)=>{
    const li=document.createElement('li'); li.className='score-row'+(r.student_name===student && Number(r.score)===score?' me':'');
    const rank=document.createElement('span'); rank.className='rank'; rank.textContent=(i===0?'🥇':i===1?'🥈':i===2?'🥉':(i+1)+'.');
    const name=document.createElement('span'); name.textContent=r.student_name;
    const sc=document.createElement('span'); sc.className='score'; sc.textContent=r.score+' / 50';
    li.append(rank,name,sc); list.appendChild(li);
  });
  if(data && Number.isFinite(Number(data.secondsUntilReset))) $('resetNote').textContent='Resets in '+formatReset(Number(data.secondsUntilReset))+'.';
}
async function showWelcomeLeaderboard(){
  showOnly(scoresScreen); $('welcomeScoreList').innerHTML='<li class="empty-board">Loading scores…</li>';
  try{
    const res=await fetch('?action=leaderboard'), data=await res.json();
    if(!res.ok||!data.ok) throw new Error();
    const list=$('welcomeScoreList'); list.innerHTML=''; const rows=data.scores||[];
    if(!rows.length) list.innerHTML='<li class="empty-board">No scores yet. Be the first!</li>';
    else rows.forEach((r,i)=>{const li=document.createElement('li');li.className='score-row';
      const rank=document.createElement('span');rank.className='rank';rank.textContent=i===0?'🥇':i===1?'🥈':i===2?'🥉':(i+1)+'.';
      const name=document.createElement('span');name.textContent=r.student_name;
      const sc=document.createElement('span');sc.className='score';sc.textContent=r.score+' / 50';
      li.append(rank,name,sc);list.appendChild(li)});
    if(Number.isFinite(Number(data.secondsUntilReset))) $('welcomeResetNote').textContent='Resets in '+formatReset(Number(data.secondsUntilReset))+'.';
  }catch(e){$('welcomeScoreList').innerHTML='<li class="empty-board">The high-score list is unavailable.</li>'}
}

async function saveAndShowLeaderboard(answered, completed){
  $('scoreList').innerHTML='<li class="empty-board">Saving score…</li>';
  $('boardStatus').textContent='';
  if(scoreSaved){return}
  scoreSaved=true;
  try{
    const res=await fetch('?action=submit_score',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:student,score,answered,completed,livesLeft:lives})});
    const data=await res.json(); if(!res.ok||!data.ok) throw new Error(data.error||'Could not save score.');
    renderLeaderboard(data);
  }catch(err){
    $('scoreList').innerHTML='<li class="empty-board">The high-score list is unavailable.</li>';
    $('boardStatus').textContent='Check that verbs.sqlite is writable by PHP.';
  }
}
function finishGame(){
  $('endTitle').textContent='🏆 Challenge complete!'; $('endMessage').textContent='Well done, '+student+'. You finished all 5 levels with '+lives+' '+(lives===1?'life':'lives')+' left.';
  $('finalScore').textContent=score+' / '+(LEVELS*QUESTIONS_PER_LEVEL); showOnly(endScreen); saveAndShowLeaderboard(50,true);
}
function gameOver(){
  $('endTitle').textContent='Game over'; $('endMessage').textContent=student+', you used all 5 lives. You reached level '+level+'.';
  const answered=(level-1)*QUESTIONS_PER_LEVEL+qIndex+1; $('finalScore').textContent=score+' correct / '+answered+' answered'; showOnly(endScreen); saveAndShowLeaderboard(answered,false);
}
$('viewScores').addEventListener('click',showWelcomeLeaderboard);
$('backFromScores').addEventListener('click',()=>showOnly(startScreen));
$('nameForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const name=$('studentName').value.trim(), error=$('nameError');
  if(!name)return;
  error.textContent='';
  try{
    const res=await fetch('?action=check_name&name='+encodeURIComponent(name));
    const data=await res.json();
    if(!res.ok||!data.ok) throw new Error(data.error||'Could not check the name.');
    if(!data.available){error.textContent='That name is already in the high scores. Choose a different name.';$('studentName').focus();return}
    student=name; beginGame();
  }catch(err){error.textContent=err.message||'Could not check the name. Please try again.'}
});
$('nextLevel').addEventListener('click',()=>{level++;beginLevel()});
$('restart').addEventListener('click',()=>{showOnly(startScreen);$('studentName').focus()});
document.querySelectorAll('.choice').forEach(btn=>btn.addEventListener('click',()=>answer(btn.dataset.sound)));
$('speak').addEventListener('click',()=>{if(!('speechSynthesis' in window))return;speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(levelQuestions[qIndex].word.replace(/[()]/g,''));u.lang='en-GB';u.rate=.78;speechSynthesis.speak(u)});
</script>
</body>
</html>
