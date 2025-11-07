<?php

class Book
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(?string $q = null, ?int $limit = null, int $offset = 0): array
    {
        $isSearch = ($q !== null && trim($q) !== '');
        $like = $isSearch ? '%' . trim($q) . '%' : null;

        if ($isSearch) {
            if ($limit === null) {
                $sql = "SELECT * FROM books
                        WHERE title  LIKE :q COLLATE NOCASE
                           OR author LIKE :q COLLATE NOCASE
                        ORDER BY created_at DESC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':q', $like, PDO::PARAM_STR);
            } else {
                $sql = "SELECT * FROM books
                        WHERE title  LIKE :q COLLATE NOCASE
                           OR author LIKE :q COLLATE NOCASE
                        ORDER BY created_at DESC
                        LIMIT :limit OFFSET :offset";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':q', $like, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            }
        } else {
            if ($limit === null) {
                $sql = "SELECT * FROM books ORDER BY created_at DESC";
                $stmt = $this->pdo->prepare($sql);
            } else {
                $sql = "SELECT * FROM books ORDER BY created_at DESC
                        LIMIT :limit OFFSET :offset";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            }
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCount(?string $q = null): int
    {
        if ($q !== null && trim($q) !== '') {
            $sql = "SELECT COUNT(*) FROM books
                    WHERE title  LIKE :q COLLATE NOCASE
                       OR author LIKE :q COLLATE NOCASE";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':q', '%' . trim($q) . '%', PDO::PARAM_STR);
        } else {
            $sql = "SELECT COUNT(*) FROM books";
            $stmt = $this->pdo->prepare($sql);
        }

        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function create($title, $author, $year, $genre)
    {
        $stmt = $this->pdo->prepare("INSERT INTO books (title, author, year, genre) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$title, $author, $year, $genre]);
    }

    public function update($id, $title, $author, $year, $genre)
    {
        $stmt = $this->pdo->prepare("UPDATE books SET title = ?, author = ?, year = ?, genre = ? WHERE id = ?");
        return $stmt->execute([$title, $author, $year, $genre, $id]);
    }

    public function delete($id)
    {
        $stmt = $this->pdo->prepare("DELETE FROM books WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
