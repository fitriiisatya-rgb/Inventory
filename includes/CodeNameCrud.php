<?php
declare(strict_types=1);

/** Shared CRUD for categories/locations — identical shape (id, code, name, status). */
final class CodeNameCrud
{
    public function __construct(private PDO $pdo, private string $table)
    {
    }

    public function list(): array
    {
        return $this->pdo->query("SELECT * FROM {$this->table} ORDER BY name")->fetchAll();
    }

    public function create(string $code, string $name, int $actorId): array
    {
        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('Kode dan nama wajib diisi.');
        }
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (code, name, status) VALUES (?, ?, 'ACTIVE')");
        $stmt->execute([$code, $name]);
        $id = (int) $this->pdo->lastInsertId();

        $row = $this->find($id);
        Audit::log($actorId, strtoupper($this->table) . '_CREATE', $this->table, $id, null, $row);
        return $row;
    }

    public function update(int $id, string $code, string $name, string $status, int $actorId): array
    {
        $old = $this->find($id);
        if (!$old) {
            throw new RuntimeException('Data tidak ditemukan.');
        }
        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('Kode dan nama wajib diisi.');
        }
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new InvalidArgumentException('Status tidak valid.');
        }
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET code = ?, name = ?, status = ? WHERE id = ?");
        $stmt->execute([$code, $name, $status, $id]);

        $new = $this->find($id);
        Audit::log($actorId, strtoupper($this->table) . '_UPDATE', $this->table, $id, $old, $new);
        return $new;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
