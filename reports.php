<?php
// reports.php — Reportes básicos (SQLite + PDO)
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/auth_guard.php';

/* Utilidades */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to){ header("Location: {$to}"); exit; }

/* Conexión */
$pdo = null;
try {
  require_once __DIR__ . '/config/database.php';
  if (isset($pdo) && $pdo instanceof PDO) {
    // ok
  } elseif (function_exists('db')) {
    $pdo = db();
  } elseif (function_exists('getPDO')) {
    $pdo = getPDO();
  } else {
    throw new RuntimeException('No se pudo obtener la conexión PDO desde config/database.php');
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  redirect('install.php');
}

/* Verificación mínima */
try {
  $hasBooks = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'")->fetchColumn();
  if (!$hasBooks) redirect('install.php');
} catch (Throwable $e) { redirect('install.php'); }

/* ✅ Asegurar que exista la tabla ratings (migración silenciosa) */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS ratings (
      user_id    INTEGER NOT NULL,
      book_id    INTEGER NOT NULL,
      stars      INTEGER NOT NULL CHECK (stars BETWEEN 1 AND 5),
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      updated_at TEXT NOT NULL DEFAULT (datetime('now')),
      PRIMARY KEY (user_id, book_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    );
    CREATE INDEX IF NOT EXISTS idx_ratings_book ON ratings(book_id);
    CREATE INDEX IF NOT EXISTS idx_ratings_user ON ratings(user_id);
  ");
} catch (Throwable $e) {}

/* Consultas de estadísticas */
$stats = [
  'total'     => 0,
  'autores'   => 0,
  'generos'   => 0,
  'ultimos30' => 0,
];

try {
  // Totales
  $stats['total']   = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
  $stats['autores'] = (int)$pdo->query("
      SELECT COUNT(DISTINCT TRIM(author))
      FROM books
      WHERE author IS NOT NULL AND TRIM(author) <> ''
  ")->fetchColumn();
  $stats['generos'] = (int)$pdo->query("
      SELECT COUNT(DISTINCT TRIM(genre))
      FROM books
      WHERE genre IS NOT NULL AND TRIM(genre) <> ''
  ")->fetchColumn();
  // Últimos 30 días (requiere created_at compatible con DATE())
  $stats['ultimos30'] = (int)$pdo->query("
      SELECT COUNT(*)
      FROM books
      WHERE created_at IS NOT NULL
        AND DATE(created_at) >= DATE('now','-30 day')
  ")->fetchColumn();
} catch (Throwable $e) {}

/* Por género */
$byGenre = [];
try {
  $stmt = $pdo->query("
    SELECT COALESCE(NULLIF(TRIM(genre),''),'(Sin género)') AS genre,
           COUNT(*) AS c
    FROM books
    GROUP BY 1
    ORDER BY c DESC, genre ASC
  ");
  $byGenre = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $byGenre = []; }

/* Por año (numérico) */
$byYear = [];
try {
  $stmt = $pdo->query("
    SELECT CAST(year AS INTEGER) AS y, COUNT(*) AS c
    FROM books
    WHERE TRIM(IFNULL(year,'')) <> ''
    GROUP BY y
    ORDER BY y DESC
  ");
  $byYear = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $byYear = []; }

/* Por categoría (tags) — usa alias cnt para evitar ambigüedades */
$byCat = [];
try {
  $stmt = $pdo->query("
    SELECT c.name AS category, COUNT(*) AS cnt
    FROM categories c
    JOIN book_category bc ON bc.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY cnt DESC, LOWER(c.name) ASC
  ");
  $byCat = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $byCat = []; }

/* Top autores */
$topAuthors = [];
try {
  $stmt = $pdo->query("
    SELECT TRIM(author) AS author, COUNT(*) AS c
    FROM books
    WHERE TRIM(IFNULL(author,'')) <> ''
    GROUP BY author
    ORDER BY c DESC, author ASC
    LIMIT 10
  ");
  $topAuthors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $topAuthors = []; }

/* Altas mensuales (últimos 12 meses) */
$byMonth = [];
try {
  $stmt = $pdo->query("
    SELECT strftime('%Y-%m', created_at) AS ym, COUNT(*) AS c
    FROM books
    WHERE created_at IS NOT NULL
    GROUP BY ym
    ORDER BY ym DESC
    LIMIT 12
  ");
  $byMonth = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $byMonth = array_reverse($byMonth); // cronológico asc
} catch (Throwable $e) { $byMonth = []; }

/* Top mejor valorados */
$topRated = [];
try {
  $stmt = $pdo->query("
  SELECT b.id, b.title,
         IFNULL(ROUND(AVG(r.stars),1),0) AS avg,
         COUNT(r.stars) AS cnt
  FROM books b
  JOIN ratings r ON r.book_id = b.id
  GROUP BY b.id, b.title
  HAVING cnt >= 1               -- ← antes: >= 2
  ORDER BY avg DESC, cnt DESC, b.title ASC
  LIMIT 10
");
  $topRated = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $topRated = []; }

/* Helpers para barras */
function maxCount(array $rows, string $key='c'): int {
  $m = 0; foreach ($rows as $r) { $v = (int)($r[$key] ?? 0); if ($v > $m) $m = $v; }
  return max($m, 1);
}
$maxGenre = maxCount($byGenre);         // usa 'c'
$maxYear  = maxCount($byYear);          // usa 'c'
$maxAuth  = maxCount($topAuthors);      // usa 'c'
$maxMonth = maxCount($byMonth);         // usa 'c'
$maxCat   = maxCount($byCat, 'cnt');    // usa 'cnt' en categorías
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Reportes — Book Manager Pro</title>
  <link rel="stylesheet" href="assets/css/style.css"/>
  <style>
  /* Lista bonita para Top mejor valorados */
  .toprated-list{
    display:grid;
    grid-template-columns: 1fr auto;
    align-items:center;
    row-gap:10px;
  }
  .toprated-title{
    max-width: 100%;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    padding-right: 10px;
  }
  .rating-chip{
    display:inline-block;
    padding:4px 10px;
    border:1px solid var(--border);
    border-radius:999px;
    background:var(--bg-table-row);
    font-weight:600;
  }
  .rating-chip small{ font-weight:400; opacity:.8; margin-left:6px; }
  /* Título del card un poco más compacto */
  .card h3 .subtle{
    font-size:.9rem; font-weight:500; opacity:.8; margin-left:.35rem;
  }
</style>

</head>
<body>
  <nav class="top-navbar">
    <div class="navbar-container">
      <div class="navbar-brand">
        <span class="brand-icon">📊</span>
        <div class="brand-text">
          <h1>Book Manager</h1>
          <span class="brand-subtitle">Estadísticas básicas</span>
        </div>
      </div>
      <div class="navbar-menu">
        <a href="index.php" class="menu-item"><span>Biblioteca</span></a>
        <a href="add.php" class="menu-item"><span>Agregar</span></a>
		<a class="menu-item" href="add_from_google.php">Buscar en Google</a>
        <a href="reports.php" class="menu-item active"><span>Reportes</span></a>
      </div>
      <button id="themeToggle" class="menu-item" type="button" title="Cambiar tema">
        <span id="themeIcon">🌙</span>
      </button>
      <div class="navbar-user">
        <div class="user-name"><span><?= h($_SESSION['username'] ?? 'usuario') ?></span></div>
        <form action="logout.php" method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer">
            Salir
          </button>
        </form>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header-bar">
      <div class="header-title">
        <span class="title-icon">📚</span>
        <h2>Estadísticas de biblioteca</h2>
      </div>
      <div class="counter-compact">
        <span class="count-num"><?= (int)$stats['total'] ?></span> libros
      </div>
    </div>

    <!-- KPIs -->
    <section class="kpis">
      <div class="kpi">
        <div class="kpi-label">Total de libros</div>
        <div class="kpi-value"><?= (int)$stats['total'] ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Autores</div>
        <div class="kpi-value"><?= (int)$stats['autores'] ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Géneros</div>
        <div class="kpi-value"><?= (int)$stats['generos'] ?></div>
      </div>
    </section>

    <div class="reports-grid">
      <!-- Por Género -->
      <section class="card">
        <h3>Libros por género</h3>
        <?php foreach ($byGenre as $r): ?>
          <?php $w = round(((int)$r['c'] / $maxGenre) * 100); ?>
          <div class="bar-row">
            <div class="bar-label" title="<?= h($r['genre']) ?>"><?= h($r['genre']) ?></div>
            <div class="bar"><i style="width:<?= $w ?>%"></i></div>
            <div class="bar-count"><?= (int)$r['c'] ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($byGenre)): ?>
          <p class="text-muted">Sin datos.</p>
        <?php endif; ?>
      </section>

      <!-- ✅ Libros por Categoría -->
      <section class="card">
        <h3>Libros por categoría</h3>
        <?php foreach ($byCat as $r): ?>
          <?php $w = round(((int)$r['cnt'] / $maxCat) * 100); ?>
          <div class="bar-row">
            <div class="bar-label" title="<?= h($r['category']) ?>"><?= h($r['category']) ?></div>
            <div class="bar"><i style="width:<?= $w ?>%"></i></div>
            <div class="bar-count"><?= (int)$r['cnt'] ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($byCat)): ?>
          <p class="text-muted">Sin datos.</p>
        <?php endif; ?>

        <!-- Nube de etiquetas por categoría -->
        <section class="card">
          <h3>Nube de etiquetas</h3>
          <div class="tag-cloud" style="line-height:2; word-wrap:break-word;">
            <?php
              if (!empty($byCat)) {
                // Escalar tamaños entre 12px y 28px según frecuencia
                $minSize = 12; $maxSize = 28;
                $minC = min(array_map(fn($r)=>(int)$r['cnt'], $byCat));
                $maxC = max(array_map(fn($r)=>(int)$r['cnt'], $byCat));
                $spread = max(1, $maxC - $minC);

                // Traer id + nombre + conteo para los enlaces (COUNT AS c aquí está bien)
                $stmtC = $pdo->query("
                  SELECT c.id, c.name, COUNT(*) AS c
                  FROM categories c
                  JOIN book_category bc ON bc.category_id = c.id
                  GROUP BY c.id, c.name
                  ORDER BY LOWER(c.name)
                ");
                $byCatWithId = $stmtC->fetchAll(PDO::FETCH_ASSOC);

                foreach ($byCatWithId as $r) {
                  $count = (int)$r['c'];
                  $size  = $minSize + ( ($count - $minC) / $spread ) * ($maxSize - $minSize);
                  $cid   = (int)$r['id'];
                  $name  = (string)$r['name'];

                  echo '<a href="index.php?cat=' . $cid . '" ' .
                       'style="font-size:' . round($size) . 'px" ' .
                       'class="tag-cloud-item">' . h($name) . '</a> ';
                }
              } else {
                echo '<p class="text-muted">Sin datos.</p>';
              }
            ?>
          </div>
        </section>
      </section>

      <!-- Top autores -->
      <section class="card">
        <h3>Top 10 autores por cantidad de libros</h3>
        <?php foreach ($topAuthors as $r): ?>
          <?php $w = round(((int)$r['c'] / $maxAuth) * 100); ?>
          <div class="bar-row">
            <div class="bar-label" title="<?= h($r['author']) ?>"><?= h($r['author']) ?></div>
            <div class="bar"><i style="width:<?= $w ?>%"></i></div>
            <div class="bar-count"><?= (int)$r['c'] ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($topAuthors)): ?>
          <p class="text-muted">Sin datos.</p>
        <?php endif; ?>
      </section>

      <!-- Por Año -->
      <section class="card span-2">
        <h3>Libros por año</h3>
        <table class="table-compact">
          <thead><tr><th>Año</th><th>Cantidad</th></tr></thead>
          <tbody>
            <?php foreach ($byYear as $r): ?>
              <tr>
                <td><?= h((string)$r['y']) ?></td>
                <td><?= (int)$r['c'] ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($byYear)): ?>
              <tr><td colspan="2" class="text-muted">Sin datos.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </section>

      <!-- Top mejor valorados -->
<section class="card span-2">
  <h3>Top libros mejor valorados</h3>

  <?php if (empty($topRated)): ?>
    <p class="text-muted">Sin datos.</p>
  <?php else: ?>
    <div class="toprated-list">
      <?php foreach ($topRated as $r): ?>
        <div class="toprated-title" title="<?= h($r['title']) ?>">
          <?= h($r['title']) ?>
        </div>
        <div class="rating-chip">
          ★ <?= h((string)$r['avg']) ?> <small>(<?= (int)$r['cnt'] ?>)</small>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

    </div>
  </div>

  <footer class="site-footer">
    <h3 class="footer-title">Grupo 2</h3>
    <ul class="footer-grid">
      <li><span class="name">Alexia Alvarado Alfaro</span> <span class="id">(402580319)</span></li>
      <li><span class="name">Kendra Artavia Caballero</span> <span class="id">(402580003)</span></li>
      <li><span class="name">Randy Nuñez Vargas</span> <span class="id">(119100297)</span></li>
      <li><span class="name">Katherine Jara Arroyo</span> <span class="id">(402650268)</span></li>
      <li><span class="name">Jose Carballo Morales</span> <span class="id">(119060186)</span></li>
    </ul>
  </footer>

  <script>
  (function(){
    const html = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    const ico  = document.getElementById('themeIcon');
    if(!btn || !ico) return;
    const saved = localStorage.getItem('bm-theme');
    if (saved === 'light') html.setAttribute('data-theme','light');
    const applyIcon = () => { const isLight = html.getAttribute('data-theme') === 'light'; ico.textContent = isLight ? '🌞' : '🌙'; };
    applyIcon();
    btn.addEventListener('click', () => {
      const isLight = html.getAttribute('data-theme') === 'light';
      if (isLight) { html.removeAttribute('data-theme'); localStorage.setItem('bm-theme','dark'); }
      else { html.setAttribute('data-theme','light'); localStorage.setItem('bm-theme','light'); }
      applyIcon();
    });
  })();
  </script>
</body>
</html>
