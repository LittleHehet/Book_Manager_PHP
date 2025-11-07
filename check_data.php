<?php
require_once 'config/database.php';
$stmt = $pdo->query('SELECT * FROM books');
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo 'Total de libros en la BD REAL: ' . count($books) . "\n\n";
foreach ($books as $book) {
    echo '- ' . $book['title'] . ' por ' . $book['author'] . ' (' . $book['year'] . ")\n";
}
?>