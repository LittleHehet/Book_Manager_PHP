<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/Book.php';

class BookTest extends TestCase
{
    private $book;
    private $pdo;

    protected function setUp(): void
    {
        global $pdo;
        $this->pdo = $pdo;

        // Asegurar que exista la tabla (solo en caso)
        $this->pdo->exec("DELETE FROM books");

        // Crear instancia del modelo
        $this->book = new Book($this->pdo);
    }

    public function testCreateBook()
    {
        $result = $this->book->create('El Quijote', 'Cervantes', 1605, 'Novela');
        $this->assertTrue($result);

        $this->assertEquals(1, $this->book->getCount());
    }

    public function testGetAllBooks()
    {
        $this->book->create('Libro 1', 'Autor 1', 2020, 'Ficción');
        $this->book->create('Libro 2', 'Autor 2', 2021, 'Drama');

        $books = $this->book->getAll();
        $this->assertCount(2, $books);

        $titles = array_column($books, 'title');
        $this->assertContains('Libro 1', $titles);
        $this->assertContains('Libro 2', $titles);
    }

    public function testSearchBooks()
    {
        $this->book->create('Harry Potter', 'J.K. Rowling', 1997, 'Fantasía');
        $this->book->create('El Señor de los Anillos', 'Tolkien', 1954, 'Fantasía');

        $results = $this->book->getAll('Harry');
        $this->assertCount(1, $results);
        $this->assertEquals('Harry Potter', $results[0]['title']);
    }

    public function testGetCount()
    {
        $this->book->create('Libro 1', 'Autor 1', 2020, 'Ficción');
        $this->book->create('Libro 2', 'Autor 2', 2021, 'Drama');

        $count = $this->book->getCount();
        $this->assertEquals(2, $count);
    }

    public function testGetCountWithSearch()
    {
        $this->book->create('Harry Potter', 'J.K. Rowling', 1997, 'Fantasía');
        $this->book->create('El Señor de los Anillos', 'Tolkien', 1954, 'Fantasía');

        $count = $this->book->getCount('Harry');
        $this->assertEquals(1, $count);
    }

    public function testUpdateBook()
    {
        $this->book->create('Título Original', 'Autor Original', 2020, 'Drama');
        $books = $this->book->getAll();
        $id = $books[0]['id'];

        $result = $this->book->update($id, 'Título Actualizado', 'Autor Actualizado', 2021, 'Comedia');
        $this->assertTrue($result);

        $updatedBooks = $this->book->getAll();
        $this->assertEquals('Título Actualizado', $updatedBooks[0]['title']);
    }

    public function testDeleteBook()
    {
        $this->book->create('Libro a Eliminar', 'Autor', 2020, 'Drama');
        $books = $this->book->getAll();
        $id = $books[0]['id'];

        $result = $this->book->delete($id);
        $this->assertTrue($result);

        $count = $this->book->getCount();
        $this->assertEquals(0, $count);
    }

    public function testPagination()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->book->create("Libro $i", "Autor $i", 2020 + $i, 'Drama');
        }

        $page1 = $this->book->getAll(null, 2, 0);
        $this->assertCount(2, $page1);

        $page2 = $this->book->getAll(null, 2, 2);
        $this->assertCount(2, $page2);

        $this->assertNotEquals($page1[0]['id'], $page2[0]['id']);
    }
}
