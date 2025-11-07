<?php
// add.php
require_once __DIR__ . '/models/Book.php';

$errores = [];
$titulo = $autor = $anio = $genero = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['title']);
    $autor = trim($_POST['author']);
    $anio = trim($_POST['year']);
    $genero = trim($_POST['genre']);

    // Validaciones básicas
    if ($titulo === '') $errores[] = "El título es obligatorio.";
    if ($autor === '') $errores[] = "El autor es obligatorio.";
    if ($anio !== '' && !is_numeric($anio)) $errores[] = "El año debe ser un número.";
    
    // Si no hay errores, agregar libro
    if (empty($errores)) {
        Book::create($titulo, $autor, $anio ?: null, $genero);
        header("Location: index.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>➕ Agregar Libro</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>


    <h1>➕ Agregar Libro</h1>
    <a href="index.php">← Volver al listado</a>

    <?php if (!empty($errores)): ?>
        <ul style="color: red;">
            <?php foreach ($errores as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="POST">
        <label>Título:<br>
            <input type="text" name="title" value="<?= htmlspecialchars($titulo) ?>" required>
        </label><br><br>

        <label>Autor:<br>
            <input type="text" name="author" value="<?= htmlspecialchars($autor) ?>" required>
        </label><br><br>

        <label>Año:<br>
            <input type="number" name="year" value="<?= htmlspecialchars($anio) ?>">
        </label><br><br>

        <label>Género:<br>
            <input type="text" name="genre" value="<?= htmlspecialchars($genero) ?>">
        </label><br><br>

        <button type="submit">📚 Guardar libro</button>
    </form>
</body>
</html>
 