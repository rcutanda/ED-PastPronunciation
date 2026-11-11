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
                         LIMIT 50");
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
<link rel="stylesheet" href="style.css">
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
    <button id="nextVerb" class="primary next-verb hidden" type="button">Next verb →</button>
    <div class="tip"><strong>Tip:</strong> /ɪd/ comes after a final /t/ or /d/ sound. For the other verbs, decide whether the final sound before <em>-ed</em> is voiced or unvoiced.</div>
  </div>
</div>

<div id="scoresScreen" class="screen hidden"><div class="end-card"><button id="backFromScoresTop" class="primary" type="button">Back</button><div class="leaderboard" aria-live="polite">
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
  <footer class="copyright"><a href="https://github.com/rcutanda/ED-PastPronunciation" target="_blank" rel="noopener noreferrer" aria-label="GitHub repository"><svg class="github-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 .5a7.5 7.5 0 0 0-2.37 14.62c.38.07.51-.16.51-.36v-1.46c-2.1.46-2.54-.89-2.54-.89-.34-.87-.84-1.1-.84-1.1-.69-.47.05-.46.05-.46.76.05 1.16.78 1.16.78.68 1.16 1.78.83 2.21.63.07-.49.27-.83.48-1.02-1.67-.19-3.43-.84-3.43-3.72 0-.82.29-1.49.78-2.02-.08-.19-.34-.96.07-2 0 0 .64-.2 2.06.77A7.1 7.1 0 0 1 8 3.52c.64 0 1.27.09 1.87.25 1.43-.97 2.06-.77 2.06-.77.41 1.04.15 1.81.07 2 .49.53.78 1.2.78 2.02 0 2.89-1.76 3.53-3.44 3.72.27.23.51.69.51 1.39v2.63c0 .2.14.43.52.36A7.5 7.5 0 0 0 8 .5Z"/></svg>CC-BY Ramón Cutanda López - v1.1</a></footer>
</section>
<script>
const allVerbs = <?php echo json_encode($verbs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const LEVELS=5, QUESTIONS_PER_LEVEL=10, MAX_LIVES=5;
let student='', level=1, qIndex=0, score=0, lives=MAX_LIVES, locked=false, levelQuestions=[], scoreSaved=false;

const $=id=>document.getElementById(id);
const startScreen=$('startScreen'), gameScreen=$('gameScreen'), scoresScreen=$('scoresScreen'), levelScreen=$('levelScreen'), endScreen=$('endScreen');
const wordEl=$('word'), feedbackEl=$('feedback'), barEl=$('bar'), nextVerbBtn=$('nextVerb');

function shuffle(arr){const a=[...arr];for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]]}return a}
function showOnly(el){[startScreen,gameScreen,scoresScreen,levelScreen,endScreen].forEach(x=>x.classList.toggle('hidden',x!==el))}
let usedWords = new Set();

function makeLevelQuestions(lvl) {
  const pool = shuffle(allVerbs.filter(v => Number(v.level) === lvl));
  const picked = [];
  for (const v of pool) {
    const key = v.word.trim().toLowerCase();
    if (usedWords.has(key)) continue;   // already shown: draw another
    usedWords.add(key);
    picked.push(v);
    if (picked.length === QUESTIONS_PER_LEVEL) break;
  }
  return picked;
}
function updateStats(){
  $('playerName').textContent=student; $('levelStat').textContent=level+' / '+LEVELS;
  $('questionStat').textContent=Math.min(qIndex+1,QUESTIONS_PER_LEVEL)+' / '+QUESTIONS_PER_LEVEL;
  $('scoreStat').textContent=score; $('livesStat').textContent='♥'.repeat(lives)+'♡'.repeat(MAX_LIVES-lives);
  $('progressText').textContent=qIndex+' / '+QUESTIONS_PER_LEVEL; barEl.style.width=(qIndex/QUESTIONS_PER_LEVEL*100)+'%';
}
function beginGame(){level=1;qIndex=0;score=0;lives=MAX_LIVES;scoreSaved=false;usedWords=new Set();beginLevel()}
function beginLevel(){qIndex=0;levelQuestions=makeLevelQuestions(level);showOnly(gameScreen);showQuestion()}
function showQuestion(){
  locked=false; feedbackEl.textContent=''; feedbackEl.className='';
  nextVerbBtn.classList.add('hidden');
  document.querySelectorAll('.choice').forEach(b=>{b.disabled=false;b.classList.remove('correct-answer','wrong-choice','dimmed')});
  wordEl.textContent=levelQuestions[qIndex].word; updateStats();
}
function advance(){
  nextVerbBtn.classList.add('hidden');
  if(lives<=0){gameOver();return}
  qIndex++;
  if(qIndex>=QUESTIONS_PER_LEVEL){completeLevel();return}
  showQuestion();
}
function answer(chosen){
  if(locked)return; locked=true; document.querySelectorAll('.choice').forEach(b=>b.disabled=true);
  const correct=levelQuestions[qIndex].sound;
  if(chosen===correct){
    score++;feedbackEl.textContent='✓ Correct!';feedbackEl.className='good';
    updateStats();
    setTimeout(advance,1100);
  }else{
    lives--;const label=correct==='id'?'/ɪd/':'/'+correct+'/';
    feedbackEl.textContent='✗ The correct sound is '+label+'. You lost one life.';feedbackEl.className='bad';
    document.querySelectorAll('.choice').forEach(b=>{
      if(b.dataset.sound===correct) b.classList.add('correct-answer');
      else if(b.dataset.sound===chosen) b.classList.add('wrong-choice');
      else b.classList.add('dimmed');
    });
    updateStats();
    nextVerbBtn.classList.remove('hidden'); nextVerbBtn.focus();
  }
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
nextVerbBtn.addEventListener('click',advance);
document.querySelectorAll('.choice').forEach(btn=>btn.addEventListener('click',()=>answer(btn.dataset.sound)));
$('speak').addEventListener('click',()=>{if(!('speechSynthesis' in window))return;speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(levelQuestions[qIndex].word.replace(/[()]/g,''));u.lang='en-GB';u.rate=.78;speechSynthesis.speak(u)});
document.getElementById('backFromScoresTop').addEventListener('click', () => document.getElementById('backFromScores').click());
</script>
</body>
</html>
