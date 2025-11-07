<?php
// index.php — versión conectada a BD (SQLite + PDO)
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/auth_guard.php';
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* =========================
   Utilidades
   ========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to){ header("Location: {$to}"); exit; }

/* =========================
   Cargar conexión y modelos
   ========================= */
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
  @require_once __DIR__ . '/models/Book.php';
  @require_once __DIR__ . '/models/User.php';
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  redirect('install.php');
}

/* =========================
   Verificación de instalación
   ========================= */
try {
  $hasBooks = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'")->fetchColumn();
  $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
  if (!$hasBooks || !$hasUsers) redirect('install.php');
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
} catch (Throwable $e) {
  // tolerante: continúa sin frenar la página
}

/* =========================
   Sesión de usuario (simple)
   ========================= */
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

/* =========================
   Helper: categorías de un libro (para chips)
   ========================= */
function getBookCategoryNames(PDO $pdo, int $bookId): array {
  try {
    $hasCats = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='categories'")->fetchColumn();
    $hasBC   = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='book_category'")->fetchColumn();
    if (!$hasCats || !$hasBC) return [];

    $q = $pdo->prepare("
      SELECT c.name
      FROM categories c
      JOIN book_category bc ON bc.category_id = c.id
      WHERE bc.book_id = :b
      ORDER BY LOWER(c.name)
    ");
    $q->execute([':b' => $bookId]);
    return $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

/* =========================
   Helpers para ratings
   ========================= */
/** promedio y conteo de un libro */
function getBookRatingStats(PDO $pdo, int $bookId): array {
  try {
    $q = $pdo->prepare("SELECT AVG(stars) AS avg, COUNT(*) AS cnt FROM ratings WHERE book_id = :b");
    $q->execute([':b'=>$bookId]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: ['avg'=>null,'cnt'=>0];
    $avg = is_null($r['avg']) ? null : round((float)$r['avg'], 1);
    return ['avg'=>$avg, 'cnt'=>(int)($r['cnt'] ?? 0)];
  } catch(Throwable $e){ return ['avg'=>null,'cnt'=>0]; }
}

/** calificación actual del usuario en ese libro (o null si no ha calificado) */
function getUserRating(PDO $pdo, int $userId, int $bookId): ?int {
  try {
    $q = $pdo->prepare("SELECT stars FROM ratings WHERE user_id = :u AND book_id = :b LIMIT 1");
    $q->execute([':u'=>$userId, ':b'=>$bookId]);
    $v = $q->fetchColumn();
    return $v === false ? null : (int)$v;
  } catch(Throwable $e){ return null; }
}

/** render de 5 estrellas como form POST (accesible y compacto) */
function renderStarsForm(int $bookId, ?int $userStars, string $qsBack, string $csrf): string {
  $out  = '<form method="post" action="rate.php" class="stars-form" style="display:inline-block">';
  $out .= '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf,ENT_QUOTES,'UTF-8').'">';
  $out .= '<input type="hidden" name="book_id" value="'.$bookId.'">';
  $out .= '<input type="hidden" name="back" value="index.php'.$qsBack.'">';
  $out .= '<div class="stars" role="radiogroup" aria-label="Calificar este libro">';
  for ($i=1; $i<=5; $i++){
    $id = "b{$bookId}_s{$i}";
    $checked = ($userStars === $i) ? ' checked' : '';
    $out .= '<input type="radio" id="'.$id.'" name="stars" value="'.$i.'"'.$checked.'>';
    $out .= '<label for="'.$id.'" title="'.$i.' estrellas">★</label>';
  }
  $out .= '<button type="submit" class="btn-rate" style="margin-left:6px;">Guardar</button>';
  $out .= '</div></form>';
  return $out;
}

/* =========================
   Parámetros de búsqueda, filtro y paginación
   ========================= */
$q      = isset($_GET['q'])     ? trim((string)$_GET['q'])     : '';
$genre  = isset($_GET['genre']) ? trim((string)$_GET['genre']) : '';
$cat    = isset($_GET['cat'])   ? (int)$_GET['cat']            : 0;

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

/* =========================
   Lista de géneros y categorías
   ========================= */
try {
  $stmtGenres = $pdo->query("
    SELECT DISTINCT TRIM(genre) AS g
    FROM books
    WHERE genre IS NOT NULL AND TRIM(genre) <> ''
    ORDER BY LOWER(g)
  ");
  $genres = $stmtGenres->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $genres = []; }

// ✅ Categorías globales (para filtro) + mapa nombre→id (evita N+1)
try {
  $allCats = $pdo->query("SELECT id, name FROM categories ORDER BY LOWER(name)")
                 ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $allCats = []; }
$catIdByName = [];
foreach ($allCats as $c) { $catIdByName[mb_strtolower((string)$c['name'])] = (int)$c['id']; }

/* =========================
   Lectura con filtros
   ========================= */
try {
  $where  = [];
  $params = [];

  if ($q !== '') {
    $where[]        = "(title LIKE :q COLLATE NOCASE OR author LIKE :q COLLATE NOCASE)";
    $params[':q']   = "%{$q}%";
  }
  if ($genre !== '') {
    $where[]          = "genre = :genre";
    $params[':genre'] = $genre;
  }

  // ✅ JOIN por categoría si aplica
  $join = '';
  if ($cat > 0) {
    $join = "JOIN book_category bc ON bc.book_id = books.id";
    $where[] = "bc.category_id = :cat";
    $params[':cat'] = $cat;
  }

  $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

  // Total (DISTINCT por posible JOIN)
  $sqlCount  = "SELECT COUNT(DISTINCT books.id) FROM books {$join} {$whereSql}";
  $stmtCount = $pdo->prepare($sqlCount);
  foreach ($params as $k => $v) {
    $stmtCount->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
  }
  $stmtCount->execute();
  $total = (int)$stmtCount->fetchColumn();

  // Página
  $sql = "
    SELECT DISTINCT books.id, title, author, year, genre, created_at
    FROM books
    {$join}
    {$whereSql}
    ORDER BY books.id DESC
    LIMIT :limit OFFSET :offset
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
  }
  $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
  $stmt->execute();
  $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $total = 0; $books = [];
}

/* =========================
   Datos de paginación
   ========================= */
$totalPages = max(1, (int)ceil($total / $perPage));
$hasPrev    = $page > 1;
$hasNext    = $page < $totalPages;

/* =========================
   QS para conservar filtros en paginación
   ========================= */
$qsParts = [];
if ($q !== '')     $qsParts[] = 'q=' . urlencode($q);
if ($genre !== '') $qsParts[] = 'genre=' . urlencode($genre);
if ($cat > 0)      $qsParts[] = 'cat=' . $cat;
$qs = empty($qsParts) ? '' : '&' . implode('&', $qsParts);

/* =========================
   Flash messages
   ========================= */
$message      = $_SESSION['flash']      ?? '';
$message_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash'], $_SESSION['flash_type']);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Biblioteca — Book Manager Pro</title>
  <link rel="stylesheet" href="assets/css/style.css" />
  <style>
.books-table .table-header,
.books-table .table-row{
  display: grid;
  grid-template-columns:
    64px      /* # */
    2fr       /* Título */
    1.4fr     /* Autor  */
    92px      /* Año    */
    140px     /* Género */
    220px     /* Categorías */
    130px     /* Agregado */
    180px;    /* Acciones  (↑ antes 120px) */
  align-items: center;
  gap: 12px;
  overflow: visible;           /* ← evita cortes del contenido */
}
.th-category, .td-category{ white-space: normal; }
.td-category .genre-tag{
  font-size: 12px; padding: 4px 10px; border-radius: 999px; display: inline-block;
}
.td-id{ text-align: center; }
.td-year{ text-align: right; }
.td-date{ text-align: right; }

/* Acciones: que no recorte y permita envolver */
.td-actions{
  display:flex; flex-direction:column; gap:6px; justify-content:flex-start;
  overflow: visible;              /* ← muy importante */
}

/* Responsivo */
@media (max-width: 1024px){
  .books-table .table-header,
  .books-table .table-row{
    grid-template-columns: 48px 1.8fr 1.2fr 72px 120px 1.6fr 110px 160px;
    gap: 10px;
  }
}
@media (max-width: 768px){
  .books-table .table-row{
    grid-template-columns: 48px 1fr 1fr 72px 110px 1fr 100px 150px;
  }
  .td-category{ grid-column: 2 / span 6; margin-top: 6px; }
}

/* Stars */
.stars-form .stars { display:inline-flex; align-items:center; gap:2px; flex-wrap:nowrap; }
.stars-form input[type="radio"] { display:none; }
.stars-form label {
  cursor:pointer; font-size:20px; line-height:1; user-select:none;
}
.stars-form input[type="radio"]:checked + label ~ label { opacity:.55; }
.stars-form .btn-rate {
  padding:4px 10px; border:1px solid var(--border); border-radius:999px;
  background:var(--bg-table-row); cursor:pointer; font-size:.85rem;
}
.stars-form .btn-rate:hover { background:var(--bg-secondary); }
.rating-badge {
  display:inline-block; font-size:.9rem; padding:2px 8px; border-radius:999px;
  background:var(--bg-table-row); border:1px solid var(--border);
}
</style>

</head>
<body>
  <!-- Top Navigation Bar -->
  <nav class="top-navbar">
    <div class="navbar-container">
      <div class="navbar-brand">
        <span class="brand-icon">📚</span>
        <div class="brand-text">
          <h1>Book Manager</h1>
          <span class="brand-subtitle">Tu colección literaria</span>
        </div>
      </div>

      <div class="navbar-menu">
        <a href="index.php" class="menu-item active"><span>Biblioteca</span></a>
        <a href="add.php" class="menu-item"><span>Agregar</span></a>
		<a class="menu-item" href="add_from_google.php">Buscar en Google</a>
        <a href="reports.php" class="menu-item"><span>Reportes</span></a>
      </div>

      <button id="themeToggle" class="menu-item" type="button" title="Cambiar tema">
        <span id="themeIcon">🌙</span>
      </button>

      <div class="navbar-user">
        <div class="user-name"><span><?= h($_SESSION['username'] ?? 'usuario') ?></span></div>
        <form action="logout.php" method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer">Salir</button>
        </form>
      </div>
    </div>
  </nav>

  <!-- Main Container -->
  <div class="container">
    <!-- Mensajes -->
    <?php if (!empty($message)): ?>
      <div class="alert alert-<?= h($message_type) ?>">
        <span class="alert-icon"><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
        <span><?= h($message) ?></span>
      </div>
    <?php endif; ?>

    <!-- Encabezado -->
    <div class="page-header-bar">
      <div class="header-title">
        <span class="title-icon">📚</span>
        <h2>Lista de Libros</h2>
      </div>
      <div class="counter-compact">
        <span class="count-num"><?= (int)$total ?></span> <?= $q === '' ? 'libros' : 'resultado(s)' ?>
      </div>
    </div>

    <!-- Buscador -->
    <form class="search-bar" method="get" action="index.php" role="search" aria-label="Buscar libros por título o autor">
      <select name="genre" aria-label="Filtrar por género" onchange="this.form.submit()">
        <option value="">Todos los géneros</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= h($g) ?>" <?= $genre === $g ? 'selected' : '' ?>><?= h($g) ?></option>
        <?php endforeach; ?>
      </select>

      <!-- ✅ Filtro por Categoría (tags) -->
      <select name="cat" aria-label="Filtrar por categoría" onchange="this.form.submit()">
        <option value="0">Todas las etiquetas</option>
        <?php foreach ($allCats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id'] ? 'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por título o autor…" />
      <button type="submit" class="menu-item active">Buscar</button>

      <?php if ($q !== '' || $genre !== '' || $cat>0): ?>
        <a class="menu-item" href="index.php">Limpiar</a>
      <?php endif; ?>
    </form>

    <div class="result-meta">
      <?php if ($q !== ''): ?>
        Búsqueda: <strong><?= h($q) ?></strong> — <?= (int)$total ?> resultado(s).
      <?php else: ?>
        Mostrando <?= count($books) ?> de <?= (int)$total ?> libros.
      <?php endif; ?>
    </div>

    <!-- Tabla / Estado -->
    <?php if (empty($books)): ?>
      <div class="empty-state">
        <div class="empty-icon">📚</div>
        <h3>No hay libros <?= $q !== '' ? 'que coincidan con tu búsqueda' : 'registrados' ?></h3>
        <p><?= $q !== '' ? 'Intenta con otro término o usa palabras clave parciales.' : 'Comienza agregando tu primer libro favorito.' ?></p>
        <a href="add.php" class="btn-add-book">➕ Agregar Libro</a>
      </div>
    <?php else: ?>
      <div class="books-table">
        <!-- Header -->
        <div class="table-header">
          <div class="th th-id">#</div>
          <div class="th th-title">Título</div>
          <div class="th th-author">Autor</div>
          <div class="th th-year">Año</div>
          <div class="th th-genre">Género</div>
          <div class="th th-category">Categorías</div>
          <div class="th th-date">Agregado</div>
          <div class="th th-actions">Acciones</div>
        </div>

        <!-- Body -->
        <div class="table-body">
        <?php foreach ($books as $book): ?>
          <div class="table-row">
            <div class="td td-id"><span class="book-number">#<?= h($book['id']) ?></span></div>

            <div class="td td-title">
              <div class="title-with-icon"><span class="book-title"><?= h($book['title']) ?></span></div>
            </div>

            <div class="td td-author"><?= h($book['author']) ?></div>

            <div class="td td-year"><?= h((string)($book['year'] ?? '')) ?></div>

            <div class="td td-genre">
              <?php if (!empty($book['genre'])): ?>
                <span class="genre-tag"><?= h($book['genre']) ?></span>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </div>

            <!-- 🔸 COLUMNA: CATEGORÍAS (sin N+1 queries) -->
            <div class="td td-category">
              <?php
                $catNames = getBookCategoryNames($pdo, (int)$book['id']);
                if (!empty($catNames)):
              ?>
                <div class="tags" style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php foreach ($catNames as $nm):
                    $cid = $catIdByName[mb_strtolower((string)$nm)] ?? 0; ?>
                    <?php if ($cid > 0): ?>
                      <a class="genre-tag" href="index.php?cat=<?= $cid ?>"><?= h($nm) ?></a>
                    <?php else: ?>
                      <span class="genre-tag"><?= h($nm) ?></span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </div>
            <!-- 🔸 FIN COLUMNA -->

            <div class="td td-date">
              <?php
                $ts = $book['created_at'] ?? null;
                echo $ts ? h(date('d/m/Y', strtotime((string)$ts))) : '<span class="text-muted">-</span>';
              ?>
            </div>

            <div class="td td-actions">
              <a class="btn-icon btn-edit" href="edit.php?id=<?= h($book['id']) ?>" title="Editar">✏️</a>
              <a class="btn-icon btn-delete" href="delete.php?id=<?= h($book['id']) ?>" title="Eliminar">🗑️</a>
              <?php
                $stats = getBookRatingStats($pdo, (int)$book['id']);
                $my    = getUserRating($pdo, (int)$_SESSION['user_id'], (int)$book['id']);
                $qsBack = '?page='.$page.$qs; // vuelve a la misma página con filtros
              ?>
              <div style="display:flex; flex-direction:column; gap:6px; margin-top:6px;">
                <span class="rating-badge" title="Promedio y cantidad de calificaciones">
                  <?= is_null($stats['avg']) ? 'Sin calificar' : ('★ '.h((string)$stats['avg']).' ('.(int)$stats['cnt'].')') ?>
                </span>
                <?= renderStarsForm((int)$book['id'], $my, $qsBack, (string)($_SESSION['csrf'] ?? '')) ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      </div>

      <!-- Paginación -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination" aria-label="Paginación">
          <?php if ($hasPrev): ?>
            <a class="page-num" href="?page=<?= $page-1 . $qs ?>" aria-label="Página anterior">«</a>
          <?php else: ?>
            <span class="page-dot">«</span>
          <?php endif; ?>

          <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1) echo '<span class="page-dot">…</span>';
            for ($p = $start; $p <= $end; $p++):
          ?>
            <a class="page-num <?= $p === $page ? 'active' : '' ?>" href="?page=<?= $p . $qs ?>"><?= $p ?></a>
          <?php endfor;
            if ($end < $totalPages) echo '<span class="page-dot">…</span>';
          ?>

          <?php if ($hasNext): ?>
            <a class="page-num" href="?page=<?= $page+1 . $qs ?>" aria-label="Página siguiente">»</a>
          <?php else: ?>
            <span class="page-dot">»</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
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
    // Oculta mensajes después de 5s
    setTimeout(() => {
      document.querySelectorAll('.alert').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(-20px)';
        setTimeout(() => el.remove(), 300);
      });
    }, 5000);
  </script>

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
